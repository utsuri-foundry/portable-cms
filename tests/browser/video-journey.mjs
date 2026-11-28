import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { resolve } from "node:path";
import { connectLocalBrowser } from "./local-browser.mjs";

const origin = process.env.CMS_BROWSER_ORIGIN;
const videoRequests = [];
let chooserOpened = null;
const browser = await connectLocalBrowser(
	origin,
	process.env.CMS_BROWSER_DEBUG_URL,
	(method, params) => {
		if (method === "Page.fileChooserOpened") chooserOpened?.(params);
		if (
			method === "Network.requestWillBeSent" &&
			/\/media\/[a-f0-9]{32}\/video$/.test(new URL(params.request.url).pathname)
		)
			videoRequests.push(params.request.url);
	},
);
const { command, evaluate, navigate, ready, browserErrors } = browser;
const record = "/records/project/10000000000000000000000000000001";
// Native responses stream their form; wait for a new complete form before submitting again.
async function submitNative(expression) {
	await evaluate(
		`document.querySelector('[data-editorial-form]').setAttribute('data-video-submission','');${expression}`,
	);
	await ready(
		"document.readyState==='complete' && !document.querySelector('[data-video-submission]') && document.querySelector('[data-editorial-form] [name=_editorial_end]')?.value==='1'",
	);
}
// Each visible row names its file plainly: no badge or card is drawn over the name.
async function rowsReadPlainly(width, names) {
	const rows = await evaluate(
		`[...document.querySelectorAll('#field-trailer [data-video-row]:not([hidden])')].map(row => {const name=row.querySelector('.admin-video-row__name');const box=name.getBoundingClientRect();name.scrollIntoView({block:'center'});const after=name.getBoundingClientRect();const top=document.elementFromPoint(after.left+4,after.top+after.height/2);return {text:name.textContent,before:getComputedStyle(row,'::before').content,visible:box.width>0&&box.height>0,covered:!(top===name||name.contains(top))}})`,
	);
	assert.deepEqual(
		rows.map((row, index) => (names[index] === null ? null : row.text)),
		names,
		`rows are plainly named at ${width}px`,
	);
	for (const row of rows) {
		assert.equal(
			row.before,
			"none",
			`no badge is drawn before ${row.text} at ${width}px`,
		);
		assert.equal(row.visible, true, `${row.text} is visible at ${width}px`);
		assert.equal(row.covered, false, `nothing covers ${row.text} at ${width}px`);
	}
}
function worker() {
	execFileSync(
		"php",
		[
			"tools/conventional-media-worker.php",
			`--config=${process.env.CMS_BROWSER_WORKER_CONFIG}`,
			"--max-jobs=10",
			"--max-seconds=30",
			"--worker-id=video-browser",
		],
		{ timeout: 40000 },
	);
}
try {
	await command("Page.enable");
	await command("Runtime.enable");
	await command("Log.enable");
	await command("Network.enable");
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
	await navigate(record, "[name=trailer__video]");
	const original = await evaluate(
		"Object.fromEntries(new FormData(document.querySelector('[data-editorial-form]')))",
	);
	assert.equal(
		await evaluate("document.querySelector('[name=trailer]').tagName").catch(
			() => null,
		),
		null,
	);
	assert.match(
		await evaluate("document.querySelector('#media-upload-files-trailer').accept"),
		/video\/mp4/,
	);
	const { root } = await command("DOM.getDocument");
	const { nodeId } = await command("DOM.querySelector", {
		nodeId: root.nodeId,
		selector: "#media-upload-files-trailer",
	});
	await command("DOM.setFileInputFiles", {
		nodeId,
		files: [
			resolve("tests/fixtures/video/prepared.mp4"),
			resolve("tests/fixtures/video/prepared.webm"),
		],
	});
	await evaluate(
		"document.querySelector('[data-editorial-form]').requestSubmit(document.querySelector('[data-upload-field=\"trailer\"] [data-native-upload-start]'))",
	);
	await ready(
		"document.readyState==='complete' && document.querySelector('[name=trailer__video]')?.value!==''",
	);
	assert.equal(
		await evaluate("document.querySelector('[data-media-upload-result]')"),
		null,
		"no notice far from the field",
	);
	const joined = await evaluate(
		"[document.querySelector('#field-trailer [data-video-name=video]').textContent, document.querySelector('#field-trailer [data-video-row=video] [data-media-state]')?.textContent, document.querySelector('#field-trailer [data-video-row=video]').hasAttribute('data-media-pending')]",
	);
	assert.equal(joined[0], "prepared.mp4", "the first uploaded video joins the field");
	assert.match(
		joined[1],
		/^Being prepared\. Ready in /,
		"and says when it will be ready",
	);
	assert.equal(joined[2], true, "its row asks again until it is ready");
	assert.equal(
		await evaluate(
			"document.querySelector('#field-trailer [data-video-choosers=poster]').hidden",
		),
		false,
		"the poster choice follows, with its own upload",
	);
	worker();
	await navigate(record, "[name=trailer__video]");
	const media = await evaluate(
		"Object.fromEntries([...document.querySelectorAll('#field-trailer [data-video-choose=video]')].map(o=>[o.dataset.mediaName,o.dataset.mediaId]))",
	);
	assert.ok(media["prepared.mp4"] && media["prepared.webm"]);
	// Native picker actions update the transient form without saving its revision.
	const nativeRevision = await evaluate(
		"document.querySelector('[name=_revision]').value",
	);
	await submitNative(
		`document.querySelector('[value="select-video:trailer:${media["prepared.mp4"]}"]').click()`,
	);
	await ready(
		`document.readyState==='complete' && document.querySelector('[name=trailer__video]').value===${JSON.stringify(media["prepared.mp4"])}`,
	);
	assert.equal(
		await evaluate("document.querySelector('[name=_revision]').value"),
		nativeRevision,
	);
	assert.equal(
		await evaluate(
			"document.querySelector('#field-trailer [data-video-name=video]').textContent",
		),
		"prepared.mp4",
		"the chosen clip is named in its row",
	);
	const poster = "d0000000000000000000000000000003";
	await submitNative(
		`document.querySelector('[value="select-poster:trailer:${poster}"]').click()`,
	);
	await ready(
		`document.readyState==='complete' && document.querySelector('[name=trailer__poster]').value==='${poster}'`,
	);
	assert.match(
		await evaluate(
			"document.querySelector('#field-trailer [data-video-thumb=poster] img').src",
		),
		new RegExp(`/media/${poster}/thumbnail$`),
	);
	await rowsReadPlainly(await evaluate("innerWidth"), ["prepared.mp4", null]);
	await submitNative(
		`document.querySelector('[name=trailer__video]').value=${JSON.stringify(media["prepared.mp4"])};document.querySelector('[name=trailer__poster]').value='${poster}';document.querySelector('[data-editorial-form]').requestSubmit()`,
	);
	await ready(
		`location.pathname===${JSON.stringify(record)} && document.querySelector('[name=trailer__video]')?.value===${JSON.stringify(media["prepared.mp4"])} && document.readyState==='complete'`,
	);
	assert.equal(
		await evaluate("document.querySelector('[role=alert]')?.textContent ?? null"),
		null,
	);
	const source = await evaluate(
		"document.querySelector('#field-layout select[name$=__source]').name",
	);
	const originalSource = await evaluate(
		`document.querySelector('[name="${source}"]').value`,
	);
	await evaluate(
		`document.querySelector('[name="${source}"]').value='video:trailer';document.querySelector('[data-composition-preview-button]').click()`,
	);
	await ready(
		"document.querySelector('[data-composition-frame]').contentDocument.querySelector('video') !== null",
	);
	assert.equal(
		await evaluate(
			"document.querySelector('[data-composition-frame]').contentDocument.querySelector('video').getAttribute('preload')",
		),
		"none",
	);
	assert.match(
		await evaluate(
			"document.querySelector('[data-composition-frame]').contentDocument.querySelector('video').poster",
		),
		new RegExp(`/media/${poster}/thumbnail$`),
	);
	// Real native playback consumes authenticated immutable bytes with no page scripts.
	await evaluate(
		"document.querySelector('[data-composition-frame]').contentDocument.querySelector('video').play()",
	);
	await ready(
		"document.querySelector('[data-composition-frame]').contentDocument.querySelector('video').readyState>=2",
	);
	assert.equal(
		await evaluate(
			"document.querySelector('[data-composition-frame]').contentDocument.querySelector('video').videoWidth",
		),
		160,
	);
	// A missing poster produces a validation error and retains the selected video.
	await submitNative(
		"document.querySelector('[name=trailer__poster]').value='';document.querySelector('[data-editorial-form]').requestSubmit()",
	);
	await ready("document.querySelector('[data-validation-summary]') !== null");
	assert.equal(
		await evaluate("document.querySelector('[name=trailer__video]').value"),
		media["prepared.mp4"],
	);
	// WebM selection and the same poster survive ordinary native save/reload.
	await submitNative(
		`document.querySelector('[name=trailer__video]').value=${JSON.stringify(media["prepared.webm"])};document.querySelector('[name=trailer__poster]').value='${poster}';document.querySelector('[name="${source}"]').value=${JSON.stringify(originalSource)};document.querySelector('[data-editorial-form]').requestSubmit()`,
	);
	await ready(
		`location.pathname===${JSON.stringify(record)} && document.querySelector('[name=trailer__video]')?.value===${JSON.stringify(media["prepared.webm"])} && !document.querySelector('[data-validation-summary]')`,
	);
	await command("Emulation.setDeviceMetricsOverride", {
		width: 360,
		height: 800,
		deviceScaleFactor: 1,
		mobile: false,
	});
	await ready("!!document.querySelector('link[href^=\"/assets/admin.css\"]')?.sheet");
	assert.equal(
		await evaluate("document.documentElement.scrollWidth<=innerWidth"),
		true,
	);
	await rowsReadPlainly(360, ["prepared.webm", null]);
	const savedRevision = await evaluate(
		"document.querySelector('[name=_revision]').value",
	);
	await submitNative(
		`document.querySelector('[name=_revision]').value=String(Number(${JSON.stringify(savedRevision)})-1);document.querySelector('[data-editorial-form]').requestSubmit()`,
	);
	await ready("document.querySelector('[data-conflict-summary]') !== null");
	await ready(
		"document.readyState!=='loading' && document.querySelector('[data-editorial-form] [name=_editorial_end]')?.value==='1'",
	);
	assert.equal(
		await evaluate("document.querySelector('[name=_editor_base_revision]').value"),
		String(Number(savedRevision) - 1),
	);
	assert.equal(
		await evaluate("document.querySelector('[name=trailer__video]').value"),
		media["prepared.webm"],
	);
	assert.equal(
		await evaluate("document.querySelector('[name=trailer__poster]').value"),
		poster,
	);
	// Restore the original choices for subsequent independent journeys.
	// Fetch a current native form rather than reusing the conflict response.
	await navigate(record, "[name=trailer__video]");
	const restoreRevision = await evaluate(
		"document.querySelector('[name=_revision]').value",
	);
	assert.equal(restoreRevision, savedRevision, "A rejected save must not commit");
	assert.equal(
		await evaluate(
			"document.querySelector('[data-conflict-summary], [name=_editor_base_revision]') !== null",
		),
		false,
	);
	await submitNative(
		`document.querySelector('[name=trailer__video]').value=${JSON.stringify(original.trailer__video ?? "")};document.querySelector('[name=trailer__poster]').value=${JSON.stringify(original.trailer__poster ?? "")};document.querySelector('[data-editorial-form]').requestSubmit()`,
	);
	// Native controls are usable while library thumbnails elsewhere on the page load.
	await ready(
		`!document.querySelector('[data-validation-summary]') && !document.querySelector('[data-conflict-summary]') && document.readyState!=='loading' && document.querySelector('[name=_revision]').value===${JSON.stringify(String(Number(restoreRevision) + 1))} && document.querySelector('[name=trailer__video]').value===${JSON.stringify(original.trailer__video ?? "")} && document.querySelector('[name=trailer__poster]').value===${JSON.stringify(original.trailer__poster ?? "")}`,
	);
	await command("Emulation.setScriptExecutionDisabled", { value: false });
	await navigate(record, "#media-upload-files-trailer");
	assert.deepEqual(
		await evaluate(
			"[document.querySelector('[name=trailer__video]').value, document.querySelector('[name=trailer__poster]').value, document.querySelector('[name=_revision]').value]",
		),
		[
			original.trailer__video ?? "",
			original.trailer__poster ?? "",
			String(Number(restoreRevision) + 1),
		],
		"Original video and poster choices must persist for subsequent journeys",
	);
	// Enhanced choices update immediately and fetch no video until play.
	videoRequests.length = 0;
	const beforeChoice = await evaluate("location.href");
	await evaluate(
		`document.querySelector('#field-trailer details').open=true;document.querySelector('[value="select-video:trailer:${media["prepared.mp4"]}"]').click()`,
	);
	assert.equal(
		await evaluate("document.querySelector('[name=trailer__video]').value"),
		media["prepared.mp4"],
	);
	assert.equal(await evaluate("location.href"), beforeChoice);
	assert.equal(videoRequests.length, 0, "candidate selection must not fetch video");
	assert.deepEqual(
		await evaluate(
			"[document.querySelector('#field-trailer [data-video-choosers=video]').hidden, document.querySelector('#field-trailer [data-video-browse=video]').open, document.querySelector('#field-trailer [data-video-choosers=poster]').hidden]",
		),
		[true, false, false],
		"choosing closes the library and offers what is still missing, the poster",
	);
	await evaluate(
		`document.querySelector('#field-trailer [data-video-browse=poster]').open=true;document.querySelector('[value="select-poster:trailer:${poster}"]').click()`,
	);
	assert.match(
		await evaluate(
			"document.querySelector('#field-trailer [data-video-thumb=poster] img').src",
		),
		new RegExp(`/media/${poster}/thumbnail$`),
	);
	assert.equal(
		await evaluate(
			"document.querySelector('#field-trailer [data-video-row=poster]').hidden",
		),
		false,
		"the poster row appears with the choice",
	);
	assert.equal(videoRequests.length, 0, "choosing a poster must not fetch the clip");
	assert.equal(
		await evaluate("document.querySelector('#field-trailer video')"),
		null,
		"the field draws no player; its thumbnails stand for the files",
	);
	await ready(
		"document.querySelector('[data-save-status]').textContent.startsWith('Saved at ')",
	);
	await command("Page.navigate", { url: `${origin}/media?q=prepared` });
	await ready(
		"location.pathname==='/media' && document.readyState==='complete' && document.querySelector('[data-video-thumbnail]')",
	);
	for (const filename of ["prepared.mp4", "prepared.webm"]) {
		const tile = `[data-video-thumbnail][data-media-id="${media[filename]}"]`;
		await evaluate(
			`document.querySelector(${JSON.stringify(tile)}).scrollIntoView({block:'center'})`,
		);
		try {
			await ready(
				`(() => {const image=document.querySelector(${JSON.stringify(`${tile} [data-video-frame-image]`)}); return image && !image.hidden && image.naturalWidth>0;})()`,
			);
		} catch (failure) {
			console.error(
				filename,
				await evaluate(
					`(() => {const tile=document.querySelector(${JSON.stringify(tile)}); const image=tile?.querySelector('img'); return {tile:tile?.dataset, image:image && {src:image.getAttribute('src'),hidden:image.hidden,loading:image.loading,complete:image.complete,width:image.naturalWidth}};})()`,
				),
			);
			throw failure;
		}
	}
	assert.equal(
		await evaluate(
			"document.querySelector('[data-video-thumbnail]').textContent.includes('…')",
		),
		false,
	);
	await navigate(`/media/${media["prepared.mp4"]}`, "[data-video-player]");
	assert.equal(
		await evaluate("document.querySelector('[data-video-player]').preload"),
		"metadata",
	);
	// The item page states the size facts once: kind, duration, pixels and bytes.
	assert.match(
		await evaluate("document.querySelector('main').textContent"),
		/Video · 0:01 · 160 × 90 · /,
	);
	await navigate(record, "#media-upload-files-trailer");
	assert.equal(
		await evaluate("document.querySelector('[name=trailer__video]').value"),
		media["prepared.mp4"],
		"enhanced video choices are kept automatically",
	);
	await evaluate(
		`for(const [name,value] of Object.entries(${JSON.stringify({ trailer__video: original.trailer__video ?? "", trailer__poster: original.trailer__poster ?? "" })})){const input=document.querySelector('[name="'+name+'"]');input.value=value;input.dispatchEvent(new Event('input',{bubbles:true}))}`,
	);
	await ready(
		"document.querySelector('[data-save-status]').textContent.startsWith('Saved at ')",
	);
	await navigate(record, "#field-trailer");
	// One step: Upload video opens the file chooser, the browser checks the clip and draws its first
	// frame as the poster, and both upload at once and join the field.
	await ready(
		"document.querySelector('#field-trailer [data-media-upload-choose=video]')?.checkVisibility()",
	);
	await command("Page.setInterceptFileChooserDialog", { enabled: true });
	const opened = new Promise((resolve) => {
		chooserOpened = resolve;
	});
	await evaluate(
		"document.querySelector('#field-trailer [data-media-upload-choose=video]').click()",
	);
	const chooser = await opened;
	chooserOpened = null;
	assert.equal(chooser.mode, "selectSingle", "one video at a time");
	assert.equal(
		await evaluate("document.querySelector('#media-upload-files-trailer').accept"),
		"video/mp4,video/webm",
	);
	await command("DOM.setFileInputFiles", {
		backendNodeId: chooser.backendNodeId,
		files: [resolve("tests/fixtures/video/prepared.webm")],
	});
	await command("Page.setInterceptFileChooserDialog", { enabled: false });
	await ready(
		"document.readyState==='complete' && document.querySelector('[name=trailer__video]')?.value!=='' && document.querySelector('[name=trailer__poster]')?.value!==''",
	);
	assert.deepEqual(
		await evaluate(
			"[...document.querySelectorAll('#field-trailer [data-video-row]:not([hidden])')].map(row=>[row.querySelector('[data-video-name]').textContent, row.hasAttribute('data-media-pending')])",
		),
		[
			["prepared.webm", true],
			["prepared-poster.jpg", true],
		],
		"the video and the poster made from its first frame join the field and wait to be prepared",
	);
	worker();
	await ready(
		"!document.querySelector('#field-trailer [data-media-pending]') && document.querySelector('#field-trailer [data-video-thumb=poster] img')",
		40000,
	);
	// Once both are ready, the record keeps them without any action.
	await ready(
		"fetch(location.pathname).then(r=>r.text()).then(h=>{const d=new DOMParser().parseFromString(h,'text/html');return d.querySelector('[name=trailer__video]').value!=='' && d.querySelector('[name=trailer__poster]').value!==''})",
	);
	await navigate(record, "[name=trailer__video]");
	await evaluate(
		`for(const [name,value] of Object.entries(${JSON.stringify({ trailer__video: original.trailer__video ?? "", trailer__poster: original.trailer__poster ?? "" })})){const input=document.querySelector('[name="'+name+'"]');input.value=value;input.dispatchEvent(new Event('input',{bubbles:true}))}`,
	);
	await ready(
		`fetch(location.pathname).then(r=>r.text()).then(h=>{const d=new DOMParser().parseFromString(h,'text/html');return d.querySelector('[name=trailer__video]').value===${JSON.stringify(original.trailer__video ?? "")} && d.querySelector('[name=trailer__poster]').value===${JSON.stringify(original.trailer__poster ?? "")}})`,
	);
	await navigate(record, "#media-upload-files-trailer");
	assert.equal(
		await evaluate(
			"[...document.querySelectorAll('#field-trailer [data-video-choose=video]')].filter(o=>o.dataset.mediaName==='prepared.webm').length",
		),
		2,
		"the enhanced upload joins the library and is offered to the field",
	);
	assert.equal(
		await evaluate("document.querySelector('#media-upload-files-trailer').disabled"),
		false,
	);
	assert.deepEqual(browserErrors, []);
	console.log(
		"PASS prepared video: no-script uploads into the field, one-step upload with a first-frame poster, preparation states, MP4/WebM names and separate poster, plainly named rows at desktop and phone widths, saved references, private native playback, validation/conflict recovery, persisted fixture restoration and narrow layout.",
	);
} finally {
	await command("Emulation.setScriptExecutionDisabled", { value: false });
	await browser.close();
}
