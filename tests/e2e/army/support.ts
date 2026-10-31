// Shared helpers for the army suite: E2E-only REST seams of the mu-plugin (wordpress/einv-e2e-http.php),
// the fake Mat Bao control plane (fake-matbao/router.php) and the admin sign-in. All data is synthetic.
import { credentials, expect } from "e2e";

type Json = Record<string, unknown>;

/** Anything with the fixtures a helper needs. */
export interface Ctx {
	app: { baseUrl?: string; open(path?: string): Promise<void> };
	screen: any;
	browser: any;
}

const base = (ctx: Pick<Ctx, "app">): string => ctx.app.baseUrl ?? "http://localhost";

async function readJson(response: Response, what: string): Promise<any> {
	const text = await response.text();
	if (!response.ok) throw new Error(`${what} failed: HTTP ${response.status} ${text.slice(0, 200)}`);
	return text === "" ? {} : JSON.parse(text);
}

/** Calls an `einv-e2e/v1` seam. */
export async function seam(ctx: Pick<Ctx, "app">, path: string, body?: Json, method = body === undefined ? "GET" : "POST"): Promise<any> {
	const response = await fetch(`${base(ctx)}/?rest_route=/einv-e2e/v1${path}`, {
		method,
		headers: { "content-type": "application/json" },
		body: body === undefined ? undefined : JSON.stringify(body),
	});
	return readJson(response, `seam ${path}`);
}

/**
 * The fake Mat Bao control plane listens on 127.0.0.1:8099 inside the WordPress container, which the browser
 * container shares; the test process runs in that container too, so it is reachable as localhost:8099.
 */
export async function fake(path: string, body?: Json, method = body === undefined ? "GET" : "POST"): Promise<any> {
	const response = await fetch(`http://127.0.0.1:8099/__control/${path}`, {
		method,
		headers: { "content-type": "application/json" },
		body: body === undefined ? undefined : JSON.stringify(body),
	});
	return readJson(response, `fake ${path}`);
}

/** Scripts the next answers of one fake endpoint (one step per call). */
export const script = (endpoint: "create-invoice" | "invoice-detail-batch" | "templates" | "login", steps: Json[]) => fake("script", { endpoint, steps });

/** Resets the fake, the plugin ledger and the settings every test relies on. */
export async function resetWorld(ctx: Pick<Ctx, "app">): Promise<void> {
	await fake("reset", {});
	await seam(ctx, "/reset", {});
	await seam(ctx, "/credentials/restore", {});
	await seam(ctx, "/settings", { prefix: "E2E", walk_in: "" });
}

/** Reads the plugin rows of an order (invoice row and attempt outcomes). */
export const invoiceOf = (ctx: Pick<Ctx, "app">, orderId: number) => seam(ctx, `/invoice/${orderId}`);

/** Polls until the invoice row of an order reaches a status (the queue runs on request). */
export async function waitForStatus(ctx: Pick<Ctx, "app">, orderId: number, status: string, tick = false): Promise<any> {
	let last: any;
	for (let i = 0; i < 20; i++) {
		if (tick) await seam(ctx, "/tick", { rounds: 1 });
		else await seam(ctx, "/run-queue", {});
		last = await invoiceOf(ctx, orderId);
		if (last.invoice?.status === status) return last;
		await new Promise((r) => setTimeout(r, 250));
	}
	throw new Error(`order ${orderId}: expected status ${status}, got ${JSON.stringify(last)}`);
}

/** Signs in to wp-admin as the E2E administrator. */
export async function signInAdmin(ctx: Ctx): Promise<void> {
	const admin = credentials.user("admin");
	await ctx.app.open("/wp-login.php");
	await ctx.screen.getByLabel("Username or Email Address").fill(admin.username);
	await ctx.screen.getByLabel("Password", { exact: false }).first().fill(admin.password);
	await ctx.screen.getByRole("button", "Log In").tap();
	await expect(ctx.browser).toHaveURL(/wp-admin/);
}

/** Signs in as a throwaway user of a role (created through a seam). */
export async function signInAs(ctx: Ctx, role: string): Promise<void> {
	const user = await seam(ctx, "/user", { role });
	if (!user.ok) throw new Error(`could not create a ${role} user`);
	await ctx.app.open("/wp-login.php");
	await ctx.screen.getByLabel("Username or Email Address").fill(user.login);
	await ctx.screen.getByLabel("Password", { exact: false }).first().fill(user.password);
	await ctx.screen.getByRole("button", "Log In").tap();
	await expect(ctx.browser).toHaveURL(/wp-admin/);
}

export const PAGES = {
	settings: "/wp-admin/admin.php?page=einvoice-matbao-settings",
	invoices: "/wp-admin/admin.php?page=einvoice-matbao-invoices",
	order: (id: number) => `/wp-admin/post.php?post=${id}&action=edit`,
};

/** A completed VND order of the seeded service product; returns its ID. */
export async function completedOrder(ctx: Pick<Ctx, "app">, extra: Json = {}): Promise<number> {
	const order = await seam(ctx, "/order", { lines: [{ sku: "E2E-SVC", qty: 1 }], status: "completed", ...extra });
	return Number(order.id);
}

/** The result notice the plugin renders after a htmx action: `role=status` on success, `role=alert` on error. */
export const okNotice = (screen: any) => screen.getByRole("status").filter({ hasText: "Thành công" });
export const errorNotice = (screen: any) => screen.getByRole("alert").filter({ hasText: "Lỗi" });
