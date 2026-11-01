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
				"You are the shop administrator in wp-admin. Plugin pages are under WooCommerce: 'Hóa đơn Mắt Bão' is the settings page, 'Danh sách hóa đơn' the invoice list. 'Lưu kết nối' saves the Mat Bao connection, 'Kiểm tra kết nối' tests it, 'Tải lại danh sách mẫu' reloads invoice templates, 'Lưu mẫu mặc định' saves the chosen template. In the invoice list: the status select and search box filter the table; 'Chi tiết' opens an invoice; 'Kiểm tra trạng thái' checks the draft on Mat Bao; 'Gửi lại' resends. Confirmation dialogs are accepted by the test. IMPORTANT: you only perform the requested actions. Many tests expect the page to answer with an error message (a red 'Lỗi' notice, a refusal, a validation message); that error is the expected outcome, not a failure of your task. Your task is complete as soon as the last requested control has been clicked and the page reacted. Never retry a click to make an error go away.",
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
