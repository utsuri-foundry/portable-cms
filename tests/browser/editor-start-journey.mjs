import assert from "node:assert/strict";
import { connectLocalBrowser } from "./local-browser.mjs";

const origin = process.env.CMS_BROWSER_ORIGIN;
const debug = process.env.CMS_BROWSER_DEBUG_URL;
assert.ok(origin && debug);
let hold = false;
let fault = null;
let faultCount = 0;
const held = new Set();
const transportErrors = [];
const browser = await connectLocalBrowser(origin, debug, (method, params) => {
	if (method !== "Fetch.requestPaused") return;
	const path = new URL(params.request.url).pathname;
	const { requestId } = params;
	let result;
	if (fault && path.endsWith(fault.file)) {
		faultCount++;
		result = fault.throw
			? browser.command("Fetch.fulfillRequest", {
					requestId,
					responseCode: 200,
					responseHeaders: [{ name: "Content-Type", value: "text/javascript" }],
					body: Buffer.from('throw new Error("fixture startup failure");').toString(
						"base64",
					),
				})
			: browser.command("Fetch.failRequest", { requestId, errorReason: "Failed" });
	} else if (hold && !path.endsWith("/admin-start.js")) {
		held.add(requestId);
		return;
	} else result = browser.command("Fetch.continueRequest", { requestId });
	result.catch((error) => transportErrors.push(error));
});
// First-paint probes intentionally run while enhancement responses are held.
const { command, evaluate, readyDuringLoad: ready, navigate } = browser;
const record = "/records/article/30000000000000000000000000000001";
const visible = (selector) =>
	`!!document.querySelector(${JSON.stringify(selector)})?.checkVisibility()`;
async function accessibleNames(labels) {
	const tree = await command("Accessibility.getFullAXTree");
	const names = tree.nodes
		.filter((node) => !node.ignored && node.role?.value === "button")
		.map((node) => node.name?.value);
	for (const fallback of ["Save draft", "Save changes", "Preview changes"])
		assert.equal(
			names.includes(fallback),
			false,
			"native fallbacks stay outside the accessibility tree",
		);
	return names.filter((name) => labels.includes(name)).sort();
}
const probe = `(() => {
	window.editorPaints = [];
	window.editorPaintObserver = new PerformanceObserver(list => {
		for (const entry of list.getEntries()) if (entry.name === 'first-contentful-paint') window.editorFirstPaint = entry.startTime;
	});
	window.editorPaintObserver.observe({type:'paint', buffered:true});
	const sample = () => {
		const bar = document.querySelector('.admin-save-bar');
		if (bar?.checkVisibility()) {
			const shown = selector => !!document.querySelector(selector)?.checkVisibility();
			const bounds = bar.getBoundingClientRect();
			window.editorPaints.push({
				save:shown('[data-record-save]'), fallback:shown('[data-live-fallback]'),
				live:shown('[data-live-toggle]'), exact:shown('[data-exact-preview]'),
				locales:shown('[data-record-locale-switcher]'), note:shown('[data-exact-save-first]'),
				width:bounds.width, height:bounds.height,
				status:document.querySelector('[data-save-status]').innerText,
				buttons:[...bar.querySelectorAll('button')].filter(button=>button.checkVisibility()).map(button=>button.textContent),
			});
		}
		if (window.editorPaints.length < 600) requestAnimationFrame(sample);
	};
	requestAnimationFrame(sample);
})()`;

async function nativeBar() {
	await ready("document.readyState === 'complete'");
	assert.equal(await evaluate(visible("[data-record-save]")), true);
	assert.equal(await evaluate(visible("[data-live-fallback]")), true);
	assert.equal(await evaluate(visible("[data-live-toggle]")), false);
	assert.equal(await evaluate(visible("[data-record-locale-switcher]")), false);
	assert.equal(await evaluate(visible("[data-exact-preview]")), true);
	assert.equal(await evaluate(visible("[data-exact-save-first]")), true);
	assert.equal(
		await evaluate("document.querySelector('[data-editorial-form]').noValidate"),
		false,
	);
	assert.equal(
		await evaluate(
			"[...document.querySelectorAll('[data-locale-panel]')].every(panel=>!panel.hidden && getComputedStyle(panel).display!=='none')",
		),
		true,
		"native language fields remain reachable",
	);
}

