import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { connectLocalBrowser } from "./local-browser.mjs";
import { verifySharedPreview } from "./preview-share-journey.mjs";

// This installation has an administrator but no content seeder, records or media.
const origin = process.env.CMS_BROWSER_ORIGIN;
const debug = process.env.CMS_BROWSER_DEBUG_URL;
assert.ok(origin && debug && process.env.CMS_BROWSER_WORKER_CONFIG);
const browser = await connectLocalBrowser(origin, debug);
const { command, evaluate, ready, navigate, browserErrors } = browser;
const title = "An article authored from an empty installation";
const locales = ["ca", "es", "en"];

async function create(type, fields, rich = false) {
	await navigate(`/records/${type}/new`, "[data-editorial-form]");
	if (rich) {
		for (const locale of locales) {
			const name = `body__${locale}`;
			await evaluate(`(() => {
				const root = document.querySelector('[data-rich-name="${name}"]');
				root.closest('form').requestSubmit(root.querySelector('[data-rich-action=add]'));
			})()`);
			await ready(`document.querySelector('[name="${name}__0__0__text"]')`);
			await evaluate(
				`document.querySelector('[name="${name}__0__0__text"]').value='Original body ${locale}'`,
			);
		}
	}
	await evaluate(`(() => {
		for (const [name,value] of Object.entries(${JSON.stringify(fields)})) {
			const control = document.querySelector('[data-editorial-form]').elements.namedItem(name);
			if (!control) throw new Error('Missing native control '+name);
			control.value = value;
		}
		document.querySelector('[data-editorial-form]').requestSubmit();
	})()`);
	await ready(
		`location.pathname.match(/^\\/records\\/${type}\\/[a-f0-9]{32}$/) && document.querySelector('[name=_revision]')?.value==='1'`,
	);
	return evaluate("location.pathname");
}

async function status(path, state) {
	await navigate(path, "#record-status-form");
	const revision = Number(
		await evaluate("document.querySelector('[name=_revision]').value"),
	);
	await evaluate(`(() => {
		const form=document.querySelector('#record-status-form');
		form.elements.namedItem('_workflow_state').value=${JSON.stringify(state)};
		form.elements.namedItem('_confirmed').checked=true;
		form.requestSubmit();
	})()`);
	await ready(
		`location.pathname===${JSON.stringify(path)} && document.readyState==='complete' && document.querySelector('[name=_revision]')?.value==='${revision + 1}'`,
	);
	assert.match(
		await evaluate("document.querySelector('main').textContent"),
		/Website releases take the published version/,
	);
}

async function preview(includeDrafts, expected) {
	await navigate("/previews", "form[action='/previews']");
	await evaluate(`(() => {
		const form=document.querySelector('form[action="/previews"]');
		form.elements.namedItem('include_drafts').checked=${includeDrafts};
		form.requestSubmit();
	})()`);
	await ready(
		"location.pathname.match(/^\\/previews\\/[a-f0-9]{32}$/) && document.querySelector('[data-preview-state]')",
	);
	const path = await evaluate("location.pathname");
	execFileSync(
		"php",
		[
			"tools/conventional-media-worker.php",
			`--config=${process.env.CMS_BROWSER_WORKER_CONFIG}`,
			"--max-jobs=1",
			"--max-seconds=10",
			"--worker-id=empty-workflow-browser",
		],
		{ timeout: 15000 },
	);
	await navigate(path, "[data-preview-state]");
	assert.equal(
		await evaluate(
			"document.querySelector('[data-preview-state]').dataset.previewState",
		),
		"ready",
	);
	assert.equal(
		await evaluate("document.querySelector('[data-preview-mode]').dataset.previewMode"),
		includeDrafts ? "drafts" : "published",
	);
	await navigate(`${path}/artifact/en/articles/`, "main");
	assert.equal(
		(await evaluate("document.querySelector('main').textContent")).includes(title),
		expected,
	);
	return path;
}

