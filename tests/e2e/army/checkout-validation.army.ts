import { test, describe, beforeEach } from "@e2e-dev/web";
import { expect } from "e2e";
import { fake, orderIdFromUrl, resetWorld, seam, waitForStatus } from "./support.ts";

// Stories 13-19: the invoice request at checkout. Every step is exact (no agent): the browser rules mirror the
// server rules, a failing form never reaches the server, and a direct Store API call is validated the same way.
// Buyer data is synthetic; the CCCD and tax codes are made-up numbers.

const BILLING = { first_name: "Nguyễn", last_name: "Văn Thử", phone: "0912345678", email: "e2e-buyer@example.invalid", address: "3 Đường Thử Nghiệm", city: "Hồ Chí Minh" };
const LABELS: Record<string, string> = { first_name: "First name", last_name: "Last name", address_1: "Street address", city: "Town / City", phone: "Phone", email: "Email address" };

async function fillClassicBilling({ screen, app }: any) {
	await app.open(`/?add-to-cart=${process.env.E2E_PRODUCT_ID}`);
	await app.open("/?pagename=classic-checkout");
	for (const [field, value] of [["first_name", BILLING.first_name], ["last_name", BILLING.last_name], ["address_1", BILLING.address], ["city", BILLING.city], ["phone", BILLING.phone], ["email", BILLING.email]]) {
		await screen.getByLabel(LABELS[field], { exact: false }).first().fill(value);
	}
}

const placeOrder = (screen: any) => screen.getByRole("button", "Place order", { exact: false }).first();

describe("classic checkout validation", { serial: false }, () => {
	beforeEach(async (ctx) => {
		await resetWorld(ctx);
	});

	test("the invoice fields stay hidden until the checkbox is ticked", async ({ app, screen, browser }) => {
		await fillClassicBilling({ app, screen });
		await expect(browser.locator("#einv-invoice-fields")).toBeHidden();
		await screen.getByLabel("Yêu cầu xuất hóa đơn").check();
		await expect(browser.locator("#einv-invoice-fields")).toBeVisible();
		await expect(screen.getByLabel("Cá nhân")).toBeVisible();
		await expect(screen.getByLabel("Doanh nghiệp")).toBeVisible();
	});

	test("ticking the request without choosing a type blocks the order in the browser", async ({ app, screen, browser }) => {
		await fillClassicBilling({ app, screen });
		await screen.getByLabel("Yêu cầu xuất hóa đơn").check();
		await placeOrder(screen).tap();
		await expect(browser.locator("#einv_buyer_type_error")).toContainText("Vui lòng chọn xuất hóa đơn cho cá nhân hoặc doanh nghiệp.");
		await expect(browser).toHaveURL(/classic-checkout/);
	});

	test("a business needs name, a 10 or 13 digit tax code and an address", async ({ app, screen, browser }) => {
		await fillClassicBilling({ app, screen });
		await screen.getByLabel("Yêu cầu xuất hóa đơn").check();
		await screen.getByLabel("Doanh nghiệp").check();
		await screen.getByLabel("Mã số thuế").fill("12345");
		await placeOrder(screen).tap();
		await expect(browser.locator("#einv_business_name_error")).toContainText("Vui lòng nhập tên doanh nghiệp");
		await expect(browser.locator("#einv_tax_code_error")).toContainText("Mã số thuế phải gồm 10 hoặc 13 chữ số");
		await expect(browser.locator("#einv_address_error")).toContainText("Vui lòng nhập địa chỉ xuất hóa đơn");
		await expect(browser).toHaveURL(/classic-checkout/);

		// Fixing the fields clears the errors as the buyer types.
		await screen.getByLabel("Tên doanh nghiệp").fill("CÔNG TY TNHH THỬ NGHIỆM E2E");
		await screen.getByLabel("Mã số thuế").fill("0000000000");
		await screen.getByLabel("Địa chỉ xuất hóa đơn").fill("2 Đường Thử Nghiệm, Quận 3");
		await expect(browser.locator("#einv_tax_code_error")).toHaveText("");
		await expect(browser.locator("#einv_business_name_error")).toHaveText("");
	});

	test("an individual needs a 12 digit CCCD", async ({ app, screen, browser }) => {
		await fillClassicBilling({ app, screen });
		await screen.getByLabel("Yêu cầu xuất hóa đơn").check();
		await screen.getByLabel("Cá nhân").check();
		await screen.getByLabel("Địa chỉ xuất hóa đơn").fill("1 Đường Thử Nghiệm");
		await screen.getByLabel("Số CCCD").fill("12345");
		await placeOrder(screen).tap();
		await expect(browser.locator("#einv_id_number_error")).toContainText("Số CCCD phải gồm đúng 12 chữ số.");
		await expect(browser).toHaveURL(/classic-checkout/);
	});

	test("a ticked request with invalid data never reaches the server", async ({ app, screen, browser }) => {
		await fillClassicBilling({ app, screen });
		await screen.getByLabel("Yêu cầu xuất hóa đơn").check();
		await screen.getByLabel("Doanh nghiệp").check();
		const seen: string[] = [];
		await browser.route("**/?wc-ajax=checkout", async (route: any) => {
			seen.push(route.request().url());
			await route.continue();
		});
		await placeOrder(screen).tap();
		await expect(browser.locator("#einv_tax_code_error")).toContainText("Mã số thuế");
		expect(seen.length).toBe(0);
	});

	test("without the checkbox the order is a walk-in buyer even with billing data", async ({ app, screen, browser }) => {
		await fillClassicBilling({ app, screen });
		await placeOrder(screen).tap();
		await expect(browser).toHaveURL(/order-received/);
		const orderId = await orderIdFromUrl(browser);
		await seam({ app }, `/status/${orderId}`, { status: "completed" });
		await waitForStatus({ app }, orderId, "draft_created");
		expect((await fake("requests?endpoint=create-invoice"))[0].body[0].NMua_Ten).toBe("Khách hàng không lấy hóa đơn");
	});

	test("a valid business request is saved and sent with its tax code and address", async ({ app, screen, browser }) => {
		await fillClassicBilling({ app, screen });
		await screen.getByLabel("Yêu cầu xuất hóa đơn").check();
		await screen.getByLabel("Doanh nghiệp").check();
		await screen.getByLabel("Tên doanh nghiệp").fill("CÔNG TY TNHH THỬ NGHIỆM E2E");
		await screen.getByLabel("Mã số thuế").fill("0000000000");
		await screen.getByLabel("Địa chỉ xuất hóa đơn").fill("2 Đường Thử Nghiệm, Quận 3, TP HCM");
		await placeOrder(screen).tap();
		await expect(browser).toHaveURL(/order-received/);
		const orderId = await orderIdFromUrl(browser);
		await seam({ app }, `/status/${orderId}`, { status: "completed" });
		await waitForStatus({ app }, orderId, "draft_created");
		const body = (await fake("requests?endpoint=create-invoice"))[0].body[0];
		expect(body.NMua_Ten).toBe("CÔNG TY TNHH THỬ NGHIỆM E2E");
		expect(body.NMua_MST).toBe("0000000000");
		expect(body.NMua_DChi).toBe("2 Đường Thử Nghiệm, Quận 3, TP HCM");
	});

	test("a valid individual request sends the CCCD to Mat Bao but never stores it in the clear", async ({ app, screen, browser }) => {
		await fillClassicBilling({ app, screen });
		await screen.getByLabel("Yêu cầu xuất hóa đơn").check();
		await screen.getByLabel("Cá nhân").check();
		await screen.getByLabel("Địa chỉ xuất hóa đơn").fill("1 Đường Thử Nghiệm, Quận 1, TP HCM");
		await screen.getByLabel("Số CCCD").fill("000000000042");
		await placeOrder(screen).tap();
		await expect(browser).toHaveURL(/order-received/);
		const orderId = await orderIdFromUrl(browser);
		await seam({ app }, `/status/${orderId}`, { status: "completed" });
		await waitForStatus({ app }, orderId, "draft_created");
		const body = (await fake("requests?endpoint=create-invoice"))[0].body[0];
		expect(body.NMua_CCCDan).toBe("000000000042");
		const meta = await seam({ app }, `/order-meta/${orderId}`);
		expect(JSON.stringify(meta)).not.toContain("000000000042");
		expect(JSON.stringify(meta)).toContain("********0042");
	});
});

