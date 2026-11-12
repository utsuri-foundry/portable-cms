import assert from "node:assert/strict";

/** A fixture's saved edits need a separate native publication before a release test. */
export async function publishSavedRecord(browser) {
	const { evaluate, ready } = browser;
	await ready(
		"document.readyState==='complete' && document.querySelector('[name=_editorial_end]')?.value==='1'",
	);
	const revision = Number(
		await evaluate("document.querySelector('[name=_revision]').value"),
	);
	assert.ok(
		await evaluate("document.querySelector('#record-publish-form')"),
		"the saved fixture has an explicit publication action",
	);
	await evaluate(
		"document.querySelector('#record-publish-form').setAttribute('data-fixture-publication','');document.querySelector('#record-publish-form').requestSubmit()",
	);
	await ready(
		`document.readyState==='complete' && !document.querySelector('[data-fixture-publication]') && document.querySelector('[name=_revision]')?.value==='${revision + 1}' && !document.querySelector('[data-unpublished-changes]')`,
	);
}
