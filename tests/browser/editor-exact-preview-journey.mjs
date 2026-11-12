import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { isolatedPreviewBrowser } from "./isolated-preview-browser.mjs";
import { connectLocalBrowser } from "./local-browser.mjs";

const origin = process.env.CMS_BROWSER_ORIGIN;
const debug = process.env.CMS_BROWSER_DEBUG_URL;
const config = process.env.CMS_BROWSER_WORKER_CONFIG;
assert.ok(origin && debug && config);
execFileSync("php", ["tests/php/prepare-editor-exact-browser.php", config, "prepare"]);
const browser = await connectLocalBrowser(origin, debug);
const { command, evaluate, ready, navigate, browserErrors } = browser;
const record = "/records/project/10000000000000000000000000000001";
const created = new Set();
const opened = new Set();
async function pages() {
	return (await command("Target.getTargets")).targetInfos.filter(
		(target) => target.type === "page",
	);
}
async function click(selector) {
	await command("Page.bringToFront");
	await evaluate(
		`document.querySelector(${JSON.stringify(selector)}).scrollIntoView({block:'center'})`,
	);
	const point = await evaluate(`(() => {
		const button=document.querySelector(${JSON.stringify(selector)}), r=button.getBoundingClientRect();
		return {x:r.x+r.width/2,y:r.y+r.height/2,reachable:document.elementFromPoint(r.x+r.width/2,r.y+r.height/2)===button};
	})()`);
	assert.equal(point.reachable, true, "native preview control is reachable");
	for (const type of ["mousePressed", "mouseReleased"])
		await command("Input.dispatchMouseEvent", {
			type,
			x: point.x,
			y: point.y,
			button: "left",
			clickCount: 1,
		});
}
async function type(value) {
	await evaluate(
		`(() => {const field=document.querySelector('[name=title__en]');field.value=${JSON.stringify(value)};field.dispatchEvent(new Event('input',{bubbles:true}));})()`,
	);
}
async function attachNew(before) {
	let target;
	for (let attempt = 0; attempt < 300 && !target; attempt++) {
		target = (await pages()).find((page) => !before.has(page.targetId));
		if (!target) await new Promise((resolve) => setTimeout(resolve, 100));
	}
	assert.ok(
		target,
		`exact preview opens a real new tab: ${JSON.stringify(target ? null : await evaluate("({path:location.pathname,save:document.querySelector('[data-save-status]')?.textContent,exact:document.querySelector('[data-exact-status]')?.textContent,busy:document.querySelector('[data-exact-preview]')?.disabled,events:window.exactEvents})"))}`,
	);
	opened.add(target.targetId);
	const { sessionId } = await command("Target.attachToTarget", {
		targetId: target.targetId,
		flatten: true,
	});
	const tabCommand = (method, params = {}, child = sessionId) =>
		command(method, params, child);
	async function tabEvaluate(expression) {
		const result = await tabCommand("Runtime.evaluate", {
			expression,
			returnByValue: true,
			awaitPromise: true,
		});
		if (result.exceptionDetails)
			throw new Error(JSON.stringify(result.exceptionDetails));
		return result.result.value;
	}
	async function tabReady(expression) {
		for (let attempt = 0; attempt < 300; attempt++) {
			try {
				if (await tabEvaluate(expression)) return;
			} catch {
				// A native POST, redirect or refresh may replace this context.
			}
			await new Promise((resolve) => setTimeout(resolve, 100));
		}
		const state = await tabEvaluate(
			"({path:location.href,ready:document.readyState,explanation:document.body.textContent.slice(0,1600)})",
		);
		assert.fail(
			`exact preview tab did not become ready: ${expression}; ${JSON.stringify(state)}`,
		);
	}
	await tabCommand("Page.enable");
	await tabCommand("Runtime.enable");
	return {
		command: tabCommand,
		evaluate: tabEvaluate,
		ready: tabReady,
		close: async () => {
			await command("Target.closeTarget", { targetId: target.targetId });
			opened.delete(target.targetId);
		},
	};
}
async function built(tab) {
	await tab.ready(
		"location.pathname.startsWith('/previews/newest/records/') && document.readyState==='complete'",
	);
	const url = await tab.evaluate("location.href");
	assert.ok(
		!url.includes("/artifact/"),
		"address follows newest rather than a fixed build",
	);
	if (new URL(url).searchParams.has("waiting")) {
		assert.equal(
			await tab.evaluate("!!document.querySelector('[role=status]')"),
			true,
			"new tab explains the waiting state without JavaScript",
		);
		execFileSync("php", [
			"tools/conventional-media-worker.php",
			`--config=${config}`,
			"--max-jobs=1",
			"--max-seconds=60",
			"--worker-id=editor-exact-browser",
		]);
		await tab.command("Page.reload");
	}
	await tab.ready(
		"!!document.querySelector('[data-editor-exact-frame]') && document.querySelector('[data-editor-exact-frame]').contentDocument===null && !location.search.includes('waiting=')",
	);
	const details = await tab.evaluate(
		"({url:location.href, id:document.querySelector('[data-editor-exact-frame]').dataset.previewId, source:document.querySelector('[data-editor-exact-frame]').src, opaque:document.querySelector('[data-editor-exact-frame]').contentDocument===null})",
	);
	created.add(details.id);
	assert.equal(details.opaque, true, "profile scripts stay outside the CMS origin");
	return details;
}
async function exact() {
	const before = new Set((await pages()).map((page) => page.targetId));
	await evaluate(
		"window.exactEvents=[];for(const name of ['click','submit'])document.addEventListener(name,e=>window.exactEvents.push({name,trusted:e.isTrusted,active:navigator.userActivation.isActive,target:e.target.id,submit:e.submitter?.dataset?.exactPreview}),{once:true,capture:true})",
	);
	await click("[data-exact-preview]");
	return attachNew(before);
}

