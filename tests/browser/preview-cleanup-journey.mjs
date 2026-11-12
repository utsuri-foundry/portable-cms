import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { connectLocalBrowser } from "./local-browser.mjs";

const origin = process.env.CMS_BROWSER_ORIGIN;
const debug = process.env.CMS_BROWSER_DEBUG_URL;
assert.ok(origin && debug);
const browser = await connectLocalBrowser(origin, debug);
const { command, evaluate, ready, navigate, browserErrors } = browser;

async function create() {
	await navigate("/previews", "form[action='/previews']");
	await evaluate(
		"document.querySelector('form[action=\"/previews\"]').requestSubmit()",
	);
	await ready(
		"location.pathname.match(/^\\/previews\\/[a-f0-9]{32}$/) && document.querySelector('[data-preview-state]') && document.readyState === 'complete'",
	);
	// Finish each fixture capture before leaving its self-refreshing queued page.
	execFileSync(
		"php",
		[
			"tools/conventional-media-worker.php",
			`--config=${process.env.CMS_BROWSER_WORKER_CONFIG}`,
			"--max-jobs=10",
			"--max-seconds=20",
			"--worker-id=preview-cleanup-browser",
		],
		{ timeout: 30000 },
	);
	await ready(
		"document.readyState==='complete' && document.querySelector('[data-preview-state]')?.dataset.previewState==='ready'",
	);
	return (await evaluate("location.pathname")).split("/").at(-1);
}

async function click(selector) {
	const point = await evaluate(`(() => {
		const element = document.querySelector(${JSON.stringify(selector)});
		element.scrollIntoView({block:'center'});
		const bounds = element.getBoundingClientRect();
		const x = bounds.x + bounds.width / 2, y = bounds.y + bounds.height / 2;
		const hit = document.elementFromPoint(x,y);
		return {x,y,reachable: hit === element || element.contains(hit)};
	})()`);
	assert.equal(
		point.reachable,
		true,
		"native controls are not covered by preview links",
	);
	for (const type of ["mousePressed", "mouseReleased"]) {
		await command("Input.dispatchMouseEvent", {
			type,
			x: point.x,
			y: point.y,
			button: "left",
			clickCount: 1,
		});
	}
}

try {
	await command("Page.enable");
	await command("Runtime.enable");
	await command("Log.enable");
	await navigate("/login", "form");
	if (await evaluate("document.querySelector('[name=login]') !== null")) {
		await evaluate(
			"document.querySelector('[name=login]').value='admin@portable.test';document.querySelector('[name=password]').value='portable-cms-local';document.querySelector('form').requestSubmit()",
		);
		await ready(
			"location.pathname==='/' && document.querySelector('.admin-navigation')",
		);
	}
	const ids = [await create(), await create()];
	await navigate("/previews", "[data-preview-selection]");
	assert.equal(
		await evaluate("document.querySelector('[data-preview-delete-selected]').disabled"),
		true,
	);
	const selector = `[name="preview_ids[]"][value="${ids[0]}"]`;
	await click(selector);
	assert.equal(
		await evaluate(`document.querySelector(${JSON.stringify(selector)}).checked`),
		true,
	);
	assert.equal(
		await evaluate("location.pathname"),
		"/previews",
		"checkbox does not open a preview",
	);
	// Keyboard selection uses the actual checkbox, not a custom widget.
	await evaluate(
		`document.querySelector('[name="preview_ids[]"][value="${ids[1]}"]').focus()`,
	);
	await command("Input.dispatchKeyEvent", {
		type: "keyDown",
		key: " ",
		code: "Space",
		windowsVirtualKeyCode: 32,
	});
	await command("Input.dispatchKeyEvent", {
		type: "keyUp",
		key: " ",
		code: "Space",
		windowsVirtualKeyCode: 32,
	});
	assert.equal(
		await evaluate(
			"document.querySelector('[data-preview-selected-count]').textContent",
		),
		"2 selected",
	);
	await click("[data-preview-delete-selected]");
	await ready(
		"location.pathname==='/previews/delete/review' && document.querySelector('[name=selection]')",
	);
	assert.ok(
		(await evaluate("document.querySelector('main').textContent")).includes(
			"There is no undo",
		),
	);
	await evaluate("document.querySelector('a[href=\"/previews\"]').click()");
	await ready(
		"location.pathname==='/previews' && document.querySelector('[data-preview-selection]')",
	);
	assert.equal(
		await evaluate(
			"document.querySelectorAll('[name=\"preview_ids[]\"]:checked').length",
		),
		0,
	);
	// Complete the same flow with scripting disabled, including on a narrow viewport.
	await command("Emulation.setDeviceMetricsOverride", {
		width: 360,
		height: 780,
		deviceScaleFactor: 1,
		mobile: false,
	});
	await command("Emulation.setScriptExecutionDisabled", { value: true });
	await navigate("/previews", "[data-preview-selection]");
	assert.equal(
		await evaluate("document.documentElement.scrollWidth <= innerWidth"),
		true,
		"360px list remains usable",
	);
	for (const id of ids) await click(`[name="preview_ids[]"][value="${id}"]`);
	await click("[data-preview-delete-selected]");
	await ready(
		"location.pathname==='/previews/delete/review' && document.querySelector('[name=selection]')",
	);
	await click("form[action='/previews/delete/confirm'] button");
	await ready(
		"location.pathname==='/previews/delete/confirm' && document.querySelector('main').textContent.includes('Cleanup results')",
	);
	let deleted = 0;
	for (let attempt = 0; attempt < 8; attempt++) {
		deleted +=
			(await evaluate("document.querySelector('main').textContent")).split(
				"Deleted; preview storage cleaned up.",
			).length - 1;
		if (
			!(await evaluate(
				"document.querySelector('form[action=\"/previews/delete/confirm\"]') !== null",
			))
		)
			break;
		const previousDocument = await evaluate("performance.timeOrigin");
		await click("form[action='/previews/delete/confirm'] button");
		await ready(
			`performance.timeOrigin !== ${previousDocument} && document.readyState === 'complete' && document.querySelector('main').textContent.includes('Cleanup results')`,
		);
	}
	assert.equal(
		deleted,
		2,
		"bounded retries finish only the remaining selected previews",
	);
	await command("Emulation.setScriptExecutionDisabled", { value: false });
	await command("Emulation.clearDeviceMetricsOverride");
	await navigate("/previews", "[data-preview-selection]");
	for (const id of ids)
		assert.equal(
			await evaluate(
				`document.querySelector('[name="preview_ids[]"][value="${id}"]') === null`,
			),
			true,
		);
	assert.deepEqual(browserErrors, []);
	await evaluate("document.querySelector('form[action=\"/logout\"]').requestSubmit()");
	await ready("location.pathname==='/login'");
	process.stdout.write(
		"PASS bulk preview cleanup browser: pointer and keyboard selection, cancel, finite review/confirmation, no-script and 360px layout.\n",
	);
} finally {
	await command("Emulation.setScriptExecutionDisabled", { value: false });
	await browser.close();
}
