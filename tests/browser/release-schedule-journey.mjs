import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { connectLocalBrowser } from "./local-browser.mjs";

const origin = process.env.CMS_BROWSER_ORIGIN;
const debug = process.env.CMS_BROWSER_DEBUG_URL;
assert.ok(origin && debug && process.env.CMS_BROWSER_WORKER_CONFIG);
const browser = await connectLocalBrowser(origin, debug);
const { command, evaluate, ready, navigate, browserErrors } = browser;
const form = (action) =>
	`document.querySelector('[name=action][value="${action}"]').form`;
async function submit(expression) {
	await evaluate(
		`document.documentElement.dataset.scheduleWaiting='1';${expression}.requestSubmit()`,
	);
	await ready(
		"!document.documentElement.dataset.scheduleWaiting && document.readyState==='complete'",
	);
}
function worker() {
	execFileSync(
		"php",
		[
			"tools/conventional-media-worker.php",
			`--config=${process.env.CMS_BROWSER_WORKER_CONFIG}`,
			"--max-jobs=5",
			"--max-seconds=60",
			"--worker-id=schedule-browser",
		],
		{ stdio: "pipe" },
	);
}
try {
	await command("Page.enable");
	await command("Runtime.enable");
	await command("Log.enable");
	await command("Emulation.setScriptExecutionDisabled", { value: true });
	await navigate("/login", "main");
	if (await evaluate("!!document.querySelector('[name=login]')")) {
		await evaluate(
			"document.querySelector('[name=login]').value='admin@portable.test';document.querySelector('[name=password]').value='portable-cms-local';document.querySelector('form').requestSubmit()",
		);
	}
	await ready("location.pathname==='/' && document.querySelector('.admin-navigation')");
	await navigate("/settings/publication", "[name=mode]");
	const originalMode = await evaluate("document.querySelector('[name=mode]').value");
	await evaluate("document.querySelector('[name=mode]').value='live'");
	await submit("document.querySelector('[name=mode]').form");
	await navigate("/previews", "form[action='/previews']");
	await submit("document.querySelector('form[action=\"/previews\"]')");
	await ready("/^\\/previews\\/[a-f0-9]{32}$/.test(location.pathname)");
	const previewPath = await evaluate("location.pathname");
	worker();
	await navigate(previewPath, '[data-preview-state="ready"]');
	const previewId = previewPath.split("/").at(-1);
	await navigate("/releases", "[name=timing]");
	assert.ok(
		await evaluate("document.querySelector('main').textContent.includes('UTC')"),
		"the installation zone is named",
	);
	const dates = [1, 2].map((days) =>
		new Date(Date.now() + days * 86400000).toISOString().slice(0, 16),
	);
	await evaluate(
		`(() => {const f=${form("approve")};f.elements.namedItem('id').value=${JSON.stringify(previewId)};f.elements.namedItem('timing').value='later';f.elements.namedItem('scheduled_at').value=${JSON.stringify(dates[0])};f.elements.namedItem('confirmed').checked=true})()`,
	);
	await submit(form("approve"));
	await ready("!!document.querySelector('[name=action][value=move_schedule]')");
	const releaseId = await evaluate(
		`${form("move_schedule")}.elements.namedItem('id').value`,
	);
	assert.equal(
		await evaluate(
			`${form("move_schedule")}.elements.namedItem('schedule_revision').value`,
		),
		"1",
	);
	assert.equal(
		await evaluate(`${form("move_schedule")}.elements.namedItem('scheduled_at').value`),
		dates[0],
	);
	assert.ok(
		await evaluate(
			"document.querySelector('main').textContent.includes('first run at or after')",
		),
	);
	worker();
	await navigate("/releases", "[name=action][value=move_schedule]");
	assert.equal(
		await evaluate(
			`${form("move_schedule")}.elements.namedItem('schedule_revision').value`,
		),
		"1",
		"an early real worker leaves the schedule untouched",
	);
	await navigate(previewPath, "[data-release-schedules]");
	assert.ok(
		await evaluate(
			"document.querySelector('[data-release-schedules]').textContent.includes('approved preview')",
		),
	);
	await navigate(
		"/records/project/10000000000000000000000000000001",
		"[data-release-schedules]",
	);
	assert.ok(
		await evaluate(
			`document.querySelector('[data-release-schedules]').textContent.includes(${JSON.stringify(releaseId.slice(0, 8))})`,
		),
	);
	await navigate("/releases", "[name=action][value=move_schedule]");
	await evaluate(
		`${form("move_schedule")}.elements.namedItem('scheduled_at').value=${JSON.stringify(dates[1])}`,
	);
	await submit(form("move_schedule"));
	assert.equal(
		await evaluate(
			`${form("move_schedule")}.elements.namedItem('schedule_revision').value`,
		),
		"2",
	);
	assert.equal(
		await evaluate(`${form("move_schedule")}.elements.namedItem('scheduled_at').value`),
		dates[1],
	);
	assert.equal(
		await evaluate(
			`fetch('/releases',{method:'POST',body:new URLSearchParams({_csrf:${form("move_schedule")}.elements.namedItem('_csrf').value,action:'cancel_schedule',id:${JSON.stringify(releaseId)},schedule_revision:'1',confirmed:'1'})}).then(r=>r.status)`,
		),
		409,
		"a stale cancellation cannot replace the moved schedule",
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
		"native scheduling fits a narrow screen",
	);
	await submit(form("cancel_schedule"));
	assert.equal(
		await evaluate(`!!document.querySelector('[name=action][value=move_schedule]')`),
		false,
	);
	assert.ok(
		await evaluate("document.querySelector('main').textContent.includes('Cancelled')"),
	);
	await navigate(previewPath, "[data-preview-state]");
	assert.equal(
		await evaluate("!!document.querySelector('[data-release-schedules]')"),
		false,
	);
	await navigate("/releases", `[data-scheduled-release="${releaseId}"]`);
	const forget = `document.querySelector('[data-scheduled-release="${releaseId}"]').closest('li').querySelector('[name=action][value=forget]').form`;
	await evaluate(`${forget}.elements.namedItem('confirmed').checked=true`);
	await submit(forget);
	assert.equal(
		await evaluate(
			`!!document.querySelector('[data-scheduled-release="${releaseId}"]')`,
		),
		false,
	);
	await navigate(previewPath, 'form[action$="/delete"]');
	await submit("document.querySelector('form[action$=\"/delete\"]')");
	await ready("location.pathname==='/previews'");
	await navigate("/settings/publication", "[name=mode]");
	await evaluate(
		`document.querySelector('[name=mode]').value=${JSON.stringify(originalMode)};document.querySelector('[name=confirmed]').checked=true`,
	);
	await submit("document.querySelector('[name=mode]').form");
	await submit("document.querySelector('form[action=\"/logout\"]')");
	await ready("location.pathname==='/login' && document.querySelector('[name=login]')");
	assert.deepEqual(browserErrors, []);
	process.stdout.write(
		"PASS native release scheduling: exact preview, named zone, early worker, record and preview notices, moving, stale cancellation, cancellation and 360px layout without scripts.\n",
	);
} finally {
	await browser.close();
}
