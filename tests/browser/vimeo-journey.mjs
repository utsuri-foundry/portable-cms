import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { createHash } from "node:crypto";
import { mkdir, readFile, writeFile } from "node:fs/promises";
import { dirname, join } from "node:path";
import { isolatedPreviewBrowser } from "./isolated-preview-browser.mjs";
import { connectLocalBrowser } from "./local-browser.mjs";
import { publishSavedRecord } from "./published-record-actions.mjs";

const origin = process.env.CMS_BROWSER_ORIGIN;
const debug = process.env.CMS_BROWSER_DEBUG_URL;
const state = process.env.CMS_BROWSER_VIMEO_STATE;
const publicOrigin = process.env.CMS_BROWSER_PUBLIC_ORIGIN;
assert.ok(origin && debug && state && publicOrigin);
// Exact previews include saved drafts; qualify the seeded incomplete draft as
// the dedicated exact-preview journey does, without changing published history.
execFileSync("php", [
	"tests/php/prepare-editor-exact-browser.php",
	process.env.CMS_BROWSER_WORKER_CONFIG,
	"prepare",
]);
const requests = [];
const failures = [];
let providerMode = "ready";
const held = [];
const playerHtml = (mode) =>
	`<!doctype html><title>Fixture player</title><button>Play fixture</button>${
		mode === "ready"
			? `<script>
if(self.origin!=='null')parent.postMessage(JSON.stringify({event:'ready'}),'*');
addEventListener('message',e=>{if(self.origin!=='null' && e.source===parent && e.data?.method==='ping')parent.postMessage({method:'ping'},'*')});
</script>`
			: mode === "error"
				? "<script>parent.postMessage({event:'error',data:{method:'ready'}},'*')</script>"
				: ""
	}`;
async function fulfill(params, sessionId, mode = "ready") {
	await browser.command(
		"Fetch.fulfillRequest",
		{
			requestId: params.requestId,
			responseCode: 200,
			responseHeaders: [
				{ name: "Content-Type", value: "text/html" },
				{ name: "Cache-Control", value: "no-store" },
			],
			body: Buffer.from(playerHtml(mode)).toString("base64"),
		},
		sessionId,
	);
}
const referrer = (request) =>
	Object.entries(request.headers).find(
		([name]) => name.toLowerCase() === "referer",
	)?.[1];
const browser = await connectLocalBrowser(
	origin,
	debug,
	(method, params, sessionId) => {
		if (method !== "Fetch.requestPaused") return;
		const url = new URL(params.request.url);
		if (["localhost", "127.0.0.1"].includes(url.hostname)) {
			void browser
				.command("Fetch.continueRequest", { requestId: params.requestId }, sessionId)
				.catch((error) => failures.push(error));
			return;
		}
		requests.push(params.request);
		if (!params.request.url.startsWith("https://player.vimeo.com/video/"))
			failures.push(new Error(`Unexpected external request: ${url}`));
		if (providerMode === "hold") held.push({ params, sessionId });
		else
			void fulfill(params, sessionId, providerMode).catch((error) =>
				failures.push(error),
			);
	},
);
const { command, evaluate, ready, navigate } = browser;
const path = "/records/project/10000000000000000000000000000001";
const poster = "d0000000000000000000000000000003";
const title = "Evening study <film>";
const expected =
	"https://player.vimeo.com/video/123456789?h=abcdef1234&dnt=1&autoplay=1";
const settled =
	"document.readyState==='complete' && !document.querySelector('[data-vimeo-submission]') && document.querySelector('[name=_editorial_end]')?.value==='1'";
const posterVisible =
	"getComputedStyle(document.querySelector('[data-vimeo-video] > img')).visibility==='visible'";
const fallbackVisible =
	"document.querySelector('.cms-vimeo__link').hidden===false && !document.querySelector('[data-vimeo-play]') && !document.querySelector('[data-vimeo-video] iframe')";
