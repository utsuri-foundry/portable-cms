import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { connectLocalBrowser } from "./local-browser.mjs";

const origin = process.env.CMS_BROWSER_ORIGIN ?? "http://localhost:4182";
const debug = process.env.CMS_BROWSER_DEBUG_URL ?? "http://127.0.0.1:9223";
const browser = await connectLocalBrowser(origin, debug);
const { command, evaluate, ready, navigate, browserErrors } = browser;
const saved = (revision) =>
	`document.querySelector('[data-save-status]')?.textContent.match(/Saved at |No unsaved changes/) && document.querySelector('[name=_revision]').value !== ${JSON.stringify(revision)}`;

/** Chooses each language's state and the primary language in Settings and waits for the saved notice. */
async function chooseLanguages(states, confirm, primary = "ca") {
	await navigate("/settings/languages", "[name=primary]");
	const revision = await evaluate("document.querySelector('[name=_revision]').value");
	await evaluate(
		`${Object.entries(states)
			.map(
				([locale, state]) =>
					`document.querySelector('[name=state__${locale}][value=${state}]').checked=true;`,
			)
			.join(
				"",
			)}document.querySelector('[name=primary]').value=${JSON.stringify(primary)};document.querySelector('[name=confirmed]').checked=${confirm};document.querySelector('[name=primary]').form.requestSubmit()`,
	);
	await ready(
		`location.search==='?saved=1' && document.querySelector('[name=_revision]')?.value===${JSON.stringify(String(Number(revision) + 1))}`,
	);
}

/** Builds a preview, optionally with the languages in preparation, and returns its private address. */
async function preview(includePreparation) {
	await navigate("/previews", "form[action='/previews']");
	await evaluate(
		`(() => { const form = document.querySelector('form[action="/previews"]'); const choice = form.querySelector('[name=include_preparation]'); if (choice) choice.checked = ${includePreparation}; form.requestSubmit(); })()`,
	);
	await ready(
		"location.pathname.match(/^\\/previews\\/[a-f0-9]{32}$/) && document.querySelector('[data-preview-state]')",
	);
	const path = await evaluate("location.pathname");
	execFileSync(
		"php",
		[
			"tools/conventional-media-worker.php",
			`--config=${process.env.CMS_BROWSER_WORKER_CONFIG}`,
			"--max-jobs=2",
			"--max-seconds=10",
			"--worker-id=languages-browser",
		],
		{ timeout: 20000 },
	);
	await navigate(path, "[data-preview-state]");
	await ready(
		"document.querySelector('[data-preview-state]')?.dataset.previewState==='ready'",
	);
	return path;
}

/** The status of a page of a preview, and its document language. */
function page(path, route) {
	return evaluate(
		`fetch(${JSON.stringify(`${path}/artifact/${route}`)}).then(async r => [r.status, r.ok ? new DOMParser().parseFromString(await r.text(), 'text/html').documentElement.lang : ''])`,
	);
}

