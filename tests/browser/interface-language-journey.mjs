import assert from "node:assert/strict";
import { connectLocalBrowser } from "./local-browser.mjs";

const origin = process.env.CMS_BROWSER_ORIGIN ?? "http://localhost:4183";
const debug = process.env.CMS_BROWSER_DEBUG_URL ?? "http://127.0.0.1:9224";
const browser = await connectLocalBrowser(origin, debug);
const { command, evaluate, ready, navigate, browserErrors } = browser;
const recordPath = "/records/project/10000000000000000000000000000001";
const pages = [
	["/", ".admin-navigation"],
	["/records/project", ".admin-table__title"],
	[recordPath, "[data-editorial-form]"],
	["/media", ".admin-media-grid"],
	["/settings/design", "[data-design-form]"],
	["/settings/terminology", ".admin-form"],
];

async function viewport(width, height) {
	await command("Emulation.setDeviceMetricsOverride", {
		width,
		height,
		deviceScaleFactor: 1,
		mobile: false,
	});
}

/** Submits the account menu's native language form and waits for the reloaded page. */
async function chooseLanguage(value, expectedLang) {
	await evaluate(
		`(()=>{const s=document.querySelector('#admin-language');s.value=${JSON.stringify(value)};s.form.requestSubmit();})()`,
	);
	await ready(
		`document.readyState==='complete' && document.documentElement.lang===${JSON.stringify(expectedLang)} && document.querySelector('#admin-language')?.value===${JSON.stringify(value)}`,
	);
}

/** The record editor leads with content, keeps Details together and opens pickers on demand. */
async function assertEditorStructure(label) {
	assert.equal(
		await evaluate(
			"(()=>{const ids=[...document.querySelectorAll('.admin-editor__main > [data-editorial-section]')].map(s=>s.dataset.editorialSection);return ids[0]==='content' && ids.indexOf('media')<ids.indexOf('layout') && ids.indexOf('layout')<ids.indexOf('relationships')})()",
		),
		true,
		`${label}: sections lead with content`,
	);
	assert.equal(
		await evaluate(
			"!!document.querySelector('.admin-editor__main #section-details [name=year]') && !document.querySelector('#section-properties')",
		),
		true,
		`${label}: Details stay together`,
	);
	assert.equal(
		await evaluate(
			"document.querySelectorAll('details.admin-picker__browse[open]').length",
		),
		0,
		`${label}: pickers open on demand`,
	);
	assert.equal(
		await evaluate(
			"!document.querySelector('[name=trailer]') && !document.querySelector('#ai-assistance')",
		),
		true,
		`${label}: unavailable editors and capabilities are hidden`,
	);
}

async function assertNoOverflow(label) {
	assert.equal(
		await evaluate("document.documentElement.scrollWidth <= innerWidth"),
		true,
		`${label} should not scroll sideways`,
	);
}

