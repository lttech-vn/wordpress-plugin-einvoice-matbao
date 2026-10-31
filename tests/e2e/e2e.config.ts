import type { E2EConfig } from "e2e";
import { web } from "@e2e-dev/web";
import { createOpenRouter } from "@openrouter/ai-sdk-provider";

// The site is the Docker Compose stack (docker compose up -d --wait); WordPress answers on the host
// port in EINV_SITE_URL, so the browser uses that exact origin.
const siteUrl = process.env.EINV_SITE_URL ?? "http://localhost:8115";
const openrouter = createOpenRouter({ apiKey: process.env.OPENROUTER_API_KEY });

export default {
	tests: "**/*.e2e.ts",
	targets: [{ engine: web({ locale: "vi-VN" }), app: { url: siteUrl } }],
	timeout: 240_000,
	agents: {
		default: {
			model: openrouter(process.env.EINV_E2E_MODEL ?? "openai/gpt-4.1-mini"),
			system: "You are a careful QA agent for a WooCommerce shop. Do only the stated goal, then stop.",
			context: "Checkout labels are Vietnamese. 'Yêu cầu xuất hóa đơn' is the invoice request checkbox; 'Cá nhân' is an individual buyer and 'Doanh nghiệp' a business buyer.",
		},
	},
	credentials: {
		admin: { username: process.env.EINV_E2E_ADMIN_USER ?? "admin", password: () => process.env.EINV_E2E_ADMIN_PASS ?? "e2e-admin-pass" },
	},
} satisfies E2EConfig;
