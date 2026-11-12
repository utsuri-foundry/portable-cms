import { readFile, writeFile } from "node:fs/promises";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { parseArgs } from "node:util";
import { auditBehaviour, auditLinks } from "./quality/public-site/behaviour.mjs";
import { launchBrowser } from "./quality/public-site/browser.mjs";
import { auditWithLighthouse } from "./quality/public-site/lighthouse.mjs";
import { startStaticHost } from "./quality/public-site/static-host.mjs";
import { publicDocuments, publicTemplates } from "./quality/public-site/templates.mjs";
import { validateHtml } from "./quality/public-site/validate-html.mjs";

// Usage: node tools/audit-public-site.mjs <artifact> [--report=<file>] [--template=<id>]...
//        [--runs=<n>] [--concurrency=1]
// Audits a compiled static artifact against tools/quality/public-site/bar.json and exits
// non-zero when any page falls short. Requires the pinned shell (Apache, Chromium,
// Lighthouse and the Nu validator).
const { values, positionals } = parseArgs({
	allowPositionals: true,
	options: {
		report: { type: "string" },
		template: { type: "string", multiple: true },
		runs: { type: "string" },
		concurrency: { type: "string", default: "1" },
	},
});
if (positionals.length !== 1) {
	console.error(
		"Usage: node tools/audit-public-site.mjs <artifact> [--report=<file>] [--template=<id>] [--runs=<n>]",
	);
	process.exit(64);
}
if (values.concurrency !== "1") {
	console.error(
		"Lighthouse measurements must run sequentially on each host; --concurrency must be 1.",
	);
	process.exit(64);
}
const artifact = resolve(positionals[0]);
const bar = JSON.parse(
	await readFile(
		join(dirname(fileURLToPath(import.meta.url)), "quality/public-site/bar.json"),
		"utf8",
	),
);
if (values.runs !== undefined)
	bar.lighthouse.runs = Math.max(1, Number.parseInt(values.runs, 10) || 1);

const host = await startStaticHost(artifact);
let browser;
const report = {
	artifact,
	documents: 0,
	templates: [],
	pages: {},
	links: [],
	passed: false,
};
const pageFindings = (path) => {
	report.pages[path] ??= [];
	return report.pages[path];
};
try {
	browser = await launchBrowser();
	const documents = publicDocuments(host.manifest);
	report.documents = documents.length;
	const templates = publicTemplates(host.manifest).filter(
		(template) => !values.template || values.template.includes(template.id),
	);
	if (templates.length === 0) throw new Error("No public templates to audit");

	for (const [path, errors] of await validateHtml(artifact, documents))
		pageFindings(path).push(...errors);

	const behaviour = await auditBehaviour(browser, host.origin, documents, bar);
	for (const [path, findings] of behaviour.findings)
		pageFindings(path).push(...findings);
	report.links = await auditLinks(
		host.origin,
		host.manifest.publicUrlContext?.baseUrl,
		behaviour.urls,
	);

	// Independent browsers still share the host CPU. Measure every sample and
	// template sequentially, as required by Lighthouse's variability guidance.
	for (const template of templates) {
		const url = host.origin + template.representative;
		const response = await fetch(url);
		const noindex =
			/noindex/i.test(response.headers.get("x-robots-tag") ?? "") ||
			/<meta\s+name="robots"\s+content="[^"]*noindex/i.test(await response.text());
		const result = {
			id: template.id,
			path: template.representative,
			noindex,
			...(await auditWithLighthouse(url, browser.port, bar.lighthouse, { noindex })),
		};
		report.templates.push(result);
		console.log(
			`${result.id} ${result.path} ${Object.entries(result.scores)
				.map(([category, score]) => `${category} ${Math.round(score * 100)}`)
				.join(", ")}${result.noindex ? " (deliberately not indexed)" : ""}`,
		);
	}
} finally {
	try {
		if (browser) await browser.close();
	} finally {
		await host.close();
	}
}

let failures = 0;
for (const template of report.templates) {
	for (const finding of template.findings) {
		console.log(`FAIL ${template.id} ${template.path}: ${finding}`);
		failures++;
	}
}
for (const [path, findings] of Object.entries(report.pages).sort()) {
	for (const finding of findings) {
		console.log(`FAIL ${path}: ${finding}`);
		failures++;
	}
}
for (const link of report.links) {
	console.log(`FAIL link ${link}`);
	failures++;
}
report.passed = failures === 0;
if (values.report)
	await writeFile(values.report, `${JSON.stringify(report, null, "\t")}\n`);
console.log(
	report.passed
		? `Public site audit passed: ${report.templates.length} templates and ${report.documents} pages.`
		: `Public site audit failed with ${failures} finding(s).`,
);
process.exitCode = report.passed ? 0 : 1;
