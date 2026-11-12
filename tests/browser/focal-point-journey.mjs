import assert from "node:assert/strict";
import { connectLocalBrowser } from "./local-browser.mjs";

const origin = process.env.CMS_BROWSER_ORIGIN ?? "http://localhost:4183";
const debug = process.env.CMS_BROWSER_DEBUG_URL ?? "http://127.0.0.1:9224";
const browser = await connectLocalBrowser(origin, debug);
const { command, evaluate, ready, navigate, browserErrors } = browser;
const path = "/media/d0000000000000000000000000000001";
const values =
	"[document.querySelector('[name=focal_x]').value,document.querySelector('[name=focal_y]').value]";
try {
	await command("Page.enable");
	await command("Runtime.enable");
	await command("Log.enable");
	await command("Emulation.setScriptExecutionDisabled", { value: false });
	await command("Page.navigate", { url: `${origin}/login` });
	await ready(
		"['/login','/'].includes(location.pathname) && document.querySelector('[name=login], .admin-navigation') && document.readyState==='complete'",
	);
	if (await evaluate("document.querySelector('[name=login]') !== null")) {
		await evaluate(
			"document.querySelector('[name=login]').value='admin@portable.test';document.querySelector('[name=password]').value='portable-cms-local';document.querySelector('form').requestSubmit()",
		);
		await ready(
			"location.pathname==='/' && document.querySelector('.admin-navigation')",
		);
	}
	await navigate(path, "[data-focal-pointer]:not([hidden])");
	await ready("document.querySelector('[data-focal-surface] img').complete");
	await evaluate(
		"document.querySelector('[data-focal-surface]').scrollIntoView({block:'center'})",
	);
	assert.equal(
		await evaluate("document.querySelector('[data-focal-surface] img').complete"),
		true,
	);
	const rect = await evaluate(
		"(()=>{const r=document.querySelector('[data-focal-pointer]').getBoundingClientRect();return {x:r.x,y:r.y,width:r.width,height:r.height}})()",
	);
	for (const type of ["mousePressed", "mouseReleased"]) {
		await command("Input.dispatchMouseEvent", {
			type,
			button: "left",
			clickCount: 1,
			x: rect.x + rect.width * 0.25,
			y: rect.y + rect.height * 0.8,
		});
	}
	assert.deepEqual(await evaluate(values), ["25", "80"]);
	await evaluate("document.querySelector('[data-focal-pointer]').focus()");
	await command("Input.dispatchKeyEvent", {
		type: "keyDown",
		key: "ArrowRight",
		code: "ArrowRight",
		windowsVirtualKeyCode: 39,
	});
	await command("Input.dispatchKeyEvent", {
		type: "keyUp",
		key: "ArrowRight",
		code: "ArrowRight",
		windowsVirtualKeyCode: 39,
	});
	assert.deepEqual(await evaluate(values), ["26", "80"]);
	assert.equal(
		await evaluate("document.querySelector('[data-focal-marker]').getAttribute('cx')"),
		"26",
	);
	assert.match(
		await evaluate("document.querySelector('[data-focal-output]').textContent"),
		/26.*80/,
	);
	const crop = await evaluate(
		"(()=>{const i=document.querySelector('[data-focal-crop]');return {x:Number(i.getAttribute('x')),y:Number(i.getAttribute('y')),w:Number(i.getAttribute('width')),h:Number(i.getAttribute('height'))}})()",
	);
	assert.ok(Math.abs(crop.x - (400 - crop.w) * 0.26) < 0.001);
	assert.ok(Math.abs(crop.y - (240 - crop.h) * 0.8) < 0.001);
	const revision = await evaluate("document.querySelector('[name=_revision]').value");
	await evaluate("document.querySelector('.admin-form').requestSubmit()");
	await ready(
		`document.querySelector('[name=_revision]')?.value===${JSON.stringify(String(Number(revision) + 1))}`,
	);
	assert.deepEqual(await evaluate(values), ["26", "80"]);
	// Percentages, saved crop, conflicts and clearing remain available without scripts.
	await command("Emulation.setScriptExecutionDisabled", { value: true });
	await command("Emulation.setDeviceMetricsOverride", {
		width: 360,
		height: 900,
		deviceScaleFactor: 1,
		mobile: false,
	});
	await navigate(path, "[name=focal_x]");
	assert.equal(
		await evaluate("document.querySelector('[data-focal-pointer]').hidden"),
		true,
	);
	await evaluate(
		"document.querySelector('[name=focal_x]').value='85';document.querySelector('[name=focal_y]').value='20';document.querySelector('.admin-form').requestSubmit()",
	);
	await ready(
		`document.querySelector('[name=_revision]')?.value===${JSON.stringify(String(Number(revision) + 2))}`,
	);
	assert.deepEqual(await evaluate(values), ["85", "20"]);
	assert.equal(
		await evaluate("document.querySelector('[data-focal-marker]').getAttribute('cx')"),
		"85",
	);
	assert.equal(
		await evaluate("document.documentElement.scrollWidth<=innerWidth"),
		true,
	);
	await evaluate(
		`document.querySelector('[name=_revision]').value=${JSON.stringify(revision)};document.querySelector('[name=focal_x]').value='12';document.querySelector('.admin-form').requestSubmit()`,
	);
	await ready("document.querySelector('[role=alert]')");
	assert.deepEqual(await evaluate(values), ["12", "20"]);
	await navigate(path, "[name=focal_x]");
	assert.deepEqual(await evaluate(values), ["85", "20"]);
	await evaluate(
		"document.querySelector('[name=focal_reset]').checked=true;document.querySelector('.admin-form').requestSubmit()",
	);
	await ready(
		`document.querySelector('[name=_revision]')?.value===${JSON.stringify(String(Number(revision) + 3))}`,
	);
	assert.deepEqual(await evaluate(values), ["50", "50"]);
	assert.deepEqual(browserErrors, []);
	process.stdout.write(
		"PASS image focal point: pointer and keyboard selection, live crop, native save, no-script percentages, conflicts, clearing and 360px layout.\n",
	);
} finally {
	await command("Emulation.setScriptExecutionDisabled", { value: false });
	await command("Emulation.clearDeviceMetricsOverride");
	await browser.close();
}
