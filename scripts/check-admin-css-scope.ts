// Fails when the built admin CSS could change WordPress admin styles outside #einv-app:
// every rule must either be scoped under #einv-app or only declare custom properties (--*),
// which is what Tailwind's theme variables and @property fallbacks do.
const path = process.argv[2] ?? "assets/dist/admin.css";
const css = (await Bun.file(path).text()).replace(/\/\*[\s\S]*?\*\//g, "");

const violations: string[] = [];
// Innermost rules: a selector followed by a declaration block without nested blocks.
for (const match of css.matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
	const selector = match[1].trim();
	const declarations = match[2].split(";").map((d) => d.trim()).filter(Boolean);
	if (selector.startsWith("@") || /^(from|to|\d+(\.\d+)?%)(\s*,|$)/.test(selector)) {
		continue;
	}
	const onlyCustomProperties = declarations.every((d) => d.startsWith("--"));
	const scoped = selector
		.split(",")
		.every((part) => /^(:where\(|:is\()?#einv-app[\s.:>\[#)]/.test(part.trim()));
	if (!scoped && !onlyCustomProperties) {
		violations.push(`${selector} { ${declarations.join("; ")} }`);
	}
}

if (violations.length > 0) {
	console.error(`Unscoped CSS rules in ${path}:\n${violations.join("\n")}`);
	process.exit(1);
}
console.log(`${path}: all style rules are scoped under #einv-app (no global preflight).`);
