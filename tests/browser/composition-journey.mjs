import assert from "node:assert/strict";
import { writeFile } from "node:fs/promises";
import { connectLocalBrowser } from "./local-browser.mjs";

const origin = process.env.CMS_BROWSER_ORIGIN;
const debug = process.env.CMS_BROWSER_DEBUG_URL;
const publicOrigin = process.env.CMS_BROWSER_PUBLIC_ORIGIN;
assert.ok(origin && debug && publicOrigin);
const browser = await connectLocalBrowser(origin, debug);
const publicBrowser = await connectLocalBrowser(publicOrigin, debug);
const { command, evaluate, ready, navigate, browserErrors } = browser;
const path = "/records/project/10000000000000000000000000000001";
async function previewReady() {
	await evaluate("document.querySelector('[data-layout-workspace]').open=true");
	await evaluate("document.querySelector('[data-composition-preview-button]').click()");
	try {
		await ready(
			"document.querySelector('[data-composition-status]')?.textContent.startsWith('Layout preview.')",
		);
	} catch (error) {
		const state = await evaluate(
			"(()=>{const frame=document.querySelector('[data-composition-frame]');return {status:document.querySelector('[data-composition-status]')?.textContent,frameState:frame?.contentDocument?.readyState,sequence:frame?.contentDocument?.querySelector('meta[name=composition-sequence]')?.content,frameText:frame?.contentDocument?.body?.textContent?.slice(0,300)}})()",
		);
		process.stderr.write(
			`Composition preview failure: ${JSON.stringify({ state, browserErrors })}\n`,
		);
		throw error;
	}
	await evaluate(
		"document.querySelector('[data-composition-frame]').contentDocument.fonts.ready",
	);
	await ready(
		"[...document.querySelector('[data-composition-frame]').contentDocument.images].every(i=>i.complete && i.naturalWidth>0)",
	);
}
async function action(value, condition) {
	await evaluate(
		`document.querySelector('[data-editorial-form]').requestSubmit([...document.querySelectorAll('[name=_composition_action]')].find(b=>b.value===${JSON.stringify(value)}))`,
	);
	await ready(`(${condition}) && document.readyState==='complete'`);
}
/** The whole rendered page is visible inside the stage, without inner scrollbars. */
const fitFacts =
	"(()=>{const s=document.querySelector('[data-composition-stage]');const b=document.querySelector('[data-composition-box]');const f=document.querySelector('[data-composition-frame]');const sr=s.getBoundingClientRect(),br=b.getBoundingClientRect();return {frame:parseFloat(f.style.height),page:f.contentDocument.documentElement.scrollHeight,box:[br.left,br.right,br.height],stage:[sr.left,sr.right,s.scrollHeight,s.clientHeight,s.scrollWidth,s.clientWidth],label:!document.querySelector('[data-composition-scale]').hidden}})()";
async function assertFits(label) {
	try {
		await ready(
			`(()=>{const f=${fitFacts};return Math.abs(f.frame-f.page)<=1 && f.box[0]>=f.stage[0]-1 && f.box[1]<=f.stage[1]+1 && f.stage[2]<=f.stage[3]+1 && f.stage[4]<=f.stage[5]+1 && f.label})()`,
		);
	} catch {
		assert.fail(`${label}: ${JSON.stringify(await evaluate(fitFacts))}`);
	}
}
const preset = (width) =>
	evaluate(
		`document.querySelector('[name=layout__viewport][value="${width}"]').click()`,
	);
