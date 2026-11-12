import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { join } from "node:path";
import { connectLocalBrowser } from "./local-browser.mjs";

const browser = await connectLocalBrowser(
	process.env.CMS_BROWSER_ORIGIN,
	process.env.CMS_BROWSER_DEBUG_URL,
);
const { command, evaluate, navigate, ready, browserErrors } = browser;
const record = "/records/project/10000000000000000000000000000001";
const messages = {
	duration: /16 seconds; maximum 15 seconds/,
	width: /2000 pixels wide; maximum 1920 pixels/,
	height: /1200 pixels high; maximum 1080 pixels/,
	sound: /audio track; no sound/,
	bytes: /maximum 1048576 bytes/,
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
		/15 seconds[\s\S]*1920 pixels[\s\S]*1080 pixels[\s\S]*No sound/,
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
		"Object.fromEntries([...document.querySelectorAll('#field-trailer [data-video-choose=video]')].map(o=>[o.dataset.mediaName,{id:o.dataset.mediaId,disabled:o.disabled,text:o.closest('li').textContent}]))",
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
		"PASS video field limits: native field uploads, retained library assets, measured unavailable choices, forged saves, silent fitting clip and 360px layout.",
	);
} finally {
	await command("Emulation.setScriptExecutionDisabled", { value: false });
	await browser.close();
}
