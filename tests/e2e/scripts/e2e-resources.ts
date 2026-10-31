import { spawnSync } from "node:child_process";
import { randomBytes } from "node:crypto";

// Every run owns a compose project and a browser container named after its id; cleanup touches nothing else.
export function createE2EResources() {
	const id = randomBytes(6).toString("hex");
	const project = `einv-e2e-${id}`;
	const browser = `${project}-browser`;

	function cleanup(): boolean {
		spawnSync("docker", ["rm", "-f", browser], { stdio: "ignore", timeout: 30000 });
		const down = spawnSync("docker", ["compose", "-p", project, "down", "-v", "--remove-orphans"], { stdio: "ignore", timeout: 120000, env: composeEnv(project) });
		return down.status === 0;
	}

	return { id, project, browser, cleanup };
}

export function composeEnv(project: string): NodeJS.ProcessEnv {
	return { ...process.env, COMPOSE_PROJECT_NAME: project, COMPOSE_FILE: "compose.yaml:tests/e2e/docker/compose.e2e.yaml" };
}
