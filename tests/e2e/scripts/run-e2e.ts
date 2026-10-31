// Whole stack in Docker, no published port: WordPress+MariaDB (compose project per run), a browser container
// that joins the WordPress container's network namespace (so http://localhost is the shop), artifacts via docker cp.
//   bun scripts/run-e2e.ts record|live|replay
import { spawn, spawnSync } from "node:child_process";
import { randomBytes } from "node:crypto";
import { existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { join, resolve } from "node:path";
import { parseEnv } from "node:util";
import { assertReplayEnvironment, isCI } from "../army/config.ts";
import { composeEnv, createE2EResources } from "./e2e-resources.ts";

const root = resolve(import.meta.dirname, "../../..");
const e2eDir = resolve(import.meta.dirname, "..");
// Like `task e2e:record`: load .env.e2e.local at the runner boundary (run with `bun --no-env-file`).
const envFile = join(e2eDir, ".env.e2e.local");
if (existsSync(envFile) && process.argv[2] !== "replay") {
	for (const [name, value] of Object.entries(parseEnv(readFileSync(envFile, "utf8")))) process.env[name] ??= value;
}
const mode = isCI(process.env) ? "replay" : (process.argv[2] ?? "record");
if (!["record", "live", "replay"].includes(mode)) throw new Error("E2E_CONFIG_INVALID: mode must be record, live or replay");
if (mode === "replay") assertReplayEnvironment(process.env);
if (process.env.EINV_DEMO_E2E === "1" && mode === "replay") throw new Error("E2E_CONFIG_INVALID: replay never calls the Mat Bao demo");

const { id, project, browser, cleanup } = createE2EResources();
const adminPassword = randomBytes(18).toString("hex");
const artifactPath = join(e2eDir, "test-results", "runs", id);
mkdirSync(artifactPath, { recursive: true });
console.log(`E2E run ${id} (${mode}); artifacts: ${artifactPath}`);

function run(command: string, args: string[], options: { env?: NodeJS.ProcessEnv; cwd?: string } = {}): string {
	const result = spawnSync(command, args, { encoding: "utf8", stdio: ["ignore", "pipe", "inherit"], ...options });
	if (result.status !== 0) throw new Error(`E2E_DOCKER_FAILED: ${command} ${args[0]} exited ${result.status}`);
	return result.stdout.trim();
}

let finished = false;
function finish(code: number): never {
	if (!finished) {
		finished = true;
		for (const directory of [".e2e", "army-cache"]) {
			const destination = join(artifactPath, directory);
			mkdirSync(destination, { recursive: true });
			spawnSync("docker", ["cp", `${browser}:/e2e/${directory}/.`, destination], { stdio: "ignore", timeout: 30000 });
		}
		if (code === 0) {
			try {
				const report = JSON.parse(readFileSync(join(artifactPath, ".e2e/report.json"), "utf8"));
				const tests = (report.run?.tests ?? report.tests ?? []) as { status?: string }[];
				const bad = tests.filter((t) => t.status !== "passed");
				if (report.run?.status !== "passed" || bad.length > 0) throw new Error("report is not all-passed");
			} catch (error) {
				console.error(`Report gate: ${error instanceof Error ? error.message : "unreadable report"}`);
				code = 1;
			}
		}
		if (!cleanup()) {
			console.error("E2E cleanup failed; keeping a nonzero exit");
			code = code || 1;
		}
		writeFileSync(join(artifactPath, "run.json"), JSON.stringify({ id, mode, exitCode: code }, null, 2));
	}
	process.exit(code);
}
for (const signal of ["SIGTERM", "SIGINT"] as const) process.once(signal, () => finish(signal === "SIGINT" ? 130 : 143));

try {
	const env = { ...composeEnv(project), EINV_HTTP_PORT: "0" };
	const image = `einv-e2e-army:${project}`;
	run("docker", ["build", "-f", "tests/e2e/docker/e2e.Dockerfile", "-t", image, "tests/e2e"], { cwd: root });
	run("docker", ["compose", "up", "-d", "--wait"], { cwd: root, env });
	run("docker", ["compose", "exec", "-T", "wordpress", "einv-setup"], { cwd: root, env });
	// setup-site.sh seeds products/settings and prints "product <id>"; the admin password is random per run.
	const setup = run("bash", ["tests/e2e/setup-site.sh"], {
		cwd: root,
		env: { ...env, EINV_E2E_MODE: process.env.EINV_DEMO_E2E === "1" ? "demo" : "fake", EINV_E2E_ADMIN_PASS: adminPassword },
	});
	const productId = /^product (\d+)$/m.exec(setup)?.[1];
	if (!productId) throw new Error("E2E_SETUP_FAILED: no product id");
	const wordpress = run("docker", ["compose", "ps", "-q", "wordpress"], { cwd: root, env });

	const modelEnv = mode === "replay" ? {} : Object.fromEntries(Object.entries(process.env).filter(([name]) => /^(E2E_SYSTEMONE_|E2E_TEXT_)/.test(name)));
	const childEnv = { ...modelEnv, E2E_MODE: mode,
		...(process.env.E2E_BROWSER ? { E2E_BROWSER: process.env.E2E_BROWSER } : {}),
		...(process.env.E2E_BLOCKS ? { E2E_BLOCKS: process.env.E2E_BLOCKS } : {}), E2E_PRODUCT_ID: productId, E2E_USER_ADMIN_PASSWORD: adminPassword, ...(isCI(process.env) ? { CI: "1" } : {}) };
	run("docker", ["create", "--name", browser, "--network", `container:${wordpress}`, "--shm-size", "1g", ...Object.keys(childEnv).flatMap((n) => ["-e", n]), image, "node", "node_modules/e2e/dist/cli/bin.js", "run", "--config", "e2e.army.config.ts", "--output", ".e2e"], {
		env: { ...process.env, ...childEnv },
	});
	if (mode === "replay" && existsSync(join(e2eDir, "army-cache"))) run("docker", ["cp", join(e2eDir, "army-cache"), `${browser}:/e2e/army-cache`]);

	const child = spawn("docker", ["start", "-a", browser], { stdio: "inherit" });
	child.once("error", () => finish(1));
	child.once("exit", (code) => finish(code ?? 1));
} catch (error) {
	console.error(error);
	finish(1);
}
