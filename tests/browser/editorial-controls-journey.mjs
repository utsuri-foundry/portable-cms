import assert from "node:assert/strict";
import { writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import { connectLocalBrowser } from "./local-browser.mjs";

const origin = process.env.CMS_BROWSER_ORIGIN ?? "http://localhost:4183";
const debug = process.env.CMS_BROWSER_DEBUG_URL ?? "http://127.0.0.1:9224";
const browser = await connectLocalBrowser(origin, debug);
const { command, evaluate, ready, navigate, reload, browserErrors } = browser;
const selected = () =>
	evaluate(
		"[...document.querySelectorAll('#field-gallery .admin-selections input')].map(c=>c.value)",
	);
async function action(value, expected) {
	await evaluate(
		`document.querySelector('[data-editorial-form]').requestSubmit([...document.querySelectorAll('[name=_editor_action]')].find(b=>b.value===${JSON.stringify(value)}))`,
	);
	await ready(`(${expected}) && document.readyState==='complete'`);
}
try {
	await command("Page.enable");
	await command("Runtime.enable");
	await command("Log.enable");
	await command("Page.navigate", { url: `${origin}/login` });
	await ready(
		"document.readyState === 'complete' && (document.querySelector('[name=login]') || document.querySelector('.admin-navigation'))",
	);
	if (await evaluate("document.querySelector('[name=login]') !== null")) {
		await evaluate(
			"document.querySelector('[name=login]').value='admin@portable.test';document.querySelector('[name=password]').value='portable-cms-local';document.querySelector('form').requestSubmit()",
		);
	}
	await ready(
		"location.pathname === '/' && document.querySelector('.admin-navigation')",
	);
	await navigate("/media", ".admin-media-grid");
	await ready(
		"[...document.querySelectorAll('.admin-thumbnail')].some(i=>i.tagName==='IMG' && i.complete && i.naturalWidth>0)",
	);
	const detailPath = await evaluate(
		"document.querySelector('.admin-media-grid a').getAttribute('href')",
	);
	await navigate(detailPath, "[name=name]");
	const originalName = await evaluate("document.querySelector('[name=name]').value");
	await evaluate(
		"document.querySelector('[name=name]').value='Browser media review';document.querySelector('.admin-form').requestSubmit()",
	);
	await ready("document.querySelector('h1').textContent==='Browser media review'");
	await evaluate(
		`document.querySelector('[name=name]').value=${JSON.stringify(originalName)};document.querySelector('.admin-form').requestSubmit()`,
	);
	await ready(
		`document.querySelector('h1').textContent===${JSON.stringify(originalName)}`,
	);
	await navigate("/records/project", ".admin-table__title");
	const path = "/records/project/10000000000000000000000000000001";
	await navigate(path, "[data-editorial-form]");
	const originals = await selected();
	const originalForm = await evaluate(
		"[...new FormData(document.querySelector('[data-editorial-form]')).entries()]",
	);
	await ready(
		"document.querySelector('[data-record-locale-switcher]') && !document.querySelector('[data-record-locale-switcher]').hidden",
	);
	// Native structured actions preserve their input and scripted keeping persists it.
	const first = originals[0];
	assert.ok(first);
	await action(
		`remove:gallery:${first}`,
		`!document.querySelector('#field-gallery .admin-selections input[value="${first}"]')`,
	);
	assert.equal(await evaluate("document.activeElement.id"), "field-gallery");
	await action(
		`select:gallery:${first}`,
		`document.querySelector('#field-gallery .admin-selections input[value="${first}"]')`,
	);
	assert.equal((await selected()).at(-1), first);
	if (originals.length > 1) {
		await action(
			`up:gallery:${first}`,
			`document.querySelector('#field-gallery .admin-selections input')?.value==='${first}'`,
		);
	}
	const originalCredits = await evaluate(
		"document.querySelectorAll('[name=\"credits_ca_items[]\"]').length",
	);
	await action(
		"add:credits:ca",
		`document.querySelectorAll('[name="credits_ca_items[]"]').length===${originalCredits + 1}`,
	);
	const itemId = await evaluate(
		"[...document.querySelectorAll('[name=\"credits_ca_items[]\"]')].at(-1).value",
	);
	assert.match(itemId, /^[a-f0-9]{32}$/);
	await action(
		"add:credits:ca",
		`document.querySelectorAll('[name="credits_ca_items[]"]').length===${originalCredits + 2}`,
	);
	const secondId = await evaluate(
		"[...document.querySelectorAll('[name=\"credits_ca_items[]\"]')].at(-1).value",
	);
	await action(
		`up:credits:ca:${secondId}`,
		`[...document.querySelectorAll('[name="credits_ca_items[]"]')].at(-2).value==='${secondId}'`,
	);
	assert.equal(await evaluate("document.activeElement.id"), "field-credits");
	await action(
		`remove:credits:ca:${secondId}`,
		`document.querySelectorAll('[name="credits_ca_items[]"]').length===${originalCredits + 1}`,
	);
	assert.equal(
		await evaluate(
			"[...document.querySelectorAll('[name=\"credits_ca_items[]\"]')].at(-1).value",
		),
		itemId,
	);
	// Short credits are one aligned list of rows with a menu per row.
	const rows = "#field-credits [data-locale-panel='ca'] [data-group-row]";
	const ids = () =>
		evaluate(
			"[...document.querySelectorAll('[name=\"credits_ca_items[]\"]')].map(i=>i.value)",
		);
	await action(
		"add:credits:ca",
		`document.querySelectorAll('[name="credits_ca_items[]"]').length===${originalCredits + 2}`,
	);
	const [firstCredit] = await ids();
	await action(
		`insert:credits:ca:${firstCredit}`,
		`document.querySelectorAll('[name="credits_ca_items[]"]').length===${originalCredits + 3}`,
	);
	const inserted = (await ids())[1];
	assert.ok(
		![itemId, firstCredit].includes(inserted),
		"Insert below adds a row after the first",
	);
	await ready("document.querySelector('[data-group-rows][data-group-drag]')");
	await evaluate(
		`[...document.querySelectorAll("${rows} textarea")].forEach((t,i)=>{if(!t.value)t.value=i%2?'Name '+i+' with a long list of several people who share this credit':'Role '+i})`,
	);
	const columns = await evaluate(
		`[...document.querySelectorAll("${rows}")].map(r=>[...r.querySelectorAll('.admin-group-cell textarea')].map(c=>Math.round(c.getBoundingClientRect().x)))`,
	);
	assert.ok(columns.length >= 3);
	for (const column of columns[0].keys())
		assert.equal(
			new Set(columns.map((row) => row[column])).size,
			1,
			`every row's inputs start at the same point: ${JSON.stringify(columns)}`,
		);
	assert.equal(
		await evaluate(
			"document.querySelectorAll('#field-credits [data-locale-panel=\"ca\"] .admin-group-rows__head').length",
		),
		1,
		"column names appear once",
	);
	assert.match(
		await evaluate(
			"document.querySelector('#field-credits [data-locale-panel=\"ca\"] .admin-group-foot').textContent",
		),
		/^\+ Add credit\d+ items$/,
		"the add button names one item beside the count",
	);
	assert.deepEqual(
		await evaluate(
			`[...document.querySelectorAll("${rows}")].slice(0,2).flatMap(r=>[...r.querySelectorAll('textarea')].map(t=>t.labels[0].textContent))`,
		),
		["Role, row 1", "Name, row 1", "Role, row 2", "Name, row 2"],
	);
	assert.deepEqual(
		await evaluate(
			`[...document.querySelectorAll("${rows}")].map(r=>[r.querySelector('[data-group-action=up]').disabled,r.querySelector('[data-group-action=down]').disabled])`,
		).then((states) => [states[0], states.at(-1)]),
		[
			[true, false],
			[false, true],
		],
		"unavailable moves are disabled, not omitted",
	);
	assert.equal(
		await evaluate(
			`(()=>{const t=document.querySelector("${rows} textarea");return t.scrollHeight<=t.clientHeight+1})()`,
		),
		true,
	);
	assert.ok(
		(await evaluate(
			`[...document.querySelectorAll("${rows}")][1].querySelectorAll('textarea')[1].getBoundingClientRect().height`,
		)) >
			(await evaluate(
				`document.querySelector("${rows} textarea").getBoundingClientRect().height`,
			)),
		"long names wrap",
	);
	// One menu at a time; Escape and a click elsewhere close it.
	const summaries = `[...document.querySelectorAll("${rows} [data-group-menu] summary")]`;
	await evaluate(`${summaries}[0].click()`);
	await evaluate(`${summaries}[1].click()`);
	assert.deepEqual(
		await evaluate(`${summaries}.map(s=>s.parentElement.open)`).then((open) =>
			open.slice(0, 2),
		),
		[false, true],
	);
	await command("Input.dispatchKeyEvent", {
		type: "keyDown",
		key: "Escape",
		code: "Escape",
	});
	await command("Input.dispatchKeyEvent", {
		type: "keyUp",
		key: "Escape",
		code: "Escape",
	});
	assert.equal(await evaluate(`${summaries}.some(s=>s.parentElement.open)`), false);
	await evaluate(`${summaries}[0].click()`);
	await evaluate("document.querySelector('h1').click()");
	assert.equal(await evaluate(`${summaries}.some(s=>s.parentElement.open)`), false);
	// Dragging the last row's grip above the first reorders and renumbers without a request.
	const before = await ids();
	const point = async (selector) =>
		evaluate(
			`(()=>{const b=document.querySelector(${JSON.stringify(selector)}).getBoundingClientRect();return {x:b.x+b.width/2,y:b.y+b.height/2}})()`,
		);
	await evaluate(
		`[...document.querySelectorAll("${rows}")].at(-1).scrollIntoView({block:'center'})`,
	);
	const grip = await point(`${rows}:last-child [data-group-grip]`);
	const target = await point(`${rows}:first-child`);
	await command("Input.dispatchMouseEvent", { type: "mouseMoved", ...grip });
	await command("Input.dispatchMouseEvent", {
		type: "mousePressed",
		...grip,
		button: "left",
		buttons: 1,
		clickCount: 1,
	});
	for (const step of [0.5, 1])
		await command("Input.dispatchMouseEvent", {
			type: "mouseMoved",
			x: grip.x,
			y: grip.y + (target.y - 4 - grip.y) * step,
			button: "left",
			buttons: 1,
		});
	await command("Input.dispatchMouseEvent", {
		type: "mouseReleased",
		x: grip.x,
		y: target.y - 4,
		button: "left",
		buttons: 0,
		clickCount: 1,
	});
	assert.deepEqual(
		await ids(),
		[before.at(-1), ...before.slice(0, -1)],
		"the dragged row moves first",
	);
	assert.equal(
		await evaluate(`document.querySelector("${rows} textarea").labels[0].textContent`),
		"Role, row 1",
	);
	assert.equal(
		await evaluate(`document.querySelector("${rows} [data-group-action=up]").disabled`),
		true,
	);
	assert.match(
		await evaluate("document.querySelector('[data-save-status]').textContent"),
		/^(Saving|Saved at )/,
	);
	await action("search:related", "document.activeElement.id==='field-related'");
	assert.equal(
		await evaluate("document.querySelector('#field-related textarea')===null"),
		true,
	);
	// Force server validation, not just HTML validation, and retain selected media.
	await evaluate(
		"document.querySelector('[name=title__ca]').value='';document.querySelector('[data-editorial-form]').requestSubmit()",
	);
	await ready("document.querySelector('[data-validation-summary]')");
	await ready("document.activeElement.name==='title__ca'");
	assert.deepEqual(await selected(), originals);
	assert.equal(await evaluate("document.activeElement.name"), "title__ca");
	await command("Emulation.setDeviceMetricsOverride", {
		width: 360,
		height: 800,
		deviceScaleFactor: 1,
		mobile: false,
	});
	assert.equal(
		await evaluate("document.documentElement.scrollWidth<=innerWidth"),
		true,
	);
	// Narrow rows stack their fields under small names; the column names are hidden.
	assert.deepEqual(
		await evaluate(
			`(()=>{const r=document.querySelector("${rows}");const n=r.querySelector('.admin-group-cell__name').getBoundingClientRect();const c=[...r.querySelectorAll('textarea')].map(t=>t.getBoundingClientRect());return [getComputedStyle(document.querySelector('#field-credits .admin-group-rows__head')).display, n.width>1, c[1].y>c[0].bottom-1, Math.round(c[0].x)===Math.round(c[1].x)]})()`,
		),
		["none", true, true, true],
	);
	await evaluate(
		"document.querySelector('#field-gallery').scrollIntoView({block:'start'})",
	);
	const screenshot = await command("Page.captureScreenshot", {
		format: "png",
		captureBeyondViewport: false,
	});
	await writeFile(
		"/tmp/portable-cms-controls-review.png",
		Buffer.from(screenshot.data, "base64"),
	);
	// Leave the enhanced form before an out-of-band fixture restore. Its keeper or
	// pending native submission must not race the restoration with the old revision.
	await command("Emulation.setScriptExecutionDisabled", { value: true });
	await navigate("/media", ".admin-media-grid");
	await navigate(path, "[data-editorial-form]");
	const restoredRevision =
		Number(await evaluate("document.querySelector('[name=_revision]').value")) + 1;
	assert.deepEqual(
		await evaluate(
			`(async()=>{const form=document.querySelector('[data-editorial-form]');const data=new URLSearchParams(${JSON.stringify(originalForm)});for(const name of ['_revision','_edit_sequence','_csrf'])data.set(name,form.elements.namedItem(name).value);const response=await fetch(location.pathname,{method:'POST',body:data});return {status:response.status,redirected:response.redirected}})()`,
		),
		{ status: 200, redirected: true },
	);
	// No-script path: native media removal, group mutation, and multipart upload.
	await reload();
	await ready(
		`document.querySelector('[name=_revision]').value===${JSON.stringify(String(restoredRevision))} && !document.querySelector('[role=alert]')`,
	);
	assert.equal(
		await evaluate(
			"[...document.querySelectorAll('[data-locale-panel]')].every(p=>!p.hidden)",
		),
		true,
	);
	await action(
		`remove:gallery:${first}`,
		`!document.querySelector('#field-gallery .admin-selections input[value="${first}"]')`,
	);
	await action(
		"add:credits:ca",
		`document.querySelectorAll('[name="credits_ca_items[]"]').length===${originalCredits + 1}`,
	);
	assert.equal(
		await evaluate(
			"[...document.querySelectorAll('[data-group-grip]')].every(g=>g.hidden)",
		),
		true,
		"grips need the island; native menus reorder without it",
	);
	const noScript = await ids();
	await action(
		`down:credits:ca:${noScript[0]}`,
		`[...document.querySelectorAll('[name="credits_ca_items[]"]')][1].value==='${noScript[0]}'`,
	);
	await navigate(path, "[data-media-upload]");
	const { root } = await command("DOM.getDocument");
	const { nodeId } = await command("DOM.querySelector", {
		nodeId: root.nodeId,
		selector: "[name='images[]']",
	});
	await command("DOM.setFileInputFiles", {
		nodeId,
		files: [
			resolve(
				"profiles/reference-studio/fixtures/media/d0000000000000000000000000000001-display_1600_webp.webp",
			),
		],
	});
	await evaluate(
		"document.getElementById('media-upload-form').requestSubmit(document.querySelector('[data-native-upload-start][form=media-upload-form]'))",
	);
	await ready(
		"location.pathname.endsWith('/upload') && document.querySelector('[role=status]')",
	);
	assert.match(
		await evaluate("document.querySelector('[role=status]').textContent"),
		/1 image\(s\) accepted/,
	);
	await command("Emulation.setScriptExecutionDisabled", { value: false });
	await navigate("/media", ".admin-media-grid");
	assert.deepEqual(browserErrors, []);
	console.log(
		"PASS real Chromium media controls: private thumbnails, naming, selection/order, repeatable IDs, aligned group rows with menus and drag reordering, focus, validation, 360px and no-script upload and reordering.",
	);
} finally {
	await browser.close();
}
