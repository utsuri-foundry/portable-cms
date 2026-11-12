import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { createHash } from "node:crypto";
import { writeFile } from "node:fs/promises";
import { connectLocalBrowser } from "./local-browser.mjs";
import { publishSavedRecord } from "./published-record-actions.mjs";

const origin = process.env.CMS_BROWSER_ORIGIN;
const debug = process.env.CMS_BROWSER_DEBUG_URL;
assert.ok(origin && debug && process.env.CMS_BROWSER_WORKER_CONFIG);
const browser = await connectLocalBrowser(origin, debug);
const { command, evaluate, ready, navigate, browserErrors } = browser;
const settingsPath = "/records/site-settings/70000000000000000000000000000001";
const metadataField = (group, locale, id) =>
	`group-${createHash("sha256").update(`${group}_${locale}:${id}`).digest("hex").slice(0, 40)}`;
const settingsTitle = metadataField("projectsMetadata", "en", "searchTitle");
const settingsSharing = metadataField("projectsMetadata", "en", "sharingTitle");
const recordPath = "/records/project/10000000000000000000000000000001";
const field = (locale, id) =>
	`group-${createHash("sha256").update(`searchSharing_${locale}:${id}`).digest("hex").slice(0, 40)}`;
const title = field("en", "searchTitle");
const description = field("en", "description");
const sharingTitle = field("en", "sharingTitle");
const indexing = field("en", "excludeFromSearch");
const image = field("en", "sharingImage");
const imageId = "d0000000000000000000000000000002";
const firstTitle = 'Search archive & "landscape"';
const firstDescription = "A specific summary for the archive.";
async function saved(revision) {
	try {
		await ready(
			`document.readyState === 'complete' && document.querySelector('[name=_editorial_end]') && document.querySelector('[name=_revision]')?.value === ${JSON.stringify(String(revision))}`,
		);
	} catch (error) {
		const state = await evaluate(
			"({path:location.pathname,ready:document.readyState,revision:document.querySelector('[name=_revision]')?.value,complete:!!document.querySelector('[name=_editorial_end]'),alert:document.querySelector('[role=alert]')?.textContent,invalid:[...document.querySelectorAll(':invalid')].map(e=>e.name)})",
		);
		throw new Error(
			`Search/sharing save did not reach revision ${revision}: ${JSON.stringify(state)}`,
			{ cause: error },
		);
	}
}
try {
	await command("Page.enable");
	await command("Runtime.enable");
	await command("Log.enable");
	await navigate("/login", "form");
	await evaluate(
		"document.querySelector('[name=login]').value='admin@portable.test';document.querySelector('[name=password]').value='portable-cms-local';document.querySelector('form').requestSubmit()",
	);
	await ready("location.pathname==='/' && document.querySelector('.admin-navigation')");
	await navigate(settingsPath, "[data-editorial-form]");
	const settingsOriginal = await evaluate(
		`Object.fromEntries(['defaultSearchDescription__en','${settingsTitle}','${settingsSharing}'].map(name=>[name,document.querySelector('[name="'+name+'"]').value]))`,
	);
	const settingsRevision = Number(
		await evaluate("document.querySelector('[name=_revision]').value"),
	);
	await evaluate(
		`document.querySelector('[name=defaultSearchDescription__en]').value='Website fallback from browser.';document.querySelector('[name=${settingsTitle}]').value='Studio work index';document.querySelector('[name=${settingsSharing}]').value='Share studio work';document.querySelector('[data-editorial-form]').requestSubmit()`,
	);
	await saved(settingsRevision + 1);
	await publishSavedRecord(browser);
	assert.equal(
		await evaluate("document.querySelectorAll('[data-search-sharing-editor]').length"),
		7,
	);
	assert.ok(
		await evaluate(
			"document.querySelector('#field-projectsMetadata').textContent.includes('Automatic: website default.')",
		),
	);
	assert.equal(
		await evaluate("document.documentElement.scrollWidth<=innerWidth"),
		true,
	);
	await navigate(recordPath, "[data-editorial-form]");
	await ready("!document.querySelector('[data-record-locale-switcher]').hidden");
	const original = await evaluate(
		"Object.fromEntries([...document.querySelectorAll('#field-searchSharing input,#field-searchSharing textarea')].filter(c=>c.name && c.type!=='hidden').map(c=>[c.name,c.value]))",
	);
	const revision = Number(
		await evaluate("document.querySelector('[name=_revision]').value"),
	);
	const originalIndexing = await evaluate(
		`document.querySelector('[name=${indexing}]').checked`,
	);
	const originalImages = await evaluate(
		`[...document.querySelectorAll('[name="${image}[]"]')].map(input=>input.value)`,
	);
	await evaluate(
		`document.documentElement.dataset.sharingPickerWaiting='1';document.querySelector('[data-record-locale-switcher] [data-locale-tab=en]').click();document.querySelector('[data-editorial-form]').requestSubmit([...document.querySelectorAll('button[name=_editor_action]')].find(button=>button.value==='select:${image}:${imageId}'))`,
	);
	await ready(
		`!document.documentElement.dataset.sharingPickerWaiting && document.readyState==='complete' && document.querySelector('[name=_editorial_end]') && !document.querySelector('[data-record-locale-switcher]').hidden && document.querySelector('[name="${image}[]"]')?.value === '${imageId}'`,
	);
	await evaluate(
		`document.querySelector('[data-record-locale-switcher] [data-locale-tab=en]').click();document.querySelector('[name=${title}]').value=${JSON.stringify(firstTitle)};document.querySelector('[name=${description}]').value=${JSON.stringify(firstDescription)};document.querySelector('[name=${sharingTitle}]').value='Sharing archive';document.querySelector('[name=${indexing}]').checked=true;document.querySelectorAll('[name="related[]"]').forEach(input=>input.remove());document.querySelector('[data-editorial-form]').requestSubmit()`,
	);
	await saved(revision + 1);
	await publishSavedRecord(browser);
	const exported = await evaluate(
		"fetch('/exports',{method:'POST',body:new URLSearchParams({_csrf:document.querySelector('[name=_csrf]').value})}).then(response=>response.json())",
	);
	assert.equal(
		exported.sourcePublicUrlContext.baseUrl,
		"https://reference-studio.example",
	);
	const exportedRecord = exported.records.find(
		(record) => record.id === recordPath.split("/").at(-1),
	);
	const exportedRevision = exported.revisions.find(
		(entry) => entry.id === exportedRecord.currentRevisionId,
	);
	const settingsRecord = exported.records.find(
		(record) => record.id === settingsPath.split("/").at(-1),
	);
	const settingsExport = exported.revisions.find(
		(entry) => entry.id === settingsRecord.currentRevisionId,
	);
	assert.equal(
		settingsExport.body.defaultSearchDescription.en,
		"Website fallback from browser.",
	);
	assert.equal(
		settingsExport.body.projectsMetadata.en.searchTitle,
		"Studio work index",
	);
	assert.equal(exportedRevision.body.searchSharing.en.searchTitle, firstTitle);
	assert.deepEqual(exportedRevision.body.searchSharing.en.sharingImage, [imageId]);
	assert.equal(exportedRevision.body.searchSharing.en.excludeFromSearch, true);
	assert.ok(
		await evaluate(
			"document.querySelector('[data-search-sharing-editor] > summary').textContent.match(/Custom text: [A-Z, ]*EN/)!==null",
		),
		"the collapsed summary names the languages with custom text",
	);
	assert.ok(
		await evaluate(
			"document.querySelector('[data-search-sharing-editor] > summary .admin-status--warning').textContent.match(/Hidden from search engines: [A-Z, ]*EN/)!==null",
		),
		"hidden languages are a visible warning",
	);
	assert.ok(
		await evaluate(
			"[...document.querySelectorAll('[data-search-sharing-editor] .admin-serp__title')].some(t=>t.textContent.length>0)",
		),
		"the illustrative search result is rendered",
	);
	for (const [width, name] of [
		[1440, "desktop"],
		[390, "phone"],
	]) {
		await command("Emulation.setDeviceMetricsOverride", {
			width,
			height: 900,
			deviceScaleFactor: 1,
			mobile: false,
		});
		await evaluate(
			"document.querySelector('[data-search-sharing-editor]').open=true;document.querySelector('#section-discovery').scrollIntoView({block:'start'})",
		);
		const seoShot = await command("Page.captureScreenshot", { format: "png" });
		await writeFile(
			`${process.env.CMS_BROWSER_SCREENSHOT ?? "/tmp/cms-review.png"}.seo-${name}.png`,
			Buffer.from(seoShot.data, "base64"),
		);
	}
	assert.equal(
		await evaluate(`document.querySelector('[name=${title}]').value`),
		firstTitle,
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
	await navigate("/previews", "form[action='/previews']");
	await evaluate(
		"document.querySelector('form[action=\"/previews\"]').requestSubmit()",
	);
	await ready(
		"location.pathname.match(/^\\/previews\\/[a-f0-9]{32}$/) && document.querySelector('[data-preview-state]')",
	);
	const previewPath = await evaluate("location.pathname");
	// A subsequent save must not alter the already frozen website metadata.
	await navigate(recordPath, "[data-editorial-form]");
	await evaluate(
		`document.querySelector('[name=${title}]').value='Later live metadata';document.querySelector('[data-editorial-form]').requestSubmit()`,
	);
	await saved(revision + 3);
	await publishSavedRecord(browser);
	execFileSync(
		"php",
		[
			"tools/conventional-media-worker.php",
			`--config=${process.env.CMS_BROWSER_WORKER_CONFIG}`,
			"--max-jobs=2",
			"--max-seconds=5",
			"--worker-id=seo-browser",
		],
		{ timeout: 10000 },
	);
	await navigate(
		`${previewPath}/artifact/en/projects/archive-of-mist/`,
		".studio-header",
	);
	assert.equal(await evaluate("document.title"), firstTitle);
	assert.equal(
		await evaluate("document.querySelector('meta[name=description]').content"),
		firstDescription,
	);
	assert.equal(
		await evaluate("document.querySelector('meta[property=\"og:title\"]').content"),
		"Sharing archive",
	);
	assert.equal(
		await evaluate("document.querySelector('h1').textContent"),
		"Archive of Mist",
	);
	const imageUrl = await evaluate(
		"document.querySelector('meta[property=\"og:image\"]').content",
	);
	assert.match(
		imageUrl,
		/^https:\/\/reference-studio\.example\/sharing\/[a-f0-9]{64}\.jpg$/,
	);
	assert.equal(
		await evaluate("document.querySelector('meta[name=\"twitter:card\"]').content"),
		"summary_large_image",
	);
	assert.equal(
		await evaluate("document.querySelector('meta[property=\"og:image:alt\"]').content"),
		"Abstract study with a gradient from warm brown to cream.",
	);
	const sharingManifest = await evaluate(
		`fetch(${JSON.stringify(`${previewPath}/artifact/artifact.json`)}).then(r=>r.json())`,
	);
	const sharingImage = sharingManifest.sharingImages.find(
		(entry) => entry.mediaId === imageId,
	);
	assert.equal(imageUrl, `https://reference-studio.example/${sharingImage.path}`);
	const servedImage = await evaluate(
		`fetch(${JSON.stringify(`${previewPath}/artifact/`)}+${JSON.stringify(sharingImage.path)}).then(async response=>{const bytes=await response.arrayBuffer();const hash=await crypto.subtle.digest('SHA-256',bytes);return {mime:response.headers.get('content-type'),hash:[...new Uint8Array(hash)].map(byte=>byte.toString(16).padStart(2,'0')).join(''),size:bytes.byteLength};})`,
	);
	assert.equal(servedImage.hash, sharingImage.sha256);
	assert.equal(servedImage.size, sharingImage.byteSize);
	assert.equal(servedImage.mime, "image/jpeg");
	assert.equal(await evaluate("document.querySelectorAll('title').length"), 1);
	assert.equal(
		await evaluate("document.querySelector('meta[name=robots]').content"),
		"noindex,follow",
	);
	assert.equal(
		await evaluate("document.querySelector('link[rel=canonical]').href"),
		"https://reference-studio.example/en/projects/archive-of-mist/",
	);
	assert.equal(
		await evaluate("document.querySelector('link[hreflang=es]')===null"),
		true,
	);
	const discovery = await evaluate(
		`Promise.all([fetch(${JSON.stringify(`${previewPath}/artifact/sitemap.xml`)}).then(r=>r.text()),fetch(${JSON.stringify(`${previewPath}/artifact/robots.txt`)}).then(r=>r.text())])`,
	);
	assert.ok(
		!discovery[0].includes(
			"https://reference-studio.example/en/projects/archive-of-mist/",
		),
	);
	assert.ok(!discovery[0].includes("/settings/"));
	assert.ok(
		discovery[1].includes("Sitemap: https://reference-studio.example/sitemap.xml"),
	);
	const indexMetadata = await evaluate(
		`fetch(${JSON.stringify(`${previewPath}/artifact/en/projects/`)}).then(r=>r.text())`,
	);
	assert.ok(indexMetadata.includes("<title>Studio work index</title>"));
	assert.ok(
		indexMetadata.includes(
			'name="description" content="Website fallback from browser."',
		),
	);
	assert.ok(indexMetadata.includes('property="og:title" content="Share studio work"'));
	const headers = await evaluate(
		"fetch(location.href).then(r=>({robots:r.headers.get('x-robots-tag'),cache:r.headers.get('cache-control')}))",
	);
	assert.equal(headers.robots, "noindex, nofollow, noarchive");
	assert.equal(headers.cache, "no-store");
	await command("Emulation.setScriptExecutionDisabled", { value: true });
	await navigate(
		`${previewPath}/artifact/es/projects/archivo-de-la-niebla/`,
		".studio-header",
	);
	assert.match(await evaluate("document.title"), /^Archivo de la niebla/);
	assert.equal(
		await evaluate("document.querySelector('link[hreflang=en]')===null"),
		true,
	);
	assert.equal(
		await evaluate("document.querySelector('link[hreflang=ca]').href"),
		"https://reference-studio.example/ca/projects/arxiu-de-la-boira/",
	);
	assert.notEqual(
		await evaluate("document.querySelector('meta[property=\"og:title\"]').content"),
		"Sharing archive",
	);
	await navigate(recordPath, "[data-editorial-form]");
	assert.equal(
		await evaluate(
			"[...document.querySelectorAll('#field-searchSharing [data-locale-panel]')].every(p=>!p.hidden)",
		),
		true,
	);
	await evaluate(
		`document.querySelectorAll('[name="${image}[]"]').forEach(input=>input.remove());for(const value of ${JSON.stringify(originalImages)}){const input=document.createElement('input');input.type='hidden';input.name='${image}[]';input.value=value;document.querySelector('[data-editorial-form]').append(input);}for(const [name,value] of Object.entries(${JSON.stringify(original)}))document.querySelector('[name="'+name+'"]').value=value;document.querySelector('[name=${indexing}]').checked=${originalIndexing};document.querySelector('[data-editorial-form]').requestSubmit()`,
	);
	await saved(revision + 5);
	await publishSavedRecord(browser);
	await navigate(settingsPath, "[data-editorial-form]");
	assert.equal(
		await evaluate(
			"[...document.querySelectorAll('[data-search-sharing-editor] [data-locale-panel]')].every(p=>!p.hidden)",
		),
		true,
	);
	await evaluate(
		`for(const [name,value] of Object.entries(${JSON.stringify(settingsOriginal)}))document.querySelector('[name="'+name+'"]').value=value;document.querySelector('[data-editorial-form]').requestSubmit()`,
	);
	await saved(settingsRevision + 3);
	await publishSavedRecord(browser);
	await command("Emulation.setScriptExecutionDisabled", { value: false });
	await navigate(previewPath, "form[action$='/delete']");
	await evaluate("document.querySelector('form[action$=\"/delete\"]').requestSubmit()");
	await ready(
		"location.pathname==='/previews' && document.readyState==='complete' && document.querySelector('form[action=\"/logout\"]')",
	);
	await evaluate("document.querySelector('form[action=\"/logout\"]').requestSubmit()");
	await ready("location.pathname==='/login' && document.querySelector('[name=login]')");
	assert.deepEqual(browserErrors, []);
	process.stdout.write(
		"PASS search/sharing browser: native localized save/reload, frozen exact-preview metadata, escaping, independent visible heading, 360px, no-script defaults and private headers.\n",
	);
} finally {
	await browser.close();
}
