import assert from "node:assert/strict";
import { createHash } from "node:crypto";
import { connectLocalBrowser } from "./local-browser.mjs";

const origin = process.env.CMS_BROWSER_PUBLIC_ORIGIN;
const debug = process.env.CMS_BROWSER_DEBUG_URL;
assert.ok(origin && debug);
const browser = await connectLocalBrowser(origin, debug);
const { command, evaluate, navigate, ready, browserErrors } = browser;
try {
	await command("Page.enable");
	await command("Runtime.enable");
	await command("Log.enable");
	await navigate("/en/articles/measuring-shade/", ".studio-header");
	const json = await evaluate(
		"document.querySelector('script[type=\"application/ld+json\"]').textContent",
	);
	const policy = await evaluate(
		"document.querySelector('meta[http-equiv=\"Content-Security-Policy\"]').content",
	);
	const graph = JSON.parse(json)["@graph"];
	assert.equal(
		graph.find((entry) => entry["@type"] === "Article").headline,
		await evaluate("document.querySelector('h1').textContent"),
	);
	assert.ok(graph.some((entry) => entry["@type"] === "BreadcrumbList"));
	assert.ok(
		policy.includes(`'sha256-${createHash("sha256").update(json).digest("base64")}'`),
	);
	assert.ok(!policy.includes("unsafe-inline"));
	assert.equal(
		await evaluate("fetch(location.href).then(r=>r.headers.get('x-robots-tag'))"),
		null,
	);
	await command("Emulation.setScriptExecutionDisabled", { value: true });
	await navigate("/en/", ".studio-header");
	assert.equal(
		await evaluate(
			"JSON.parse(document.querySelector('script[type=\"application/ld+json\"]').textContent)['@graph'][0]['@type']",
		),
		"Organization",
	);
	await command("Emulation.setScriptExecutionDisabled", { value: false });
	assert.deepEqual(browserErrors, []);
	await evaluate(
		"const attack=document.createElement('script');attack.textContent='window.unapprovedInlineExecuted=true';document.body.append(attack)",
	);
	await ready("window.unapprovedInlineExecuted===undefined");
	assert.equal(await evaluate("window.unapprovedInlineExecuted===undefined"), true);
	assert.ok(
		browserErrors.some((error) =>
			/Executing inline script violates|Refused to execute inline script/.test(error),
		),
	);
	assert.ok(
		browserErrors.every((error) =>
			/Executing inline script violates|Refused to execute inline script/.test(error),
		),
	);
	process.stdout.write(
		"PASS public structured metadata: original HTML, no-script facts, exact JSON hash, strict CSP denies unapproved inline scripts and no admin noindex leakage.\n",
	);
} finally {
	await browser.close();
}