try {
	for (const domain of ["Page", "Runtime", "Network", "Log"])
		await command(`${domain}.enable`);
	await command("Emulation.setDeviceMetricsOverride", {
		width: 1440,
		height: 1000,
		deviceScaleFactor: 1,
		mobile: false,
	});
	await navigate("/login", "main");
	await evaluate(
		"document.querySelector('[name=login]').value='admin@portable.test';document.querySelector('[name=password]').value='portable-cms-local';document.querySelector('form').requestSubmit()",
	);
	await ready("location.pathname==='/' && document.readyState==='complete'");
	await command("Network.setCacheDisabled", { cacheDisabled: true });
	await command("Fetch.enable", {
		patterns: [{ urlPattern: "*/assets/*.js*", resourceType: "Script" }],
	});
	await command("Page.addScriptToEvaluateOnNewDocument", { source: probe });
	for (const width of [1440, 360]) {
		await command("Emulation.setDeviceMetricsOverride", {
			width,
			height: 1000,
			deviceScaleFactor: 1,
			mobile: false,
		});
		hold = true;
		await command("Page.navigate", { url: origin + record });
		await ready("window.editorFirstPaint > 0 && window.editorPaints?.length >= 20");
		assert.ok(held.size >= 2, "both enhancement entries are held after first paint");
		assert.notEqual(await evaluate("document.readyState"), "complete");
		const before = await evaluate("window.editorPaints");
		for (const sample of before) {
			assert.equal(
				sample.save,
				false,
				"native Save never paints during delayed startup",
			);
			assert.equal(sample.fallback, false, "native Preview changes never paints");
			assert.equal(sample.live && sample.exact && sample.locales, true);
			assert.equal(sample.note, false);
			assert.match(sample.status, /^Saved at /);
		}
		const names = await evaluate(
			"[...document.querySelectorAll('[data-locale-tab]')].map(button=>({name:button.textContent,tabIndex:button.tabIndex,pressed:button.getAttribute('aria-pressed')}))",
		);
		const labels = [
			...names.map((button) => button.name.trim()),
			"Live preview",
			"Open exact preview",
		].sort();
		assert.deepEqual(
			await accessibleNames(labels),
			labels,
			"each primed control is exposed once with its visible name",
		);
		await evaluate(
			"window.initialLive = document.querySelector('[data-live-toggle]'); window.initialExact = document.querySelector('[data-exact-preview]'); window.initialLive.focus()",
		);
		hold = false;
		await Promise.all(
			[...held].map((requestId) => command("Fetch.continueRequest", { requestId })),
		);
		held.clear();
		await ready(
			"document.readyState==='complete' && document.querySelector('[data-keeping=true]') && !document.documentElement.hasAttribute('data-admin-pending')",
		);
		await ready(`window.editorPaints.length >= ${before.length + 10}`);
		const after = await evaluate("window.editorPaints");
		for (const sample of after) {
			assert.equal(sample.save || sample.fallback || sample.note, false);
			assert.equal(sample.live && sample.exact && sample.locales, true);
			assert.deepEqual(
				sample.buttons,
				before[0].buttons,
				"the same controls paint in the same order",
			);
			assert.equal(sample.width, before[0].width);
			assert.equal(
				sample.height,
				before[0].height,
				"save bar retains its shape across handoff",
			);
		}
		assert.deepEqual(
			await evaluate(
				"[...document.querySelectorAll('[data-locale-tab]')].map(button=>({name:button.textContent,tabIndex:button.tabIndex,pressed:button.getAttribute('aria-pressed')}))",
			),
			names,
		);
		assert.deepEqual(
			await accessibleNames(labels),
			labels,
			"accessible names remain stable through handoff",
		);
		assert.equal(
			await evaluate(
				"document.activeElement === window.initialLive && document.querySelector('[data-live-toggle]') === window.initialLive && document.querySelector('[data-exact-preview]') === window.initialExact",
			),
			true,
			"handoff preserves controls and keyboard focus",
		);
		await command("Input.dispatchKeyEvent", {
			type: "keyDown",
			key: "Tab",
			code: "Tab",
			windowsVirtualKeyCode: 9,
		});
		await command("Input.dispatchKeyEvent", {
			type: "keyUp",
			key: "Tab",
			code: "Tab",
			windowsVirtualKeyCode: 9,
		});
		assert.equal(
			await evaluate("document.activeElement === window.initialExact"),
			true,
			"native tab order has no duplicate preview control",
		);
	}
	assert.deepEqual(
		browser.browserErrors,
		[],
		"normal startup respects the unchanged CSP",
	);

	// Typing may change publication facts; test it separately from the untouched
	// bar's first-paint geometry and keyboard handoff above.
	hold = true;
	await command("Page.navigate", { url: origin + record });
	await ready("window.editorFirstPaint > 0 && window.editorPaints?.length >= 10");
	const earlySequence = await evaluate(
		"document.querySelector('[name=_edit_sequence]').value",
	);
	await evaluate(
		"(() => {const field=document.querySelector('[name=title__en]');field.value='Edited before scripts loaded';field.dispatchEvent(new Event('input',{bubbles:true}));})()",
	);
	hold = false;
	await Promise.all(
		[...held].map((requestId) => command("Fetch.continueRequest", { requestId })),
	);
	held.clear();
	await ready(
		`document.readyState==='complete' && document.querySelector('[name=_edit_sequence]').value!==${JSON.stringify(earlySequence)} && document.querySelector('[data-save-status]').textContent.startsWith('Saved at ')`,
	);
	for (const failure of [
		{ file: "/editorial-form.js" },
		{ file: "/record-keeper.js" },
		{ file: "/editorial-form.js", throw: true },
	]) {
		fault = failure;
		faultCount = 0;
		await navigate(record, "[data-editorial-form]");
		assert.equal(
			await evaluate("document.querySelector('[name=title__en]').value"),
			"Edited before scripts loaded",
			"pre-handoff input survives a real reload even when the next enhancement fails",
		);
		assert.equal(faultCount, 1, "fixture actually prevents enhancement takeover");
		await nativeBar();
	}
	fault = null;
	const partialFailure = await command("Page.addScriptToEvaluateOnNewDocument", {
		source: `(() => {
			const original = Document.prototype.querySelector;
			Document.prototype.querySelector = function(selector) {
				if (selector === '[data-live-preview]' && !window.startupFaultFired) {
					window.startupFaultFired = true;
					throw new Error('fixture partial startup failure');
				}
				return original.call(this, selector);
			};
		})()`,
	});
	await navigate(record, "[data-editorial-form]");
	assert.equal(await evaluate("window.startupFaultFired"), true);
	await nativeBar();
	assert.equal(
		await evaluate("!!document.querySelector('[data-keeping]')"),
		false,
		"failed startup disposes automatic keeping",
	);
	await command("Page.removeScriptToEvaluateOnNewDocument", partialFailure);
	// The shell uses the same recovery contract on pages without a record editor.
	await command("Emulation.setDeviceMetricsOverride", {
		width: 1440,
		height: 1000,
		deviceScaleFactor: 1,
		mobile: false,
	});
	fault = { file: "/admin-shell.js", throw: true };
	await navigate("/", "[data-admin-shell]");
	await evaluate("document.querySelector('.admin-account').open=true");
	assert.equal(await evaluate(visible("[data-theme-apply]")), true);
	fault = null;
	await command("Fetch.disable");
	await command("Emulation.setScriptExecutionDisabled", { value: true });
	await navigate(record, "[data-editorial-form]");
	await nativeBar();
	const nativeRevision = await evaluate(
		"document.querySelector('[name=_revision]').value",
	);
	await evaluate(
		"document.querySelector('[name=title__en]').value='Native save after a no-script opening';document.querySelector('[data-editorial-form]').requestSubmit(document.querySelector('[data-record-save]'))",
	);
	await ready(
		`document.readyState==='complete' && document.querySelector('[name=title__en]')?.value==='Native save after a no-script opening' && document.querySelector('[name=_revision]')?.value!==${JSON.stringify(nativeRevision)}`,
	);
	await navigate(record, "[data-editorial-form]");
	assert.equal(
		await evaluate("document.querySelector('[name=title__en]').value"),
		"Native save after a no-script opening",
		"native Save persists the authored language independently of the heading language",
	);
	await nativeBar();
	assert.deepEqual(transportErrors, []);
	process.stdout.write(
		"Editor first paint, delayed handoff, focus, startup failure and no-script saving passed.\n",
	);
} finally {
	hold = false;
	await command("Fetch.disable");
	await command("Emulation.setScriptExecutionDisabled", { value: false });
	await browser.close();
}
