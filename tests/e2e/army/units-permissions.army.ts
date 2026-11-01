import { test, describe, beforeEach } from "@e2e-dev/web";
import { expect } from "e2e";
import { PAGES, completedOrder, fake, resetWorld, seam, signInAdmin, signInAs, waitForStatus } from "./support.ts";

// Stories 32, 34: the unit of measure (store default, product override) and who may touch the plugin.

describe("unit of measure", { serial: false }, () => {
	beforeEach(async (ctx) => {
		await resetWorld(ctx);
	});

	test("the store default unit is sent for products without an override", async ({ app }) => {
		await seam({ app }, "/settings", { unit: "Lượt" });
		const orderId = await completedOrder({ app });
		await waitForStatus({ app }, orderId, "draft_created");
		const line = (await fake("requests?endpoint=create-invoice"))[0].body[0].DSHHDVu[0];
		expect(line.DVTinh).toBe("Lượt");
	});
});

describe("product unit field", { serial: false }, () => {
	beforeEach(async (ctx) => {
		await resetWorld(ctx);
		await signInAdmin(ctx);
	});

	test("the override saved on the product edit screen is sent on the invoice line", async ({ app, screen, browser }) => {
		const productId = (await seam({ app }, "/product-id/E2E-VAT5")).id as number;
		await app.open(`/wp-admin/post.php?post=${productId}&action=edit`);
		await screen.getByLabel("Đơn vị tính hóa đơn").fill("Tháng");
		await browser.locator("#publish").tap();
		await expect(screen.getByLabel("Đơn vị tính hóa đơn")).toHaveValue("Tháng");

		const orderId = await completedOrder({ app }, { lines: [{ sku: "E2E-VAT5", qty: 1 }] });
		await waitForStatus({ app }, orderId, "draft_created");
		const line = (await fake("requests?endpoint=create-invoice"))[0].body[0].DSHHDVu[0];
		expect(line.DVTinh).toBe("Tháng");
	});

	test("the unit input caps its length in the browser and the server refuses a longer value that bypasses it", async ({ app, screen, browser }) => {
		const productId = (await seam({ app }, "/product-id/E2E-VAT8")).id as number;
		await app.open(`/wp-admin/post.php?post=${productId}&action=edit`);
		await screen.getByLabel("Đơn vị tính hóa đơn").fill("Tháng");
		await browser.locator("#publish").tap();
		await expect(screen.getByLabel("Đơn vị tính hóa đơn")).toHaveValue("Tháng");
		await expect(browser.locator("#einvoice_matbao_unit")).toHaveAttribute("maxlength", "50");

		// A script that removes the browser limit still cannot store a longer unit: the old value stays.
		await browser.evaluate(() => document.querySelector("#einvoice_matbao_unit")?.removeAttribute("maxlength"));
		await screen.getByLabel("Đơn vị tính hóa đơn").fill("x".repeat(60));
		await browser.locator("#publish").tap();
		await expect(screen.getByText("Đơn vị tính hóa đơn tối đa 50 ký tự", { exact: false }).first()).toBeVisible();
		await expect(screen.getByLabel("Đơn vị tính hóa đơn")).toHaveValue("Tháng");
	});
});

describe("permissions", { serial: false }, () => {
	beforeEach(async (ctx) => {
		await resetWorld(ctx);
	});

	test("a shop manager cannot open the settings or the invoice list", async (ctx) => {
		await signInAs(ctx, "shop_manager");
		for (const path of [PAGES.settings, PAGES.invoices]) {
			await ctx.app.open(path);
			await expect(ctx.screen.getByText("Sorry, you are not allowed to access this page.", { exact: false })).toBeVisible();
		}
	});

	test("a shop manager does not see the plugin menu entries", async (ctx) => {
		await signInAs(ctx, "shop_manager");
		await ctx.app.open("/wp-admin/admin.php?page=wc-admin");
		await expect(ctx.screen.getByRole("link", "Danh sách hóa đơn")).toHaveCount(0);
		await expect(ctx.screen.getByRole("link", "Hóa đơn Mắt Bão")).toHaveCount(0);
	});

	test("the plugin REST routes refuse an anonymous visitor", async ({ app }) => {
		const anonymous = await fetch(`${app.baseUrl}/wp-json/einvoice-matbao/v1/invoices`);
		expect(anonymous.status).toBe(401);
		const resend = await fetch(`${app.baseUrl}/wp-json/einvoice-matbao/v1/invoices/1/resend`, { method: "POST" });
		expect(resend.status).toBe(401);
	});

	test("the plugin REST routes refuse a shop manager even with a valid nonce", async (ctx) => {
		await signInAs(ctx, "shop_manager");
		await ctx.app.open("/wp-admin/");
		const result = await ctx.browser.evaluate(async () => {
			// Core hands a logged-in user a fresh REST nonce here; the routes then fail on capability, not on the nonce.
			const nonce = await (await fetch("/wp-admin/admin-ajax.php?action=rest-nonce", { credentials: "same-origin" })).text();
			const calls = [
				["GET", "/wp-json/einvoice-matbao/v1/invoices"],
				["POST", "/wp-json/einvoice-matbao/v1/settings/matbao/test"],
				["POST", "/wp-json/einvoice-matbao/v1/settings/uninstall"],
			];
			const out: number[] = [];
			for (const [method, url] of calls) {
				const response = await fetch(url, { method, credentials: "same-origin", headers: { "X-WP-Nonce": nonce } });
				out.push(response.status);
			}
			return { nonce: nonce !== "", statuses: out };
		});
		expect(result.nonce).toBe(true);
		expect(result.statuses).toEqual([403, 403, 403]);
	});

	test("an administrator reaches both pages", async (ctx) => {
		await signInAdmin(ctx);
		await ctx.app.open(PAGES.settings);
		await expect(ctx.screen.getByRole("heading", "Cài đặt hóa đơn Mắt Bão")).toBeVisible();
		await ctx.app.open(PAGES.invoices);
		await expect(ctx.screen.getByRole("heading", "Danh sách hóa đơn Mắt Bão")).toBeVisible();
	});
});
