import { test, describe, beforeEach } from "@e2e-dev/web";
import { expect } from "e2e";
import { PAGES, completedOrder, openOnlyInvoice, resetWorld, signInAdmin, waitForStatus } from "./support.ts";

// The tabbed plugin screen (Hóa đơn Mắt Bão): default tab, htmx tab switching with history, deep links and the
// legacy invoice list URL. Exact steps only: tab links are located by their data attribute, because role/name
// lookups are substring matches ("Hóa đơn" also hits "Xuất hóa đơn" and the sidebar).

const SCREEN = "/wp-admin/admin.php?page=einvoice-matbao-settings";
const tabLink = (browser: any, key: string) => browser.locator(`[data-einv-tab-link="${key}"]`);

describe("plugin screen tabs", { serial: false, agent: "admin" }, () => {
	beforeEach(async (ctx) => {
		await resetWorld(ctx);
		await signInAdmin(ctx);
	});

	test("the invoices tab is the default", async ({ app, browser }) => {
		await app.open(SCREEN);
		await expect(tabLink(browser, "invoices")).toHaveAttribute("aria-current", "page");
		await expect(browser.locator("[data-einv-total]")).toBeVisible();
	});

	test("the notifications tab sits between Invoicing and Advanced", async ({ app, browser }) => {
		await app.open(SCREEN);
		const keys = await browser.evaluate(() => Array.from(document.querySelectorAll("[data-einv-tab-link]")).map((a) => a.getAttribute("data-einv-tab-link")));
		expect(keys).toEqual(["invoices", "connection", "invoicing", "notifications", "advanced"]);
		await expect(tabLink(browser, "notifications")).toHaveText("Thông báo");
	});

	test("switching tabs does not reload and updates the URL", async ({ app, screen, browser }) => {
		await app.open(SCREEN);
		await browser.evaluate(() => {
			(window as any).__einvMarker = 1;
		});
		await tabLink(browser, "notifications").tap();
		await expect(screen.getByLabel("Webhook URL")).toBeVisible();
		await expect(browser).toHaveURL(/tab=notifications/);
		expect(await browser.evaluate(() => (window as any).__einvMarker)).toBe(1);
	});

	test("legacy lark and monitoring URLs open the notifications tab", async ({ app, screen, browser }) => {
		for (const url of [PAGES.legacyLark, PAGES.legacyMonitoring]) {
			await app.open(url);
			await expect(tabLink(browser, "notifications")).toHaveAttribute("aria-current", "page");
			await expect(screen.getByLabel("Webhook URL")).toBeVisible();
		}
	});

	test("switching to the connection tab loads the template list", async ({ app, screen, browser }) => {
		await app.open(SCREEN);
		await tabLink(browser, "connection").tap();
		await expect(screen.getByLabel("Mẫu số / ký hiệu")).toBeVisible();
	});

	test("reload keeps the tab", async ({ app, screen, browser }) => {
		await app.open(SCREEN);
		await tabLink(browser, "invoicing").tap();
		await expect(browser.locator('[data-einv-tab="invoicing"]')).toBeVisible();
		await app.open(await browser.url());
		await expect(screen.getByLabel("Đơn vị tính mặc định (DVTinh)")).toBeVisible();
	});

	test("back returns to the previous tab", async ({ app, browser }) => {
		await app.open(SCREEN);
		await tabLink(browser, "connection").tap();
		await expect(browser.locator('[data-einv-tab="connection"]')).toBeVisible();
		await browser.evaluate(() => history.back());
		await expect(browser.locator('[data-einv-tab="invoices"]')).toBeVisible();
		expect(await browser.url()).not.toContain("tab=connection");
	});

	test("the old invoice list URL lands on the invoices tab", async ({ app, browser }) => {
		await app.open(PAGES.oldInvoices);
		await expect(browser).toHaveURL(/tab=invoices/);
		await expect(browser.locator('[data-einv-tab="invoices"]')).toBeVisible();
	});

	test("detail stays inside the invoices tab", async ({ app, screen, browser }) => {
		const orderId = await completedOrder({ app });
		await waitForStatus({ app }, orderId, "draft_created");
		await app.open(PAGES.invoices);
		await openOnlyInvoice(screen);
		await expect(screen.getByText("Lịch sử gửi")).toBeVisible();
		await expect(tabLink(browser, "invoices")).toHaveAttribute("aria-current", "page");
	});
});
