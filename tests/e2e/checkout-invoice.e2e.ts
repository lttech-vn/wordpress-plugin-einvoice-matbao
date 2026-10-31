import { test, describe } from "@e2e-dev/web";
import { credentials, expect } from "e2e";
import { completeOrderAndRunQueue, latestOrderId, productId } from "./support.js";

// Classic and Blocks checkout with an invoice request; then the order is completed (wp-cli) and the
// admin screens must show the created draft. Mat Bao is answered by the fake mu-plugin unless
// ./setup-site.sh ran in demo mode. All buyer data is synthetic. The CCCD below is a made-up number.

const BILLING = { first_name: "Nguyễn", last_name: "Văn Thử", phone: "0912345678", email: "e2e-buyer@example.invalid", address: "3 Đường Thử Nghiệm", city: "Hồ Chí Minh" };
const INDIVIDUAL = { address: "1 Đường Thử Nghiệm, Quận 1, TP HCM", id_number: "000000000000" };
const BUSINESS = { name: "CÔNG TY TNHH THỬ NGHIỆM E2E", tax_code: "0000000000", address: "2 Đường Thử Nghiệm, Quận 3, TP HCM" };

type Buyer = "walk_in" | "individual" | "business";

const BUYER_GOALS: Record<Buyer, string> = {
	walk_in: "leave the invoice request checkbox unticked",
	individual: "tick the invoice request checkbox 'Yêu cầu xuất hóa đơn', choose 'Cá nhân', fill the invoice address {address} and the ID number {id_number}",
	business: "tick the invoice request checkbox 'Yêu cầu xuất hóa đơn', choose 'Doanh nghiệp', fill the business name {name}, the tax code {tax_code} and the invoice address {address}",
};
const BUYER_PARAMS: Record<Buyer, Record<string, string>> = { walk_in: {}, individual: INDIVIDUAL, business: BUSINESS };

describe("checkout invoice request", { serial: false }, () => {
	for (const checkout of ["classic", "blocks"] as const) {
		for (const buyer of ["walk_in", "individual", "business"] as Buyer[]) {
			if (checkout === "blocks" && buyer === "individual") continue; // Classic covers individual; keep model cost down.
			test(`${checkout} checkout, ${buyer} buyer: completed order gets a draft`, async ({ app, agent, screen, browser }) => {
				await app.open(`/?add-to-cart=${productId()}`);
				await app.open(checkout === "classic" ? "/?pagename=classic-checkout" : "/?pagename=checkout");

				await agent.act("fill the billing details: first name {first_name}, last name {last_name}, address {address}, city {city}, phone {phone}, email {email}", {
					params: { ...BILLING },
				});
				await agent.act(BUYER_GOALS[buyer], { params: BUYER_PARAMS[buyer] });
				await agent.act("choose cash on delivery if a payment method must be chosen, then place the order");
				await expect(browser).toHaveURL(/order-received/);
				await expect(screen.getByText("Dịch vụ E2E", { exact: false }).first()).toBeVisible();

				const orderId = latestOrderId();
				completeOrderAndRunQueue(orderId);

				// The shopper checked out as a guest; sign in as the admin only now.
				const admin = credentials.user("admin");
				await app.open("/wp-login.php");
				await screen.getByLabel("Username or Email Address").fill(admin.username);
				await screen.getByLabel("Password", { exact: false }).first().fill(admin.password);
				await screen.getByRole("button", "Log In").tap();
				await expect(browser).toHaveURL(/wp-admin/);

				await app.open(`/wp-admin/post.php?post=${orderId}&action=edit`);
				await expect(screen.getByText("Đã tạo nháp", { exact: false }).first()).toBeVisible();

				await app.open(`/wp-admin/admin.php?page=einvoice-matbao-invoices`);
				await screen.getByRole("searchbox").fill(String(orderId));
				await screen.getByRole("searchbox").press("Enter");
				await expect(screen.getByText("Đã tạo nháp", { exact: false }).first()).toBeVisible();
				await expect(screen.getByText(`#${orderId}`, { exact: true })).toBeVisible();
			});
		}
	}
});
