import { test, describe, beforeEach } from "@e2e-dev/web";
import { expect } from "e2e";
import { PAGES, completedOrder, fake, invoiceOf, resetWorld, seam, signInCustomer, waitForStatus } from "./support.ts";

// Customer invoice view: the My Account order page shows the invoice status to the owner and serves the PDF
// of an issued invoice. No agent steps, so replay needs no model cache.

describe("customer invoice view", { serial: false }, () => {
	beforeEach(async (ctx) => {
		await resetWorld(ctx);
	});

	test("customer sees the draft as being issued, then downloads the PDF once issued", async (ctx) => {
		const { app, screen, browser } = ctx;
		const customer = await signInCustomer(ctx);
		const orderId = await completedOrder({ app }, { customer_id: customer.id });
		await waitForStatus({ app }, orderId, "draft_created");

		await app.open(PAGES.viewOrder(orderId));
		await expect(screen.getByText("Hóa đơn điện tử", { exact: false }).first()).toBeVisible();
		await expect(screen.getByText("Đang xuất hóa đơn", { exact: false }).first()).toBeVisible();
		await expect(browser.locator("a[href*='einvoice-matbao-pdf']")).toHaveCount(0);

		await fake("issue", { mtChieu: `E2EWC${orderId}` });
		await seam({ app }, "/status-sync", {});
		let row: any;
		for (let i = 0; i < 20; i++) {
			row = await invoiceOf({ app }, orderId);
			if (row.invoice?.matbao_status === 4) break;
			await new Promise((r) => setTimeout(r, 250));
		}
		expect(row.invoice?.matbao_status).toBe(4);

		await app.open(PAGES.viewOrder(orderId));
		await expect(screen.getByText("Đã xuất hóa đơn", { exact: false }).first()).toBeVisible();
		const button = browser.locator("a[href*='einvoice-matbao-pdf']");
		await expect(button).toBeVisible();

		// Limitation: this fetch carries the browser cookies and the nonce of the link, but it does not
		// exercise the browser download UI (save dialog, file name handling in the browser).
		const href = await button.getAttribute("href");
		const result = await browser.evaluate(async (url: string) => {
			const response = await fetch(url, { credentials: "same-origin" });
			const bytes = new Uint8Array(await response.arrayBuffer());
			return { status: response.status, type: response.headers.get("content-type") ?? "", head: String.fromCharCode(...bytes.slice(0, 5)) };
		}, href);
		expect(result.status).toBe(200);
		expect(result.type).toContain("application/pdf");
		expect(result.head).toBe("%PDF-");
		expect((await fake("requests?endpoint=download-invoice")).length).toBe(1);
	});

	test("another customer cannot see the invoice", async (ctx) => {
		const { app, screen, browser } = ctx;
		const owner = await seam({ app }, "/user", { role: "customer" });
		const orderId = await completedOrder({ app }, { customer_id: owner.id });
		await waitForStatus({ app }, orderId, "draft_created");

		await signInCustomer(ctx);
		await app.open(PAGES.viewOrder(orderId));
		await expect(browser.locator("body")).not.toContainText("Hóa đơn điện tử");
		await expect(browser.locator("a[href*='einvoice-matbao-pdf']")).toHaveCount(0);
	});
});
