import assert from "node:assert/strict";
import { connectLocalBrowser } from "./local-browser.mjs";

const origin = process.env.CMS_BROWSER_ORIGIN;
const debug = process.env.CMS_BROWSER_DEBUG_URL;
assert.ok(origin && debug);
const browser = await connectLocalBrowser(origin, debug);
const { command, evaluate, ready, navigate, browserErrors } = browser;
const project = "/records/project/10000000000000000000000000000001";
const field = "wording__metadata-year__ca";
const settled =
	"!document.querySelector('[data-live-preview]').hasAttribute('aria-busy') && !!document.querySelector('[data-live-frame][data-live-active]')?.getAttribute('src')?.startsWith('/live-previews/')";

/** Waits for the saved notice of the next wording revision and returns the field's value. */
async function saved(revision) {
	await ready(
		`location.search==='?saved=1' && document.querySelector('[name=_revision]')?.value===${JSON.stringify(String(Number(revision) + 1))}`,
	);
	return evaluate(`document.querySelector('[name=${field}]').value`);
}

/** The labels of the project's metadata in the Catalan live preview of the project. */
async function liveLabels() {
	await navigate(project, "[data-editorial-form]");
	if (
		(await evaluate(
			"document.querySelector('[data-live-toggle]').getAttribute('aria-pressed')",
		)) !== "true"
	) {
		await evaluate("document.querySelector('[data-live-toggle]').click()");
	}
	await ready(settled);
	return evaluate(
		"fetch(document.querySelector('[data-live-frame][data-live-active]').getAttribute('src')).then(r => r.text()).then(html => [...new DOMParser().parseFromString(html, 'text/html').querySelectorAll('.studio-metadata dt')].map(d => d.textContent))",
	);
}

try {
	await command("Page.enable");
	await command("Runtime.enable");
	await command("Emulation.setDeviceMetricsOverride", {
		width: 1440,
		height: 900,
		deviceScaleFactor: 1,
		mobile: false,
	});
	await command("Page.navigate", { url: `${origin}/login` });
	await ready(
		"document.readyState==='complete' && (document.querySelector('[name=login]') || document.querySelector('.admin-navigation'))",
	);
	if (await evaluate("document.querySelector('[name=login]') !== null")) {
		await evaluate(
			"document.querySelector('[name=login]').value='admin@portable.test';document.querySelector('[name=password]').value='portable-cms-local';document.querySelector('form').requestSubmit()",
		);
		await ready(
			"location.pathname==='/' && document.querySelector('.admin-navigation')",
		);
	}
	await navigate("/settings/wording", `[name=${field}]`);
	const previous = await evaluate(
		"({choice:document.querySelector('#admin-language').value,language:document.documentElement.lang})",
	);
	await evaluate(
		"const s=document.querySelector('#admin-language');s.value='ca';s.form.requestSubmit()",
	);
	await ready(
		"document.readyState==='complete' && document.documentElement.lang==='ca' && document.querySelector('#admin-language')?.value==='ca'",
	);
	assert.deepEqual(
		await evaluate(
			"(()=>{const e=document.querySelector('[data-wording-key=\"metadata.year\"]');return [e.querySelector('legend').textContent,e.querySelector('.admin-help').textContent]})()",
		),
		["Etiqueta de l’any", "L’etiqueta de l’any d’un projecte."],
		"wording is named and explained in the Catalan administration",
	);
	assert.deepEqual(
		await liveLabels(),
		["Any", "Estat", "Fotografia"],
		"the live preview starts with the profile's labels and the authored credit role",
	);

	// An editor corrects the Catalan year label on the site wording page.
	await navigate("/settings/wording", `[name=${field}]`);
	assert.equal(
		await evaluate(
			"document.querySelector('a[href=\"/settings/wording\"]')?.getAttribute('aria-current')",
		),
		"page",
		"the page is in the Website navigation",
	);
	assert.equal(
		await evaluate(`document.querySelector('[name=${field}]').value`),
		"Any",
	);
	const revision = await evaluate("document.querySelector('[name=_revision]').value");
	await evaluate(
		`document.querySelector('[name=${field}]').value='Any de l’obra';document.querySelector('[name=${field}]').form.requestSubmit()`,
	);
	assert.equal(await saved(revision), "Any de l’obra", "the corrected label is saved");
	assert.equal(
		await evaluate(`document.querySelector('#hint-${field}')?.textContent`),
		"Text del perfil: Any",
		"the profile's text stays visible beside the correction",
	);
	assert.deepEqual(
		await liveLabels(),
		["Any de l’obra", "Estat", "Fotografia"],
		"the live preview shows the corrected label without changing the authored credit role",
	);

	// Using the profile text again restores the default everywhere.
	await navigate("/settings/wording", `[name=${field}]`);
	const corrected = await evaluate("document.querySelector('[name=_revision]').value");
	await evaluate(
		"document.querySelector('[data-wording-key=\"metadata.year\"] button[name=_reset]').click()",
	);
	assert.equal(await saved(corrected), "Any", "the label follows the profile again");
	assert.equal(
		await evaluate(
			"document.querySelector('[data-wording-key=\"metadata.year\"] button[name=_reset]')",
		),
		null,
		"a key that follows the profile offers no reset",
	);
	assert.deepEqual(
		await liveLabels(),
		["Any", "Estat", "Fotografia"],
		"the live preview follows the profile again",
	);
	await navigate("/settings/wording", `[name=${field}]`);
	await evaluate(
		`const s=document.querySelector('#admin-language');s.value=${JSON.stringify(previous.choice)};s.form.requestSubmit()`,
	);
	await ready(
		`document.readyState==='complete' && document.documentElement.lang===${JSON.stringify(previous.language)} && document.querySelector('#admin-language')?.value===${JSON.stringify(previous.choice)}`,
	);
	assert.deepEqual(browserErrors, []);
	console.log(
		"PASS site wording: Catalan administration labels and purposes, native caption correction, live preview and language preference restoration",
	);
} finally {
	await browser.close();
}
