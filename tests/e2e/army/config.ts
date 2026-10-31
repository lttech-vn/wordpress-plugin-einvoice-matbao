export const isCI = (env: NodeJS.ProcessEnv): boolean => !["", "0", "false"].includes((env.CI ?? "").trim().toLowerCase());

export type Mode = "record" | "live" | "replay";

export function modeOf(env: NodeJS.ProcessEnv): Mode {
	if (isCI(env)) return "replay";
	const mode = env.E2E_MODE ?? "record";
	if (mode !== "record" && mode !== "live" && mode !== "replay") throw new Error(`E2E_CONFIG_INVALID: E2E_MODE must be record, live or replay (got ${mode})`);
	return mode;
}

export const isReplay = (env: NodeJS.ProcessEnv): boolean => modeOf(env) === "replay";

// Replay must be provably model-free: refuse any API key or token in the environment.
export function assertReplayEnvironment(env: NodeJS.ProcessEnv): void {
	for (const key of Object.keys(env)) {
		if (/(?:API_KEY|AUTH_TOKEN|ACCESS_TOKEN)$/.test(key) && env[key]?.trim()) throw new Error(`E2E_CONFIG_INVALID: replay refuses credential ${key}`);
	}
}

// Jev needs provider + key; otherwise a local Ollama endpoint. Same variables as the workvps/manage reference.
export function modelSettings(env: NodeJS.ProcessEnv) {
	const provider = env.E2E_SYSTEMONE_PROVIDER?.trim();
	if (provider && !["jev", "ollama"].includes(provider)) throw new Error("E2E_CONFIG_INVALID: E2E_SYSTEMONE_PROVIDER must be jev or ollama");
	const jev = provider === "jev" && !!env.E2E_SYSTEMONE_API_KEY?.trim();
	const explicit = jev || provider === "ollama";
	return {
		decision: {
			baseURL: (explicit && env.E2E_SYSTEMONE_BASE_URL) || (jev ? "https://api.typesafe.ai/v1" : "http://127.0.0.1:11434/v1"),
			model: (explicit && env.E2E_SYSTEMONE_MODEL) || (jev ? "jev-latest" : "clef-flash"),
			apiKey: jev ? env.E2E_SYSTEMONE_API_KEY!.trim() : "ollama-local",
		},
		text: {
			baseURL: env.E2E_TEXT_BASE_URL || "http://127.0.0.1:20128/v1",
			model: env.E2E_TEXT_MODEL || "gpt-6-luna",
			apiKey: env.E2E_TEXT_API_KEY || "local",
		},
	};
}
