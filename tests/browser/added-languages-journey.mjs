import assert from "node:assert/strict";
import { connectLocalBrowser } from "./local-browser.mjs";

const origin = process.env.CMS_BROWSER_ORIGIN ?? "http://localhost:4182";
const debug = process.env.CMS_BROWSER_DEBUG_URL ?? "http://127.0.0.1:9223";
const browser = await connectLocalBrowser(origin, debug);
const { command, evaluate, ready, navigate, browserErrors } = browser;

/** Adds a catalog language from the add form and waits for the notice. */
async function add(locale) {
	await navigate("/settings/languages", "#languages-add");
	await evaluate(
		`document.querySelector('#languages-add').value=${JSON.stringify(locale)};document.querySelector('#languages-add').form.requestSubmit()`,
	);
	await ready(
		`location.search==='?notice=added' && document.querySelector('legend[lang=${locale}]')`,
	);
}

/** Presses the remove button of an added language and waits for the next page. */
async function remove(locale) {
	await navigate(
		"/settings/languages",
		`button[form=languages-remove][value=${locale}]`,
	);
	await evaluate(
		`document.querySelector('button[form=languages-remove][value=${locale}]').click()`,
	);
	await ready(
		"document.readyState==='complete' && (location.search==='?notice=removed' || document.querySelector('.admin-notice--danger'))",
	);
}

/** The language tabs of the record form. */
function tabs() {
	return evaluate(
		"[...document.querySelectorAll('[data-record-locale-switcher] [data-locale-tab]')].map(b => b.dataset.localeTab)",
	);
}

try {
	await command("Page.enable");
	await command("Runtime.enable");
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

	// Reference Studio's Latin, left-to-right design lets the administrator add Italian.
	await navigate("/settings/languages", "#languages-add");
	assert.equal(
		await evaluate(
			"document.querySelector('#languages-add option[value=it]')?.textContent",
		),
		"Italiano",
		"the catalog names each language in itself",
	);
	assert.equal(
		await evaluate("document.querySelector('#languages-add option[value=es]')"),
		null,
		"the profile's own languages are not offered",
	);
	await add("it");
	assert.equal(
		await evaluate(
			"document.querySelector('[name=state__it][value=preparation]').checked",
		),
		true,
		"an added language starts in preparation",
	);

	// Editors write in it at once, without it being required.
	await navigate("/records/discipline", ".admin-table__title");
	const recordPath = await evaluate(
		"document.querySelector('.admin-table__title').getAttribute('href')",
	);
	await navigate(recordPath, "[data-editorial-form]");
	assert.deepEqual(
		await tabs(),
		["ca", "es", "en", "it"],
		"the record form offers the added language",
	);
	assert.equal(
		await evaluate("document.querySelector('[name=label__it]').required"),
		false,
		"a language in preparation is never required",
	);
	const revision = await evaluate("document.querySelector('[name=_revision]').value");
	await evaluate(
		"document.querySelector('[name=label__it]').value='Etichetta italiana';document.querySelector('[data-editorial-form]').requestSubmit()",
	);
	await ready(
		`document.querySelector('[data-save-status]')?.textContent.match(/Saved at |No unsaved changes/) && document.querySelector('[name=_revision]').value !== ${JSON.stringify(revision)}`,
	);
	assert.equal(
		await evaluate("document.querySelector('[name=label__it]').value"),
		"Etichetta italiana",
	);
	await navigate("/settings/wording", "[name=wording__page-home__it]");

	// Italian now has text, so it can only be turned off; its text stays.
	await remove("it");
	assert.match(
		await evaluate(
			"document.querySelector('.admin-notice--danger')?.textContent ?? ''",
		),
		/Italiano already has text/,
		"a language with text is not removed",
	);
	await navigate("/settings/languages", "[name=primary]");
	const languages = await evaluate("document.querySelector('[name=_revision]').value");
	await evaluate(
		"document.querySelector('[name=state__it][value=off]').checked=true;document.querySelector('[name=confirmed]').checked=true;document.querySelector('[name=primary]').form.requestSubmit()",
	);
	await ready(
		`location.search==='?saved=1' && document.querySelector('[name=_revision]')?.value===${JSON.stringify(String(Number(languages) + 1))}`,
	);
	await navigate(recordPath, "[data-editorial-form]");
	assert.deepEqual(
		await tabs(),
		["ca", "es", "en"],
		"an added language that is off is hidden like any other",
	);

	// A language added by mistake, with nothing written in it, is removed again.
	await add("pt");
	await remove("pt");
	assert.equal(
		await evaluate("location.search"),
		"?notice=removed",
		"a language without text is removed",
	);
	assert.equal(
		await evaluate(
			"document.querySelector('legend[lang=pt]') === null && document.querySelector('#languages-add option[value=pt]')?.textContent === 'Português'",
		),
		true,
		"the removed language is offered again",
	);
	assert.deepEqual(browserErrors, []);
	console.log(
		"PASS added languages: an administrator adds Italian, editors write in it, and a language is removed only while empty",
	);
} finally {
	await browser.close();
}