async function releasePlayer() {
	for (let i = 0; i < 100 && held.length === 0; i++)
		await new Promise((resolve) => setTimeout(resolve, 50));
	assert.equal(held.length, 1);
	const { params, sessionId } = held.shift();
	await fulfill(params, sessionId);
	providerMode = "ready";
}
async function submit(expression) {
	await evaluate(
		`document.querySelector('[data-editorial-form]').setAttribute('data-vimeo-submission','');${expression}`,
	);
	await ready(settled);
}
async function login() {
	await navigate("/login", "[name=login]");
	await evaluate(
		"document.querySelector('[name=login]').value='admin@portable.test';document.querySelector('[name=password]').value='portable-cms-local';document.querySelector('form').requestSubmit()",
	);
	await ready("location.pathname==='/' && document.querySelector('.admin-navigation')");
}
async function openLive() {
	await ready("document.querySelector('[data-live-toggle]')?.hidden===false");
	await evaluate(
		"if(document.querySelector('[data-live-preview]').hidden) document.querySelector('[data-live-toggle]').click()",
	);
	await ready(
		"!document.querySelector('[data-live-preview]').hasAttribute('aria-busy') && document.querySelector('[data-live-frame][data-live-active]')?.getAttribute('src')?.startsWith('/live-previews/')",
	);
	const source = await evaluate(
		"document.querySelector('[data-live-frame][data-live-active]').getAttribute('src')",
	);
	const workspace = await isolatedPreviewBrowser(browser, origin + source);
	await workspace.command("Fetch.enable", {
		patterns: [{ urlPattern: "*", requestStage: "Request" }],
	});
	await workspace.ready("document.querySelector('[data-vimeo-play]')?.hidden===false");
	return workspace;
}
async function playInShell(page, mode = "ready") {
	await page.ready(
		"document.querySelector('[data-vimeo-video]')?.dataset.vimeoShell==='true' && document.querySelector('[data-vimeo-play]')?.hidden===false",
	);
	await page.evaluate(
		"document.querySelector('[data-vimeo-play]').scrollIntoView({block:'center'})",
	);
	await new Promise((resolve) => setTimeout(resolve, 100));
	const before = requests.length;
	const rect = await page.evaluate(
		"(()=>{const r=document.querySelector('[data-vimeo-video]').getBoundingClientRect();return {x:r.x,y:r.y,width:r.width,height:r.height}})()",
	);
	providerMode = mode === "ready" ? "hold" : "silent";
	await page.evaluate("document.querySelector('[data-vimeo-play]').click()");
	await ready("!!document.querySelector('[data-preview-vimeo]')");
	assert.equal(
		await page.evaluate("document.querySelector('[data-vimeo-video] iframe')"),
		null,
		"the opaque page never hosts the provider",
	);
	assert.equal(await page.evaluate(posterVisible), true);
	if (mode === "ready") {
		assert.equal(
			await evaluate(
				"getComputedStyle(document.querySelector('[data-preview-vimeo]')).visibility",
			),
			"hidden",
		);
		await releasePlayer();
		await ready(
			"document.activeElement===document.querySelector('[data-preview-vimeo]')",
		);
		await page.ready(
			"document.querySelector('[data-vimeo-video]').dataset.vimeoPlaying==='true'",
		);
		assert.equal(await page.evaluate(posterVisible), false);
		const actual = await evaluate(
			"(()=>{const p=document.querySelector('[data-preview-vimeo]').getBoundingClientRect(), f=document.querySelector('iframe[data-vimeo-shell-ready]').getBoundingClientRect(), scale=f.width/document.querySelector('iframe[data-vimeo-shell-ready]').offsetWidth;return {left:p.left,top:p.top,width:p.width,height:p.height,frameLeft:f.left,frameTop:f.top,scale}})()",
		);
		for (const [key, value] of Object.entries({
			left: actual.frameLeft + rect.x * actual.scale,
			top: actual.frameTop + rect.y * actual.scale,
			width: rect.width * actual.scale,
			height: rect.height * actual.scale,
		}))
			assert.ok(Math.abs(actual[key] - value) < 1, key);
	} else {
		await page.ready(fallbackVisible);
		assert.equal(await page.evaluate(posterVisible), true);
		assert.equal(
			await evaluate("document.querySelector('[data-preview-vimeo]')"),
			null,
		);
	}
	assert.equal(requests.length, before + 1);
	assert.equal(requests.at(-1).url, expected);
	assert.equal(
		referrer(requests.at(-1)),
		`${origin}/`,
		"only the CMS origin reaches Vimeo",
	);
	providerMode = "ready";
}
async function play(expression = "document") {
	await ready(`${expression}.querySelector('[data-vimeo-play]')?.hidden===false`);
	const before = requests.length;
	assert.equal(
		await evaluate(`${expression}.querySelector('[data-vimeo-video] iframe')===null`),
		true,
	);
	const size = await evaluate(
		`(()=>{const r=${expression}.querySelector('[data-vimeo-video]').getBoundingClientRect();return [r.width,r.height]})()`,
	);
	providerMode = "hold";
	await evaluate(`${expression}.querySelector('[data-vimeo-play]').click()`);
	await ready(
		`${expression}.querySelector('[data-vimeo-video] iframe')?.src===${JSON.stringify(expected)}`,
	);
	assert.equal(await evaluate(posterVisible), true);
	assert.equal(
		await evaluate(
			"getComputedStyle(document.querySelector('[data-vimeo-video] iframe')).visibility",
		),
		"hidden",
	);
	await releasePlayer();
	await ready(
		`${expression}.querySelector('[data-vimeo-video] iframe')===${expression}.activeElement`,
	);
	assert.equal(await evaluate(posterVisible), false);
	for (let attempt = 0; attempt < 100 && requests.length === before; attempt++)
		await new Promise((resolve) => setTimeout(resolve, 50));
	assert.equal(requests.length, before + 1, "only Play initiates the provider request");
	assert.equal(requests.at(-1).url, expected);
	assert.equal(
		referrer(requests.at(-1)),
		`${new URL(await evaluate(`${expression}.location.href`)).origin}/`,
		"domain-restricted players receive the authoring/public origin without preview credentials",
	);
	assert.deepEqual(
		await evaluate(
			`(()=>{const f=${expression}.querySelector('[data-vimeo-video] iframe');const r=f.getBoundingClientRect();return [f.title,f.allow,f.hasAttribute('allowfullscreen'),f.hasAttribute('referrerpolicy'),r.width,r.height]})()`,
		),
		[title, "autoplay; fullscreen; picture-in-picture", true, false, ...size],
	);
}
try {
	await command("Page.enable");
	await command("Runtime.enable");
	await command("Network.enable");
	await command("Network.setCacheDisabled", { cacheDisabled: true });
	await command("Fetch.enable", {
		patterns: [{ urlPattern: "*", requestStage: "Request" }],
	});
	await command("Emulation.setFocusEmulationEnabled", { enabled: true });
	await login();
	await navigate(path, "[name=film__url]");
	await submit(
		`document.querySelector('[name=title__en]').value='Evening study';document.querySelector('[name=slug__en]').value='evening-study';document.querySelectorAll('[name="related[]"]').forEach(input=>input.remove());document.querySelector('[name=film__url]').value='https://evil.test/123';document.querySelector('[name=film__title]').value=${JSON.stringify(title)};document.querySelector('[name=film__poster][value="${poster}"]').checked=true;document.querySelector('[data-editorial-form]').requestSubmit()`,
	);
	assert.equal(
		await evaluate("document.querySelector('[name=film__url]').value"),
		"https://evil.test/123",
	);
	assert.ok(await evaluate("document.querySelector('#field-film [role=alert]')"));
	await submit(
		"document.querySelector('[name=film__url]').value='https://player.vimeo.com/video/123456789?h=abcdef1234';document.querySelector('[data-editorial-form]').requestSubmit()",
	);
	assert.equal(
		await evaluate("document.querySelector('[name=film__url]').value"),
		"https://vimeo.com/123456789/abcdef1234",
	);
	assert.ok(
		await evaluate("document.querySelector('#field-film img[src*=thumbnail]')"),
	);
	assert.equal(await evaluate("document.querySelector('#field-film iframe')"), null);
	assert.equal(requests.length, 0, "authoring never loads Vimeo");

	const workspace = await openLive();
	const live = workspace.evaluate;
	assert.equal(requests.length, 0, "live preview makes no provider request until Play");
	assert.equal(await live("self.origin"), "null", "workspace isolation remains opaque");
	await live(
		"document.querySelector('[data-vimeo-play]').scrollIntoView({block:'center'})",
	);
	await new Promise((resolve) => setTimeout(resolve, 100));
	providerMode = "hold";
	await live("document.querySelector('[data-vimeo-play]').click()");
	for (let i = 0; i < 100 && requests.length === 0; i++)
		await new Promise((resolve) => setTimeout(resolve, 50));
	assert.equal(requests.length, 1);
	assert.equal(requests[0].url, expected);
	assert.equal(
		referrer(requests[0]),
		`${origin}/`,
		JSON.stringify(requests[0].headers),
	);
	assert.equal(await live(posterVisible), true);
	assert.equal(
		await evaluate(
			"getComputedStyle(document.querySelector('[data-live-vimeo]')).visibility",
		),
		"hidden",
	);
	await releasePlayer();
	await ready("document.activeElement===document.querySelector('[data-live-vimeo]')");
	for (let i = 0; i < 100 && (await live(posterVisible)); i++)
		await new Promise((resolve) => setTimeout(resolve, 50));
	assert.equal(await live(posterVisible), false);
	assert.equal(
		await evaluate(
			"document.activeElement===document.querySelector('[data-live-vimeo]')",
		),
		true,
	);
	await evaluate("dispatchEvent(new Event('resize'))");
	for (let i = 0; i < 100 && !(await live(fallbackVisible)); i++)
		await new Promise((resolve) => setTimeout(resolve, 50));
	assert.equal(await live(fallbackVisible), true);
	assert.equal(await live(posterVisible), true);
	await workspace.close();
	await navigate(path, "#record-publish-form");
	const silentWorkspace = await openLive();
	await silentWorkspace.evaluate(
		"document.querySelector('[data-vimeo-play]').scrollIntoView({block:'center'})",
	);
	await new Promise((resolve) => setTimeout(resolve, 100));
	providerMode = "silent";
	await silentWorkspace.evaluate("document.querySelector('[data-vimeo-play]').click()");
	await silentWorkspace.ready(fallbackVisible);
	assert.equal(await silentWorkspace.evaluate(posterVisible), true);
	assert.equal(await evaluate("document.querySelector('[data-live-vimeo]')"), null);
	const afterLive = requests.length;
	assert.equal(afterLive, 2);
	providerMode = "ready";
	await silentWorkspace.close();
	await navigate(path, "#record-publish-form");
	await publishSavedRecord(browser);
	await navigate("/previews", "form[action='/previews']");
	await evaluate(
		"document.querySelector('form[action=\"/previews\"]').elements.namedItem('include_drafts').checked=true;document.querySelector('form[action=\"/previews\"]').requestSubmit()",
	);
	await ready(
		"location.pathname.match(/^\\/previews\\/[a-f0-9]{32}$/) && document.querySelector('[data-preview-state]')",
	);
	const preview = await evaluate("location.pathname");
	execFileSync(
		"php",
		[
			"tools/conventional-media-worker.php",
			`--config=${process.env.CMS_BROWSER_WORKER_CONFIG}`,
			"--max-jobs=10",
			"--max-seconds=30",
			"--worker-id=vimeo-browser",
		],
		{ timeout: 40000 },
	);
	await navigate(preview, "[data-preview-state]");
	assert.equal(
		await evaluate(
			"document.querySelector('[data-preview-state]').dataset.previewState",
		),
		"ready",
	);
	const artifactRoot = join(
		state,
		"private/media/previews/artifacts",
		preview.split("/").at(-1),
	);
	const manifest = JSON.parse(
		await readFile(join(artifactRoot, "artifact.json"), "utf8"),
	);
	const files = manifest.files;
	assert.ok(Array.isArray(files));
	let route;
	for (const file of files) {
		const bytes = await readFile(join(artifactRoot, file.path));
		assert.equal(createHash("sha256").update(bytes).digest("hex"), file.sha256);
		const target = join(state, "static", file.path);
		await mkdir(dirname(target), { recursive: true });
		await writeFile(target, bytes);
		if (
			file.path.startsWith("en/") &&
			file.path.endsWith("/index.html") &&
			bytes.includes("data-vimeo-video")
		)
			route = file.path.replace(/index.html$/, "");
	}
	assert.ok(route, "the saved reference is rendered from a frozen record");
	await navigate(`${preview}/artifact/${route}`, "[data-vimeo-video]");
	assert.equal(
		requests.length,
		afterLive,
		"exact preview does not contact Vimeo before Play",
	);
	await play();
	providerMode = "silent";
	await command("Page.reload");
	await ready(
		"document.querySelector('[data-vimeo-play]')?.hidden===false && document.readyState==='complete'",
	);
	await evaluate("document.querySelector('[data-vimeo-play]').click()");
	await ready(fallbackVisible, 8000);
	assert.equal(await evaluate(posterVisible), true);
	providerMode = "ready";
	await command("Page.navigate", {
		url: `${origin}/previews/newest/records/project/10000000000000000000000000000001?locale=en`,
	});
	await ready(
		"document.readyState==='complete' && document.querySelector('[data-editor-exact-frame]')?.dataset.vimeoShellReady==='true'",
	);
	const exactSource = await evaluate(
		"document.querySelector('[data-editor-exact-frame]').src",
	);
	const exact = await isolatedPreviewBrowser(browser, exactSource);
	assert.equal(await exact.evaluate("self.origin"), "null");
	await playInShell(exact);
	await exact.evaluate("dispatchEvent(new Event('scroll'))");
	await exact.ready(fallbackVisible);
	assert.equal(await evaluate("document.querySelector('[data-preview-vimeo]')"), null);
	await exact.evaluate("location.reload()");
	await playInShell(exact, "silent");
	await exact.close();
	const beforePublic = requests.length;
	await command("Page.navigate", { url: `${publicOrigin}/${route}` });
	await ready(
		"document.querySelector('[data-vimeo-play]')?.hidden===false && document.readyState==='complete'",
	);
	assert.equal(requests.length, beforePublic, "the public poster is local");
	await play();
	await command("Emulation.setScriptExecutionDisabled", { value: true });
	await command("Page.reload");
	await ready(
		"document.readyState==='complete' && document.querySelector('.cms-vimeo__link')",
	);
	assert.equal(
		await evaluate("document.querySelector('.cms-vimeo__link').hidden"),
		false,
	);
	assert.equal(
		await evaluate("document.querySelector('.cms-vimeo__link').href"),
		"https://vimeo.com/123456789/abcdef1234",
	);
	assert.equal(requests.length, beforePublic + 1);
	await command("Emulation.setScriptExecutionDisabled", { value: false });
	// A fulfilled but silent/error document must not replace the poster either.
	for (const mode of ["silent", "error"]) {
		await command("Page.reload");
		await ready(
			"document.querySelector('[data-vimeo-play]')?.hidden===false && document.readyState==='complete'",
		);
		providerMode = mode;
		await evaluate("document.querySelector('[data-vimeo-play]').click()");
		await ready(fallbackVisible, 8000);
		assert.equal(await evaluate(posterVisible), true);
		assert.deepEqual(
			await evaluate(
				"(()=>{const a=document.querySelector('.cms-vimeo__link');return [a.href,a.target,document.activeElement===a]})()",
			),
			["https://vimeo.com/123456789/abcdef1234", "_blank", true],
		);
	}
	providerMode = "ready";
	const beforeShared = requests.length;
	const shareRoute = `${preview}/shares`;
	await navigate(shareRoute, "#share-expiry");
	await evaluate(
		`document.querySelector('[name=expiry_hours]').value='24';document.querySelector('form[action="${shareRoute}"]').requestSubmit()`,
	);
	await ready("document.querySelector('#share-link')");
	const link = await evaluate("document.querySelector('#share-link').value");
	await command("Network.clearBrowserCookies");
	await command("Page.navigate", { url: link });
	await ready(
		"document.readyState==='complete' && document.querySelector('iframe[data-share-renew]')",
	);
	const sharedSource = new URL(
		route,
		await evaluate("document.querySelector('iframe').src"),
	).href;
	await evaluate(
		`document.querySelector('iframe').src=${JSON.stringify(sharedSource)}`,
	);
	const shared = await isolatedPreviewBrowser(browser, sharedSource);
	await shared.command("Fetch.enable", {
		patterns: [{ urlPattern: "*", requestStage: "Request" }],
	});
	assert.equal(requests.length, beforeShared, "shared preview waits for Play");
	assert.equal(await shared.evaluate("self.origin"), "null");
	await playInShell(shared);
	await evaluate("dispatchEvent(new Event('resize'))");
	await shared.ready(fallbackVisible);
	assert.equal(await shared.evaluate(posterVisible), true);
	await shared.evaluate("location.reload()");
	await playInShell(shared, "silent");
	// Exercise the real popup permission with a local blank destination; record
	// the requested Vimeo URL without making a remote navigation in this fixture.
	const beforeTabs = new Set(
		(await command("Target.getTargets")).targetInfos.map((target) => target.targetId),
	);
	await evaluate(
		"window.vimeoMessages=[];addEventListener('message',e=>{if(e.data?.type==='cms-vimeo')window.vimeoMessages.push({data:e.data,origin:e.origin,source:e.source===document.querySelector('iframe').contentWindow,active:navigator.userActivation.isActive})});window.vimeoTabs=[];window.vimeoOpen=window.open;window.open=(url,target,features)=>{window.vimeoTabs.push([url,target,features]);return window.vimeoOpen('about:blank',target,features)}",
	);
	await shared.evaluate("document.querySelector('.cms-vimeo__link').click()");
	try {
		await ready("window.vimeoTabs.length===1");
	} catch (error) {
		throw new Error(
			`${error.message}; handoff=${JSON.stringify(await evaluate("window.vimeoMessages"))}`,
		);
	}
	assert.deepEqual(await evaluate("window.vimeoTabs"), [
		["https://vimeo.com/123456789/abcdef1234", "_blank", "noopener,noreferrer"],
	]);
	let popup;
	for (let i = 0; i < 100 && !popup; i++) {
		popup = (await command("Target.getTargets")).targetInfos.find(
			(target) => target.type === "page" && !beforeTabs.has(target.targetId),
		);
		if (!popup) await new Promise((resolve) => setTimeout(resolve, 50));
	}
	assert.ok(popup, "the sandboxed fallback click opens a tab through the shell");
	const { sessionId: popupSession } = await command("Target.attachToTarget", {
		targetId: popup.targetId,
		flatten: true,
	});
	const popupState = await command(
		"Runtime.evaluate",
		{ expression: "window.opener===null", returnByValue: true },
		popupSession,
	);
	assert.equal(popupState.result.value, true);
	await command("Target.closeTarget", { targetId: popup.targetId });
	providerMode = "ready";
	await shared.close();
	assert.deepEqual(failures, []);
	assert.deepEqual(browser.browserErrors, []);
	process.stdout.write(
		"PASS Vimeo browser: real-origin sibling players in opaque exact/shared shells, normal public/direct/live playback, poster readiness and timeout/error fallback, placement, origin-only referrers, shared fallback tab without opener and zero provider requests before Play. Provider responses are hermetic fixtures.\n",
	);
} finally {
	await browser.close();
}
