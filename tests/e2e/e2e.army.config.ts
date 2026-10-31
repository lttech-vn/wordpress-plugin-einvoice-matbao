import type { E2EConfig } from "e2e";
import { web } from "@e2e-dev/web";
import { isReplay, modeOf } from "./army/config.ts";
import { lightpanda } from "@e2e-dev/lightpanda";
import { decisionExecutor } from "@e2e-dev/decision";
import { createModels } from "./army/models.ts";

// Modes (E2E_MODE): record = read-write cache, live = no cache, replay (and CI) = read-only strict cache,
// no model call possible. Run through `bun run e2e:record|live|replay`, which builds the whole stack in Docker.
const mode = modeOf(process.env);
const replay = isReplay(process.env);
const models = createModels(process.env, replay);
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
			executor: decisionExecutor({ model: models.decision, textModel: models.text, vision: false }),
			context:
				"Checkout labels are Vietnamese. 'Yêu cầu xuất hóa đơn' is the invoice request checkbox; 'Cá nhân' is an individual buyer and 'Doanh nghiệp' a business buyer.",
			maxModelCalls: 25,
		},
	},
	credentials: {
		admin: { username: "admin", password: () => process.env.E2E_USER_ADMIN_PASSWORD ?? "" },
	},
	timeout: 300_000,
	retries: 0,
	workers: 1,
	cache: { dir: "army-cache", mode: replay ? "read-only" : mode === "live" ? "off" : "read-write", strict: replay },
	reporters: ["list", "junit"],
} satisfies E2EConfig;
