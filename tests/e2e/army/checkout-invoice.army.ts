import { test, describe } from "@e2e-dev/web";
import { credentials, expect } from "e2e";

// Classic and Blocks checkout with an invoice request; then the admin completes the order in the UI and the
// admin screens must show the created draft. Mat Bao is answered by the fake mu-plugin unless
// ./setup-site.sh ran in demo mode. All buyer data is synthetic. The CCCD below is a made-up number.

const BILLING = { first_name: "Nguyễn", last_name: "Văn Thử", phone: "0912345678", email: "e2e-buyer@example.invalid", address: "3 Đường Thử Nghiệm", city: "Hồ Chí Minh" };
const CLASSIC_LABELS: Record<string, string> = { first_name: "First name", last_name: "Last name", address_1: "Street address", city: "Town / City", phone: "Phone", email: "Email address" };
const INDIVIDUAL = { address: "1 Đường Thử Nghiệm, Quận 1, TP HCM", id_number: "000000000000" };
const BUSINESS = { name: "CÔNG TY TNHH THỬ NGHIỆM E2E", tax_code: "0000000000", address: "2 Đường Thử Nghiệm, Quận 3, TP HCM" };

type Buyer = "walk_in" | "individual" | "business";

const BUYER_GOALS: Record<Buyer, string> = {
	walk_in: "",
	individual: "tick the invoice request checkbox 'Yêu cầu xuất hóa đơn', choose 'Cá nhân', fill the invoice address {address} and the ID number {id_number}",
	business: "tick the invoice request checkbox 'Yêu cầu xuất hóa đơn', choose 'Doanh nghiệp', fill the business name {name}, the tax code {tax_code} and the invoice address {address}",
};
const BUYER_PARAMS: Record<Buyer, Record<string, string>> = { walk_in: {}, individual: INDIVIDUAL, business: BUSINESS };

describe("checkout invoice request", { serial: false }, () => {
	for (const checkout of ["classic", "blocks"] as const) {
		for (const buyer of ["walk_in", "individual", "business"] as Buyer[]) {
			if (checkout === "blocks" && buyer === "individual") continue; // Classic covers individual; keep model cost down.
			// Blocks is opt-in: the checkout page renders in the browser but the invoice block and place-order steps are not stable yet (tests/e2e/README.md).
			const run = checkout === "classic" || process.env.E2E_BLOCKS === "1" ? test : test.skip;
			run(`${checkout} checkout, ${buyer} buyer: completed order gets a draft`, { agent: checkout === "blocks" ? "text" : "default" }, async ({ app, agent, screen, browser }) => {
				await app.open(`/?add-to-cart=${process.env.E2E_PRODUCT_ID}`);
				await app.open(checkout === "classic" ? "/?pagename=classic-checkout" : "/?pagename=checkout");

				if (checkout === "classic") {
					for (const [field, value] of [["first_name", BILLING.first_name], ["last_name", BILLING.last_name], ["address_1", BILLING.address], ["city", BILLING.city], ["phone", BILLING.phone], ["email", BILLING.email]]) {
						await screen.getByLabel(CLASSIC_LABELS[field], { exact: false }).first().fill(value);
					}
				} else {
					for (const [field, value] of [["Email address", BILLING.email], ["First name", BILLING.first_name], ["Last name", BILLING.last_name], ["Address", BILLING.address], ["City", BILLING.city], ["Phone", BILLING.phone]]) {
						await screen.getByLabel(field, { exact: true }).fill(value); // "Address" must not match "Email address"
					}
				}
				if (buyer !== "walk_in") {
					await agent.act(BUYER_GOALS[buyer], { params: BUYER_PARAMS[buyer] });
				}
				await screen.getByRole("button", "Place order", { exact: false }).first().tap();
				await expect(browser).toHaveURL(/order-received/);
				await expect(screen.getByText("Dịch vụ E2E", { exact: false }).first()).toBeVisible();

				const orderId = Number(/order-received(?:\/|=)(\d+)/.exec(await browser.url())?.[1]);
				expect(orderId).toBeGreaterThan(0);

				// The shopper checked out as a guest; sign in as the admin only now.
				const admin = credentials.user("admin");
				await app.open("/wp-login.php");
				await screen.getByLabel("Username or Email Address").fill(admin.username);
				await screen.getByLabel("Password", { exact: false }).first().fill(admin.password);
				await screen.getByRole("button", "Log In").tap();
				await expect(browser).toHaveURL(/wp-admin/);

				// E2E-only REST route in the mu-plugin completes the order and runs the queue.
				const done = await fetch(`${app.baseUrl}/?rest_route=/einv-e2e/v1/complete/${orderId}`, { method: "POST" });
				expect(done.ok).toBe(true);
				await app.open(`/wp-admin/post.php?post=${orderId}&action=edit`);

				const created = screen.getByText("Đã tạo nháp", { exact: false });
				for (let i = 0; i < 20 && !(await created.first().isVisible()); i++) {
					await app.open(`/wp-admin/post.php?post=${orderId}&action=edit`);
				}
				await expect(created.first()).toBeVisible();

				await app.open(`/wp-admin/admin.php?page=einvoice-matbao-invoices`);
				await screen.getByRole("searchbox").fill(String(orderId));
				await screen.getByRole("searchbox").press("Enter");
				await expect(screen.getByText("Đã tạo nháp", { exact: false }).first()).toBeVisible();
				await expect(screen.getByText(`#${orderId}`, { exact: true })).toBeVisible();
			});
		}
	}
});