describe("server-side validation", { serial: false }, () => {
	beforeEach(async (ctx) => {
		await resetWorld(ctx);
	});

	/**
	 * Calls the Store API checkout route from the browser with a real cart session, the way a script that skips the
	 * page's JavaScript would. Returns the HTTP status and the JSON body.
	 */
	async function storeApiCheckout({ app, browser }: any, extension: Record<string, unknown>) {
		await app.open(`/?add-to-cart=${process.env.E2E_PRODUCT_ID}`);
		await app.open("/?pagename=checkout");
		return browser.evaluate(async (ext: Record<string, unknown>) => {
			const cart = await fetch("/wp-json/wc/store/v1/cart", { credentials: "same-origin" });
			const nonce = cart.headers.get("Nonce") ?? cart.headers.get("X-WC-Store-API-Nonce") ?? "";
			const response = await fetch("/wp-json/wc/store/v1/checkout", {
				method: "POST",
				credentials: "same-origin",
				headers: { "Content-Type": "application/json", Nonce: nonce, "X-WC-Store-API-Nonce": nonce },
				body: JSON.stringify({
					billing_address: { first_name: "Nguyễn", last_name: "Văn Thử", address_1: "3 Đường Thử Nghiệm", city: "Hồ Chí Minh", state: "", postcode: "700000", country: "VN", email: "e2e-buyer@example.invalid", phone: "0912345678" },
					payment_method: "cod",
					extensions: { "einvoice-matbao": ext },
				}),
			});
			return { status: response.status, body: await response.text() };
		}, extension);
	}

	test("the Store API refuses a business request with a bad tax code (HTTP 400, no order)", async (ctx) => {
		const result = await storeApiCheckout(ctx, { request_invoice: true, buyer_type: "business", business_name: "Công ty Thử", tax_code: "12", address: "1 Đường Thử" });
		expect(result.status).toBe(400);
		const error = JSON.parse(result.body);
		expect(error.code).toBe("einvoice_buyer_invalid");
		expect(error.message).toContain("Mã số thuế phải gồm 10 hoặc 13 chữ số");
	});

	test("the Store API refuses an invalid buyer type and a non-boolean request flag", async (ctx) => {
		const type = await storeApiCheckout(ctx, { request_invoice: true, buyer_type: "alien" });
		expect(type.status).toBe(400);
		const flag = await storeApiCheckout(ctx, { request_invoice: "true", buyer_type: "business" });
		expect(flag.status).toBe(400);
	});

	test("the Store API accepts a complete business request and does not echo it back", async (ctx) => {
		const result = await storeApiCheckout(ctx, { request_invoice: true, buyer_type: "business", business_name: "Công ty Thử", tax_code: "0000000000", address: "1 Đường Thử, Quận 1" });
		expect(result.status).toBe(200);
		expect(result.body).not.toContain("0000000000");
	});
});
