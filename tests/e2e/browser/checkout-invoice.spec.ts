import { expect, test, type Page } from "@playwright/test";
import { mkdirSync, writeFileSync } from "node:fs";

// Classic and Blocks checkout with an invoice request, then the admin completes the order and the
// plugin creates the Mat Bao draft. EINV_E2E_MODE=fake (default) answers Mat Bao inside WordPress
// (tests/e2e/wordpress/einv-e2e-http.php); EINV_E2E_MODE=demo calls the demo API for real.
// Buyer data below is synthetic test data, never real people or companies.

const MODE = process.env.EINV_E2E_MODE ?? "fake";
const ADMIN_USER = process.env.EINV_E2E_ADMIN_USER ?? "admin";
const ADMIN_PASS = process.env.EINV_E2E_ADMIN_PASS ?? "admin";
const outcomes: Record<string, unknown>[] = [];

type Buyer =
	| { type: "walk_in" }
	| { type: "individual"; address: string; idNumber: string }
	| { type: "business"; name: string; taxCode: string; address: string };

const INDIVIDUAL: Buyer = { type: "individual", address: "1 Đường Thử Nghiệm, Quận 1, TP HCM", idNumber: "000000000000" };
const BUSINESS: Buyer = { type: "business", name: "CÔNG TY TNHH THỬ NGHIỆM E2E", taxCode: "0000000000", address: "2 Đường Thử Nghiệm, Quận 3, TP HCM" };

const BILLING = { first: "Nguyễn", last: "Văn Thử", phone: "0912345678", email: "e2e-buyer@example.invalid", address: "3 Đường Thử Nghiệm", city: "Hồ Chí Minh" };

async function addProductToCart(page: Page): Promise<void> {
	const id = process.env.EINV_E2E_PRODUCT_ID;
	expect(id, "EINV_E2E_PRODUCT_ID is exported by run.sh").toBeTruthy();
	await page.goto(`/?add-to-cart=${id}`);
}

async function classicCheckout(page: Page, buyer: Buyer): Promise<number> {
	await addProductToCart(page);
	await page.goto("/?pagename=classic-checkout");
	await page.locator("#billing_first_name").fill(BILLING.first);
	await page.locator("#billing_last_name").fill(BILLING.last);
	await page.locator("#billing_address_1").fill(BILLING.address);
	await page.locator("#billing_city").fill(BILLING.city);
	await page.locator("#billing_phone").fill(BILLING.phone);
	await page.locator("#billing_email").fill(BILLING.email);
	if (buyer.type !== "walk_in") {
		await page.locator("#einv_request_invoice").check();
		await page.locator(`input[name=einv_buyer_type][value=${buyer.type}]`).check();
		if (buyer.type === "business") {
			await page.locator("#einv_business_name").fill(buyer.name);
			await page.locator("#einv_tax_code").fill(buyer.taxCode);
		} else {
			await page.locator("#einv_id_number").fill(buyer.idNumber);
		}
		await page.locator("#einv_address").fill(buyer.address);
	}
	await page.locator("#payment_method_cod").check({ force: true });
	await page.locator("#place_order").click();
	return orderReceivedId(page);
}

async function blocksCheckout(page: Page, buyer: Buyer): Promise<number> {
	await addProductToCart(page);
	await page.goto("/?pagename=checkout");
	await page.locator("#email").fill(BILLING.email);
	await page.locator("#billing-first_name").fill(BILLING.first);
	await page.locator("#billing-last_name").fill(BILLING.last);
	await page.locator("#billing-address_1").fill(BILLING.address);
	await page.locator("#billing-city").fill(BILLING.city);
	await page.locator("#billing-phone").fill(BILLING.phone);
	if (buyer.type !== "walk_in") {
		await page.getByText("Yêu cầu xuất hóa đơn", { exact: true }).click();
		await page.locator(`input[name=einv_buyer_type][value=${buyer.type}]`).check();
		if (buyer.type === "business") {
			await page.locator("#einv_business_name").fill(buyer.name);
			await page.locator("#einv_tax_code").fill(buyer.taxCode);
		} else {
			await page.locator("#einv_id_number").fill(buyer.idNumber);
		}
		await page.locator("#einv_address").fill(buyer.address);
	}
	await page.locator(".wc-block-components-checkout-place-order-button").click();
	return orderReceivedId(page);
}

