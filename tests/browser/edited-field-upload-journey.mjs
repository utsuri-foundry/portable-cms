import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { basename, resolve } from "node:path";
import { connectLocalBrowser } from "./local-browser.mjs";

const origin = process.env.CMS_BROWSER_ORIGIN;
let chooserOpened = null;
const browser = await connectLocalBrowser(
	origin,
	process.env.CMS_BROWSER_DEBUG_URL,
	(method, params) => {
		if (method === "Page.fileChooserOpened") chooserOpened?.(params);
	},
);
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
/** Without scripts, Upload images opens the native form in place. */
async function chooseNativeFiles(paths) {
	await evaluate(
		"(()=>{const choice=document.querySelector('#field-gallery details.admin-picker__upload');if(!choice.open)choice.querySelector(':scope > summary').click()})()",
	);
	assert.equal(
		await evaluate(
			"document.querySelector('#media-upload-files-gallery').checkVisibility()",
		),
		true,
		"Upload images reveals the native input",
	);
	await setFiles(input, paths);
}
/** With scripts, Upload images is one button: it opens the file chooser and choosing uploads. */
async function uploadFiles(paths) {
	const choose = "#field-gallery [data-media-upload-choose]";
	await ready(`document.querySelector(${JSON.stringify(choose)})?.checkVisibility()`);
	assert.equal(
		await evaluate(
			"!document.querySelector('#field-gallery details.admin-picker__browse') && !document.querySelector('#field-gallery details.admin-picker__upload') && !document.querySelector('#media-upload-files-gallery').checkVisibility()",
		),
		true,
		"no disclosure or file input stands before the upload button",
	);
	await command("Page.setInterceptFileChooserDialog", { enabled: true });
	const opened = new Promise((resolve) => {
		chooserOpened = resolve;
	});
	await evaluate(`document.querySelector(${JSON.stringify(choose)}).click()`);
	const chooser = await opened;
	chooserOpened = null;
	assert.equal(chooser.mode, "selectMultiple", "a gallery accepts several files");
	await command("DOM.setFileInputFiles", {
		backendNodeId: chooser.backendNodeId,
		files: paths,
	});
	await command("Page.setInterceptFileChooserDialog", { enabled: false });
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
	// A transfer that fails before any file completes says so in the file's row and keeps the way to retry.
	await evaluate(
		"(()=>{const send=window.fetch.bind(window);let failed=false;window.fetch=(url,options)=>{if(!failed && String(url).startsWith('/api/records/') && options?.method==='POST'){failed=true;window.fetch=send;return Promise.reject(new TypeError('Failed to fetch'))}return send(url,options)}})()",
	);
	await uploadFiles([files[0]]);
	await ready(
		"document.querySelector('#field-gallery [data-upload-row] .admin-field__error')",
	);
	assert.equal(
		await evaluate(
			"document.querySelector('#field-gallery [data-upload-row] .admin-field__error').textContent",
		),
		"The upload failed. Upload it again.",
		"a dropped connection is said in plain words with what to do",
	);
	assert.equal(
		await evaluate(
			"document.activeElement===document.querySelector('#field-gallery [data-media-upload-choose]')",
		),
		true,
		"the upload button keeps the focus for another try",
	);
	assert.deepEqual(await ids(), original, "nothing joins the field");
	// Interrupt the second transfer. The first completed upload must still join the field.
	await evaluate(
		"(()=>{const send=window.fetch.bind(window);let count=0;window.fetch=(url,options)=>{if(String(url).startsWith('/api/records/') && options?.method==='POST'){count++;if(count===1)return new Promise(resolve=>{window.resumeFieldUpload=()=>resolve(send(url,options))});if(count===2)return Promise.reject(new Error('Interrupted field batch'))}return send(url,options)}})()",
	);
	await uploadFiles(files);
	await ready("typeof window.resumeFieldUpload==='function'");
	revision = await evaluate("document.querySelector('[name=_revision]').value");
	assert.equal(
		await evaluate(
			"document.querySelector('[data-upload-field=gallery]').dataset.recordRevision",
		),
		revision,
		"automatic keeping refreshes the uploader's revision without replacing its controls",
	);
	assert.deepEqual(
		await evaluate(
			"Array.from(document.querySelectorAll('#field-gallery [data-upload-row]')).map(row=>[row.lastChild.textContent,!!row.querySelector('progress')])",
		),
		files.map((file) => [basename(file), true]),
		"each chosen file is a row of the field with its own progress",
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
	await uploadFiles(files);
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
	const preparing = await evaluate(
		"Array.from(document.querySelectorAll('#field-gallery [data-media-pending] [data-media-state]')).map(state=>state.textContent)",
	);
	assert.equal(preparing.length, 2, "both new images are waiting");
	for (const line of preparing)
		assert.match(
			line,
			/^Being prepared\. Ready in (?:a few minutes|about \d+ minutes?)\.$/,
			"new images say when they will be ready, not an engine state",
		);
	// Waiting for preparation is not a failed save: the rest of the record is kept.
	await ready(
		"document.querySelector('[data-save-status]').textContent.startsWith('Saved at ') && document.querySelector('[data-keep-retry]')?.hidden !== false",
	);
	assert.equal(
		await evaluate("document.querySelectorAll('[data-keep-error]').length"),
		0,
		"and puts no error under the field",
	);
	worker();
	await ready(
		"!document.querySelector('#field-gallery [data-media-pending]') && document.querySelectorAll('#field-gallery .admin-selections img.admin-thumbnail').length>=2",
		40000,
	);
	// Once prepared, the images are kept with the record without any action.
	await ready(
		`fetch(location.pathname).then(r=>r.text()).then(h=>new DOMParser().parseFromString(h,'text/html').querySelectorAll('#field-gallery input[name="gallery[]"]').length===${completed.length})`,
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
	await chooseNativeFiles([files[0]]);
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
	const beforePick = await ids();
	await evaluate(
		"document.querySelector('#field-gallery details.admin-picker__library > summary').click()",
	);
	const pick = await evaluate(
		"(()=>{const button=document.querySelector('#field-gallery .admin-picker__pick');button.focus();return document.activeElement===button?button.value.split(':')[2]:null})()",
	);
	assert.ok(pick, "a ready library image is one focusable button");
	assert.equal(
		await evaluate("document.querySelectorAll('[name=\"_pick_gallery[]\"]').length"),
		0,
		"there is nothing to tick before adding",
	);
	await command("Input.dispatchKeyEvent", {
		type: "keyDown",
		key: "Enter",
		code: "Enter",
		windowsVirtualKeyCode: 13,
		text: "\r",
	});
	await command("Input.dispatchKeyEvent", {
		type: "keyUp",
		key: "Enter",
		code: "Enter",
		windowsVirtualKeyCode: 13,
	});
	await ready(`(${idsExpression}).length===${beforePick.length + 1}`);
	assert.deepEqual(
		await ids(),
		[...beforePick, pick],
		"activating a library image from the keyboard adds it at once",
	);
	assert.equal(
		await evaluate(
			"document.querySelector('#field-gallery details.admin-picker__library').open",
		),
		false,
		"the chooser closes after the pick",
	);
	assert.equal(
		await evaluate("document.querySelector('#field-title-ca').value"),
		"Native unsaved title",
		"picking preserves the form too",
	);
	await evaluate(`document.querySelector('[value="remove:gallery:${pick}"]').click()`);
	await ready(`!(${idsExpression}).includes(${JSON.stringify(pick)})`);
	assert.deepEqual(await ids(), beforePick, "removing the row undoes the pick");
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
		"PASS edited-field uploads: interrupted enhanced batch/resume, ordered native additions, preserved unsaved form/revision, one-activation library pick and undo, upload rows, preparation and failure states, library retention and 360px no-script use.",
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
