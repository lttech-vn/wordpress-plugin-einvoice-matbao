import { test, describe, beforeEach } from "@e2e-dev/web";
import { expect } from "e2e";
import { PAGES, completedOrder, fake, invoiceOf, lines, resetWorld, script, seam, sentCount, signInAdmin, waitForStatus } from "./support.ts";

// Stories 11, 12, 27: changing an order after the draft only warns (the draft is never updated or recreated);
// before the draft an administrator's buyer edit re-captures the snapshot.

const sent = sentCount;

describe("order changes after the draft", { serial: false, agent: "admin" }, () => {
	beforeEach(async (ctx) => {
		await resetWorld(ctx);
		await signInAdmin(ctx);
	});

	test("changing the order total after the draft raises one warning and sends nothing", async ({ app, screen, browser }) => {
		const orderId = await completedOrder({ app });
		await waitForStatus({ app }, orderId, "draft_created");

		await seam({ app }, `/edit/${orderId}`, { total: "130000" });
		await app.open(PAGES.order(orderId));
		await expect(browser.locator("[data-einv-error-code='order_amounts_changed']")).toBeVisible();
		await expect(screen.getByText("Số tiền đơn hàng đổi sau khi có nháp", { exact: false }).first()).toBeVisible();
		await expect(screen.getByText("hệ thống chỉ cảnh báo, không tự cập nhật hay hủy hóa đơn", { exact: false }).first()).toBeVisible();

		// Status is unchanged, nothing was resent or recreated.
		const row = await invoiceOf({ app }, orderId);
		expect(row.invoice.status).toBe("draft_created");
		expect(await sent()).toBe(1);
		expect((await fake("invoices")).length).toBe(1);
	});

	test("editing the buyer after the draft warns without exposing the new values", async ({ app, screen, browser }) => {
		const orderId = await completedOrder({ app });
		await waitForStatus({ app }, orderId, "draft_created");

		await seam({ app }, `/edit/${orderId}`, { billing: { first_name: "Trần", last_name: "Thị Khác" } });
		await app.open(PAGES.order(orderId));
		await expect(browser.locator("[data-einv-error-code='order_buyer_changed']")).toBeVisible();
		// The warning (box, note, alert) names the change, never the new values.
		const box = browser.locator("#einvoice-matbao-invoice");
		await expect(box).toBeVisible();
		await expect(box).not.toContainText("Trần");
		expect(await sent()).toBe(1);
	});

	test("a partial refund after the draft raises a warning and the draft stays", async ({ app, browser }) => {
		const orderId = await completedOrder({ app });
		await waitForStatus({ app }, orderId, "draft_created");
		const refund = await seam({ app }, `/refund/${orderId}`, { amount: "10000" });
		expect(refund.ok).toBe(true);
		await seam({ app }, `/edit/${orderId}`, {}); // an ordinary save lets the watcher compare the order

		await app.open(PAGES.order(orderId));
		await expect(browser.locator("[data-einv-error-code='order_refunded_after_draft']")).toBeVisible();
		expect((await invoiceOf({ app }, orderId)).invoice.status).toBe("draft_created");
		expect(await sent()).toBe(1);
	});

	test("an administrator's buyer edit before the draft re-captures the snapshot (story 27)", async ({ app, browser }) => {
		// The order is completed but its job has not run; the admin fixes the invoice address on the order screen.
		const order = await seam({ app }, "/order", { ...lines("E2E-SVC"), request: { type: "business", business_name: "CÔNG TY SAI", tax_code: "0000000000", address: "1 Đường Sai" } });
		await seam({ app }, `/status/${order.id}`, { status: "completed", run_queue: false });
		await app.open(PAGES.order(Number(order.id)));
		// The administrator edits the billing name on the real order screen. WooCommerce's address editor is a hidden
		// panel with many identical controls, so these steps are exact.
		await browser.locator("a.edit_address").first().tap();
		await browser.locator("#_billing_first_name").fill("Chị");
		await browser.locator("#_billing_last_name").fill("Sửa");
		await browser.locator("button.save_order, input.save_order").first().tap();
		await seam({ app }, "/run-queue", {});
		await waitForStatus({ app }, Number(order.id), "draft_created");
		const body = (await fake("requests?endpoint=create-invoice"))[0].body[0];
		expect(body.NMua_Ten).toBe("CÔNG TY SAI"); // business name is the buyer; billing name is not
		expect(body.NMua_MST).toBe("0000000000");
	});

	test("a retry sends from the buyer snapshot, not from billing edited meanwhile", async ({ app }) => {
		// Mat Bao is briefly unavailable, so the invoice waits for a retry. The retry sends from the snapshot taken
		// when the order completed, not from whatever the billing fields say by then.
		await script("create-invoice", [{ http: 503 }]);
		const orderId = await completedOrder({ app }, { request: { type: "business", business_name: "CÔNG TY CŨ", tax_code: "0000000000", address: "1 Đường Cũ" } });
		expect((await invoiceOf({ app }, orderId)).invoice.status).toBe("pending");
		await seam({ app }, `/edit/${orderId}`, { billing: { first_name: "Người", last_name: "Khác" } });
		await waitForStatus({ app }, orderId, "draft_created", true);
		const bodies = (await fake("requests?endpoint=create-invoice")).map((r: any) => r.body[0]);
		expect(bodies.length).toBe(2);
		expect(bodies[1].NMua_Ten).toBe("CÔNG TY CŨ");
		expect(bodies[1].NMua_MST).toBe("0000000000");
	});

	test("an order that is refunded in full before the job runs stops with no draft", async ({ app }) => {
		const order = await seam({ app }, "/order", { lines: [{ sku: "E2E-SVC", qty: 1 }] });
		await seam({ app }, `/status/${order.id}`, { status: "completed", run_queue: false });
		await seam({ app }, `/status/${order.id}`, { status: "refunded", run_queue: false });
		await seam({ app }, "/run-queue", {});
		const row = await invoiceOf({ app }, Number(order.id));
		expect(row.invoice.status).toBe("stopped");
		expect(await sent()).toBe(0);
	});
});
