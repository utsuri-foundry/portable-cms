import assert from "node:assert/strict";
import { resolve } from "node:path";
import { connectLocalBrowser } from "./local-browser.mjs";

const origin = process.env.CMS_BROWSER_ORIGIN ?? "http://localhost:4183";
const debug = process.env.CMS_BROWSER_DEBUG_URL ?? "http://127.0.0.1:9224";
const browser = await connectLocalBrowser(origin, debug);
const { command, evaluate, ready, navigate, browserErrors } = browser;
const id = "d0000000000000000000000000000001";
const path = `/media/${id}`;
const input = (name) => `document.querySelector('[name="${name}"]')`;
try {
	await command("Page.enable");
	await command("Runtime.enable");
	await command("Log.enable");
	await command("Page.navigate", { url: `${origin}/login` });
	await ready(
		"document.readyState==='complete' && (document.querySelector('[name=login]') || document.querySelector('.admin-navigation'))",
	);
	if (await evaluate("document.querySelector('[name=login]') !== null")) {
		await evaluate(
			"document.querySelector('[name=login]').value='admin@portable.test';document.querySelector('[name=password]').value='portable-cms-local';document.querySelector('form').requestSubmit()",
		);
	}
	await ready("location.pathname==='/' && document.querySelector('.admin-navigation')");
	await command("Emulation.setScriptExecutionDisabled", { value: true });
	await navigate(
		"/records/project/10000000000000000000000000000001",
		"[data-editorial-form]",
	);
	assert.equal(
		await evaluate(
			`document.querySelector('#field-gallery a[href="${path}"]').textContent`,
		),
		"Edit image details",
	);
	await navigate(path, "[name=alternativeText__en]");
	const original = await evaluate(
		"Object.fromEntries([...document.querySelectorAll('input[type=text]')].map(c=>[c.name,c.value]))",
	);
	const revision = await evaluate(`${input("_revision")}.value`);
	await evaluate(
		`${input("alternativeText__en")}.value='Blue & cream, "painted" bands.';${input("caption__en")}.value='Authored browser caption';${input("credit__en")}.value='Browser author';document.querySelector('.admin-form').requestSubmit()`,
	);
	await ready(
		`${input("_revision")}?.value===${JSON.stringify(String(Number(revision) + 1))}`,
	);
	assert.equal(
		await evaluate(`${input("caption__en")}.value`),
		"Authored browser caption",
	);
	// A stale form preserves every submitted field and cannot overwrite newer metadata.
	await evaluate(
		`${input("_revision")}.value=${JSON.stringify(revision)};${input("caption__en")}.value='Unsaved browser caption';${input("decorative")}.checked=true;document.querySelector('.admin-form').requestSubmit()`,
	);
	await ready("document.querySelector('[role=alert]')");
	assert.equal(
		await evaluate(`${input("caption__en")}.value`),
		"Unsaved browser caption",
	);
	assert.equal(await evaluate(`${input("decorative")}.checked`), true);
	await evaluate(
		`${input("alternativeText__en")}.value='<script>bad</script>';document.querySelector('.admin-form').requestSubmit()`,
	);
	await ready(
		"document.querySelector('[role=alert]').textContent.includes('plain text')",
	);
	assert.equal(
		await evaluate(`${input("alternativeText__en")}.value`),
		"<script>bad</script>",
	);
	assert.equal(
		await evaluate("document.querySelectorAll('script:not([src])').length"),
		0,
	);
	// Explicit decoration persists through a real no-script native save.
	await evaluate(
		`${input("alternativeText__en")}.value='';document.querySelector('.admin-form').requestSubmit()`,
	);
	await ready(
		`${input("_revision")}?.value===${JSON.stringify(String(Number(revision) + 2))} && !document.querySelector('[role=alert]')`,
	);
	assert.equal(await evaluate(`${input("decorative")}.checked`), true);
	await evaluate(
		`for(const [name,value] of Object.entries(${JSON.stringify(original)}))document.querySelector('[name="'+name+'"]').value=value;${input("decorative")}.checked=false;document.querySelector('.admin-form').requestSubmit()`,
	);
	await ready(
		`${input("_revision")}?.value===${JSON.stringify(String(Number(revision) + 3))}`,
	);
	await navigate("/media", "#media-files");
	await evaluate(
		"document.querySelector('#media-owner').value=[...document.querySelectorAll('#media-owner option')].find(o=>o.value.startsWith('project/10000000000000000000000000000001/')).value",
	);
	const { root } = await command("DOM.getDocument");
	const { nodeId } = await command("DOM.querySelector", {
		nodeId: root.nodeId,
		selector: "#media-files",
	});
	await command("DOM.setFileInputFiles", {
		nodeId,
		files: [
			resolve(
				"profiles/reference-studio/fixtures/media/d0000000000000000000000000000001-display_1600_webp.webp",
			),
		],
	});
	await evaluate(
		"document.querySelector('form[action=\"/media/upload\"]').requestSubmit()",
	);
	await ready(
		"location.pathname==='/media/upload' && document.querySelector('[role=status]')",
	);
	assert.match(
		await evaluate("document.querySelector('[role=status]').textContent"),
		/1 image\(s\) accepted/,
	);
	await navigate("/media", ".admin-media-grid");
	assert.ok(
		await evaluate(
			"[...document.querySelectorAll('.admin-card strong')].some(c=>c.textContent==='d0000000000000000000000000000001-display_1600_webp.webp')",
		),
	);
	assert.deepEqual(browserErrors, []);
	console.log(
		"PASS real Chromium no-script media alternatives, captions, credits, conflicts, hostile text, decorative flag and library uploads.",
	);
} finally {
	await command("Emulation.setScriptExecutionDisabled", { value: false });
	await browser.close();
}
