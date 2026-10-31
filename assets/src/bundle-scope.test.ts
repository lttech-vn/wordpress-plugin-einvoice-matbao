import { describe, expect, test } from "bun:test";
import { mkdtempSync, readFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";

// Front-end bundles are plain <script> tags on pages that may load several of them (Checkout
// Blocks loads the Classic and the Blocks bundle); minified top-level names must not leak to global.
const entries = ["checkout.ts", "checkout-blocks.ts", "checkout-blocks-editor.ts"];
const buildScript = JSON.parse(readFileSync("package.json", "utf8")).scripts as Record<string, string>;

function buildFlags(entry: string): string[] {
	const command = Object.values(buildScript).find((script) => script.includes(`assets/src/${entry}`));
	if (!command) throw new Error(`no build script for ${entry}`);
	const segment = command.split("&&").find((part) => part.includes(`assets/src/${entry}`))!;
	return segment.trim().split(/\s+/).slice(2).filter((arg) => arg !== `assets/src/${entry}`);
}

describe("front-end bundles", () => {
	for (const entry of entries) {
		test(`${entry} is built as an IIFE that leaks no top-level names`, async () => {
			const directory = mkdtempSync(join(tmpdir(), "einv-bundle-"));
			try {
				const flags = buildFlags(entry).filter((flag, index, all) => flag !== "--outfile" && all[index - 1] !== "--outfile");
				const outfile = join(directory, "out.js");
				const proc = Bun.spawn(["bun", "build", `assets/src/${entry}`, "--outfile", outfile, ...flags], { stdout: "pipe", stderr: "pipe" });
				expect(await proc.exited).toBe(0);
				const code = readFileSync(outfile, "utf8").trimStart();
				// A global script runs in the page scope; an IIFE keeps every declaration inside the wrapper.
				expect(code).toMatch(/^\(\s*(\(\)\s*=>|function\s*\(\))\s*\{/);
				expect(code).not.toMatch(/^(var|let|const|function)\s/);
			} finally {
				rmSync(directory, { recursive: true, force: true });
			}
		});
	}
});
