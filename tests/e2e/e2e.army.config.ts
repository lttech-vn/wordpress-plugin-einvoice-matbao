import type { E2EConfig } from "e2e";
import { web } from "@e2e-dev/web";
import { isReplay, modeOf } from "./army/config.ts";
import { lightpanda } from "@e2e-dev/lightpanda";
import { createTextAgentModel } from "./army/models.ts";

// Modes (E2E_MODE): record = read-write cache, live = no cache, replay (and CI) = read-only strict cache,
// no model call possible. Run through `bun run e2e:record|live|replay`, which builds the whole stack in Docker.
const mode = modeOf(process.env);
const replay = isReplay(process.env);
const chat = createTextAgentModel(process.env, replay);
// E2E_BROWSER=chromium (default) | lightpanda. The runner passes it into the container.
const browser = process.env.E2E_BROWSER ?? "chromium";

export default {
	projectId: "einvoice-matbao-army",
	tests: "army/**/*.army.ts",
	targets: [
		{
			name: browser,
			engine: browser === "lightpanda" ? web({ browser: lightpanda({ loadResources: ["stylesheet"] }), locale: "vi-VN" }) : web({ locale: "vi-VN" }),
			// The browser container shares the WordPress container's network namespace.
			app: { url: "http://localhost", identity: "einvoice-matbao-contained" },
		},
	],
	agents: {
		default: {
			model: chat,
			context:
				"Checkout labels are Vietnamese. 'Yêu cầu xuất hóa đơn' is the invoice request checkbox; 'Cá nhân' is an individual buyer and 'Doanh nghiệp' a business buyer.",
			maxModelCalls: 25,
		},
		// Admin screens of the plugin and WooCommerce (Vietnamese labels). Its own agent, so the checkout recordings
		// (keyed by agent and context) stay valid.
		admin: {
			model: chat,
			context:
				"You are the shop administrator in wp-admin. The top-level menu 'Hóa đơn Mắt Bão' opens one screen with a row of tabs under the heading: 'Hóa đơn' (default: dashboard cards, status filter 'Trạng thái', search box, invoice table with 'Chi tiết'), 'Kết nối Mắt Bão' (card 'Kết nối Mắt Bão' with fields 'Môi trường', 'Mã số thuế', 'Tên đăng nhập', 'Mật khẩu' and buttons 'Lưu kết nối', 'Kiểm tra kết nối'; card 'Mẫu hóa đơn mặc định' with button 'Tải lại danh sách mẫu', select 'Mẫu số / ký hiệu' and button 'Lưu mẫu mặc định'), 'Xuất hóa đơn' (MTChieu prefix, walk-in buyer name, default unit; each card has its own 'Lưu'), 'Thông báo' (card 'Cảnh báo Lark' with checkbox 'Bật cảnh báo Lark', 'Webhook URL', secret, 'Lưu webhook', 'Gửi tin nhắn kiểm tra'; card 'Giám sát lỗi (Sentry)' with its own switch, DSN and buttons), 'Nâng cao' (uninstall switch). Only the selected tab's content is on screen; clicking a tab replaces the content without reloading. In the invoice list: the status select and search box filter the table; 'Chi tiết' opens an invoice; 'Kiểm tra trạng thái' checks the draft on Mat Bao; 'Gửi lại' resends. Confirmation dialogs are accepted by the test. IMPORTANT: you only perform the requested actions. Many tests expect the page to answer with an error message (a red 'Lỗi' notice, a refusal, a validation message); that error is the expected outcome, not a failure of your task. Your task is complete as soon as the last requested control has been clicked and the page reacted. Never retry a click to make an error go away.",
			maxModelCalls: 25,
		},
		text: {
			model: chat,
			system: "You are a careful QA agent for a WooCommerce shop. Do only the stated goal, then stop.",
			context:
				"Checkout labels are English or Vietnamese. 'Yêu cầu xuất hóa đơn' is the invoice request checkbox; 'Cá nhân' is an individual buyer and 'Doanh nghiệp' a business buyer.",
			maxModelCalls: 25,
		},
	},
	credentials: {
		admin: { username: "admin", password: () => process.env.E2E_USER_ADMIN_PASSWORD ?? "" },
	},
	// Synthetic passwords typed by the settings tests; the agent sees only the secret's name.
	secrets: {
		"matbao-pass": "s3cret-pass-E2E",
		"matbao-pass-nokey": "never-stored-E2E",
	},
	timeout: 300_000,
	retries: 0,
	workers: 1,
	cache: { dir: "army-cache", mode: replay ? "read-only" : mode === "live" ? "off" : "read-write", strict: replay },
	reporters: ["list", "junit"],
} satisfies E2EConfig;