async function removePreview(path) {
	await navigate(path, "[data-preview-state]");
	await evaluate("document.querySelector('form[action$=\"/delete\"]').requestSubmit()");
	await ready("location.pathname==='/previews'");
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
	await navigate("/records/discipline", ".admin-table__title");
	const recordPath = await evaluate(
		"document.querySelector('.admin-table__title').getAttribute('href')",
	);
	await navigate(recordPath, "[data-editorial-form]");
	const labels = () =>
		evaluate(
			"Object.fromEntries([...document.querySelectorAll('#field-label input')].map(c => [c.name, c.value]))",
		);
	const original = await labels();
	assert.deepEqual(Object.keys(original), ["label__ca", "label__es", "label__en"]);

	// Turning a language off without confirmation is refused and changes nothing.
	await navigate("/settings/languages", "[name=primary]");
	await evaluate(
		"document.querySelector('[name=state__en][value=off]').checked=true;document.querySelector('[name=primary]').form.requestSubmit()",
	);
	await ready("document.querySelector('.admin-notice--danger')");
	assert.equal(
		await evaluate("document.querySelector('[name=state__en][value=off]').checked"),
		true,
		"the refused choice is kept for correction",
	);
	await chooseLanguages({ es: "preparation", en: "off" }, true);

	// The record form follows: English is hidden, Spanish is edited but never required.
	await navigate(recordPath, "[data-editorial-form]");
	assert.deepEqual(
		await evaluate(
			"[...document.querySelectorAll('[data-record-locale-switcher] [data-locale-tab]')].map(b => b.dataset.localeTab)",
		),
		["ca", "es"],
		"the language switch shows the languages in use",
	);
	assert.deepEqual(
		await evaluate(
			"[...document.querySelectorAll('#field-label input')].map(c => [c.name, c.required])",
		),
		[
			["label__ca", true],
			["label__es", false],
		],
		"only the public language is required",
	);
	const revision = await evaluate("document.querySelector('[name=_revision]').value");
	await evaluate(
		`document.querySelector('[name=label__ca]').value=${JSON.stringify(`${original.label__ca} nou`)};document.querySelector('[name=label__es]').value='';document.querySelector('[data-editorial-form]').requestSubmit()`,
	);
	await ready(saved(revision));

	// A preview for review may include the language in preparation; English stays out.
	await navigate("/previews", "form[action='/previews']");
	assert.match(
		await evaluate(
			"document.querySelector('[name=include_preparation]')?.parentElement.textContent ?? ''",
		),
		/Español/,
		"previews offer the languages in preparation by name",
	);
	const review = await preview(true);
	assert.equal(
		await evaluate(
			"document.querySelector('[data-preview-languages]')?.dataset.previewLanguages",
		),
		"preparation",
		"the review preview says it includes languages in preparation",
	);
	assert.deepEqual(
		await page(review, "es/"),
		[200, "es"],
		"the language in preparation is drawn for review",
	);
	assert.equal(
		(await page(review, "en/"))[0],
		404,
		"the language that is off is not drawn",
	);
	await removePreview(review);

	// A public language must be complete, so Spanish gets its label back before it becomes public.
	await navigate(recordPath, "[data-editorial-form]");
	const translated = await evaluate("document.querySelector('[name=_revision]').value");
	await evaluate(
		`document.querySelector('[name=label__es]').value=${JSON.stringify(original.label__es)};document.querySelector('[data-editorial-form]').requestSubmit()`,
	);
	await ready(saved(translated));

	// With Spanish public and primary, an exact preview publishes Catalan and Spanish, Spanish at the root.
	await chooseLanguages({ es: "public", en: "off" }, false, "es");
	const published = await preview(false);
	assert.deepEqual(
		await page(published, ""),
		[200, "es"],
		"the root shows the primary language",
	);
	assert.equal(
		(await page(published, "en/"))[0],
		404,
		"no page is published in the language that is off",
	);
	assert.equal(
		(await page(published, "search/en.json"))[0],
		404,
		"no search index either",
	);
	await removePreview(published);

	// Turning English on again needs no confirmation and brings its text back unchanged.
	await chooseLanguages({ es: "public", en: "public" }, false);
	await navigate(recordPath, "[data-editorial-form]");
	assert.deepEqual(
		await labels(),
		{
			label__ca: `${original.label__ca} nou`,
			label__es: original.label__es,
			label__en: original.label__en,
		},
		"English text survives a save while it was off",
	);
	const restored = await evaluate("document.querySelector('[name=_revision]').value");
	await evaluate(
		`document.querySelector('[name=label__ca]').value=${JSON.stringify(original.label__ca)};document.querySelector('[name=label__es]').value=${JSON.stringify(original.label__es)};document.querySelector('[data-editorial-form]').requestSubmit()`,
	);
	await ready(saved(restored));
	assert.deepEqual(await labels(), original);
	assert.deepEqual(browserErrors, []);
	console.log(
		"PASS site languages: turning a language off and on keeps its text and the form follows",
	);
} finally {
	await browser.close();
}
