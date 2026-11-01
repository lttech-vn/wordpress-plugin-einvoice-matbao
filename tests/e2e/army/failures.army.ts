import { test, describe, beforeEach } from "@e2e-dev/web";
import { expect } from "e2e";
import { PAGES, completedOrder, fake, invoiceOf, lines, resetWorld, script, seam, sentCount, signInAdmin, waitForStatus, openOnlyInvoice } from "./support.ts";

// Stories 4-6, 8, 10, 12, 38-40: what the plugin does when a draft cannot or should not be made, and how
// temporary Mat Bao failures are retried. Orders come from a seam; Mat Bao is the fake server.
// "sent" = calls to create-invoice that reached the fake.

const sent = sentCount;
const lookups = async () => (await fake("requests?endpoint=invoice-detail-batch")).length as number;

describe("blocked drafts", { serial: false, agent: "admin" }, () => {
	beforeEach(async (ctx) => {
		await resetWorld(ctx);
		await signInAdmin(ctx);
	});

	test("an unsupported tax rate blocks the draft, names the cause and sends nothing", async ({ app, screen, browser }) => {
		const orderId = await completedOrder({ app }, lines("E2E-VAT7"));
		const row = await waitForStatus({ app }, orderId, "failed");
		expect(row.invoice.error_code).toBe("tax_rate_unsupported");
		expect(await sent()).toBe(0);

		await app.open(PAGES.order(orderId));
		await expect(browser.locator("[data-einv-error-code='tax_rate_unsupported']")).toBeVisible();
		await expect(browser.locator("[data-einv-error-kind='data']")).toBeVisible();
		await expect(screen.getByText("Thuế suất không hỗ trợ", { exact: false }).first()).toBeVisible();
	});

	test("supported rates 0/5/8/10 and not-taxable lines are sent with the right TSuat", async ({ app }) => {
		const ids: Record<string, number> = {};
		for (const sku of ["E2E-SVC", "E2E-VAT5", "E2E-VAT8", "E2E-NOTAX"]) ids[sku] = await completedOrder({ app }, lines(sku));
		for (const id of Object.values(ids)) await waitForStatus({ app }, id, "draft_created");

		const bodies = (await fake("requests?endpoint=create-invoice")).map((r: any) => r.body[0]);
		const rateOf = (sku: string) => bodies.find((b: any) => b.DSHHDVu[0].MHHDVu === sku).DSHHDVu[0].TSuat;
		expect(rateOf("E2E-SVC")).toBe(10);
		expect(rateOf("E2E-VAT5")).toBe(5);
		expect(rateOf("E2E-VAT8")).toBe(8);
		expect(rateOf("E2E-NOTAX")).toBe(-1); // KCT
	});

	test("totals that do not match the order total block the draft", async ({ app }) => {
		const order = await seam({ app }, "/order", { ...lines("E2E-SVC"), total: "999999" });
		await seam({ app }, `/status/${order.id}`, { status: "completed" });
		const row = await waitForStatus({ app }, Number(order.id), "failed");
		expect(row.invoice.error_code).toBe("totals_mismatch");
		expect(await sent()).toBe(0);
	});

	test("a fee line is sent as its own line of quantity 1 and the totals still match", async ({ app }) => {
		const orderId = await completedOrder({ app }, { fees: [{ name: "Phí xử lý E2E", amount: "50000" }] });
		await waitForStatus({ app }, orderId, "draft_created");

		const body = (await fake("requests?endpoint=create-invoice"))[0].body[0];
		expect(body.DSHHDVu.length).toBe(2);
		const fee = body.DSHHDVu.find((l: any) => l.THHDVu === "Phí xử lý E2E");
		expect(fee).toBeDefined();
		expect(fee.SLuong).toBe(1);
		expect(fee.ThTien).toBe(50000);
		expect(fee.TSuat).toBe(10);
		expect(fee.DVTinh).toBe("Gói"); // the store default unit
		const sum = (key: string) => body.DSHHDVu.reduce((n: number, l: any) => n + l[key], 0);
		expect(body.TgThTien).toBe(sum("ThTien"));
		expect(body.TgTThue).toBe(sum("TThue"));
		expect(sum("TgTien")).toBe(165000); // 100000 + 50000 plus 10 % VAT
	});

	test("a 0 VND order is skipped with a reason and has no resend button", async ({ app, screen, browser }) => {
		const orderId = await completedOrder({ app }, lines("E2E-FREE"));
		await waitForStatus({ app }, orderId, "skipped");
		expect(await sent()).toBe(0);

		await app.open(PAGES.invoices);
		await openOnlyInvoice(screen);
		const detail = browser.locator(`[data-einv-invoice-detail]`);
		await expect(detail).toBeVisible();
		await expect(detail).toContainText("Bỏ qua");
		await expect(detail).toContainText("Đơn hàng 0 đồng");
		await expect(screen.getByRole("button", "Gửi lại")).toHaveCount(0);
	});

	test("a default template Mat Bao no longer offers blocks the draft as a configuration error", async ({ app }) => {
		await fake("config", { templates: [{ thDon: "Hóa đơn giá trị gia tăng", khmshDon: "1", khhDon: "C99TAT", sLuong: 10, cLai: 5 }] });
		await seam({ app }, "/settings", { prefix: "E2E" }); // clears the templates cache
		const orderId = await completedOrder({ app });
		const row = await waitForStatus({ app }, orderId, "not_configured");
		expect(row.invoice.error_code).toBe("template_unavailable");
		expect(row.invoice.error_kind).toBe("config");
		expect(await sent()).toBe(0);
	});

	test("an order that leaves completed before the job runs stops the draft", async ({ app }) => {
		const order = await seam({ app }, "/order", lines("E2E-SVC"));
		// Complete without running the queue, then take the order away again before the job runs.
		await seam({ app }, `/status/${order.id}`, { status: "completed", run_queue: false });
		await seam({ app }, `/status/${order.id}`, { status: "cancelled", run_queue: false });
		await seam({ app }, "/run-queue", {});
		const row = await invoiceOf({ app }, Number(order.id));
		expect(row.invoice.status).toBe("stopped");
		expect(row.invoice.error_code).toBe("order_not_completed");
		expect(await sent()).toBe(0);
	});
});

