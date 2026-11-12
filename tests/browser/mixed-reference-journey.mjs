import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { mkdtempSync, rmSync } from "node:fs";
import { dirname, join } from "node:path";
import { connectLocalBrowser } from "./local-browser.mjs";

const browser = await connectLocalBrowser(
	process.env.CMS_BROWSER_ORIGIN,
	process.env.CMS_BROWSER_DEBUG_URL,
);
const { command, evaluate, ready, navigate, browserErrors } = browser;
const path = "/records/home/50000000000000000000000000000001";
// Earlier journeys retain localized links in history, beyond v1's nesting bound.
// Exercise the real supported full-history transport rather than pruning those rows.
function exportedCollections() {
	const config = process.env.CMS_BROWSER_WORKER_CONFIG;
	assert.ok(config);
	const output = mkdtempSync(join(dirname(config), "mixed-reference-export-"));
	try {
		return JSON.parse(
			execFileSync(
				"php",
				[
					"-d",
					"memory_limit=256M",
					"tests/php/mixed-reference-export.php",
					config,
					output,
				],
				{ timeout: 30000, encoding: "utf8", maxBuffer: 4_194_304 },
			),
		);
	} finally {
		rmSync(output, { recursive: true });
	}
}
const selected = () =>
	evaluate(
		`[...document.querySelectorAll('[name="featuredContent[]"]')].map(input=>input.value)`,
	);
const submit = async (action) => {
	assert.equal(
		await evaluate(
			`[...document.querySelectorAll('button[name=_editor_action]')].some(button=>button.value===${JSON.stringify(action)})`,
		),
		true,
		`native picker action must exist: ${action}`,
	);
	const before = await evaluate(
		"document.querySelector('[data-editorial-form]')?.dataset.journeyStep ?? '0'",
	);
	await evaluate(
		`document.querySelector('[data-editorial-form]').dataset.journeyStep=${JSON.stringify(String(Number(before) + 1))};document.querySelector('[data-editorial-form]').requestSubmit([...document.querySelectorAll('button[name=_editor_action]')].find(button=>button.value===${JSON.stringify(action)}))`,
	);
	await ready(
		`document.querySelector('[data-editorial-form]') && !document.querySelector('[data-editorial-form]').dataset.journeyStep && document.readyState==='complete'`,
	);
};
try {
	await command("Page.enable");
	await command("Runtime.enable");
	await command("Log.enable");
	await command("Page.navigate", { url: `${process.env.CMS_BROWSER_ORIGIN}/login` });
	await ready(
		"document.readyState==='complete' && (document.querySelector('[name=login]') || document.querySelector('.admin-navigation'))",
	);
	if (await evaluate("document.querySelector('[name=login]') !== null")) {
		await evaluate(
			"document.querySelector('[name=login]').value='admin@portable.test';document.querySelector('[name=password]').value='portable-cms-local';document.querySelector('form').requestSubmit()",
		);
	}
	await ready("location.pathname==='/' && document.querySelector('.admin-navigation')");
	await navigate(path, "[data-editorial-form]");
	const original = await selected();
	const originalExport = exportedCollections();
	assert.equal(original.length, 2);
	assert.match(original[0], /^article@[a-f0-9]{32}$/);
	assert.match(original[1], /^project@[a-f0-9]{32}$/);
	const revision = Number(
		await evaluate("document.querySelector('[name=_revision]').value"),
	);
	await evaluate(
		"document.querySelector('[name=_type_featuredContent]').value='article'",
	);
	await submit("search:featuredContent");
	assert.deepEqual(await selected(), original);
	assert.equal(
		await evaluate("document.querySelector('[name=_type_featuredContent]').value"),
		"article",
	);
	assert.equal(
		await evaluate(
			"!!document.querySelector('button[value^=\"select:featuredContent:project@\"]')",
		),
		false,
	);
	assert.ok(
		await evaluate(
			"document.querySelector('#field-featuredContent .admin-picker__results').textContent.includes('Article')",
		),
	);
	await submit(`up:featuredContent:${original[1]}`);
	assert.deepEqual(await selected(), [...original].reverse());
	await ready(
		"document.querySelector('[data-save-status]').textContent.startsWith('Saved at ')",
	);
	const savedRevision = Number(
		await evaluate("document.querySelector('[name=_revision]').value"),
	);
	assert.ok(savedRevision > revision, "mixed reference ordering is kept automatically");
	await navigate(path, "[data-editorial-form]");
	assert.deepEqual(await selected(), [...original].reverse());
	const exported = exportedCollections();
	const record = exported.records.find((entry) => entry.id === path.split("/").at(-1));
	const body = exported.revisions.find(
		(entry) => entry.id === record.currentRevisionId,
	).body;
	assert.deepEqual(
		body.featuredContent.map((entry) => `${entry.typeId}@${entry.recordId}`),
		[...original].reverse(),
	);
	assert.deepEqual(
		exported.revisions.filter((entry) => entry.recordId !== record.id),
		originalExport.revisions.filter((entry) => entry.recordId !== record.id),
		"other records' full history, including earlier links, remains exact",
	);
	await command("Emulation.setDeviceMetricsOverride", {
		width: 360,
		height: 780,
		deviceScaleFactor: 1,
		mobile: false,
	});
	await command("Emulation.setScriptExecutionDisabled", { value: true });
	await navigate(path, "[data-editorial-form]");
	assert.equal(
		await evaluate("document.documentElement.scrollWidth<=innerWidth"),
		true,
	);
	await submit(`remove:featuredContent:${original[1]}`);
	assert.deepEqual(await selected(), [original[0]]);
	const project = exported.records.find(
		(entry) => entry.id === original[1].split("@")[1],
	);
	const projectSlug = exported.revisions.find(
		(entry) => entry.id === project.currentRevisionId,
	).body.slug.en;
	assert.equal(typeof projectSlug, "string");
	// The full browser suite creates other projects; do not assume this one is on page 1.
	await evaluate(
		`document.querySelector('[name=_type_featuredContent]').value='project';document.querySelector('[name=_query_featuredContent]').value=${JSON.stringify(projectSlug)}`,
	);
	await submit("search:featuredContent");
	await submit(`select:featuredContent:${original[1]}`);
	assert.deepEqual(await selected(), original);
	await evaluate("document.querySelector('[data-editorial-form]').requestSubmit()");
	await ready(
		`document.querySelector('[name=_revision]')?.value==='${savedRevision + 1}'`,
	);
	await navigate(path, "[data-editorial-form]");
	assert.deepEqual(await selected(), original);
	assert.deepEqual(browserErrors, []);
	console.log(
		"PASS mixed reference browser: native type filtering, mixed ordering, save/reload/export, removable selections, 360px and no-script editing.",
	);
} finally {
	await command("Emulation.setScriptExecutionDisabled", { value: false });
	await browser.close();
}
