import { defineConfig } from "@playwright/test";

// Runs inside mcr.microsoft.com/playwright (see tests/e2e/run.sh) against the Docker Compose site.
export default defineConfig({
	testDir: "./browser",
	timeout: 180_000,
	expect: { timeout: 30_000 },
	workers: 1,
	retries: 0,
	reporter: [["list"], ["json", { outputFile: "results/report.json" }]],
	outputDir: "results/artifacts",
	use: {
		baseURL: process.env.EINV_E2E_BASE_URL ?? "http://localhost:8080",
		locale: "vi-VN",
		trace: "retain-on-failure",
		screenshot: "only-on-failure",
	},
});