try {
	await command("Page.enable");
	await command("Runtime.enable");
	await command("Emulation.setDeviceMetricsOverride", {
		width: 1440,
		height: 900,
		deviceScaleFactor: 1,
		mobile: false,
	});
	await navigate("/login", "main");
	if (await evaluate("!!document.querySelector('[name=login]')")) {
		await evaluate(
			"document.querySelector('[name=login]').value='admin@portable.test';document.querySelector('[name=password]').value='portable-cms-local';document.querySelector('form').requestSubmit()",
		);
		await ready(
			"location.pathname==='/' && !!document.querySelector('.admin-navigation')",
		);
	}
	await navigate(record, '[data-keeping="true"]');
	const original = await evaluate("document.querySelector('[name=title__en]').value");
	const originalYear = await evaluate("document.querySelector('[name=year]').value");
	assert.notEqual(
		originalYear,
		"",
		"the fictional project starts with an authored year",
	);
	assert.deepEqual(
		await evaluate(
			"[document.querySelector('[data-live-toggle]').textContent, document.querySelector('[data-exact-preview]').textContent]",
		),
		["Live preview", "Open exact preview"],
		"both preview actions say plainly which is which",
	);
	await evaluate("document.querySelector('[data-live-toggle]').focus()");
	for (const type of ["keyDown", "keyUp"])
		await command("Input.dispatchKeyEvent", {
			type,
			key: "Tab",
			code: "Tab",
			windowsVirtualKeyCode: 9,
		});
	assert.equal(
		await evaluate(
			"document.activeElement.matches('[data-exact-preview]:focus-visible') && getComputedStyle(document.activeElement).outlineStyle!=='none'",
		),
		true,
		"keyboard focus on the new action is visible",
	);
	await click("[data-live-toggle]");
	await ready("!document.querySelector('[data-live-preview]').hidden");
	await click("[data-live-toggle]");
	await ready("document.querySelector('[data-live-preview]').hidden");
	await click('[data-locale-tab="en"]');

	// Hold a real keep request beyond transient user activation. No tab may open before it succeeds.
	await evaluate(`window.exactOriginalFetch=window.fetch; window.fetch=(url,options)=>
		String(url).includes(${JSON.stringify(record)}) && options?.body?.get?.('_keep')==='1'
		? new Promise((resolve,reject)=>{window.releaseExactKeep=()=>window.exactOriginalFetch(url,options).then(resolve,reject);})
		: window.exactOriginalFetch(url,options)`);
	await type("Saved before opening the exact preview");
	await ready("typeof window.releaseExactKeep==='function'");
	const before = new Set((await pages()).map((page) => page.targetId));
	await click("[data-exact-preview]");
	await ready(
		"document.querySelector('[data-exact-preview]').getAttribute('aria-busy')==='true' && document.querySelector('[data-exact-status]').textContent.includes('Saving changes')",
	);
	await new Promise((resolve) => setTimeout(resolve, 8100));
	assert.deepEqual(
		new Set((await pages()).map((page) => page.targetId)),
		before,
		"keeping does not open an early tab",
	);
	await evaluate("window.fetch=window.exactOriginalFetch;window.releaseExactKeep()");
	const firstTab = await attachNew(before);
	const first = await built(firstTab);
	const firstFrame = await isolatedPreviewBrowser(firstTab, first.source);
	await firstFrame.ready(
		"document.readyState==='complete' && !!document.querySelector('h1')",
	);
	assert.equal(
		await firstFrame.evaluate("document.querySelector('h1').textContent"),
		"Saved before opening the exact preview",
	);
	assert.equal(
		await firstFrame.evaluate(
			`[...document.querySelectorAll('.studio-metadata dd')].some(value=>value.textContent.trim()===${JSON.stringify(originalYear)})`,
		),
		true,
		"keeping a title before exact capture preserves the authored year",
	);
	assert.equal(
		await firstFrame.evaluate(
			"document.styleSheets.length > 0 && getComputedStyle(document.body).fontFamily.length > 0 && document.fonts.status==='loaded'",
		),
		true,
		"opaque exact frame loads the site's styles and fonts",
	);
	await firstFrame.close();
	const reusedTab = await exact();
	const reused = await built(reusedTab);
	assert.equal(
		reused.id,
		first.id,
		"unchanged kept site opens immediately in the existing build",
	);
	await reusedTab.close();

	await command("Network.enable");
	await command("Network.emulateNetworkConditions", {
		offline: true,
		latency: 0,
		downloadThroughput: -1,
		uploadThroughput: -1,
	});
	await type("A change that cannot be kept offline");
	const failedBefore = new Set((await pages()).map((page) => page.targetId));
	await click("[data-exact-preview]");
	await ready(
		"document.querySelector('[data-exact-status]').textContent.includes('preview was not opened') && document.activeElement.matches('[data-save-status]')",
	);
	assert.deepEqual(
		new Set((await pages()).map((page) => page.targetId)),
		failedBefore,
		"failed keeping opens no preview tab",
	);
	await command("Network.emulateNetworkConditions", {
		offline: false,
		latency: 0,
		downloadThroughput: -1,
		uploadThroughput: -1,
	});
	await type(original);
	await evaluate("document.querySelector('[data-keep-retry]').click()");
	await ready(
		"document.querySelector('[data-save-status]').textContent.startsWith('Saved at ')",
	);
	const nextTab = await exact();
	const next = await built(nextTab);
	assert.notEqual(
		next.id,
		first.id,
		"a later kept change builds a new whole-site preview",
	);
	await firstTab.command("Page.reload");
	await firstTab.ready("!!document.querySelector('[data-editor-exact-frame]')");
	assert.equal(
		await firstTab.evaluate("location.href"),
		first.url,
		"newest tab address stays unchanged on reload",
	);
	assert.equal(
		await firstTab.evaluate(
			"document.querySelector('[data-editor-exact-frame]').dataset.previewId",
		),
		next.id,
		"reloading follows the newer ready preview",
	);
	await nextTab.close();
	await firstTab.close();

	await navigate("/records/article/new", '[data-keeping="true"]');
	await evaluate(`(() => {
		for (const locale of ['ca','es','en']) {
			for (const [name,value] of [['title','A newly kept exact preview article'],['slug','new-exact-preview-article'],['excerpt','Fictional content for the exact preview journey.']]) {
				const control=document.querySelector('[name="'+name+'__'+locale+'"]');
				control.value=value;
			}
			const surface=document.querySelector('[data-rich-name="body__'+locale+'"] .admin-rich-surface');
			const paragraph=document.createElement('p');paragraph.textContent='Fictional body for the exact preview journey.';
			surface.replaceChildren(paragraph);
			surface.dispatchEvent(new Event('input',{bubbles:true}));
		}
		document.querySelector('[name=title__ca]').dispatchEvent(new Event('input',{bubbles:true}));
	})()`);
	const newTab = await exact();
	const newPreview = await built(newTab);
	await ready(
		"/^\\/records\\/article\\/[a-f0-9]{32}$/.test(location.pathname) && !!document.querySelector('[data-keeping]')",
	);
	const newRecordPath = await evaluate("location.pathname");
	assert.equal(
		new URL(newPreview.url).pathname,
		`/previews/newest${newRecordPath}`,
		"first keep opens the assigned record rather than an unsaved placeholder",
	);
	const newFrame = await isolatedPreviewBrowser(newTab, newPreview.source);
	await newFrame.ready(
		"document.readyState==='complete' && !!document.querySelector('h1')",
	);
	assert.equal(
		await newFrame.evaluate("document.querySelector('h1').textContent"),
		"A newly kept exact preview article",
	);
	await newFrame.close();
	await newTab.close();
	assert.equal(
		await evaluate(`(async()=>{
		const form=document.getElementById('record-status-form'), body=new URLSearchParams(new FormData(form));
		body.set('_workflow_state','archived');body.set('_confirmed','1');
		return (await fetch(form.action,{method:'POST',body})).status;
	})()`),
		200,
		"archive only the newly created fictional article",
	);
	await navigate(newRecordPath, "#record-delete-form");
	assert.equal(
		await evaluate(`(async()=>{
		const form=document.getElementById('record-delete-form'), body=new URLSearchParams(new FormData(form));
		body.set('_confirmed','1');
		const response=await fetch(form.action,{method:'POST',body});
		return response.status===200 && new URL(response.url).pathname==='/records/article';
	})()`),
		true,
		"remove only the newly created fictional article",
	);

	await command("Emulation.setDeviceMetricsOverride", {
		width: 360,
		height: 800,
		deviceScaleFactor: 1,
		mobile: false,
	});
	await navigate(
		"/records/site-settings/70000000000000000000000000000001",
		'[data-keeping="true"]',
	);
	assert.equal(
		await evaluate("document.querySelector('[data-exact-preview]').textContent"),
		"Open exact preview (home)",
	);
	assert.equal(
		await evaluate("document.documentElement.scrollWidth <= innerWidth"),
		true,
		"both controls fit the phone layout",
	);
	const homeTab = await exact();
	const home = await built(homeTab);
	assert.match(
		new URL(home.source).pathname,
		/^\/editor-preview-files\/[a-f0-9]{32}\/[a-f0-9]{64}\/ca\/$/,
		"home fallback uses the captured localized home",
	);
	assert.equal(
		await homeTab.evaluate(
			"document.body.textContent.includes('This record has no page')",
		),
		true,
		"home fallback is explained",
	);
	await homeTab.close();

	await command("Emulation.setScriptExecutionDisabled", { value: true });
	await navigate(record, "[data-record-save]");
	assert.equal(
		await evaluate("document.querySelector('[data-record-save]').hidden"),
		false,
		"native Save remains available",
	);
	assert.equal(
		await evaluate("document.querySelector('[data-live-fallback]').textContent"),
		"Preview changes",
		"live no-script fallback is unchanged",
	);
	assert.equal(
		await evaluate("document.body.textContent.includes('Save first.')"),
		true,
	);
	const nativeRevision = await evaluate(
		"document.querySelector('[name=_revision]').value",
	);
	await evaluate(
		"document.querySelector('[name=title__en]').value='Never save this native exact form'",
	);
	const nativeTab = await exact();
	await built(nativeTab);
	await nativeTab.close();
	await command("Emulation.setScriptExecutionDisabled", { value: false });
	await navigate(record, '[data-keeping="true"]');
	assert.equal(
		await evaluate("document.querySelector('[name=_revision]').value"),
		nativeRevision,
		"native exact opening created no content revision",
	);
	assert.equal(
		await evaluate("document.querySelector('[name=title__en]').value"),
		original,
		"native exact opening saved no unsubmitted field",
	);
	assert.deepEqual(browserErrors, []);
	console.log(
		"PASS editor exact preview browser: distinct live/exact controls, focus, delayed keeping, failed keep refusal, first-save identity, newest reload, opaque pinned files, home fallback, phone and native no-script forms.",
	);
} finally {
	try {
		await command("Network.emulateNetworkConditions", {
			offline: false,
			latency: 0,
			downloadThroughput: -1,
			uploadThroughput: -1,
		});
		await command("Emulation.setScriptExecutionDisabled", { value: false });
		for (const targetId of opened) await command("Target.closeTarget", { targetId });
		for (const id of created) {
			await evaluate(
				`fetch('/previews/${id}/delete',{method:'POST',body:new URLSearchParams({_csrf:document.querySelector('[name=_csrf]').value})}).then(r=>r.text())`,
			);
		}
		if (await evaluate("!!document.querySelector('form[action=\"/logout\"]')")) {
			await evaluate(
				"document.querySelector('form[action=\"/logout\"]').requestSubmit()",
			);
			await ready(
				"location.pathname==='/login' && !!document.querySelector('[name=login]')",
			);
		}
	} finally {
		try {
			await browser.close();
		} finally {
			execFileSync("php", [
				"tests/php/prepare-editor-exact-browser.php",
				config,
				"restore",
			]);
		}
	}
}