async function orderReceivedId(page: Page): Promise<number> {
	await page.waitForURL(/order-received(?:\/|=)(\d+)/, { timeout: 60_000 });
	return Number(/order-received(?:\/|=)(\d+)/.exec(page.url())?.[1]);
}

async function loginAsAdmin(page: Page): Promise<void> {
	await page.goto("/wp-login.php");
	await page.locator("#user_login").fill(ADMIN_USER);
	await page.locator("#user_pass").fill(ADMIN_PASS);
	await page.locator("#wp-submit").click();
	await page.waitForURL(/wp-admin/);
}

async function completeOrderAndWaitForDraft(page: Page, orderId: number): Promise<{ status: string; text: string }> {
	await page.goto(`/wp-admin/post.php?post=${orderId}&action=edit`);
	await expect(page.locator("#einvoice-matbao-invoice")).toBeVisible();
	await page.locator("#order_status").selectOption("wc-completed", { force: true });
	await page.locator("button.save_order").click();
	await page.waitForLoadState("load");

	// Action Scheduler runs the draft action asynchronously (loopback after the admin request);
	// reload until the meta box leaves "pending"/"sending".
	const box = page.locator("#einvoice-matbao-invoice [data-einv-invoice-status]");
	let status = "";
	for (let i = 0; i < 30; i++) {
		status = (await box.first().getAttribute("data-einv-invoice-status")) ?? "";
		if (!["none", "pending", "sending"].includes(status)) break;
		await page.request.get("/wp-cron.php?doing_wp_cron");
		await page.waitForTimeout(2000);
		await page.reload();
	}
	return { status, text: (await page.locator("#einvoice-matbao-invoice").innerText()).replace(/\s+/g, " ").trim() };
}

async function expectInInvoiceList(page: Page, orderId: number): Promise<string> {
	await page.goto("/wp-admin/admin.php?page=einvoice-matbao-invoices");
	await page.locator("input[name=q]").fill(String(orderId));
	await page.locator("input[name=q]").press("Enter");
	const row = page.locator(`tr[data-einv-invoice-row="${orderId}"]`);
	await expect(row).toBeVisible();
	await expect(row).toContainText("Đã tạo nháp");
	return (await row.locator("code").innerText()).trim();
}

const cases: { name: string; checkout: (page: Page, buyer: Buyer) => Promise<number>; buyer: Buyer }[] = [
	{ name: "classic individual", checkout: classicCheckout, buyer: INDIVIDUAL },
	{ name: "classic business", checkout: classicCheckout, buyer: BUSINESS },
	{ name: "blocks individual", checkout: blocksCheckout, buyer: INDIVIDUAL },
	{ name: "blocks business", checkout: blocksCheckout, buyer: BUSINESS },
	{ name: "blocks walk-in", checkout: blocksCheckout, buyer: { type: "walk_in" } },
];

for (const c of cases) {
	test(`${c.name}: order completed creates the draft (${MODE})`, async ({ browser }) => {
		const shopper = await browser.newPage();
		const orderId = await c.checkout(shopper, c.buyer);
		expect(orderId).toBeGreaterThan(0);
		await shopper.close();

		const admin = await browser.newPage();
		await loginAsAdmin(admin);
		const { status, text } = await completeOrderAndWaitForDraft(admin, orderId);
		expect(status, text).toBe("draft_created");
		await expect(admin.locator("#einvoice-matbao-invoice")).toContainText("Đã tạo nháp");
		const reference = await expectInInvoiceList(admin, orderId);
		expect(reference).toMatch(/WC\d+$/);
		outcomes.push({ case: c.name, mode: MODE, order_id: orderId, reference, status, buyer_type: c.buyer.type });
		await admin.close();
	});
}

test.afterAll(() => {
	mkdirSync("results", { recursive: true });
	writeFileSync(`results/outcomes-${MODE}.json`, JSON.stringify(outcomes, null, 2));
});
