import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { resolve } from "node:path";
import { connectLocalBrowser } from "./local-browser.mjs";

const origin = process.env.CMS_BROWSER_ORIGIN ?? "http://localhost:4183";
const debug = process.env.CMS_BROWSER_DEBUG_URL ?? "http://127.0.0.1:9224";
const browser = await connectLocalBrowser(origin, debug);
const { command, evaluate, ready, navigate, browserErrors } = browser;
const owner = "/records/project/10000000000000000000000000000001";
const ids = ["d0000000000000000000000000000001", "d0000000000000000000000000000002"];
const files = ids.map((id) =>
	resolve(`profiles/reference-studio/fixtures/media/${id}-display_1600_webp.webp`),
);
const input = (id, field) =>
	`document.querySelector('[name="description_${id}__${field}"]')`;
async function submit(selector, submitter = "") {
	await evaluate(
		`document.documentElement.dataset.descriptionWaiting='1';const form=document.querySelector(${JSON.stringify(selector)});form.requestSubmit(${submitter ? `form.querySelector(${JSON.stringify(submitter)})` : ""})`,
	);
	await ready(
		"!document.documentElement.dataset.descriptionWaiting && document.readyState==='complete'",
		20000,
	);
}
async function chooseFiles(selector) {
	const { root } = await command("DOM.getDocument");
	const { nodeId } = await command("DOM.querySelector", {
		nodeId: root.nodeId,
		selector,
	});
	await command("DOM.setFileInputFiles", { nodeId, files });
}
try {
	await command("Page.enable");
	await command("Runtime.enable");
	await command("Log.enable");
	await command("Page.navigate", { url: `${origin}/login` });
	await ready(
		"document.readyState==='complete' && (document.querySelector('[name=login]') || document.querySelector('.admin-navigation'))",
	);
	if (await evaluate("!!document.querySelector('[name=login]')")) {
		await evaluate(
			"document.querySelector('[name=login]').value='admin@portable.test';document.querySelector('[name=password]').value='portable-cms-local';document.querySelector('form').requestSubmit()",
		);
	}
	await ready("location.pathname==='/' && document.querySelector('.admin-navigation')");
	const originals = await evaluate(
		`fetch('/media/descriptions?ids=${ids.join(",")}',{headers:{Accept:'application/json'}}).then(r=>r.json()).then(v=>v.items)`,
	);
	await command("Emulation.setScriptExecutionDisabled", { value: true });
	await navigate("/media", ".admin-media-grid");
	await evaluate(
		`for(const id of ${JSON.stringify(ids)})document.querySelector('[name="media_ids[]"][value="'+id+'"]').checked=true`,
	);
	await submit('form[action="/media/descriptions"]', 'button[name="_mode"]');
	assert.equal(
		await evaluate("document.querySelectorAll('[data-media-description]').length"),
		2,
	);
	for (const [index, id] of ids.entries()) {
		await evaluate(
			`${input(id, "alternativeText__ca")}.value='Authored batch alternative ${index}';${input(id, "caption__en")}.value='Batch caption ${index}';${input(id, "credit__en")}.value='Batch author ${index}'`,
		);
	}
	await submit("[data-media-descriptions-form]");
	assert.equal(
		await evaluate(`${input(ids[1], "caption__en")}.value`),
		"Batch caption 1",
	);
	assert.equal(
		await evaluate(
			"[...document.querySelectorAll('[data-description-result]')].every(p=>p.textContent==='Description saved.')",
		),
		true,
	);
	// The ordinary detail page changes metadata between the bulk form's read and write.
	await evaluate(
		`(async()=>{const body=new URLSearchParams({_csrf:document.querySelector('[name=_csrf]').value,_revision:${input(ids[0], "revision")}.value,name:${JSON.stringify(originals[0].name)}});const response=await fetch('/media/${ids[0]}',{method:'POST',body});if(!response.ok)throw new Error('Concurrent edit failed')})()`,
	);
	await evaluate(`${input(ids[0], "caption__en")}.value='Unsaved concurrent caption'`);
	await submit("[data-media-descriptions-form]");
	assert.equal(
		await evaluate(`${input(ids[0], "caption__en")}.value`),
		"Unsaved concurrent caption",
	);
	assert.match(
		await evaluate("document.querySelector('[data-description-result]').textContent"),
		/changed/i,
	);
	await evaluate(
		`${input(ids[0], "alternativeText__ca")}.value='x'.repeat(501);document.querySelector('[data-media-descriptions-form]').noValidate=true`,
	);
	await submit("[data-media-descriptions-form]");
	assert.match(
		await evaluate("document.querySelector('[data-description-result]').textContent"),
		/500/,
	);
	assert.equal(
		await evaluate(`${input(ids[0], "alternativeText__ca")}.value.length`),
		501,
	);
	await evaluate(
		`${input(ids[0], "alternativeText__ca")}.value='';${input(ids[0], "decorative")}.checked=true`,
	);
	await submit("[data-media-descriptions-form]");
	assert.equal(await evaluate(`${input(ids[0], "decorative")}.checked`), true);
	assert.equal(
		await evaluate("document.querySelector('[data-description-warning]').hidden"),
		true,
	);
	// Native multi-file upload lands directly on the description form, before processing is required.
	await navigate("/media", "#media-files");
	await evaluate(
		"document.querySelector('#media-owner').value=[...document.querySelectorAll('#media-owner option')].find(o=>o.value.startsWith('project/10000000000000000000000000000001/')).value",
	);
	await chooseFiles("#media-files");
	await submit('form[action="/media/upload"]');
	assert.equal(
		await evaluate("document.querySelectorAll('[data-media-description]').length"),
		2,
	);
	assert.equal(
		await evaluate(
			"[...document.querySelectorAll('[data-description-warning]')].every(p=>!p.hidden)",
		),
		true,
	);
	const nativeIds = await evaluate(
		"[...document.querySelectorAll('[data-media-description]')].map(c=>c.dataset.mediaDescription)",
	);
	await evaluate(
		`for(const id of ${JSON.stringify(nativeIds)})document.querySelector('[name="description_'+id+'__alternativeText__ca"]').value='Native upload description'`,
	);
	await submit("[data-media-descriptions-form]");
	assert.equal(
		await evaluate(
			"[...document.querySelectorAll('[data-description-warning]')].every(p=>p.hidden)",
		),
		true,
	);
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
	// The enhanced uploader keeps parent edits before uploading; descriptions remain separate.
	await command("Emulation.setScriptExecutionDisabled", { value: false });
	await navigate(owner, "[data-editorial-form]");
	const originalRecord = await evaluate(
		"[...new FormData(document.querySelector('[data-editorial-form]')).entries()]",
	);
	await evaluate(
		"const title=document.querySelector('[name=title__ca]');title.value='Unsaved parent title';title.dispatchEvent(new Event('input',{bubbles:true}))",
	);
	await chooseFiles("[data-media-upload-files]");
	await evaluate("document.querySelector('[data-media-upload-start]').click()");
	await ready(
		"document.readyState==='complete' && document.querySelector('[data-media-upload-result]') && document.querySelectorAll('[data-description-cards] [data-media-description]').length===2",
		20000,
	);
	const enhancedIds = await evaluate(
		"[...document.querySelectorAll('[data-description-cards] [data-media-description]')].map(c=>c.dataset.mediaDescription)",
	);
	const revision = await evaluate(
		"document.querySelector('[data-editorial-form] [name=_revision]').value",
	);
	assert.equal(
		await evaluate(
			`Array.from(document.querySelectorAll('#field-gallery input[name="gallery[]"]')).map(i=>i.value).filter(id=>${JSON.stringify(enhancedIds)}.includes(id)).length`,
		),
		2,
		"uploaded images are both selected and ready to describe",
	);
	assert.equal(
		await evaluate(
			"new FormData(document.querySelector('[data-editorial-form]')).has('media_ids[]')",
		),
		false,
	);
	assert.equal(
		await evaluate(
			"new FormData(document.querySelector('[data-upload-descriptions]:not([hidden]) button').form).has('title__ca')",
		),
		false,
	);
	await evaluate(
		`for(const id of ${JSON.stringify(enhancedIds)}){const control=document.querySelector('[name="description_'+id+'__alternativeText__ca"]');control.value='Enhanced upload description';control.dispatchEvent(new Event('input',{bubbles:true}))}document.querySelector('[data-upload-descriptions] button').click()`,
	);
	await ready(
		"document.querySelector('[data-descriptions-status]').textContent==='Description saved.'",
	);
	assert.equal(
		await evaluate("document.querySelector('[name=title__ca]').value"),
		"Unsaved parent title",
	);
	assert.equal(
		await evaluate(
			"document.querySelector('[data-editorial-form] [name=_revision]').value",
		),
		revision,
	);
	assert.equal(
		await evaluate(
			`fetch('/media/descriptions?ids=${enhancedIds.join(",")}',{headers:{Accept:'application/json'}}).then(r=>r.json()).then(v=>v.items.every(i=>i.text.translations.some(t=>t.alternativeText==='Enhanced upload description')))`,
		),
		true,
	);
	execFileSync(
		"php",
		[
			"tools/conventional-media-worker.php",
			`--config=${process.env.CMS_BROWSER_WORKER_CONFIG}`,
			"--max-jobs=10",
			"--max-seconds=60",
			"--worker-id=description-browser",
		],
		{ stdio: "pipe", timeout: 65000 },
	);
	await ready(
		"[...document.querySelectorAll('[data-description-cards] img')].length===2 && [...document.querySelectorAll('[data-description-cards] img')].every(i=>i.complete && i.naturalWidth>0)",
		20000,
	);
	await ready(
		"document.querySelector('[data-save-status]').textContent.startsWith('Saved at ')",
	);
	assert.equal(
		await evaluate(
			`(async()=>{const form=document.querySelector('[data-editorial-form]');const body=new URLSearchParams(${JSON.stringify(originalRecord)});for(const name of ['_revision','_edit_sequence','_csrf'])body.set(name,form.elements.namedItem(name).value);return (await fetch(location.pathname,{method:'POST',body})).status})()`,
		),
		200,
		"restore the parent's kept edits before later journeys",
	);
	assert.equal(
		await evaluate("document.documentElement.scrollWidth<=innerWidth"),
		true,
	);
	// Restore shared fictional media so later publication journeys see the unchanged descriptions.
	await evaluate(
		`(async()=>{const originals=${JSON.stringify(originals)};const current=await fetch('/media/descriptions?ids=${ids.join(",")}',{headers:{Accept:'application/json'}}).then(r=>r.json());const body=new URLSearchParams({_csrf:document.querySelector('[name=_csrf]').value,_descriptions_end:'1'});for(const item of originals){body.append('media_ids[]',item.id);const prefix='description_'+item.id+'__';body.set(prefix+'revision',current.items.find(i=>i.id===item.id).revision);if(item.text.decorative)body.set(prefix+'decorative','1');for(const translation of item.text.translations)for(const field of ['alternativeText','caption','credit'])body.set(prefix+field+'__'+translation.locale,translation[field]);}const response=await fetch('/media/descriptions',{method:'POST',headers:{Accept:'application/json'},body});if(!response.ok)throw new Error('Restore fictional descriptions failed')})()`,
	);
	const previousLanguage = await evaluate(
		"document.querySelector('#admin-language').value",
	);
	await evaluate(
		"fetch('/settings/language',{method:'POST',body:new URLSearchParams({_csrf:document.querySelector('[name=_csrf]').value,language:'de',_return:'/media'})}).then(r=>{if(!r.ok)throw new Error('Language change failed')})",
	);
	await command("Emulation.setScriptExecutionDisabled", { value: true });
	await command("Emulation.setDeviceMetricsOverride", {
		width: 1440,
		height: 900,
		deviceScaleFactor: 1,
		mobile: false,
	});
	await navigate("/media", ".admin-media-grid");
	assert.equal(await evaluate("document.documentElement.lang"), "de");
	assert.equal(
		await evaluate("document.documentElement.scrollWidth<=innerWidth"),
		true,
		"German library labels wrap long uploaded filenames",
	);
	await command("Emulation.setDeviceMetricsOverride", {
		width: 360,
		height: 800,
		deviceScaleFactor: 1,
		mobile: false,
	});
	assert.equal(
		await evaluate("document.documentElement.scrollWidth<=innerWidth"),
		true,
		"German library also fits a narrow screen without scripts",
	);
	await evaluate(
		`fetch('/settings/language',{method:'POST',body:new URLSearchParams({_csrf:document.querySelector('[name=_csrf]').value,language:${JSON.stringify(previousLanguage)},_return:'/media'})}).then(r=>{if(!r.ok)throw new Error('Language restoration failed')})`,
	);
	assert.deepEqual(browserErrors, []);
	console.log(
		"PASS image descriptions: native multi-upload and bulk edits, missing alternatives, decoration, overlong text, concurrent library edits, inline descriptions remain separate from kept records, real thumbnails and 360px layout.",
	);
} finally {
	await command("Emulation.setScriptExecutionDisabled", { value: false });
	await browser.close();
}