const geometry = `(()=>{const root=doc.querySelector('[data-layout]');const origin=root.getBoundingClientRect();return {width:origin.width,columns:[...root.querySelectorAll('[data-layout-column]')].map(c=>{const r=c.getBoundingClientRect();return [r.x-origin.x,r.y-origin.y,r.width,r.height]}),text:[...root.querySelectorAll('p')].map(p=>({text:p.textContent,width:p.getBoundingClientRect().width,height:p.getBoundingClientRect().height,size:doc.defaultView.getComputedStyle(p).fontSize}))}})()`;
try {
	for (const instance of [browser, publicBrowser]) {
		await instance.command("Page.enable");
		await instance.command("Runtime.enable");
		await instance.command("Log.enable");
	}
	await navigate("/login", "[name=login]");
	await evaluate(
		"document.querySelector('[name=login]').value='admin@portable.test';document.querySelector('[name=password]').value='portable-cms-local';document.querySelector('form').requestSubmit()",
	);
	await ready("location.pathname==='/' && document.querySelector('.admin-navigation')");
	await navigate(path, "[data-composition-editor]");
	assert.equal(
		await evaluate(
			"!!document.querySelector('.admin-layout-summary') && !document.querySelector('[data-layout-workspace]').open",
		),
		true,
		"the record shows a layout summary and opens the workspace on demand",
	);
	const original = await evaluate(
		"[...new FormData(document.querySelector('[data-editorial-form]'))].filter(([,v])=>typeof v==='string')",
	);
	await evaluate(
		"document.querySelector('[name=layout__locale]').value='en';document.querySelector('[name=layout__locale]').dispatchEvent(new Event('input',{bubbles:true}))",
	);
	await previewReady();
	// The same renderer must produce the same logical geometry at real iframe widths, not resized text.
	for (const width of [390, 768, 1440]) {
		await command("Emulation.setDeviceMetricsOverride", {
			width,
			height: 900,
			deviceScaleFactor: 1,
			mobile: false,
		});
		await publicBrowser.command("Emulation.setDeviceMetricsOverride", {
			width,
			height: 900,
			deviceScaleFactor: 1,
			mobile: false,
		});
		await preset(width);
		await previewReady();
		await publicBrowser.navigate("/en/projects/archive-of-mist/", "[data-layout]");
		await publicBrowser.evaluate("document.fonts.ready");
		await publicBrowser.ready(
			"[...document.images].every(i=>i.complete && i.naturalWidth>0)",
		);
		const preview = await evaluate(
			`(()=>{const doc=document.querySelector('[data-composition-frame]').contentDocument;return ${geometry}})()`,
		);
		const published = await publicBrowser.evaluate(
			`(()=>{const doc=document;return ${geometry}})()`,
		);
		assert.ok(
			Math.abs(preview.width - published.width) < 1,
			`layout width parity at ${width}: ${preview.width} vs ${published.width}`,
		);
		assert.equal(preview.columns.length, published.columns.length);
		for (const [index, column] of preview.columns.entries())
			for (const [coordinate, value] of column.entries())
				assert.ok(
					Math.abs(value - published.columns[index][coordinate]) < 1,
					`column geometry parity at ${width}: ${index}/${coordinate}: ${value} vs ${published.columns[index][coordinate]}`,
				);
		assert.deepEqual(preview.text, published.text, `text geometry parity at ${width}`);
		assert.equal(
			await evaluate("document.documentElement.scrollWidth<=innerWidth"),
			true,
			`no admin overflow at ${width}: ${await evaluate("JSON.stringify([...document.querySelectorAll('body *')].filter(e=>e.getBoundingClientRect().right>innerWidth+1).map(e=>[e.tagName,e.className,e.getBoundingClientRect().width]).slice(0,12))")}`,
		);
	}
	// Phone stacks columns into a tall page; desktop is short. Both fit at every admin width.
	for (const adminWidth of [360, 768, 1440]) {
		await command("Emulation.setDeviceMetricsOverride", {
			width: adminWidth,
			height: 900,
			deviceScaleFactor: 1,
			mobile: false,
		});
		for (const width of [390, 1440]) {
			await preset(width);
			await previewReady();
			await assertFits(`preview at ${width}px fits the stage at ${adminWidth}px`);
		}
	}
	await preset(768);
	await previewReady();
	assert.equal(
		await evaluate(
			"document.querySelector('[data-composition-frame]').contentWindow.innerWidth",
		),
		768,
	);
	await command("Emulation.setDeviceMetricsOverride", {
		width: 1280,
		height: 900,
		deviceScaleFactor: 1,
		mobile: false,
	});
	await evaluate("document.documentElement.style.fontSize='200%'");
	assert.equal(
		await evaluate("document.documentElement.scrollWidth<=innerWidth"),
		true,
		"composition controls reflow at 200% text zoom",
	);
	await evaluate("document.documentElement.style.fontSize=''");
	await command("Emulation.setEmulatedMedia", {
		features: [
			{ name: "forced-colors", value: "active" },
			{ name: "prefers-reduced-motion", value: "reduce" },
		],
	});
	assert.equal(await evaluate("matchMedia('(forced-colors: active)').matches"), true);
	await evaluate("document.querySelector('[data-composition-preview-button]').focus()");
	assert.equal(
		await evaluate(
			"document.activeElement.hasAttribute('data-composition-preview-button')",
		),
		true,
	);
	await command("Emulation.setEmulatedMedia", { features: [] });
	const names = await evaluate(
		"[...document.querySelector('[data-composition-editor]').querySelectorAll('input:not([type=hidden]),select')].every(c=>c.labels.length>0)",
	);
	assert.equal(names, true, "every composition control has an accessible native label");
	// Native structural actions remain unsaved, keyboard reachable, and undoable.
	const revision = await evaluate("document.querySelector('[name=_revision]').value");
	await command("Page.bringToFront");
	await evaluate(
		"[...document.querySelectorAll('[name=_composition_action]')].find(b=>b.value==='add-column:layout:intro').focus()",
	);
	await command("Input.dispatchKeyEvent", {
		type: "keyDown",
		key: "Enter",
		code: "Enter",
		text: "\r",
		unmodifiedText: "\r",
		windowsVirtualKeyCode: 13,
	});
	await command("Input.dispatchKeyEvent", {
		type: "keyUp",
		key: "Enter",
		code: "Enter",
		windowsVirtualKeyCode: 13,
	});
	await ready(
		"document.querySelectorAll('.admin-composition-column').length===3 && document.readyState==='complete'",
	);
	await ready("document.activeElement.id==='field-layout'");
	assert.equal(
		await evaluate("document.querySelector('[name=_revision]').value"),
		revision,
	);
	await action(
		"undo:layout",
		"document.querySelectorAll('.admin-composition-column').length===2",
	);
	await action(
		"add-row:layout",
		"document.querySelectorAll('.admin-composition-map li').length===3",
	);
	await action(
		"undo:layout",
		"document.querySelectorAll('.admin-composition-map li').length===2",
	);
	await previewReady();
	await evaluate(
		"document.querySelector('[name=layout__intro__c1__width]').value='80';document.querySelector('[name=layout__intro__c1__width]').dispatchEvent(new Event('input',{bubbles:true}))",
	);
	await previewReady();
	assert.equal(
		await evaluate(
			"getComputedStyle(document.querySelector('[data-composition-frame]').contentDocument.querySelector('[data-layout-column=intro-c2] .cms-layout-asset')).width.endsWith('px')",
		),
		true,
	);
	const dirtyStatus = await evaluate(
		"document.querySelector('[data-save-status]').textContent",
	);
	assert.match(
		dirtyStatus,
		/^Saved at /,
		"composition preview waits for automatic keeping",
	);
	const save = await evaluate(
		"(async()=>{const form=document.querySelector('[data-editorial-form]');return (await fetch(form.action,{method:'POST',body:new URLSearchParams(new FormData(form))})).status})()",
	);
	assert.equal(save, 200); // Fetch follows the successful 303.
	const conflict = await evaluate(
		"(async()=>{const form=document.querySelector('[data-editorial-form]');const values=new URLSearchParams(new FormData(form));values.set('_composition_action','add-column:layout:intro');const response=await fetch(form.action,{method:'POST',body:values});return {status:response.status,text:await response.text()}})()",
	);
	assert.equal(conflict.status, 409);
	assert.match(conflict.text, /This record changed while you were editing/);
	await navigate(path, "[data-composition-editor]");
	const currentRevision = await evaluate(
		"document.querySelector('[name=_revision]').value",
	);
	const restored = await evaluate(
		`(async()=>{const values=new URLSearchParams(${JSON.stringify(original)});values.set('_revision',${JSON.stringify(currentRevision)});values.set('_edit_sequence',document.querySelector('[name=_edit_sequence]').value);return (await fetch(${JSON.stringify(path)},{method:'POST',body:values})).status})()`,
	);
	assert.equal(restored, 200);
	await command("Emulation.setScriptExecutionDisabled", { value: true });
	await navigate(path, "[data-composition-editor]");
	await action(
		"edit-row:layout:detail",
		"document.querySelector('[name=layout__active]').value==='detail'",
	);
	await action(
		"add-column:layout:detail",
		"document.querySelectorAll('.admin-composition-column').length===3",
	);
	await action(
		"undo:layout",
		"document.querySelectorAll('.admin-composition-column').length===2",
	);
	await evaluate("document.querySelector('[data-composition-preview-button]').click()");
	await ready(
		"document.querySelector('[data-composition-frame]').contentDocument.querySelector('[data-layout]')",
	);
	await command("Emulation.setScriptExecutionDisabled", { value: false });
	await command("Emulation.setDeviceMetricsOverride", {
		width: 360,
		height: 800,
		deviceScaleFactor: 1,
		mobile: false,
	});
	await navigate(path, "[data-composition-editor]");
	await previewReady();
	await evaluate(
		"document.querySelector('[data-composition-editor]').scrollIntoView({block:'start'})",
	);
	const screenshot = await command("Page.captureScreenshot", {
		format: "png",
		captureBeyondViewport: false,
	});
	await writeFile(
		`${process.env.CMS_BROWSER_SCREENSHOT ?? "/tmp/cms-review.png"}.composition.png`,
		Buffer.from(screenshot.data, "base64"),
	);
	const homePath = "/records/home/50000000000000000000000000000001";
	await navigate(homePath, "[data-composition-editor]");
	const homeOriginal = await evaluate(
		"[...new FormData(document.querySelector('[data-editorial-form]'))].filter(([,v])=>typeof v==='string')",
	);
	await evaluate("document.querySelector('[name=collage__locale]').value='en'");
	for (const width of [390, 768, 1440]) {
		await command("Emulation.setDeviceMetricsOverride", {
			width,
			height: 900,
			deviceScaleFactor: 1,
			mobile: false,
		});
		await publicBrowser.command("Emulation.setDeviceMetricsOverride", {
			width,
			height: 900,
			deviceScaleFactor: 1,
			mobile: false,
		});
		await evaluate(
			`document.querySelector('[name=collage__viewport][value="${width}"]').click()`,
		);
		await previewReady();
		await publicBrowser.navigate("/en/", "[data-layout]");
		await publicBrowser.evaluate("document.fonts.ready");
		await publicBrowser.evaluate(
			"for (const image of document.images) image.loading='eager'",
		);
		await publicBrowser.ready(
			"[...document.images].every(i=>i.complete && i.naturalWidth>0)",
		);
		const preview = await evaluate(
			`(()=>{const doc=document.querySelector('[data-composition-frame]').contentDocument;return ${geometry}})()`,
		);
		const published = await publicBrowser.evaluate(
			`(()=>{const doc=document;return ${geometry}})()`,
		);
		assert.ok(Math.abs(preview.width - published.width) < 1);
		assert.equal(preview.columns.length, published.columns.length);
		for (const [index, column] of preview.columns.entries())
			for (const [coordinate, value] of column.entries())
				assert.ok(
					Math.abs(value - published.columns[index][coordinate]) < 1,
					`linked collage geometry at ${width}: ${index}/${coordinate}: ${value} vs ${published.columns[index][coordinate]}`,
				);
		const captions =
			"[...doc.querySelectorAll('[data-layout] figcaption')].map(c=>({text:c.textContent,width:c.getBoundingClientRect().width,height:c.getBoundingClientRect().height,size:doc.defaultView.getComputedStyle(c).fontSize}))";
		assert.deepEqual(
			await evaluate(
				`(()=>{const doc=document.querySelector('[data-composition-frame]').contentDocument;return ${captions}})()`,
			),
			await publicBrowser.evaluate(`(()=>{const doc=document;return ${captions}})()`),
			`localized caption geometry at ${width}`,
		);
		assert.equal(
			await publicBrowser.evaluate(
				"document.querySelectorAll('[data-layout] a').length===3 && [...document.querySelectorAll('[data-layout] a')].every(a=>a.querySelector('img[alt]') && (a.querySelector('img').alt || a.getAttribute('aria-label')) && a.closest('figure').querySelectorAll('a').length===1)",
			),
			true,
			"each linked image has one accessible link and a separate caption",
		);
	}
	await command("Emulation.setScriptExecutionDisabled", { value: true });
	await navigate(homePath, "[data-composition-editor]");
	const homeBaseRevision = await evaluate(
		"document.querySelector('[name=_revision]').value",
	);
	await evaluate(
		"document.querySelector('[data-layout-workspace]').open=true;document.querySelector('[name=collage__collage-top__c0__link]').value='article@30000000000000000000000000000001';document.querySelector('[name=\"collage__collage-top__c0__caption__en\"]').value='Native linked caption & study'",
	);
	await action(
		"link-page:collage:1",
		"document.querySelector('[name=\"collage__collage-top__c0__caption__en\"]').value==='Native linked caption & study'",
	);
	assert.equal(
		await evaluate(
			"document.querySelector('[name=collage__collage-top__c0__link]').value",
		),
		"article@30000000000000000000000000000001",
		"native target search retains unsaved mixed links and captions",
	);
	await evaluate("document.querySelector('[data-editorial-form]').requestSubmit()");
	await ready(
		`document.querySelector('[name="collage__collage-top__c0__caption__en"]').value==='Native linked caption & study' && document.readyState==='complete' && document.querySelector('[name=_revision]').value!==${JSON.stringify(homeBaseRevision)}`,
	);
	await command("Emulation.setScriptExecutionDisabled", { value: false });
	const homeRevision = await evaluate(
		"document.querySelector('[name=_revision]').value",
	);
	assert.equal(
		await evaluate(
			`(async()=>{const values=new URLSearchParams(${JSON.stringify(homeOriginal)});values.set('_revision',${JSON.stringify(homeRevision)});values.set('_edit_sequence',document.querySelector('[name=_edit_sequence]').value);return (await fetch(${JSON.stringify(homePath)},{method:'POST',body:values})).status})()`,
		),
		200,
		"collage fixture restored after the native save",
	);
	assert.deepEqual(browserErrors, []);
	assert.deepEqual(publicBrowser.browserErrors, []);
	console.log(
		"PASS composition browser: layout workspace, native mutations/undo, fitted previews, phone/tablet/desktop geometry, linked localized collage parity, save/conflict and no-script link/caption editing.",
	);
} finally {
	await browser.close();
	await publicBrowser.close();
}
