import { test, describe, beforeEach } from "@e2e-dev/web";
import { expect } from "e2e";
import { PAGES, completedOrder, errorNotice, fake, okNotice, resetWorld, script, seam, signInAdmin, waitForStatus, openOnlyInvoice } from "./support.ts";

// Stories 1, 3, 20-26: the invoice list, filters, search, detail with attempt history, status check, resend,
// the dashboard counts and the order meta box. Mat Bao is the fake server; orders come from a seam.

describe("invoice admin", { serial: false, agent: "admin" }, () => {
	beforeEach(async (ctx) => {
		await resetWorld(ctx);
		await signInAdmin(ctx);
	});

	test("a completed order gets one draft shown in the order box, the list and the detail", async ({ app, screen }) => {
		const orderId = await completedOrder({ app });
		await waitForStatus({ app }, orderId, "draft_created");

		await app.open(PAGES.order(orderId));
		await expect(screen.getByText("Đã tạo nháp", { exact: false }).first()).toBeVisible();
		await expect(screen.getByText(`E2EWC${orderId}`, { exact: false }).first()).toBeVisible();

		await app.open(PAGES.invoices);
		const row = screen.getByRole("row").filter({ hasText: `#${orderId}` });
		await expect(row).toBeVisible();
		await expect(row).toContainText("Đã tạo nháp");
		await openOnlyInvoice(screen);
		await expect(screen.getByText("Lịch sử gửi")).toBeVisible();
		await expect(screen.getByText("Thành công", { exact: true }).first()).toBeVisible();
	});

	test("completing the same order again never makes a second invoice", async ({ app }) => {
		const orderId = await completedOrder({ app });
		await waitForStatus({ app }, orderId, "draft_created");
		await seam({ app }, `/status/${orderId}`, { status: "processing" });
		await seam({ app }, `/status/${orderId}`, { status: "completed" });
		await seam({ app }, "/run-queue", {});

		expect((await fake("invoices")).length).toBe(1);
		expect((await fake("requests?endpoint=create-invoice")).length).toBe(1);
	});

	test("the list filters by status and searches by order ID and by MTChieu prefix", async ({ app, screen, browser }) => {
		const good = await completedOrder({ app });
		await script("create-invoice", [{ http: 400 }]);
		const bad = await completedOrder({ app });
		await waitForStatus({ app }, good, "draft_created");
		await waitForStatus({ app }, bad, "failed");

		await app.open(PAGES.invoices);
		await expect(screen.getByRole("row").filter({ hasText: `#${good}` })).toBeVisible();
		await expect(screen.getByRole("row").filter({ hasText: `#${bad}` })).toBeVisible();

		// Exact: "Lỗi / cần xử lý" is both a status option and a dashboard card, so the select is pinned by its label.
		await screen.getByLabel("Trạng thái").selectOption({ label: "Lỗi / cần xử lý" });
		await expect(browser.locator("[data-einv-total]")).toHaveAttribute("data-einv-total", "1"); // the reload finished
		await expect(screen.getByRole("row").filter({ hasText: `#${bad}` })).toBeVisible();
		await expect(screen.getByRole("row").filter({ hasText: `#${good}` })).toHaveCount(0);

		await screen.getByLabel("Trạng thái").selectOption({ label: "Tất cả trạng thái" });
		await screen.getByRole("searchbox").fill(String(good));
		await screen.getByRole("searchbox").press("Enter");
		await expect(browser.locator("[data-einv-total]")).toHaveAttribute("data-einv-total", "1");
		await expect(screen.getByRole("row").filter({ hasText: `#${good}` })).toBeVisible();
		await expect(screen.getByRole("row").filter({ hasText: `#${bad}` })).toHaveCount(0);

		await screen.getByRole("searchbox").fill("E2EWC");
		await screen.getByRole("searchbox").press("Enter");
		await expect(browser.locator("[data-einv-total]")).toHaveAttribute("data-einv-total", "2");
		await expect(screen.getByRole("row").filter({ hasText: `#${bad}` })).toBeVisible();
		await expect(screen.getByRole("row").filter({ hasText: `#${good}` })).toBeVisible();

		await screen.getByRole("searchbox").fill("WC");
		await screen.getByRole("searchbox").press("Enter");
		await expect(screen.getByText("Không có hóa đơn nào khớp.")).toBeVisible(); // prefix match, not substring
	});

	test("the WooCommerce order list filters orders by invoice status (story 21, HPOS list)", async ({ app, browser }) => {
		await script("create-invoice", [{ http: 400 }]);
		const bad = await completedOrder({ app });
		const good = await completedOrder({ app });
		await waitForStatus({ app }, bad, "failed");
		await waitForStatus({ app }, good, "draft_created");

		await app.open("/wp-admin/admin.php?page=wc-orders&einv_invoice=error");
		await expect(browser.locator("select[name='einv_invoice']")).toBeVisible(); // the list screen has rendered
		await expect(browser.locator(`#order-${bad}`)).toBeVisible();
		await expect(browser.locator(`#order-${good}`)).toHaveCount(0);
		await app.open("/wp-admin/admin.php?page=wc-orders&einv_invoice=");
		await expect(browser.locator(`#order-${good}`)).toBeVisible();
	});

	test("the order screen offers a resend for a failed invoice (story 20)", async ({ app, screen }) => {
		await script("create-invoice", [{ http: 400 }]);
		const orderId = await completedOrder({ app });
		await waitForStatus({ app }, orderId, "failed");
		await app.open(PAGES.order(orderId));
		await expect(screen.getByText("Lỗi", { exact: false }).first()).toBeVisible();
		await expect(screen.getByRole("link", "Danh sách hóa đơn")).toBeVisible();
	});

	test("the dashboard counts errors, waiting and created drafts and links to the filtered list", async ({ app, screen, browser }) => {
		await script("create-invoice", [{ http: 400 }]);
		const bad = await completedOrder({ app });
		const good = await completedOrder({ app });
		await waitForStatus({ app }, bad, "failed");
		await waitForStatus({ app }, good, "draft_created");

		await app.open(PAGES.invoices);
		const errors = browser.locator("[data-einv-count='error']");
		await expect(errors).toHaveText("1");
		await expect(browser.locator("[data-einv-count='created']")).toHaveText("1");
		await expect(browser.locator("[data-einv-count='waiting']")).toHaveText("0");

		await browser.locator("[data-einv-metric='error']").tap();
		await expect(screen.getByRole("row").filter({ hasText: `#${bad}` })).toBeVisible();
		await expect(screen.getByRole("row").filter({ hasText: `#${good}` })).toHaveCount(0);
	});

	test("the detail masks national ID numbers in the attempt history", async ({ app, screen }) => {
		await script("create-invoice", [{ item_error: { errorCode: 201, message: "CCCD 012345678901 khong hop le" } }]);
		const orderId = await completedOrder({ app });
		await waitForStatus({ app }, orderId, "failed");

		await app.open(PAGES.invoices);
		await openOnlyInvoice(screen);
		await expect(screen.getByText("Lịch sử gửi")).toBeVisible();
		await expect(screen.getByText("012345678901", { exact: false })).toHaveCount(0);
		await expect(screen.getByText("********8901", { exact: false }).first()).toBeVisible();
	});

	test("check status confirms a draft that is still on Mat Bao", async ({ app, screen }) => {
		const orderId = await completedOrder({ app });
		await waitForStatus({ app }, orderId, "draft_created");
		await app.open(PAGES.invoices);
		await openOnlyInvoice(screen);
		await screen.getByRole("button", "Kiểm tra trạng thái").tap();
		await expect(okNotice(screen)).toContainText("Hóa đơn nháp vẫn còn trên Mắt Bão.");
	});

	test("check status warns when the draft was issued on Mat Bao and never changes the row", async ({ app, screen }) => {
		const orderId = await completedOrder({ app });
		const done = await waitForStatus({ app }, orderId, "draft_created");
		await fake("issue", { mtChieu: done.invoice.reference });
		await app.open(PAGES.invoices);
		await openOnlyInvoice(screen);
		await screen.getByRole("button", "Kiểm tra trạng thái").tap(); // exact: a red warning is the expected result
		await expect(errorNotice(screen)).toContainText("không còn ở trạng thái Nháp");
		expect((await seam({ app }, `/invoice/${orderId}`)).invoice.status).toBe("draft_created");
	});

	test("a deleted draft is never recreated without an explicit confirmation", async ({ app, screen, browser }) => {
		const orderId = await completedOrder({ app });
		const done = await waitForStatus({ app }, orderId, "draft_created");
		await fake("delete", { mtChieu: done.invoice.reference });

		await app.open(PAGES.invoices);
		await openOnlyInvoice(screen);
		await screen.getByRole("button", "Kiểm tra trạng thái").tap(); // exact: a red warning is the expected result
		await expect(screen.getByText("Cảnh báo: hóa đơn nháp không còn trên Mắt Bão", { exact: false })).toBeVisible();
		// The status check on its own made no create call.
		expect((await fake("requests?endpoint=create-invoice")).length).toBe(1);

		const off = await browser.onDialog("accept");
		// The lost-draft form resends only after the explicit confirmation is ticked; without it nothing is queued.
		const lost = browser.locator("form:has(input[name='confirm_lost'])");
		await lost.getByRole("button", "Gửi lại").tap();
		await expect(screen.getByText("chỉ khi nháp đã bị xóa trên Mắt Bão mới được gửi lại", { exact: false })).toBeVisible();
		expect((await fake("requests?endpoint=create-invoice")).length).toBe(1);

		await screen.getByLabel("Tôi xác nhận nháp đã bị xóa và muốn tạo lại.").check();
		await lost.getByRole("button", "Gửi lại").tap();
		await expect(okNotice(screen)).toContainText("Đã xếp hóa đơn vào hàng đợi gửi lại");
		await off();
		await waitForStatus({ app }, orderId, "draft_created");
		expect((await fake("invoices")).length).toBe(1);
	});

	test("resend of a failed invoice looks the reference up first and never duplicates", async ({ app, screen, browser }) => {
		// The create call reached Mat Bao but its reply was a 4xx the plugin treats as final.
		await script("create-invoice", [{ http: 400, store: true }]);
		const orderId = await completedOrder({ app });
		await waitForStatus({ app }, orderId, "failed");
		expect((await fake("invoices")).length).toBe(1);

		await app.open(PAGES.invoices);
		await openOnlyInvoice(screen);
		const off = await browser.onDialog("accept");
		await screen.getByRole("button", "Gửi lại").tap();
		await expect(okNotice(screen)).toContainText("Đã xếp hóa đơn vào hàng đợi gửi lại");
		await off();
		await waitForStatus({ app }, orderId, "draft_created");
		// The lookup adopted the stored draft: still one invoice on Mat Bao, still one create call.
		expect((await fake("invoices")).length).toBe(1);
		expect((await fake("requests?endpoint=create-invoice")).length).toBe(1);
		expect((await fake("requests?endpoint=invoice-detail-batch")).length).toBeGreaterThan(0);
	});
});
