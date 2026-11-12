import assert from "node:assert/strict";
import { connectLocalBrowser } from "./local-browser.mjs";

const origin = process.env.CMS_BROWSER_ORIGIN;
const debug = process.env.CMS_BROWSER_DEBUG_URL;
assert.ok(origin && debug);
const browser = await connectLocalBrowser(origin, debug);
const { command, evaluate, ready, navigate } = browser;
const name = `Élan Nord <Atelier> ${"x".repeat(160)}`;

async function signIn() {
	await navigate("/login", "[name=login]");
	await evaluate(
		"document.querySelector('[name=login]').value='admin@portable.test';document.querySelector('[name=password]').value='portable-cms-local';document.querySelector('form').requestSubmit()",
	);
	await ready("location.pathname==='/' && document.querySelector('.admin-sidebar')");
}

async function saveName(value) {
	await navigate("/settings/publication", "[name=websiteName]");
	const revision = await evaluate("document.querySelector('[name=_revision]').value");
	await evaluate(
		`const input=document.querySelector('[name=websiteName]');input.value=${JSON.stringify(value)};input.form.requestSubmit()`,
	);
	await ready(
		`location.pathname==='/settings/publication' && document.querySelector('[name=_revision]')?.value===${JSON.stringify(String(Number(revision) + 1))}`,
	);
}

try {
	await command("Page.enable");
	await command("Runtime.enable");
	await command("Emulation.setScriptExecutionDisabled", { value: true });
	await command("Emulation.setDeviceMetricsOverride", {
		width: 360,
		height: 800,
		deviceScaleFactor: 1,
		mobile: false,
	});
	await signIn();
	await navigate("/settings/publication", "[name=websiteName]");
	const original = await evaluate("document.querySelector('[name=websiteName]').value");
	await saveName(name);
	assert.deepEqual(
		await evaluate(
			"[...document.querySelectorAll('.admin-brand__name')].map(e=>e.textContent)",
		),
		[name, name],
	);
	assert.deepEqual(
		await evaluate(
			"[...document.querySelectorAll('.admin-brand .admin-mark')].map(e=>e.textContent)",
		),
		["ÉN", "ÉN"],
	);
	await navigate("/settings/installation", ".admin-installation-facts");
	const facts = await evaluate(
		"Object.fromEntries([...document.querySelectorAll('.admin-installation-facts dt')].map(e=>[e.textContent,e.nextElementSibling.textContent]))",
	);
	assert.equal(facts["Website name"], name);
	assert.equal(facts["Profile ID"], "reference-studio");
	assert.match(facts["Site version (profile)"], /^\d+\.\d+\.\d+$/);
	assert.match(facts["Portable CMS engine version"], /^\d+\.\d+\.\d+$/);
	assert.equal(
		facts["Installed CMS release"],
		"Development checkout (no installed release)",
	);
	assert.equal(
		await evaluate("document.querySelector('.admin-brand__meta').textContent"),
		`Site ${facts["Site version (profile)"]}`,
	);
	assert.equal(
		await evaluate("document.documentElement.scrollWidth<=innerWidth"),
		true,
		"long saved names wrap within a narrow settings page",
	);
	await evaluate("document.querySelector('form[action=\"/logout\"]').requestSubmit()");
	await ready("location.pathname==='/login' && document.querySelector('[name=login]')");
	assert.equal(
		await evaluate("document.querySelector('.admin-auth__brand strong').textContent"),
		name,
	);
	assert.equal(
		await evaluate("document.documentElement.scrollWidth<=innerWidth"),
		true,
		"long names also fit on the sign-in page without scripts",
	);
	await signIn();
	await saveName(original);
	// Leave the shared browser signed out, as it was before this journey.
	await evaluate("document.querySelector('form[action=\"/logout\"]').requestSubmit()");
	await ready("location.pathname==='/login' && document.querySelector('[name=login]')");
	console.log(
		"PASS installation identity: native name save, both shell brands, monogram, separate versions, private details and narrow no-script sign-in.",
	);
} finally {
	await browser.close();
}
