import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { join, resolve } from "node:path";
import { connectLocalBrowser } from "./local-browser.mjs";

let chooserOpened = null;
const uploads = [];
const browser = await connectLocalBrowser(
	process.env.CMS_BROWSER_ORIGIN,
	process.env.CMS_BROWSER_DEBUG_URL,
	(method, params) => {
		if (method === "Page.fileChooserOpened") chooserOpened?.(params);
		if (
			method === "Network.requestWillBeSent" &&
			params.request.method === "POST" &&
			params.request.url.includes("/media-uploads")
		)
			uploads.push(params.request.url);
	},
);
const { command, evaluate, navigate, ready, browserErrors } = browser;
const record = "/records/project/10000000000000000000000000000001";
const messages = {
	duration:
		/takes videos up to 15 seconds, and this one lasts 16\. Shorten it and upload it again\./,
	width: /up to 1920 pixels wide, and this one is 2000\. Export it smaller/,
	height: /up to 1080 pixels high, and this one is 1200\. Export it smaller/,
	sound: /takes silent videos only, and this one has sound\. Remove the sound/,
	bytes: /the limit is 1048576\. Export it smaller/,
};
try {
	await command("Page.enable");
	await command("Runtime.enable");
	await navigate("/login", "[name=login]");
	await evaluate(
		"document.querySelector('[name=login]').value='admin@portable.test';document.querySelector('[name=password]').value='portable-cms-local';document.querySelector('form').requestSubmit()",
	);
	await ready("document.querySelector('.admin-navigation')");
	await command("Emulation.setScriptExecutionDisabled", { value: true });
	await navigate(record, "[name=trailer__video]");
	assert.match(
		await evaluate("document.querySelector('#field-trailer').textContent"),
		/up to 15 seconds, at most 1920 by 1080 pixels, without sound\./,
	);
	assert.equal(
		await evaluate(
			"document.querySelectorAll('#field-trailer [data-video-rule]').length",
		),
		1,
		"the rule is stated once",
	);
	for (const variant of ["fitting", ...Object.keys(messages)]) {
		const { root } = await command("DOM.getDocument");
		const { nodeId } = await command("DOM.querySelector", {
			nodeId: root.nodeId,
			selector: "#media-upload-files-trailer",
		});
		await command("DOM.setFileInputFiles", {
			nodeId,
			files: [join(process.env.CMS_BROWSER_VIDEO_PROBES, `limits-${variant}.mp4`)],
		});
		await evaluate(
			"document.querySelector('[data-editorial-form]').requestSubmit(document.querySelector('[name=_upload_field][value=trailer]'))",
		);
		await ready(
			"document.readyState==='complete' && document.querySelector('[name=_editorial_end]') && document.querySelector('#media-upload-files-trailer') && document.querySelector('#media-upload-files-trailer').files.length===0",
		);
		if (variant !== "fitting") {
			assert.match(
				await evaluate("document.querySelector('main').textContent"),
				messages[variant],
				`field upload explains ${variant} after inspection`,
			);
		}
	}
	execFileSync(
		"php",
		[
			"tools/conventional-media-worker.php",
			`--config=${process.env.CMS_BROWSER_WORKER_CONFIG}`,
			"--max-jobs=10",
			"--max-seconds=30",
			"--worker-id=video-limits-browser",
		],
		{ timeout: 40000 },
	);
	await navigate(record, "[name=trailer__video]");
	const choices = await evaluate(
		"Object.fromEntries([...document.querySelectorAll('#field-trailer [data-video-choosers=video] .admin-picker__results > li')].map(li=>{const pick=li.querySelector('[data-video-choose=video]');return [pick?.dataset.mediaName ?? li.dataset.mediaName,{id:pick?.dataset.mediaId ?? li.dataset.mediaId,disabled:!pick,text:li.textContent}]}))",
	);
	assert.equal(choices["limits-fitting.mp4"].disabled, false);
	for (const [variant, message] of Object.entries(messages)) {
		const option = choices[`limits-${variant}.mp4`];
		assert.ok(option, "rejected field upload remains in the library");
		assert.equal(option.disabled, true);
		assert.match(option.text, message);
		await evaluate(
			`document.querySelector('[name=trailer__video]').value=${JSON.stringify(option.id)};document.querySelector('[name=trailer__poster]').value='d0000000000000000000000000000003';document.querySelector('[data-editorial-form]').requestSubmit()`,
		);
		await ready(
			`document.readyState==='complete' && document.querySelector('[data-validation-summary]') && document.querySelector('[name=_editorial_end]') && document.querySelector('[name=_retained_trailer__video]')?.value===${JSON.stringify(option.id)}`,
		);
		assert.match(
			await evaluate("document.querySelector('[data-validation-summary]').textContent"),
			message,
			"server refuses a forged picker choice",
		);
		assert.equal(
			await evaluate("document.querySelector('[name=_retained_trailer__video]').value"),
			option.id,
			"invalid existing selection is preserved",
		);
	}
	await evaluate(
		`document.querySelector('[name=trailer__video]').value=${JSON.stringify(choices["limits-fitting.mp4"].id)};document.querySelector('[data-editorial-form]').requestSubmit()`,
	);
	await ready(
		"document.readyState==='complete' && !document.querySelector('[data-validation-summary]') && document.querySelector('[name=_editorial_end]')",
	);
	// With scripts, the browser measures a chosen clip and refuses it before any byte is sent.
	await command("Emulation.setScriptExecutionDisabled", { value: false });
	await command("Network.enable");
	await navigate(record, "#field-narrowLoop [data-media-upload-choose=video]");
	await command("Page.setInterceptFileChooserDialog", { enabled: true });
	const opened = new Promise((done) => {
		chooserOpened = done;
	});
	await evaluate(
		"document.querySelector('#field-narrowLoop [data-media-upload-choose=video]').click()",
	);
	const chooser = await opened;
	await command("DOM.setFileInputFiles", {
		backendNodeId: chooser.backendNodeId,
		files: [resolve("tests/fixtures/video/prepared.webm")],
	});
	await command("Page.setInterceptFileChooserDialog", { enabled: false });
	await ready(
		"document.querySelector('#field-narrowLoop [data-media-upload-status]')?.textContent.includes('100 pixels wide')",
	);
	assert.equal(
		await evaluate(
			"document.querySelector('#field-narrowLoop [data-media-upload-status]').textContent",
		),
		"Narrow loop takes videos up to 100 pixels wide, and this one is 160. Export it smaller and upload it again.",
		"the refusal says the limit, the measure and what to do",
	);
	assert.deepEqual(uploads, [], "nothing is uploaded");
	assert.equal(
		await evaluate(
			"document.activeElement===document.querySelector('#field-narrowLoop [data-media-upload-choose=video]') && document.querySelector('[name=narrowLoop__video]').value===''",
		),
		true,
		"the field stays empty and the upload button keeps the focus",
	);
	await command("Emulation.setDeviceMetricsOverride", {
		width: 360,
		height: 780,
		deviceScaleFactor: 1,
		mobile: false,
	});
	assert.equal(
		await evaluate("document.documentElement.scrollWidth<=innerWidth+1"),
		true,
	);
	assert.deepEqual(browserErrors, []);
	console.log(
		"PASS video field limits: refusal before upload in the browser, native field uploads, retained library assets, measured unavailable choices, forged saves, silent fitting clip and 360px layout.",
	);
} finally {
	await command("Emulation.setScriptExecutionDisabled", { value: false });
	await browser.close();
}
