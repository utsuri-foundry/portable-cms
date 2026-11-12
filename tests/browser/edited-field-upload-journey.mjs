import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { basename, resolve } from "node:path";
import { connectLocalBrowser } from "./local-browser.mjs";

const origin = process.env.CMS_BROWSER_ORIGIN;
const browser = await connectLocalBrowser(origin, process.env.CMS_BROWSER_DEBUG_URL);
const { command, evaluate, ready, navigate, setFiles, browserErrors } = browser;
const path = "/records/project/10000000000000000000000000000001";
const input = "#media-upload-files-gallery";
const panel = '[data-upload-field="gallery"]';
const files = [2, 1].map((number) =>
	resolve(
		`profiles/reference-studio/fixtures/media/d000000000000000000000000000000${number}-display_1600_webp.webp`,
	),
);
const idsExpression =
	"Array.from(document.querySelectorAll('#field-gallery .admin-selections input[name=\"gallery[]\"]')).map(i=>i.value)";
const ids = () => evaluate(idsExpression);
async function chooseFiles(paths) {
	await evaluate(
		"for(const selector of ['#field-gallery > details.admin-picker__browse','#field-gallery details.admin-picker__upload']){const choice=document.querySelector(selector);if(!choice.open)choice.querySelector(':scope > summary').click()}",
	);
	assert.equal(
		await evaluate(
			"document.querySelector('#media-upload-files-gallery').checkVisibility()",
		),
		true,
		"Add images → Upload images reveals the native input",
	);
	await setFiles(input, paths);
}
async function enhancedUpload() {
	await ready(
		`!document.querySelector(${JSON.stringify(panel + " [data-media-upload-start]")}).hidden`,
	);
	await evaluate(
		`document.querySelector(${JSON.stringify(panel + " [data-media-upload-start]")}).click()`,
	);
}
function worker() {
	execFileSync(
		"php",
		[
			"tools/conventional-media-worker.php",
			`--config=${process.env.CMS_BROWSER_WORKER_CONFIG}`,
			"--max-jobs=10",
			"--max-seconds=30",
			"--worker-id=field-browser",
		],
		{ timeout: 40000 },
	);
}
try {
	await command("Page.enable");
	await command("Runtime.enable");
	await command("Log.enable");
	await command("Emulation.setDeviceMetricsOverride", {
		width: 360,
		height: 800,
		deviceScaleFactor: 1,
		mobile: false,
	});
	await command("Page.navigate", { url: origin + "/login" });
	await ready(
		"document.readyState==='complete' && (document.querySelector('[name=login]') || document.querySelector('.admin-navigation'))",
	);
	if (await evaluate("!!document.querySelector('[name=login]')")) {
		await evaluate(
			"document.querySelector('[name=login]').value='admin@portable.test';document.querySelector('[name=password]').value='portable-cms-local';document.querySelector('form').requestSubmit()",
		);
		await ready(
			"location.pathname==='/' && document.querySelector('.admin-navigation')",
		);
	}
	await navigate(path, input);
	assert.equal(
		await evaluate(
			"!document.querySelector('#media-upload-files-gallery').checkVisibility()",
		),
		true,
		"the field uploader starts collapsed",
	);
	const initial = await ids();
	let original = initial;
	const title = await evaluate("document.querySelector('#field-title-ca').value");
	let revision = await evaluate("document.querySelector('[name=_revision]').value");
	await evaluate(
		"const t=document.querySelector('#field-title-ca');t.value='Unsaved upload title';t.dispatchEvent(new Event('input',{bubbles:true}))",
	);
	// Interrupt the second transfer. The first completed upload must still join the field.
	await evaluate(
		"(()=>{const send=window.fetch.bind(window);let count=0;window.fetch=(url,options)=>{if(String(url).startsWith('/api/records/') && options?.method==='POST'){count++;if(count===1)return new Promise(resolve=>{window.resumeFieldUpload=()=>resolve(send(url,options))});if(count===2)return Promise.reject(new Error('Interrupted field batch'))}return send(url,options)}})()",
	);
	await chooseFiles(files);
	await enhancedUpload();
	await ready("typeof window.resumeFieldUpload==='function'");
	revision = await evaluate("document.querySelector('[name=_revision]').value");
	assert.equal(
		await evaluate(
			"document.querySelector('[data-upload-field=gallery]').dataset.recordRevision",
		),
		revision,
		"automatic keeping refreshes the uploader's revision without replacing its controls",
	);
	const blocked = await evaluate(
		"Array.from(document.querySelectorAll('[data-editorial-form] [data-media-upload-start]')).map(b=>b.disabled)",
	);
	assert.ok(
		blocked.length >= 2 && blocked.every(Boolean),
		"record upload panels cannot submit overlapping batches",
	);
	await evaluate("window.resumeFieldUpload()");
	await ready(
		`document.querySelector('[data-media-upload-result]') && document.querySelector('[data-validation-summary]') && (${idsExpression}).length===${original.length + 1}`,
	);
	const partial = await ids();
	assert.deepEqual(
		partial.slice(0, original.length),
		original,
		"existing selections keep their order after a partial upload",
	);
	assert.equal(
		await evaluate("document.querySelector('#field-title-ca').value"),
		"Unsaved upload title",
		"enhanced partial failure preserves other unsaved fields",
	);
	assert.equal(
		await evaluate("document.querySelector('[name=_revision]').value"),
		revision,
		"upload choices stay within the current editing revision",
	);
	await chooseFiles(files);
	await enhancedUpload();
	await ready(
		`document.querySelector('[data-media-upload-result]') && !document.querySelector('[data-validation-summary]') && (${idsExpression}).length===${original.length + 2}`,
	);
	const completed = await ids();
	assert.deepEqual(
		completed.slice(0, partial.length),
		partial,
		"retry reuses the completed first image instead of duplicating it",
	);
	assert.deepEqual(
		await evaluate(
			"Array.from(document.querySelectorAll('#field-gallery .admin-selections > li > span:not(.admin-thumbnail):not(.admin-selection__actions)')).slice(-2).map(e=>e.firstChild.textContent.trim())",
		),
		files.map((file) => basename(file)),
		"appended images follow file order rather than library or completion order",
	);
	assert.equal(
		await evaluate("document.querySelector('#field-title-ca').value"),
		"Unsaved upload title",
	);
	worker();
	await ready(
		"document.querySelector('[data-save-status]').textContent.startsWith('Saved at ')",
	);
	original = completed;
	revision = await evaluate("document.querySelector('[name=_revision]').value");
	// The native path submits the entire form, including unrelated unsaved text.
	await command("Emulation.setScriptExecutionDisabled", { value: true });
	await navigate(path, input);
	assert.equal(
		await evaluate(
			"!document.querySelector('#media-upload-files-gallery').checkVisibility()",
		),
		true,
		"the native uploader also starts hidden until chosen",
	);
	assert.deepEqual(
		await ids(),
		original,
		"scripted field selections are kept once their media are prepared",
	);
	await evaluate(
		"document.querySelector('#field-title-ca').value='Native unsaved title'",
	);
	await chooseFiles([files[0]]);
	await evaluate(
		`document.querySelector('[data-editorial-form]').requestSubmit(document.querySelector(${JSON.stringify(panel + " [data-native-upload-start]")}))`,
	);
	await ready(
		`location.pathname.endsWith('/upload') && document.querySelector('[data-media-upload-result]') && (${idsExpression}).length===${original.length + 1}`,
	);
	const native = await ids();
	assert.equal(
		await evaluate(
			"document.querySelectorAll('[data-description-cards] [data-media-description]').length",
		),
		1,
		"native field uploads retain immediate description controls",
	);
	assert.equal(
		await evaluate(
			"new FormData(document.querySelector('[data-editorial-form]')).has('media_ids[]')",
		),
		false,
		"description metadata is separate from the pending record",
	);
	const targetsBefore = new Set(
		(await command("Target.getTargets")).targetInfos.map((target) => target.targetId),
	);
	await evaluate(
		`document.querySelector('[name="description_${native.at(-1)}__alternativeText__ca"]').value='Native image alternative';document.querySelector('[data-upload-descriptions]:not([hidden]) button').click()`,
	);
	await ready(
		`fetch('/media/descriptions?ids=${native.at(-1)}',{headers:{Accept:'application/json'}}).then(r=>r.json()).then(v=>v.items?.[0]?.text.translations.some(t=>t.locale==='ca' && t.alternativeText==='Native image alternative'))`,
	);
	const descriptionTabs = (await command("Target.getTargets")).targetInfos.filter(
		(target) =>
			!targetsBefore.has(target.targetId) &&
			target.url.startsWith(`${origin}/media/descriptions`),
	);
	assert.equal(descriptionTabs.length, 1, "native metadata saves open a separate tab");
	for (const target of descriptionTabs)
		await command("Target.closeTarget", { targetId: target.targetId });
	assert.deepEqual(
		await ids(),
		native,
		"native description saves retain pending field choices",
	);
	assert.deepEqual(
		native.slice(0, original.length),
		original,
		"native uploads append after the saved selection",
	);
	assert.equal(
		await evaluate("document.querySelector('#field-title-ca').value"),
		"Native unsaved title",
		"native upload preserves unrelated input",
	);
	assert.equal(
		await evaluate("document.querySelector('[name=_revision]').value"),
		revision,
		"native uploads are transient too",
	);
	worker();
	await evaluate(
		`document.querySelector('[value="remove:gallery:${original[0]}"]').click()`,
	);
	await ready(`!(${idsExpression}).includes(${JSON.stringify(original[0])})`);
	const beforeBulk = await ids();
	const picks = await evaluate(
		"(()=>{const boxes=Array.from(document.querySelectorAll('[name=\"_pick_gallery[]\"]')).slice(0,2);for(const box of boxes)box.checked=true;return boxes.map(b=>b.value)})()",
	);
	assert.equal(picks.length, 2, "two ready library images can be checked together");
	await evaluate("document.querySelector('[value=\"add-media:gallery\"]').click()");
	await ready(`(${idsExpression}).length===${beforeBulk.length + 2}`);
	assert.deepEqual(
		await ids(),
		[...beforeBulk, ...picks],
		"one native submission adds both library choices in picker order",
	);
	assert.equal(
		await evaluate("document.querySelector('#field-title-ca').value"),
		"Native unsaved title",
		"bulk picking preserves the form too",
	);
	const removed = native.at(-1);
	await evaluate(
		`document.querySelector('[value="remove:gallery:${removed}"]').click()`,
	);
	await ready(`!(${idsExpression}).includes(${JSON.stringify(removed)})`);
	await navigate(`/media/${removed}`, "[name=name]");
	assert.equal(
		await evaluate("!!document.querySelector('[name=name]')"),
		true,
		"removal keeps the image in the library",
	);
	await navigate(path, input);
	assert.deepEqual(
		await ids(),
		original,
		"no-script field operations require an explicit Save",
	);
	assert.equal(
		await evaluate("document.querySelector('#field-title-ca').value"),
		"Unsaved upload title",
		"no-script edits do not replace the automatically kept title",
	);
	assert.equal(
		await evaluate("document.documentElement.scrollWidth<=innerWidth+1"),
		true,
		"field uploads and bulk selection fit a narrow screen",
	);
	assert.deepEqual(
		browserErrors,
		[],
		"upload and picker interactions have no browser errors",
	);
	await evaluate(`(() => {
		document.querySelector('#field-title-ca').value=${JSON.stringify(title)};
		for (const input of document.querySelectorAll('[name="gallery[]"]')) input.remove();
		const form=document.querySelector('[data-editorial-form]');
		for(const id of ${JSON.stringify(initial)}) { const input=document.createElement('input');input.type='hidden';input.name='gallery[]';input.value=id;form.append(input); }
		form.requestSubmit(document.querySelector('[data-record-save]'));
	})()`);
	await ready(
		`document.querySelector('[name=_revision]')?.value!==${JSON.stringify(revision)} && document.readyState==='complete'`,
	);
	console.log(
		"PASS edited-field uploads: interrupted enhanced batch/resume, ordered native additions, preserved unsaved form/revision, one-action multi-select, library retention and 360px no-script use.",
	);
} catch (error) {
	process.stderr.write(
		`${JSON.stringify(await evaluate("({saved:document.querySelector('[data-save-status]')?.textContent, upload:[...document.querySelectorAll('[data-upload-field=gallery] [data-media-upload-status]')].map(n=>n.textContent), controls:[...document.querySelectorAll('[data-upload-field=gallery] [data-media-upload-start]')].map(n=>({disabled:n.disabled,hidden:n.hidden})), busy:document.querySelector('[data-editorial-form]')?.dataset.uploadBusy, errors:[...document.querySelectorAll('[data-keep-error]')].map(n=>n.textContent), revision:document.querySelector('[data-upload-field=gallery]')?.dataset.recordRevision})").catch(() => null))}\n`,
	);
	throw error;
} finally {
	await command("Emulation.setScriptExecutionDisabled", { value: false }).catch(
		() => {},
	);
	await browser.close();
}