describe("retry and recovery", { serial: false, agent: "admin" }, () => {
	beforeEach(async (ctx) => {
		await resetWorld(ctx);
		await signInAdmin(ctx);
	});

	test("a temporary 503 keeps the invoice waiting and a later retry creates it once", async ({ app, screen }) => {
		await script("create-invoice", [{ http: 503 }]);
		const orderId = await completedOrder({ app });
		const waiting = await invoiceOf({ app }, orderId);
		expect(waiting.invoice.status).toBe("pending");
		expect(waiting.invoice.error_code).toBe("matbao_http_temporary");
		// No alert while a retry waits: the notice lists only invoices an admin must act on.
		await app.open(PAGES.settings);
		await expect(screen.getByText("Có hóa đơn điện tử cần xử lý:", { exact: false })).toHaveCount(0);

		await waitForStatus({ app }, orderId, "draft_created", true);
		expect((await fake("invoices")).length).toBe(1);
		expect(await sent()).toBe(2);
	});

	test("a lost reply is recovered by looking the reference up before sending again", async ({ app }) => {
		// Mat Bao stored the draft but the answer was a 503: the retry must adopt it, not create a second one.
		await script("create-invoice", [{ http: 503, store: true }]);
		const orderId = await completedOrder({ app });
		expect((await fake("invoices")).length).toBe(1);
		await waitForStatus({ app }, orderId, "draft_created", true);

		expect((await fake("invoices")).length).toBe(1);
		expect(await sent()).toBe(1); // adopted through the lookup, no second create call
		expect(await lookups()).toBeGreaterThan(0);
	});

	test("an unreadable 2xx reply is treated as unknown and reconciled by lookup", async ({ app }) => {
		await script("create-invoice", [{ unreadable: true, store: true }]);
		const orderId = await completedOrder({ app });
		await waitForStatus({ app }, orderId, "draft_created", true);
		expect((await fake("invoices")).length).toBe(1);
	});

	test("duplicate reference 305 triggers a lookup and the same MTChieu, never a new one", async ({ app }) => {
		await script("create-invoice", [{ item_error: { errorCode: 305, message: "Trùng mã tham chiếu" }, store: true }]);
		const orderId = await completedOrder({ app });
		await waitForStatus({ app }, orderId, "draft_created", true);

		const refs = new Set((await fake("requests?endpoint=create-invoice")).map((r: any) => r.body[0].MTChieu));
		expect(refs.size).toBe(1);
		expect((await fake("invoices")).length).toBe(1);
	});

	test("five retries are made (delays are made due by a seam), then the invoice needs attention and shows an admin notice", async ({ app, screen }) => {
		await script("create-invoice", Array.from({ length: 8 }, () => ({ http: 503 })));
		const orderId = await completedOrder({ app });
		const row = await waitForStatus({ app }, orderId, "needs_attention", true);
		expect(row.invoice.error_code).toBe("matbao_http_temporary");
		expect(await sent()).toBe(6); // the first send and five retries

		// Nothing is queued any more and a further tick sends nothing.
		await seam({ app }, "/tick", { rounds: 2 });
		expect(await sent()).toBe(6);

		await app.open(PAGES.invoices);
		await expect(screen.getByText("Có hóa đơn điện tử cần xử lý:", { exact: false })).toBeVisible();
		await expect(screen.getByText(`#${orderId}`, { exact: false }).first()).toBeVisible();
	});

	test("the five retries are scheduled 1 min, 5 min, 15 min, 1 h and 6 h apart", async ({ app }) => {
		await script("create-invoice", Array.from({ length: 8 }, () => ({ http: 503 })));
		const orderId = await completedOrder({ app });
		const delays = [60, 300, 900, 3600, 21600];
		for (const [i, delay] of delays.entries()) {
			// Read the schedule first: it was queued during the failed send a moment ago, so the remaining wait is
			// the delay minus that moment (a slow runner only widens the gap, hence the small window).
			const next = await seam({ app }, "/next-retry");
			expect(next.at, `retry ${i + 1} is queued`).not.toBeNull();
			expect(next.at - next.now).toBeGreaterThan(delay - 15);
			expect(next.at - next.now).toBeLessThanOrEqual(delay);
			expect((await invoiceOf({ app }, orderId)).invoice.status).toBe("pending");
			await seam({ app }, "/tick", { rounds: 1 }); // makes the retry due and runs it
			expect(await sent()).toBe(i + 2);
		}
		expect((await invoiceOf({ app }, orderId)).invoice.status).toBe("needs_attention");
		expect((await seam({ app }, "/next-retry")).at).toBeNull();
	});

	test("a data error (HTTP 400) is final: no retry is queued", async ({ app }) => {
		await script("create-invoice", [{ http: 400 }]);
		const orderId = await completedOrder({ app });
		await waitForStatus({ app }, orderId, "failed");
		await seam({ app }, "/tick", { rounds: 2 });
		expect(await sent()).toBe(1);
		expect((await seam({ app }, "/queued")).drafts).toBe(0);
	});

	test("a rejected invoice (Mat Bao says success false) is final and alerts the admin", async ({ app, screen }) => {
		await script("create-invoice", [{ item_error: { errorCode: 202, message: "Dữ liệu không hợp lệ" } }]);
		const orderId = await completedOrder({ app });
		const row = await waitForStatus({ app }, orderId, "failed");
		expect(row.invoice.error_code).toBe("matbao_api");
		await app.open(PAGES.invoices);
		await expect(screen.getByText("Có hóa đơn điện tử cần xử lý:", { exact: false })).toBeVisible();
	});
});
