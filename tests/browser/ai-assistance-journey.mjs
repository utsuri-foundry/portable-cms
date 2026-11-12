import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { connectLocalBrowser } from "./local-browser.mjs";

const origin = process.env.CMS_BROWSER_ORIGIN;
const debug = process.env.CMS_BROWSER_DEBUG_URL;
const config = process.env.CMS_BROWSER_AI_CONFIG;
assert.ok(origin && debug && config);
const fixture = (action) =>
	execFileSync("php", ["tests/php/ai-browser-fixture.php", config, action], {
		encoding: "utf8",
	});
const facts = () => JSON.parse(fixture("facts"));
const browser = await connectLocalBrowser(origin, debug);
const { command, evaluate, navigate, ready } = browser;
const path = "/records/project/10000000000000000000000000000001";
async function action(value, expected) {
	await evaluate(
		`document.querySelector('[data-editorial-form]').requestSubmit([...document.querySelectorAll('[name=_ai_action]')].find(b=>b.value===${JSON.stringify(value)}))`,
	);
	await ready(`document.readyState==='complete' && (${expected})`);
}
try {
	await command("Page.enable");
	await command("Runtime.enable");
	await command("Page.navigate", { url: `${origin}/login` });
	await ready("document.querySelector('[name=login]') || location.pathname==='/'");
	if (await evaluate("location.pathname==='/login'")) {
		await evaluate(
			"document.querySelector('[name=login]').value='admin@portable.test';document.querySelector('[name=password]').value='portable-cms-local';document.querySelector('form').requestSubmit()",
		);
		await ready("location.pathname==='/'");
	}
	await command("Emulation.setScriptExecutionDisabled", { value: true });
	await navigate(path, "[data-editorial-form]");
	assert.equal(
		await evaluate("document.querySelector('#ai-assistance') === null"),
		true,
		"a disabled optional capability leaves the editor",
	);
	const savedRevision = await evaluate(
		"document.querySelector('[name=_revision]').value",
	);
	fixture("setup");
	await navigate(path, "#ai-media");
	const initial = facts();
	await evaluate(
		"document.querySelector('[name=title__en]').value='Unsaved local title';document.querySelector('[name=_ai_context]').value='Fictional image in a courtyard.';document.querySelector('[name=_ai_consent]').checked=true",
	);
	await action(
		"request",
		"document.querySelector('[data-ai-status]')?.textContent.includes('queued')",
	);
	assert.equal(
		await evaluate("document.querySelector('[name=title__en]').value"),
		"Unsaved local title",
	);
	assert.deepEqual(facts(), initial);
	fixture("run");
	await action("check", "document.querySelector('[data-ai-purpose=caption]')");
	assert.deepEqual(facts(), initial);
	await evaluate("document.querySelector('[name=_ai_target_caption]').value='body'");
	await action(
		"apply:caption:en",
		"document.querySelector('[name=body__en__0__0__text]').value==='Draft caption (en)'",
	);
	assert.equal(
		await evaluate("document.querySelector('[name=title__en]').value"),
		"Unsaved local title",
	);
	assert.equal(
		await evaluate("document.querySelector('[name=_revision]').value"),
		savedRevision,
	);
	assert.deepEqual(
		facts(),
		initial,
		"applying a caption leaves all database revisions unchanged",
	);
	await evaluate(
		"document.querySelector('[name=_ai_target_taxonomy]').value='disciplines'",
	);
	await action(
		"apply:taxonomy:en:0",
		"document.querySelector('[name=_term_disciplines__en]').value==='Draft taxonomy label (en)'",
	);
	assert.deepEqual(facts(), initial, "taxonomy suggestions never create terms");
	assert.match(
		await evaluate("document.querySelector('[data-ai-message]').textContent"),
		/Review every language/,
	);
	const tree = await command("Accessibility.getFullAXTree");
	assert.ok(
		tree.nodes.some(
			(node) =>
				node.role?.value === "combobox" && node.name?.value === "Image for suggestions",
		),
	);
	assert.ok(
		tree.nodes.some(
			(node) =>
				node.role?.value === "checkbox" &&
				node.name?.value.includes("Allow this image"),
		),
	);
	await command("Emulation.setDeviceMetricsOverride", {
		width: 360,
		height: 800,
		deviceScaleFactor: 1,
		mobile: false,
	});
	// Native responses expose their form before all style and thumbnail requests finish.
	await ready("!!document.querySelector('link[href^=\"/assets/admin.css\"]')?.sheet");
	assert.equal(await evaluate("document.documentElement.scrollWidth<=360"), true);
	await action(
		"cancel",
		"document.querySelector('[data-ai-status]')?.textContent.includes('cancelled')",
	);
	assert.equal(
		await evaluate("document.querySelector('[data-ai-purpose]')===null"),
		true,
	);
	assert.deepEqual(facts(), initial);
	await command("Emulation.setScriptExecutionDisabled", { value: false });
	await evaluate(
		"document.querySelector('[data-editorial-form]').requestSubmit(document.querySelector('.admin-save-bar button'))",
	);
	await ready(
		"document.querySelector('[name=_revision]').value===String(Number(" +
			JSON.stringify(savedRevision) +
			")+1)",
	);
	assert.equal(
		await evaluate("document.querySelector('[name=title__en]').value"),
		"Unsaved local title",
	);
	assert.equal(
		await evaluate("document.querySelector('[name=body__en__0__0__text]').value"),
		"Draft caption (en)",
	);
	assert.equal(facts().revisions, initial.revisions + 1);
	assert.equal(facts().terms, initial.terms);
	await navigate(path, "#ai-media");
	assert.equal(
		await evaluate("document.querySelector('[name=title__en]').value"),
		"Unsaved local title",
		"human-reviewed draft persists only after explicit Save draft",
	);
	console.log(
		"PASS optional AI native browser journey: disabled editing, request, review, unsaved application, taxonomy drafts, cancellation, accessibility and narrow layout.",
	);
} finally {
	await browser.close();
}
