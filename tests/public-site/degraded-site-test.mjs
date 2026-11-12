import assert from "node:assert/strict";
import { execFile } from "node:child_process";
import { cp, mkdir, mkdtemp, readFile, rm, stat, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";
import { promisify } from "node:util";

// Usage: node tests/public-site/degraded-site-test.mjs <compiled artifact>
// Adds one deliberately degraded page to a copy of a passing artifact and proves the
// public site audit rejects each class of regression on it.
const run = promisify(execFile);
const source = process.argv[2];
if (!source) {
	console.error("Usage: node tests/public-site/degraded-site-test.mjs <artifact>");
	process.exit(64);
}
const root = await mkdtemp(join(tmpdir(), "portable-cms-degraded-site-"));
try {
	const artifact = join(root, "artifact");
	await cp(source, artifact, { recursive: true });
	const manifest = JSON.parse(await readFile(join(artifact, "artifact.json"), "utf8"));
	const image = manifest.publicMedia[0].path;
	const files = {
		"assets/degraded.css":
			"@keyframes pulse{from{opacity:1}to{opacity:.2}}.pulse{animation:pulse 1s infinite alternate}\n",
		// Script-only text and a post-paint shift exercise separate failure modes.
		"assets/degraded.js": `console.error("degraded page script error");
const main = document.querySelector("main");
const text = document.createElement("p");
text.textContent = "${"Late content that only scripts provide. ".repeat(40)}";
main.prepend(text);
new PerformanceObserver((entries, observer) => {
	if (!entries.getEntries().some(entry => entry.name === "first-contentful-paint")) return;
	observer.disconnect();
	requestAnimationFrame(() => requestAnimationFrame(() => {
		const spacer = document.createElement("div");
		spacer.style.height = "600px";
		main.prepend(spacer);
	}));
}).observe({type: "paint", buffered: true});
`,
		"en/degraded/index.html": `<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1"><title>Degraded</title>
<meta name="description" content="A deliberately degraded page.">
<link rel="icon" href="../../assets/icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="../../assets/degraded.css">
<script src="../../assets/degraded.js" defer></script></head><body>
<main><h1 class="pulse">Degraded</h1><p>Short.<div>Block inside a paragraph</div></p>
<img src="../../${image}">
<a href="../missing/">Missing page</a> <a href="#nowhere">Nowhere</a></main></body></html>
`,
	};
	for (const [path, body] of Object.entries(files)) {
		await mkdir(dirname(join(artifact, path)), { recursive: true });
		await writeFile(join(artifact, path), body, { flag: "wx" });
		manifest.files.push({ path, byteSize: (await stat(join(artifact, path))).size });
	}
	manifest.routeIdentities.push({
		path: "/en/degraded/",
		locale: "en",
		translationGroup: "page:degraded",
	});
	await writeFile(join(artifact, "artifact.json"), JSON.stringify(manifest));

	const reportPath = join(root, "report.json");
	const audit = await run(
		"node",
		[
			"tools/audit-public-site.mjs",
			artifact,
			"--template=page:degraded",
			"--runs=1",
			"--concurrency=1",
			`--report=${reportPath}`,
		],
		{ maxBuffer: 16 * 1024 * 1024 },
	).then(
		() => assert.fail("the audit must fail on a degraded page"),
		(error) => error,
	);
	assert.equal(audit.code, 1, "the audit reports failure through its exit status");
	assert.ok(
		await stat(reportPath).then(
			() => true,
			() => false,
		),
		`the audit must write its report: ${String(audit.stderr ?? audit.stdout ?? "").slice(-4000)}`,
	);
	const report = JSON.parse(await readFile(reportPath, "utf8"));
	const template = report.templates.find((entry) => entry.id === "page:degraded");
	const findings = template.findings.join("\n");
	assert.match(findings, /accessibility .*image-alt/, "missing text alternative fails");
	assert.match(
		findings,
		/cumulative-layout-shift/,
		`late layout shift exceeds the limit: ${JSON.stringify(template.metrics)}`,
	);
	const page = (report.pages["/en/degraded/"] ?? []).join("\n");
	assert.match(page, /HTML line \d+:/, "invalid markup fails the validator");
	assert.match(
		page,
		/Console error: degraded page script error/,
		"console errors fail",
	);
	assert.match(
		page,
		/animation\(s\) run although reduced motion/,
		"motion ignores the preference",
	);
	assert.match(
		page,
		/Without JavaScript the main content shrinks/,
		"script-only content fails",
	);
	assert.match(page, /Link to #nowhere has no target/, "dangling fragments fail");
	assert.ok(
		report.links.some((link) => link.startsWith("/en/missing/ answers 404")),
		"broken internal links fail",
	);
	const others = Object.keys(report.pages).filter((path) => path !== "/en/degraded/");
	assert.deepEqual(others, [], "the untouched pages stay clean");
	console.log(
		"PASS public site audit rejects a degraded page: text alternative, layout shift, invalid HTML, console error, motion, script-only content, fragments and links.",
	);
} finally {
	await rm(root, { recursive: true, force: true, maxRetries: 5, retryDelay: 100 });
}