try {
	await command("Page.enable");
	await command("Runtime.enable");
	await command("Log.enable");
	await viewport(1440, 900);
	await command("Page.navigate", { url: `${origin}/login` });
	await ready(
		"document.readyState === 'complete' && (document.querySelector('[name=login]') || document.querySelector('.admin-navigation'))",
	);
	if (await evaluate("document.querySelector('[name=login]') !== null")) {
		await evaluate(
			"document.querySelector('[name=login]').value='admin@portable.test';document.querySelector('[name=password]').value='portable-cms-local';document.querySelector('form').requestSubmit()",
		);
	}
	await ready(
		"location.pathname === '/' && document.querySelector('.admin-navigation')",
	);
	assert.equal(await evaluate("document.documentElement.lang"), "en");
	const englishSkip = await evaluate(
		"document.querySelector('.admin-skip-link').textContent",
	);

	await chooseLanguage("de", "de");
	assert.notEqual(
		await evaluate("document.querySelector('.admin-skip-link').textContent"),
		englishSkip,
		"the shell is German",
	);
	// Profile labels follow the interface language; links keep their stable routes.
	assert.equal(
		await evaluate(
			"[...document.querySelectorAll('.admin-navigation__link')].some(a=>a.getAttribute('href')==='/records/project' && a.textContent.startsWith('Projekte'))",
		),
		true,
		"navigation shows the German profile label",
	);
	await navigate(recordPath, "[data-editorial-form]");
	assert.equal(
		await evaluate(
			"document.querySelector('#field-title legend, label[for^=title]')?.textContent.includes('Titel')",
		),
		true,
		"field labels show the German profile label",
	);
	for (const width of [1440, 360]) {
		await viewport(width, width === 360 ? 800 : 900);
		for (const [path, selector] of pages) {
			await navigate(path, selector);
			assert.equal(await evaluate("document.documentElement.lang"), "de", path);
			await assertNoOverflow(`German ${path} at ${width}px`);
			if (path === recordPath) await assertEditorStructure(`German at ${width}px`);
		}
	}

	// Content languages keep their own lang and never change the interface language.
	await viewport(1440, 900);
	await navigate(recordPath, "[data-editorial-form]");
	await ready(
		"document.querySelector('[data-record-locale-switcher]') && !document.querySelector('[data-record-locale-switcher]').hidden",
	);
	assert.equal(
		await evaluate(
			"[...document.querySelectorAll('[data-locale-panel]')].every(p=>p.lang===p.dataset.localePanel)",
		),
		true,
	);
	await evaluate("document.querySelector('[data-locale-tab=\"es\"]').click()");
	await ready(
		"document.querySelector('[data-editorial-form]').dataset.recordLocale==='es'",
	);
	assert.equal(await evaluate("document.documentElement.lang"), "de");
	// Islands speak the interface language from the server-rendered bundle.
	await evaluate(
		"(()=>{const i=document.querySelector('[name=title__es]');i.value=i.value+' ';i.dispatchEvent(new Event('input',{bubbles:true}));})()",
	);
	await ready(
		"(()=>{const b=JSON.parse(document.querySelector('[data-messages]').dataset.messages);return document.querySelector('[data-section-changes=\"content\"]').textContent===b['editor.changed'].replace('{count}','1');})()",
	);
	await navigate("/", ".admin-navigation");

	// The same choice works without script, including at phone width.
	await command("Emulation.setScriptExecutionDisabled", { value: true });
	await viewport(360, 800);
	for (const [path, selector] of pages.slice(0, 3)) {
		await navigate(path, selector);
		assert.equal(await evaluate("document.documentElement.lang"), "de", path);
		await assertNoOverflow(`German no-script ${path} at 360px`);
		if (path === recordPath)
			await assertEditorStructure("German without script at 360px");
	}
	await navigate("/", ".admin-navigation");
	await chooseLanguage("fr", "fr");
	await viewport(1440, 900);
	await navigate("/records/project", ".admin-table__title");
	assert.equal(await evaluate("document.documentElement.lang"), "fr");
	await assertNoOverflow("French no-script record list at 1440px");
	await command("Emulation.setScriptExecutionDisabled", { value: false });

	// System returns to browser negotiation; headless Chromium asks for English.
	await navigate("/", ".admin-navigation");
	await chooseLanguage("system", "en");
	await navigate(recordPath, "[data-editorial-form]");
	await assertEditorStructure("English at 1440px");
	await navigate("/", ".admin-navigation");
	assert.equal(
		await evaluate("document.querySelector('.admin-skip-link').textContent"),
		englishSkip,
	);
	assert.deepEqual(browserErrors, []);
	console.log(
		"PASS interface language journey: German and French shells, account choice and System, content language independence, translated islands, 360/1440px and no-script.",
	);
} finally {
	await command("Emulation.setScriptExecutionDisabled", { value: false }).catch(
		() => {},
	);
	await browser.close();
}
