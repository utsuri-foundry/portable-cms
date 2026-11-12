import assert from "node:assert/strict";
import { connectLocalBrowser } from "./local-browser.mjs";

const origin = process.env.CMS_BROWSER_ORIGIN;
const browser = await connectLocalBrowser(origin, process.env.CMS_BROWSER_DEBUG_URL);
const { command, evaluate, navigate, ready, browserErrors } = browser;
const record = "/records/person/40000000000000000000000000000001";
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
	await ready("document.querySelector('.admin-navigation')");
	await command("Emulation.setScriptExecutionDisabled", { value: true });
	await command("Emulation.setDeviceMetricsOverride", {
		width: 360,
		height: 780,
		deviceScaleFactor: 1,
		mobile: false,
	});
	await navigate(record, "[name=website__en__url]");
	const original = await evaluate(
		"Object.fromEntries(new FormData(document.querySelector('[data-editorial-form]')))",
	);
	assert.equal(
		await evaluate("document.querySelector('[name=website__en__url]').type"),
		"url",
	);
	assert.equal(
		await evaluate("document.documentElement.scrollWidth<=innerWidth+1"),
		true,
	);
	assert.equal(
		await evaluate("document.querySelector('#field-website textarea')===null"),
		true,
	);
	await evaluate(
		"document.querySelector('[name=website__en__url]').value='http://example.test/new?a=1&b=2';document.querySelector('[name=website__en__linkText]').value='<b>Studio & editor</b>';document.querySelector('[data-editorial-form]').requestSubmit()",
	);
	await ready(
		"document.readyState==='complete' && document.querySelector('[name=website__en__url]')?.value==='http://example.test/new?a=1&b=2' && document.querySelector('[name=_revision]')?.value!==" +
			JSON.stringify(original._revision),
	);
	assert.equal(
		await evaluate("document.querySelector('[role=alert]')?.textContent ?? null"),
		null,
	);
	const revision = await evaluate("document.querySelector('[name=_revision]').value");
	await evaluate(
		"document.querySelector('[name=website__en__url]').value='javascript:alert(1)';document.querySelector('[data-editorial-form]').requestSubmit()",
	);
	await ready(
		"document.readyState==='complete' && document.querySelector('[role=alert]')",
	);
	assert.equal(
		await evaluate("document.querySelector('[name=_revision]').value"),
		revision,
	);
	assert.equal(
		await evaluate("document.querySelector('[name=website__en__url]').value"),
		"javascript:alert(1)",
	);
	assert.equal(
		await evaluate("document.querySelector('#field-website b')===null"),
		true,
	);
	await navigate(record, "[name=website__en__url]");
	assert.equal(
		await evaluate("document.querySelector('[name=website__en__url]').value"),
		"http://example.test/new?a=1&b=2",
	);
	const fields = Object.fromEntries(
		Object.entries(original).filter(([name]) => name.startsWith("website__")),
	);
	await evaluate(
		`for(const [name,value] of Object.entries(${JSON.stringify(fields)})) document.querySelector('[name="'+name+'"]').value=value;document.querySelector('[data-editorial-form]').requestSubmit()`,
	);
	await ready(
		"document.readyState==='complete' && document.querySelector('[name=_revision]')?.value!==" +
			JSON.stringify(revision),
	);
	assert.equal(
		await evaluate("document.querySelector('[name=website__en__url]').value"),
		original.website__en__url,
	);
	assert.deepEqual(browserErrors, []);
	console.log(
		"PASS native web addresses: no-JS localized save, 360px layout, escaped labels, hostile scheme rejection and preserved input",
	);
} finally {
	await browser.close();
}