try {
	await command("Page.enable");
	await command("Runtime.enable");
	await command("Log.enable");
	await command("Emulation.setScriptExecutionDisabled", { value: true });
	await navigate("/login", "form");
	await evaluate(
		"document.querySelector('[name=login]').value='admin@portable.test';document.querySelector('[name=password]').value='portable-cms-local';document.querySelector('form').requestSubmit()",
	);
	await ready("location.pathname==='/' && document.querySelector('.admin-navigation')");
	for (const path of ["/", "/previews"]) {
		await navigate(path, "main");
		assert.match(
			await evaluate("document.querySelector('main').textContent"),
			/Before your first preview/,
		);
		for (const type of ["home", "about"]) {
			assert.equal(
				await evaluate(
					`!!document.querySelector('main a[href="/records/${type}/new"]')`,
				),
				true,
			);
		}
	}
	await evaluate(
		"document.querySelector('form[action=\"/previews\"]').elements.namedItem('include_drafts').checked=true;document.querySelector('form[action=\"/previews\"]').requestSubmit()",
	);
	await ready(
		"document.querySelector('#preflight-heading') && document.querySelector('main time')",
	);
	const failedAt = await evaluate("document.querySelector('main time').dateTime");
	assert.equal(
		await evaluate("document.querySelector('[name=include_drafts]').value"),
		"1",
	);
	await evaluate(
		"document.querySelector('form[action=\"/previews\"]').requestSubmit()",
	);
	await ready(
		`document.querySelector('#preflight-heading') && document.querySelector('main time')?.dateTime!==${JSON.stringify(failedAt)}`,
	);
	assert.equal(
		await evaluate("document.querySelector('[name=include_drafts]').value"),
		"1",
	);
	for (const path of ["/", "/previews"]) {
		await navigate(path, "main");
		assert.match(
			await evaluate("document.querySelector('main').textContent"),
			/The latest preview check needs attention/,
		);
	}
	await navigate("/records/article", "main");
	assert.equal(
		await evaluate("document.querySelectorAll('.admin-table__title').length"),
		0,
	);
	const home = await create(
		"home",
		Object.fromEntries(
			locales.map((locale) => [`introduction__${locale}`, "A fictional studio"]),
		),
	);
	const about = await create("about", {}, true);
	const settings = await create("site-settings", {
		organizationName: "Empty installation study",
	});
	for (const path of [home, about, settings]) await status(path, "published");
	const fields = {};
	for (const locale of locales) {
		fields[`title__${locale}`] = title;
		fields[`slug__${locale}`] = "empty-installation-article";
		fields[`excerpt__${locale}`] = "An original summary";
	}
	const article = await create("article", fields, true);
	await preview(false, false);
	for (const path of ["/", "/previews"]) {
		await navigate(path, "main");
		assert.doesNotMatch(
			await evaluate("document.querySelector('main').textContent"),
			/The latest preview check needs attention|Before your first preview/,
		);
	}
	const frozenDraft = await preview(true, true);
	await status(article, "published");
	await preview(false, true);
	await status(article, "draft");
	await preview(false, false);
	await status(article, "archived");
	await preview(false, false);
	await preview(true, false);
	// A later workflow change must not mutate the previously frozen draft preview.
	await navigate(
		`${frozenDraft}/artifact/en/articles/empty-installation-article/`,
		"h1",
	);
	assert.equal(await evaluate("document.querySelector('h1').textContent"), title);
	assert.ok(
		(await evaluate("document.querySelector('main').textContent")).includes(
			"Original body en",
		),
	);
	await command("Page.navigate", { url: `${origin}/records/article?status=archived` });
	await ready("document.querySelector('.admin-table__title')");
	assert.equal(
		await evaluate(
			"document.querySelector('.admin-table__title').getAttribute('href')",
		),
		article,
	);
	await command("Page.navigate", { url: `${origin}/records/article?status=published` });
	await ready(
		"location.search==='?status=published' && document.readyState==='complete'",
	);
	assert.equal(
		await evaluate("document.querySelectorAll('.admin-table__title').length"),
		0,
	);
	// The native release form approves only one saved, published-only preview.
	const releasePreview = await preview(false, false);
	await navigate("/settings/publication", "select[name=mode]");
	await evaluate(
		"document.querySelector('[name=mode]').value='live';document.querySelector('[name=mode]').form.requestSubmit()",
	);
	await ready(
		"location.pathname==='/settings/publication' && document.querySelector('[name=_revision]')?.value==='1'",
	);
	await navigate("/releases", "select[name=id]");
	await evaluate(`(() => {
		const form=document.querySelector('select[name=id]').form;
		form.elements.namedItem('id').value=${JSON.stringify(releasePreview.split("/").at(-1))};
		form.elements.namedItem('confirmed').checked=true;
		form.requestSubmit();
	})()`);
	await ready(
		"location.pathname==='/releases' && document.querySelector('main').textContent.includes('in progress')",
	);
	execFileSync(
		"php",
		[
			"tools/conventional-media-worker.php",
			`--config=${process.env.CMS_BROWSER_WORKER_CONFIG}`,
			"--max-jobs=1",
			"--max-seconds=20",
		],
		{ timeout: 25000 },
	);
	await navigate("/releases", "main");
	assert.ok(
		(await evaluate("document.querySelector('main').textContent")).includes("Active"),
	);
	assert.equal(
		await evaluate("document.querySelector('input[name=previous]').value.length"),
		32,
	);
	assert.deepEqual(browserErrors, []);
	await navigate(article, "#record-delete-form");
	await evaluate(`(() => {
		document.querySelector('[form="record-delete-form"][name="_confirmed"]').checked=true;
		document.querySelector('#record-delete-form').requestSubmit();
	})()`);
	await ready(
		"location.pathname==='/records/article' && document.readyState==='complete'",
	);
	assert.equal(
		await evaluate("document.querySelectorAll('.admin-table__title').length"),
		0,
	);
	await navigate(
		`${frozenDraft}/artifact/en/articles/empty-installation-article/`,
		"h1",
	);
	assert.equal(await evaluate("document.querySelector('h1').textContent"), title);
	await verifySharedPreview(browser, origin, frozenDraft);
	process.stdout.write(
		"PASS empty-CMS native workflow: authoring, publish/draft/archive/delete, both frozen preview modes, immutable history, status filter and verified public release without scripts.\n",
	);
} finally {
	await browser.close();
}
