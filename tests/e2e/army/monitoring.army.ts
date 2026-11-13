import { test, describe, beforeEach } from "@e2e-dev/web";
import { expect } from "e2e";
import { PAGES, errorNotice, fake, okNotice, resetWorld, signInAdmin } from "./support.ts";

// Error monitoring card on the notifications tab: the DSN points at the fake sink (POST /api/3/envelope/ on the fake Mat Bao server), so the
// test event really leaves WordPress and the test reads it back from the fake. Exact steps only: the outcome is the
// event ID in the notice and the envelope the sink recorded, not a flow an agent should improvise.

const SINK_DSN = "http://e2ekey@127.0.0.1:8099/3";

describe("monitoring card", { serial: false, agent: "admin" }, () => {
	beforeEach(async (ctx) => {
		await resetWorld(ctx);
		await signInAdmin(ctx);
		await ctx.app.open(PAGES.notifications);
	});

	test("shows the form without a stored DSN", async ({ screen }) => {
		await expect(screen.getByRole("heading", "Giám sát lỗi (Sentry)")).toBeVisible();
		await expect(screen.getByRole("button", "Gửi event thử")).toBeVisible();
	});

	test("refuses a test event while monitoring is off", async ({ screen }) => {
		await screen.getByRole("button", "Gửi event thử").tap();
		await expect(errorNotice(screen)).toContainText("Cần bật giám sát và nhập DSN hợp lệ");
		expect(await fake("requests?endpoint=sentry-envelope")).toHaveLength(0);
	});

	test("saves the DSN, never renders it back and sends a test event to the sink", async ({ screen, app }) => {
		await screen.getByLabel("DSN").fill(SINK_DSN);
		await screen.getByLabel("Bật gửi lỗi, hiệu năng và log lên máy chủ Sentry").tap();
		await screen.getByRole("button", "Lưu cấu hình").tap();
		await expect(okNotice(screen)).toContainText("Đã lưu cấu hình giám sát lỗi.");

		await app.open(PAGES.notifications);
		await expect(screen.getByText("127.0.0.1 · project 3", { exact: false })).toBeVisible();
		await expect(screen.getByText("e2ekey", { exact: false })).toHaveCount(0);

		await screen.getByRole("button", "Gửi event thử").tap();
		await expect(okNotice(screen)).toContainText("Đã gửi event thử (ID:");
		const received = await fake("requests?endpoint=sentry-envelope");
		expect(received.length).toBeGreaterThan(0);
		expect(JSON.stringify(received)).toContain("einvoice-matbao test event");
	});
});
