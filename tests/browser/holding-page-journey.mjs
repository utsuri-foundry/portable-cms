import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { connectLocalBrowser } from "./local-browser.mjs";

const origin = process.env.CMS_BROWSER_ORIGIN;
const debug = process.env.CMS_BROWSER_DEBUG_URL;
assert.ok(origin && debug && process.env.CMS_BROWSER_WORKER_CONFIG);
const browser = await connectLocalBrowser(origin, debug);
const { command, evaluate, navigate, ready, browserErrors } = browser;
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
	await navigate("/settings/publication", "[name=mode]");
	const revision = Number(
		await evaluate("document.querySelector('[name=_revision]').value"),
	);
	await evaluate(
		"document.querySelector('[name=websiteName]').value='Native holding studio';document.querySelector('[name=mode]').value='coming_soon';document.querySelector('[name=confirmed]').checked=true;document.querySelector('[name=mode]').form.requestSubmit()",
	);
	await ready(
		`document.querySelector('[name=_revision]')?.value===${JSON.stringify(String(revision + 1))}`,
	);
	assert.equal(
		await evaluate("document.documentElement.scrollWidth<=innerWidth"),
		true,
		"native settings fit a narrow screen",
	);
	await evaluate(
		"document.querySelector('[name=message_en]').value='Unsaved text retained while browsing';document.querySelector('[name=_revision]').setAttribute('data-holding-search-pending','');document.querySelector('[name=_icon_action][value=search]').click()",
	);
	await ready(
		"document.readyState==='complete' && !document.querySelector('[data-holding-search-pending]') && document.querySelector('[name=_revision]') && document.querySelector('[name=message_en]')?.value==='Unsaved text retained while browsing'",
	);
	assert.equal(
		Number(await evaluate("document.querySelector('[name=_revision]').value")),
		revision + 1,
		"media browsing does not advance the save revision",
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
	await navigate("/settings/publication", "[name=mode]");
	await evaluate(
		"document.querySelector('[name=mode]').value='live';document.querySelector('[name=mode]').form.requestSubmit()",
	);
	await ready(
		`document.querySelector('[name=_revision]')?.value===${JSON.stringify(String(revision + 2))}`,
	);
	assert.deepEqual(
		browserErrors,
		[],
		"native holding settings and inspection cause no browser errors",
	);
	process.stdout.write(
		"PASS Chromium holding journey: no-script settings, unsaved icon browsing, separate build, frozen inspection, packaged font use under self-only CSP, responsive CSS and exact preview separation.\n",
	);
} finally {
	await browser.close();
}
