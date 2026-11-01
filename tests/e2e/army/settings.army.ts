import { test, describe, beforeEach } from "@e2e-dev/web";
import { expect, secrets } from "e2e";
import { PAGES, errorNotice, fake, okNotice, resetWorld, script, seam, signInAdmin } from "./support.ts";

// Stories 28-31, 33: encrypted credentials, connection check, template pick, MTChieu prefix, walk-in name.
// Hybrid: the agent drives the user-facing flows (save the connection, test it, pick a template); steps that need a
// specific one of several identically named buttons, or that force a refusal, are exact. Every agent step is pinned
// by an exact expect, and state the screen cannot show is read through a seam. Mat Bao is the fake server.

describe("settings page", { serial: false, agent: "admin" }, () => {
	beforeEach(async (ctx) => {
		await resetWorld(ctx);
		await signInAdmin(ctx);
		await ctx.app.open(PAGES.settings);
	});

	test("shows the Mat Bao connection, template, MTChieu and Lark sections", async ({ screen }) => {
		await expect(screen.getByRole("heading", "Cài đặt hóa đơn Mắt Bão")).toBeVisible();
		for (const title of ["Kết nối Mắt Bão", "Mẫu hóa đơn mặc định", "Mã tham chiếu và người mua vãng lai", "Đơn vị tính", "Cảnh báo Lark"]) {
			await expect(screen.getByRole("heading", title)).toBeVisible();
		}
	});

	test("saves credentials encrypted and never renders the password back", async ({ agent, screen, app }) => {
		await agent.act("in the Mat Bao connection section fill the tax code {mst}, the login name {user} and the password {pass}, then click the 'Lưu kết nối' button. The task is complete once a green or red notice appears under the connection form; an error notice is an acceptable result.", {
			params: { mst: "0000000001", user: "e2e-user", pass: secrets.get("matbao-pass") },
		});
		await expect(okNotice(screen)).toContainText("Đã lưu thông tin kết nối Mắt Bão (mã hóa).");

		await app.open(PAGES.settings);
		await expect(screen.getByLabel("Mã số thuế")).toHaveValue("0000000001");
		// The password input is secure (the runner refuses to read it); the placeholder says it is stored.
		await expect(screen.getByPlaceholder("Đã lưu, để trống nếu không đổi")).toBeVisible();
		expect(JSON.stringify(await seam({ app }, "/option/einvoice_matbao_credentials"))).not.toContain("s3cret-pass-E2E");
	});

	test("without the encryption key nothing is saved and the page says why (story 29)", async ({ screen, app }) => {
		// Exact steps: the refusal is the point, so the test must press the button itself and read the notice.
		await seam({ app }, "/encryption-key", { off: true });
		await app.open(PAGES.settings);
		await expect(screen.getByText("Chưa cấu hình khóa mã hóa", { exact: false }).first()).toBeVisible();
		await screen.getByLabel("Mã số thuế").fill("0000000009");
		await screen.getByLabel("Tên đăng nhập").fill("e2e-nokey");
		await screen.getByLabel("Mật khẩu").fill(secrets.get("matbao-pass-nokey"));
		await screen.getByRole("button", "Lưu kết nối").tap();
		await expect(screen.getByRole("alert").filter({ hasText: "Không lưu được" })).toContainText("chưa cấu hình khóa mã hóa");
		await seam({ app }, "/encryption-key", { off: false });
		const stored = JSON.stringify(await seam({ app }, "/option/einvoice_matbao_credentials"));
		expect(stored).not.toContain("never-stored-E2E");
		expect(stored).not.toContain("e2e-nokey");
	});

	test("refuses incomplete credentials with a Vietnamese message", async ({ agent, app, screen }) => {
		await seam({ app }, "/credentials/clear", {}); // a stored password would be kept, so start with none
		await app.open(PAGES.settings);
		await agent.act("in the Mat Bao connection section fill only the tax code {mst} and click the 'Lưu kết nối' button. The task is complete once a green or red notice appears under the connection form; an error notice is an acceptable result.", { params: { mst: "0000000001" } });
		await expect(errorNotice(screen)).toContainText("Cần nhập đủ mã số thuế, tên đăng nhập và mật khẩu.");
	});

	test("connection check succeeds against the fake and reports a rejected login", async ({ agent, screen, app }) => {
		await agent.act("click the 'Kiểm tra kết nối' button once. The task is complete once a green or red notice appears next to the buttons; either colour is an acceptable result.");
		await expect(okNotice(screen)).toContainText("Kết nối Mắt Bão thành công.");

		await fake("config", { login_fail: true });
		await app.open(PAGES.settings); // no notice from the first click may satisfy the second goal
		await agent.act("click the 'Kiểm tra kết nối' button once. The task is complete once a green or red notice appears next to the buttons; either colour is an acceptable result.");
		await expect(errorNotice(screen)).toContainText("Mắt Bão từ chối thông tin đăng nhập.");
	});

	test("connection check reports an unreachable Mat Bao", async ({ screen }) => {
		await script("login", [{ http: 503 }, { http: 503 }]);
		await screen.getByRole("button", "Kiểm tra kết nối").tap();
		await expect(errorNotice(screen)).toContainText("Mắt Bão trả về lỗi.");
	});

	test("lists the VAT templates and saves the chosen one as the default", async ({ agent, screen }) => {
		await fake("config", {
			templates: [
				{ thDon: "Hóa đơn giá trị gia tăng", khmshDon: "1", khhDon: "C26TAT", sLuong: 1000, cLai: 500 },
				{ thDon: "Hóa đơn giá trị gia tăng", khmshDon: "1", khhDon: "C26TKK", sLuong: 1000, cLai: 20 },
			],
		});
		await agent.act("reload the template list, pick the template whose series is {series} and save it as the default template", { params: { series: "C26TKK" } });
		await expect(okNotice(screen)).toContainText("Đã chọn mẫu mặc định 1 / C26TKK.");
	});

	test("rejects a template Mat Bao no longer offers", async ({ screen }) => {
		await screen.getByRole("button", "Tải lại danh sách mẫu").tap();
		await expect(screen.getByLabel("Mẫu số / ký hiệu")).toBeVisible();
		// The template ran out of numbers between listing and saving; saving it is an exact step.
		await fake("config", { templates: [{ thDon: "Hóa đơn giá trị gia tăng", khmshDon: "1", khhDon: "C26TAT", sLuong: 1000, cLai: 0 }] });
		await screen.getByRole("button", "Lưu mẫu mặc định").tap();
		await expect(errorNotice(screen)).toContainText("Mẫu này không còn hiệu lực hoặc đã hết số trên Mắt Bão. Hãy chọn mẫu khác.");
	});

	test("rejects a MTChieu prefix with accents and keeps the old one", async ({ screen, app }) => {
		// Exact steps: three buttons are named "Lưu", so the test pins the first one.
		await screen.getByLabel("Prefix mã tham chiếu (MTChieu)").fill("QUÁDÀI");
		await screen.getByRole("button", "Lưu").first().tap();
		await app.open(PAGES.settings);
		await expect(screen.getByLabel("Prefix mã tham chiếu (MTChieu)")).toHaveValue("E2E");
	});

	test("saves the MTChieu prefix and the walk-in buyer name", async ({ screen, app }) => {
		await screen.getByLabel("Prefix mã tham chiếu (MTChieu)").fill("SHOP");
		await screen.getByLabel("Tên người mua vãng lai (NMua_Ten)").fill("Khách mua lẻ E2E");
		await screen.getByRole("button", "Lưu").first().tap();
		await expect(okNotice(screen)).toContainText("Đã lưu. Ví dụ MTChieu: SHOPWC1234. Tên người mua vãng lai: Khách mua lẻ E2E.");
		await app.open(PAGES.settings);
		await expect(screen.getByLabel("Prefix mã tham chiếu (MTChieu)")).toHaveValue("SHOP");
	});

	test("saves the default unit of measure", async ({ screen }) => {
		await screen.getByLabel("Đơn vị tính mặc định (DVTinh)").fill("Lượt");
		await screen.getByRole("button", "Lưu").nth(1).tap();
		await expect(okNotice(screen)).toContainText("Đã lưu. Đơn vị tính mặc định: Lượt.");
	});
});
