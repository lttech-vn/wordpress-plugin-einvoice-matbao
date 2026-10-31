// Fails when admin CSS could change WordPress admin styles outside #einv-app: every style rule
// must be scoped under #einv-app or only declare custom properties (--*), which is what
// Tailwind's theme variables and @property fallbacks do.
//
// Usage: bun scripts/check-admin-css-scope.ts [file.css]   (exit 1 on violations)
//        bun scripts/check-admin-css-scope.ts --self-test   (checks scripts/fixtures/*.css)

/** Returns a description of every rule that can leak outside #einv-app. */
export function findViolations(source: string): string[] {
	const css = source.replace(/\/\*[\s\S]*?\*\//g, "");
	const violations: string[] = [];
	// Only these at-rules wrap further rules, which are checked on their own below.
	const wrappers = /^@(media|supports|layer|container|scope|starting-style)\b/;
	// @property and @keyframes only define names / animation steps, not selectors on page elements.
	const harmless = /^@(property|keyframes)\b/;
	const keyframeStep = /^(from|to|\d+(\.\d+)?%)(\s*,\s*(from|to|\d+(\.\d+)?%))*$/;
	// Innermost blocks: a prelude followed by declarations without nested blocks.
	for (const match of css.matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
		const selector = match[1].trim();
		const declarations = match[2].split(";").map((d) => d.trim()).filter(Boolean);
		if (wrappers.test(selector) || harmless.test(selector) || keyframeStep.test(selector)) {
			continue;
		}
		const onlyCustomProperties = declarations.every((d) => d.startsWith("--"));
		const scoped = selector.split(",").every((part) => isScoped(part.trim()));
		if (!scoped && !onlyCustomProperties) {
			violations.push(`${selector} { ${declarations.join("; ")} }`);
		}
	}
	// Block-less or empty-bodied at-rules (@font-face, @page, @import, ...) never reach the loop above
	// when they are wrappers, but are global when they style anything.
	for (const match of css.matchAll(/@(font-face|page|import|namespace|charset)\b[^{;]*[{;]/g)) {
		violations.push(match[0].trim());
	}
	return violations;
}

/** A selector is scoped when it starts with #einv-app and its first combinator is not a sibling one. */
function isScoped(selector: string): boolean {
	const match = /^(:where\(|:is\()?#einv-app(?=[\s.:>\[#)]|$)(.*)$/s.exec(selector);
	if (!match) {
		return false;
	}
	// `#einv-app ~ x` / `#einv-app + x` would style siblings of the app root.
	return !/^\)?\s*[~+]/.test(match[2]);
}

async function selfTest(): Promise<number> {
	const dir = new URL("./fixtures/", import.meta.url).pathname;
	let failures = 0;
	for await (const name of new Bun.Glob("admin-css-*.css").scan(dir)) {
		const violations = findViolations(await Bun.file(dir + name).text());
		const expectBad = name.startsWith("admin-css-bad");
		if (expectBad === (violations.length === 0)) {
			console.error(`${name}: expected ${expectBad ? "violations" : "no violations"}, got ${violations.length}\n${violations.join("\n")}`);
			failures++;
		}
	}
	console.log(failures === 0 ? "css-scope self-test: fixtures behave as expected." : `css-scope self-test: ${failures} failure(s).`);
	return failures === 0 ? 0 : 1;
}

if (import.meta.main) {
	if (process.argv[2] === "--self-test") {
		process.exit(await selfTest());
	}
	const path = process.argv[2] ?? "assets/dist/admin.css";
	const violations = findViolations(await Bun.file(path).text());
	if (violations.length > 0) {
		console.error(`Unscoped CSS rules in ${path}:\n${violations.join("\n")}`);
		process.exit(1);
	}
	console.log(`${path}: all style rules are scoped under #einv-app (no global preflight).`);
}
