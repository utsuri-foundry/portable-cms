import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { mkdtemp, rm } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { connectLocalBrowser } from "./local-browser.mjs";

const origin = process.env.CMS_BROWSER_ORIGIN;
const debug = process.env.CMS_BROWSER_DEBUG_URL;
assert.ok(origin && debug && process.env.CMS_BROWSER_WORKER_CONFIG);
const browser = await connectLocalBrowser(origin, debug);
const { command, evaluate, navigate, ready, browserErrors } = browser;
const iconDirectory = await mkdtemp(join(tmpdir(), "website-icon-"));
try {
	await command("Page.enable");
	await command("Runtime.enable");
	await command("Log.enable");
	await command("DOM.enable");
	await command("CSS.enable");
	await command("Emulation.setScriptExecutionDisabled", { value: true });
	await command("Emulation.setDeviceMetricsOverride", {
		width: 360,
		height: 800,
		deviceScaleFactor: 1,
		mobile: false,
	});
	await command("Page.navigate", { url: `${origin}/login` });
	await ready(
		"document.readyState==='complete' && (document.querySelector('[name=login]') || document.querySelector('.admin-navigation'))",
	);
	if (await evaluate("document.querySelector('[name=login]') !== null")) {
		await evaluate(
			"document.querySelector('[name=login]').value='admin@portable.test';document.querySelector('[name=password]').value='portable-cms-local';document.querySelector('form').requestSubmit()",
		);
		await ready(
			"location.pathname==='/' && document.querySelector('.admin-navigation')",
		);
	}
	await navigate("/settings/publication", "input[name=mode]");
	const revision = Number(
		await evaluate("document.querySelector('[name=_revision]').value"),
	);
	assert.equal(
		await evaluate(
			"document.querySelector('[data-public-state]')?.dataset.publicState",
		),
		"none",
		"the page says nothing is published yet",
	);
	assert.equal(
		await evaluate("document.querySelector('[name=confirmed]')"),
		null,
		"no confirmation is asked while the saved choice is already the coming soon page",
	);
	assert.ok(
		await evaluate(
			"[...document.querySelectorAll('.admin-holding__language legend')].every(legend => legend.textContent.trim().length > 2)",
		),
		"languages are named, not coded",
	);
	const csrf = await evaluate("document.querySelector('[name=_csrf]').value");
	const settingsForm = (overrides) =>
		evaluate(`(() => {
			const form = document.querySelector('form.admin-holding');
			const data = new URLSearchParams(new FormData(form));
			for (const [key, value] of Object.entries(${JSON.stringify(overrides)})) data.set(key, value);
			return fetch('/settings/publication', { method: 'POST', body: data, headers: { 'Content-Type': 'application/x-www-form-urlencoded' } }).then(async response => ({ status: response.status, body: await response.text() }));
		})()`);
	const invalid = await settingsForm({ websiteName: "", _csrf: csrf });
	assert.equal(invalid.status, 422, "an empty name is refused");
	assert.ok(
		invalid.body.includes("A value is missing or too long") &&
			!/website_publication_[a-z]+/.test(invalid.body),
		"the refusal is a sentence, never a code",
	);
	const stale = await settingsForm({ _revision: "99", _csrf: csrf });
	assert.equal(stale.status, 409, "a stale revision is a conflict");
	assert.ok(
		stale.body.includes("Someone else saved these settings") &&
			stale.body.includes("Native holding studio") === false,
		"the conflict keeps the editor's input and explains itself",
	);
	await evaluate(`(() => {
		const form = document.querySelector('form.admin-holding');
		form.elements.namedItem('websiteName').value = 'Native holding studio';
		form.elements.namedItem('mode').value = 'coming_soon';
		form.requestSubmit();
	})()`);
	await ready(
		`document.querySelector('[data-settings-saved]') && document.querySelector('[name=_revision]')?.value===${JSON.stringify(String(revision + 1))}`,
	);
	assert.ok(
		await evaluate(
			"document.querySelector('[data-settings-saved]').textContent.includes('Saved') && document.querySelector('[data-settings-saved] a[href=\"/releases\"]') !== null",
		),
		"saving names the next step and links to Releases",
	);
	assert.equal(
		await evaluate("document.documentElement.scrollWidth<=innerWidth"),
		true,
		"native settings fit a narrow screen",
	);
	// An icon: upload a square image, prepare it, choose it and see it at the sizes it becomes.
	const iconPath = join(iconDirectory, "website-icon-square.png");
	execFileSync("php", [
		"-r",
		"$i=new Imagick(); $i->newImage(512,512,new ImagickPixel('#172029')); $d=new ImagickDraw(); $d->setFillColor(new ImagickPixel('#f1efe9')); $d->circle(256,256,256,80); $i->drawImage($d); $i->setImageFormat('png'); $i->writeImage($argv[1]);",
		iconPath,
	]);
	{
		const { root } = await command("DOM.getDocument");
		const { nodeId } = await command("DOM.querySelector", {
			nodeId: root.nodeId,
			selector: "#website-files-iconMediaId",
		});
		await command("DOM.setFileInputFiles", { nodeId, files: [iconPath] });
	}
	await evaluate(
		"document.querySelector('[name=message_en]').value='Unsaved text retained while browsing';document.querySelector('[name=_revision]').setAttribute('data-holding-upload-pending','');document.querySelector('form.admin-holding').requestSubmit(document.querySelector('[name=_website_upload][value=iconMediaId]'))",
	);
	await ready(
		"document.readyState==='complete' && !document.querySelector('[data-holding-upload-pending]') && document.querySelector('[data-website-upload-result]') && document.querySelector('[name=message_en]')?.value==='Unsaved text retained while browsing'",
	);
	execFileSync(
		"php",
		[
			"tools/conventional-media-worker.php",
			`--config=${process.env.CMS_BROWSER_WORKER_CONFIG}`,
			"--max-jobs=10",
			"--max-seconds=30",
			"--worker-id=holding-icon-browser",
		],
		{ timeout: 40000 },
	);
	await evaluate(
		"document.querySelector('[name=_icon_query]').value='website-icon-square';document.querySelector('[name=_revision]').setAttribute('data-holding-search-pending','');document.querySelector('[name=_icon_action][value=search]').click()",
	);
	await ready(
		"document.readyState==='complete' && !document.querySelector('[data-holding-search-pending]') && document.querySelector('[name=_revision]') && document.querySelector('[name=message_en]')?.value==='Unsaved text retained while browsing'",
	);
	assert.equal(
		Number(await evaluate("document.querySelector('[name=_revision]').value")),
		revision + 1,
		"media browsing does not advance the save revision",
	);
	const iconId = await evaluate(
		"[...document.querySelectorAll('[data-icon-picker] [name=iconMediaId]')].find(input => input.closest('label')?.textContent.includes('website-icon-square'))?.value",
	);
	assert.ok(iconId, "the uploaded square image is offered as an icon");
	assert.ok(
		await evaluate(
			"[...document.querySelectorAll('[data-icon-picker] [name=iconMediaId]')].every(input => !input.disabled)",
		),
		"only images that qualify are listed, so none is disabled",
	);
	await evaluate(
		`document.querySelector('[data-icon-picker] [name=iconMediaId][value="${iconId}"]').checked=true;document.querySelector('[name=message_en]').value='Coming soon.';document.querySelector('form.admin-holding').requestSubmit()`,
	);
	await ready(
		`document.querySelector('[data-settings-saved]') && document.querySelector('[name=_revision]')?.value===${JSON.stringify(String(revision + 2))}`,
	);
	assert.equal(
		await evaluate("document.querySelectorAll('[data-icon-preview] img').length"),
		3,
		"the chosen icon is previewed at the three sizes it becomes",
	);
	await ready(
		"[...document.querySelectorAll('[data-icon-preview] img')].every(image => image.complete && image.naturalWidth > 0)",
	);
	await navigate("/releases", "[name=action][value=build_holding]");
	await evaluate(
		"document.querySelector('[name=action][value=build_holding]').form.requestSubmit()",
	);
	await ready(
		"location.pathname==='/releases' && document.querySelector('main').textContent.includes('Queued')",
	);
	execFileSync(
		"php",
		[
			"tools/conventional-media-worker.php",
			`--config=${process.env.CMS_BROWSER_WORKER_CONFIG}`,
			"--max-jobs=10",
			"--max-seconds=10",
			"--worker-id=holding-browser",
		],
		{ timeout: 20000 },
	);
	await navigate("/releases", "a[href^='/releases/holding/']");
	const artifactPath = await evaluate(
		"document.querySelector('a[href^=\"/releases/holding/\"]').pathname",
	);
	await navigate(artifactPath, "h1");
	assert.equal(
		await evaluate("document.querySelector('h1').textContent"),
		"Native holding studio",
	);
	assert.equal(
		await evaluate("document.querySelector('meta[name=robots]').content"),
		"noindex,follow",
	);
	assert.equal(
		await evaluate("document.documentElement.scrollWidth<=innerWidth"),
		true,
		"holding page fits a narrow screen",
	);
	assert.equal(
		await evaluate("getComputedStyle(document.body).margin"),
		"0px",
		"mount-independent generated stylesheet loads",
	);
	await ready(
		"[...document.fonts].some(font=>font.family.includes('Studio Sans') && font.status==='loaded')",
	);
	assert.match(
		await evaluate("getComputedStyle(document.querySelector('h1')).fontFamily"),
		/Studio Sans/,
		"holding heading uses the packaged typeface",
	);
	const { root } = await command("DOM.getDocument");
	const { nodeId } = await command("DOM.querySelector", {
		nodeId: root.nodeId,
		selector: "h1",
	});
	const { fonts } = await command("CSS.getPlatformFontsForNode", { nodeId });
	assert.ok(
		fonts.some(
			(font) =>
				font.isCustomFont &&
				font.glyphCount > 0 &&
				font.familyName.includes("Studio Sans"),
		),
		"Chromium renders heading glyphs with the packaged font rather than its fallback",
	);
	const fontUrls = await evaluate(
		"performance.getEntriesByType('resource').map(entry=>entry.name).filter(url=>url.endsWith('.woff2'))",
	);
	assert.equal(fontUrls.length, 1, "holding loads only its named font");
	assert.ok(
		fontUrls[0].startsWith(`${origin}${artifactPath}assets/holding/`),
		"CSS resolves the font within the private holding artifact mount",
	);
	const fontResponse = await fetch(fontUrls[0], {
		headers: {
			cookie: (await command("Network.getCookies", { urls: [origin] })).cookies
				.map((cookie) => `${cookie.name}=${cookie.value}`)
				.join("; "),
		},
	});
	assert.equal(fontResponse.status, 200);
	assert.ok(
		fontResponse.headers.get("content-security-policy").includes("font-src 'self'"),
		"private font loads under the unchanged self-only policy",
	);
	await navigate("/previews", "main");
	assert.equal(
		await evaluate("document.querySelector('a[href^=\"/releases/holding/\"]')===null"),
		true,
		"exact previews remain separate from publication builds",
	);
	await navigate("/settings/publication", "input[name=mode]");
	const beforeLive = Number(
		await evaluate("document.querySelector('[name=_revision]').value"),
	);
	await evaluate(
		"const form=document.querySelector('form.admin-holding');form.elements.namedItem('mode').value='live';form.requestSubmit()",
	);
	await ready(
		`document.querySelector('[data-settings-saved]') && document.querySelector('[name=_revision]')?.value===${JSON.stringify(String(beforeLive + 1))}`,
	);
	assert.ok(
		await evaluate("document.querySelector('[name=confirmed]') !== null"),
		"once the website is the saved choice, going back asks for a confirmation",
	);
	await evaluate(
		"const form=document.querySelector('form.admin-holding');form.elements.namedItem('mode').value='coming_soon';form.requestSubmit()",
	);
	await ready(
		"document.querySelector('[role=alert]') && document.querySelector('[role=alert]').textContent.includes('Tick the confirmation')",
	);
	assert.equal(
		await evaluate("document.querySelector('[name=_revision]').value"),
		String(beforeLive + 1),
		"an unconfirmed return to the coming soon page saves nothing",
	);
	await evaluate(
		"const form=document.querySelector('form.admin-holding');form.elements.namedItem('mode').value='coming_soon';form.elements.namedItem('confirmed').checked=true;form.requestSubmit()",
	);
	await ready(
		`document.querySelector('[data-settings-saved]') && document.querySelector('[name=_revision]')?.value===${JSON.stringify(String(beforeLive + 2))}`,
	);
	await evaluate(
		"const form=document.querySelector('form.admin-holding');form.elements.namedItem('mode').value='live';form.requestSubmit()",
	);
	await ready(
		`document.querySelector('[data-settings-saved]') && document.querySelector('[name=_revision]')?.value===${JSON.stringify(String(beforeLive + 3))}`,
	);
	assert.deepEqual(
		browserErrors,
		[],
		"native holding settings and inspection cause no browser errors",
	);
	process.stdout.write(
		"PASS Chromium holding journey: no-script settings with a saved notice, an invalid value, a conflict, icon upload, choice and preview, separate build, frozen inspection, packaged font use under self-only CSP, responsive CSS, exact preview separation and the confirmed return to the coming soon page.\n",
	);
} finally {
	await rm(iconDirectory, { recursive: true, force: true });
	await browser.close();
}
