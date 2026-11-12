import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { connectLocalBrowser } from "./local-browser.mjs";

const origin = process.env.CMS_BROWSER_ORIGIN;
const debug = process.env.CMS_BROWSER_DEBUG_URL;
assert.ok(origin && debug && process.env.CMS_BROWSER_WORKER_CONFIG);
const browser = await connectLocalBrowser(origin, debug);
const { command, evaluate, ready, navigate, browserErrors } = browser;
const record = "/records/article/30000000000000000000000000000001";
const title = "A saved edit waiting for publication";
async function save(value) {
	const revision = Number(
		await evaluate("document.querySelector('[name=_revision]').value"),
	);
	await evaluate(
		`document.querySelector('[data-editorial-form]').setAttribute('data-published-submission','');document.querySelector('[name=title__en]').value=${JSON.stringify(value)};document.querySelector('[data-editorial-form]').requestSubmit()`,
	);
	await ready(
		`document.readyState==='complete' && !document.querySelector('[data-published-submission]') && document.querySelector('[name=_editorial_end]')?.value==='1' && document.querySelector('[name=_revision]')?.value==='${revision + 1}'`,
	);
}
async function preview() {
	await navigate("/previews", 'form[action="/previews"]');
	await evaluate(
		"document.querySelector('form[action=\"/previews\"]').requestSubmit()",
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
			"--max-jobs=3",
			"--max-seconds=20",
			"--worker-id=published-record-browser",
		],
		{ timeout: 30000 },
	);
	await navigate(path, "[data-preview-state]");
	assert.equal(
		await evaluate(
			"document.querySelector('[data-preview-state]').dataset.previewState",
		),
		"ready",
	);
	await navigate(`${path}/artifact/en/articles/measuring-shade/`, "h1");
	return {
		path,
		heading: await evaluate("document.querySelector('h1').textContent.trim()"),
	};
}
try {
	await command("Page.enable");
	await command("Runtime.enable");
	await command("Emulation.setScriptExecutionDisabled", { value: true });
	await navigate("/login", "[name=login]");
	await evaluate(
		"document.querySelector('[name=login]').value='admin@portable.test';document.querySelector('[name=password]').value='portable-cms-local';document.querySelector('form').requestSubmit()",
	);
	await ready("location.pathname==='/' && document.querySelector('.admin-navigation')");
	await navigate(record, "[data-editorial-form]");
	const original = await evaluate("document.querySelector('[name=title__en]').value");
	const publishedRevision = await evaluate(
		"document.querySelector('[data-published-revision]').dataset.publishedRevision",
	);
	await save(title);
	assert.ok(await evaluate("document.querySelector('[data-unpublished-changes]')"));
	assert.equal(
		await evaluate(
			"document.querySelector('[data-published-revision]').dataset.publishedRevision",
		),
		publishedRevision,
	);
	assert.match(
		await evaluate("document.querySelector('main').textContent"),
		/Published, with changes/,
	);
	await command("Page.navigate", {
		url: `${origin}/records/article?status=published_changes`,
	});
	await ready(
		"location.pathname==='/records/article' && location.search==='?status=published_changes' && document.querySelector('tbody a')",
	);
	assert.ok(await evaluate(`document.querySelector('tbody a[href="${record}"]')`));
	await navigate("/previews", "[data-pending-publication]");
	assert.equal(
		await evaluate(
			"document.querySelector('[data-pending-publication]').dataset.pendingPublication",
		),
		"1",
	);
	const before = await preview();
	assert.equal(
		before.heading,
		original,
		"a saved edit does not enter a published-only preview",
	);
	await navigate(record, "[data-publish-changes]");
	const current = Number(
		await evaluate("document.querySelector('[name=_revision]').value"),
	);
	await evaluate("document.querySelector('#record-publish-form').requestSubmit()");
	await ready(
		`document.querySelector('[name=_revision]')?.value==='${current + 1}' && !document.querySelector('[data-unpublished-changes]') && document.readyState==='complete'`,
	);
	assert.equal(
		await evaluate(
			"document.querySelector('[data-published-revision]').dataset.publishedRevision",
		),
		String(current + 1),
	);
	const after = await preview();
	assert.equal(
		after.heading,
		title,
		"Publish changes makes the saved version eligible for release",
	);
	await navigate(`${before.path}/artifact/en/articles/measuring-shade/`, "h1");
	assert.equal(
		await evaluate("document.querySelector('h1').textContent.trim()"),
		original,
		"the earlier exact preview stays immutable",
	);
	await navigate(record, "[data-editorial-form]");
	await save("Discard this later edit");
	assert.equal(
		await evaluate("document.querySelector('#record-discard-form').checkValidity()"),
		false,
		"discard requires its own native confirmation",
	);
	const edited = Number(
		await evaluate("document.querySelector('[name=_revision]').value"),
	);
	await evaluate(
		"document.querySelector('[form=record-discard-form][name=_confirmed]').checked=true;document.querySelector('#record-discard-form').requestSubmit()",
	);
	await ready(
		`document.querySelector('[name=_revision]')?.value==='${edited + 1}' && !document.querySelector('[data-unpublished-changes]') && document.readyState==='complete'`,
	);
	assert.equal(
		await evaluate("document.querySelector('[name=title__en]').value"),
		title,
		"discard restores the published words",
	);
	await navigate("/previews", "[data-pending-publication]");
	assert.equal(
		await evaluate(
			"document.querySelector('[data-pending-publication]').dataset.pendingPublication",
		),
		"0",
	);
	assert.deepEqual(browserErrors, []);
	process.stdout.write(
		"PASS published record browser: saved edits remain outside published-only previews, pending status/filter/count, explicit Publish changes, immutable former preview and confirmed Discard without scripts.\n",
	);
} finally {
	await browser.close();
}
