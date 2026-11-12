import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { connectLocalBrowser } from "./local-browser.mjs";

const origin = process.env.CMS_BROWSER_ORIGIN;
const debug = process.env.CMS_BROWSER_DEBUG_URL;
assert.ok(origin && debug && process.env.CMS_BROWSER_WORKER_CONFIG);
const browser = await connectLocalBrowser(origin, debug);
const other = await connectLocalBrowser(origin, debug);
const record = "/records/article/30000000000000000000000000000001";
const { command, evaluate, ready, navigate } = browser;
async function login(tab) {
	await tab.navigate("/login", "main");
	if (await tab.evaluate("!!document.querySelector('[name=login]')")) {
		await tab.evaluate(
			"document.querySelector('[name=login]').value='admin@portable.test';document.querySelector('[name=password]').value='portable-cms-local';document.querySelector('form').requestSubmit()",
		);
		await tab.ready(
			"location.pathname==='/' && document.querySelector('.admin-navigation')",
		);
	}
}
async function type(tab, name, value) {
	await tab.evaluate(
		`(() => { const input=document.querySelector('[name="${name}"]');input.value=${JSON.stringify(value)};input.dispatchEvent(new Event('input',{bubbles:true})); })()`,
	);
}
async function saved(tab, previous) {
	await tab.ready(
		`document.querySelector('[name=_edit_sequence]')?.value !== ${JSON.stringify(previous)} && document.querySelector('[data-save-status]')?.textContent.startsWith('Saved at ')`,
	);
}
try {
	for (const tab of [browser, other]) {
		await tab.command("Page.enable");
		await tab.command("Runtime.enable");
		await tab.command("Network.enable");
	}
	await command("Emulation.setDeviceMetricsOverride", {
		width: 1440,
		height: 900,
		deviceScaleFactor: 1,
		mobile: false,
	});
	await login(browser);
	await navigate(record, '[data-keeping="true"]');
	assert.equal(
		await evaluate("document.querySelector('[data-record-save]').hidden"),
		true,
		"scripted editor has no visible Save button",
	);
	assert.equal(
		await evaluate("document.querySelector('[data-record-save]').checkVisibility()"),
		false,
	);
	let sequence = await evaluate(
		"document.querySelector('[name=_edit_sequence]').value",
	);
	await type(browser, "title__en", "Kept automatically in the browser");
	await saved(browser, sequence);
	const revision = await evaluate("document.querySelector('[name=_revision]').value");
	sequence = await evaluate("document.querySelector('[name=_edit_sequence]').value");
	await type(
		browser,
		"excerpt__en",
		"The next edit belongs to the same editing revision.",
	);
	await saved(browser, sequence);
	assert.equal(
		await evaluate("document.querySelector('[name=_revision]').value"),
		revision,
		"consecutive changes are one revision",
	);
	await navigate(record, '[data-keeping="true"]');
	assert.equal(
		await evaluate("document.querySelector('[name=title__en]').value"),
		"Kept automatically in the browser",
		"reload reads kept content",
	);
	const maximum = await evaluate(
		"document.querySelector('[name=title__en]').maxLength",
	);
	await type(browser, "title__en", "x".repeat(maximum + 1));
	await type(browser, "excerpt__en", "Valid text survives an invalid sibling field.");
	await ready(
		"document.querySelector('[data-save-status]').textContent.startsWith('Not saved:') && document.querySelector('[data-keep-error]')",
	);
	assert.equal(
		await evaluate("document.querySelector('[name=title__en]').value.length"),
		maximum + 1,
		"invalid text stays in the page",
	);
	await other.navigate(record, '[data-keeping="true"]');
	assert.equal(
		await other.evaluate("document.querySelector('[name=excerpt__en]').value"),
		"Valid text survives an invalid sibling field.",
		"valid fields still reach MariaDB",
	);
	sequence = await evaluate("document.querySelector('[name=_edit_sequence]').value");
	await type(browser, "title__en", "Corrected title");
	await saved(browser, sequence);
	assert.equal(
		await evaluate("document.querySelectorAll('[data-keep-error]').length"),
		0,
	);
	await command("Network.emulateNetworkConditions", {
		offline: true,
		latency: 0,
		downloadThroughput: -1,
		uploadThroughput: -1,
	});
	await type(browser, "title__en", "Typed while offline");
	await ready(
		"document.querySelector('[data-save-status]').textContent.startsWith('Not saved:')",
	);
	await evaluate("document.querySelector('[name=title__en]').focus()");
	for (const type of ["keyDown", "keyUp"])
		await command("Input.dispatchKeyEvent", {
			type,
			key: "Enter",
			code: "Enter",
			windowsVirtualKeyCode: 13,
		});
	assert.equal(
		await evaluate("location.pathname"),
		record,
		"Enter keeps edits in the page during a network failure",
	);
	assert.equal(
		await evaluate("document.querySelector('[name=title__en]').value"),
		"Typed while offline",
	);
	sequence = await evaluate("document.querySelector('[name=_edit_sequence]').value");
	await command("Network.emulateNetworkConditions", {
		offline: false,
		latency: 0,
		downloadThroughput: -1,
		uploadThroughput: -1,
	});
	await evaluate("window.dispatchEvent(new Event('online'))");
	await saved(browser, sequence);
	assert.equal(
		await evaluate("document.querySelector('[data-keep-retry]').hidden"),
		true,
	);
	execFileSync(
		"php",
		[
			"tests/php/expire-record-editing-session.php",
			process.env.CMS_BROWSER_WORKER_CONFIG,
		],
		{ timeout: 15000 },
	);
	await type(browser, "title__en", "Kept after signing in again");
	await ready(
		"document.querySelector('[data-save-status]').textContent.includes('Sign in again')",
	);
	await login(other);
	sequence = await evaluate("document.querySelector('[name=_edit_sequence]').value");
	await evaluate("window.dispatchEvent(new Event('focus'))");
	await saved(browser, sequence);
	assert.equal(
		await evaluate("document.querySelector('[name=title__en]').value"),
		"Kept after signing in again",
		"re-authentication needs no retyping",
	);
	await other.navigate(record, '[data-keeping="true"]');
	sequence = await evaluate("document.querySelector('[name=_edit_sequence]').value");
	await type(browser, "title__en", "First tab wins");
	await saved(browser, sequence);
	await type(other, "title__en", "Second tab preserves its own words");
	await other.ready(
		"document.querySelector('[data-conflict-summary]') && document.readyState==='complete'",
	);
	assert.equal(
		await other.evaluate("document.querySelector('[name=title__en]').value"),
		"Second tab preserves its own words",
	);
	assert.equal(
		await other.evaluate(
			"document.querySelector('[data-editorial-form]').dataset.keeping",
		),
		undefined,
		"automatic writes stop at a conflict",
	);
	await other.evaluate(
		"document.querySelector('[data-editorial-form]').dataset.conflictRetry='true';document.querySelector('[data-editorial-form]').requestSubmit(document.querySelector('[name=_editor_action][value^=\"search:\"]'))",
	);
	await other.ready(
		"document.querySelector('[data-conflict-summary]') && !document.querySelector('[data-conflict-retry]') && document.readyState==='complete'",
	);
	assert.equal(
		await other.evaluate("document.querySelector('[name=title__en]').value"),
		"Second tab preserves its own words",
		"picker actions retain the same-revision conflict and input",
	);
	await navigate(record, '[data-keeping="true"]');
	assert.equal(
		await evaluate("document.querySelector('[name=title__en]').value"),
		"First tab wins",
	);
	await command("Emulation.setDeviceMetricsOverride", {
		width: 360,
		height: 780,
		deviceScaleFactor: 1,
		mobile: false,
	});
	await navigate("/records/article/new", '[data-keeping="true"]');
	await type(browser, "title__en", "A newly kept draft");
	await ready(
		"/^\\/records\\/article\\/[a-f0-9]{32}$/.test(location.pathname) && document.querySelector('[data-keeping=true]') && document.readyState==='complete'",
	);
	assert.equal(
		await evaluate("document.querySelector('[name=title__en]').value"),
		"A newly kept draft",
	);
	assert.equal(
		await evaluate("document.documentElement.scrollWidth <= innerWidth"),
		true,
		"automatic save bar fits at 360px",
	);
	await command("Emulation.setScriptExecutionDisabled", { value: true });
	await navigate(record, "[data-record-save]");
	assert.equal(
		await evaluate("document.querySelector('[data-record-save]').hidden"),
		false,
		"native Save remains available without scripts",
	);
	await type(browser, "title__en", "Explicit native save still works");
	await evaluate(
		"document.querySelector('[data-editorial-form]').requestSubmit(document.querySelector('[data-record-save]'))",
	);
	await ready(
		"document.readyState==='complete' && document.querySelector('[data-save-status]').textContent.includes('Saved revision')",
	);
	await navigate(record, "[data-editorial-form]");
	assert.equal(
		await evaluate("document.querySelector('[name=title__en]').value"),
		"Explicit native save still works",
	);
	process.stdout.write(
		"PASS automatic record keeping browser: debounce/reload, one editing revision, partial validation, offline retry, expired session and other-tab sign-in, stale-tab conflict with retained input, new drafts, phone layout and native no-script save.\n",
	);
} finally {
	await command("Network.emulateNetworkConditions", {
		offline: false,
		latency: 0,
		downloadThroughput: -1,
		uploadThroughput: -1,
	});
	await command("Emulation.setScriptExecutionDisabled", { value: false });
	await browser.close();
	await other.close();
}
