import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { connectLocalBrowser } from "./local-browser.mjs";

const origin = process.env.CMS_BROWSER_ORIGIN;
const debug = process.env.CMS_BROWSER_DEBUG_URL;
assert.ok(origin && debug);
const browser = await connectLocalBrowser(origin, debug);
const { command, evaluate, ready, navigate, browserErrors } = browser;
const recordPath = "/records/project/10000000000000000000000000000001";
const previewPath = /^\/previews\/[a-f0-9]{32}$/;

async function openListedPreview(path, keyboard = false) {
	await navigate("/previews", ".preview-cleanup-row");
	assert.equal(
		await evaluate(
			"[...document.querySelectorAll('.preview-cleanup-row')].every(row => row.querySelectorAll('a[href]').length === 1 && row.querySelector('.admin-meta').textContent.trim() && row.querySelector('.admin-status').textContent.trim())",
		),
		true,
		"each row has one opening link with readable date and status",
	);
	const selector = `.preview-cleanup-row a[href="${path}"]`;
	if (keyboard) {
		await evaluate(
			`document.querySelector(${JSON.stringify(selector)}).closest('li').querySelector('input').focus()`,
		);
		for (const type of ["keyDown", "keyUp"]) {
			await command("Input.dispatchKeyEvent", {
				type,
				key: "Tab",
				code: "Tab",
				windowsVirtualKeyCode: 9,
			});
		}
		assert.equal(
			await evaluate(
				`document.activeElement.matches(${JSON.stringify(selector)}) && document.activeElement.matches(':focus-visible')`,
			),
			true,
			"one Tab from the selection checkbox reaches the native opening link",
		);
		assert.equal(
			await evaluate(
				"getComputedStyle(document.activeElement, '::after').outlineStyle !== 'none' && parseFloat(getComputedStyle(document.activeElement, '::after').outlineWidth) > 0",
			),
			true,
			"keyboard focus visibly outlines the row",
		);
		for (const type of ["keyDown", "keyUp"]) {
			await command("Input.dispatchKeyEvent", {
				type,
				key: "Enter",
				code: "Enter",
				windowsVirtualKeyCode: 13,
			});
		}
	} else {
		const point = await evaluate(`(() => {
			const link = document.querySelector(${JSON.stringify(selector)});
			link.scrollIntoView({block:'center'});
			const row = link.closest('li').getBoundingClientRect();
			const x = row.right - 8, y = row.bottom - 8;
			return {x, y, reachable: document.elementFromPoint(x, y) === link};
		})()`);
		assert.equal(
			point.reachable,
			true,
			"row whitespace opens its preview rather than requiring a title click",
		);
		await command("Input.dispatchMouseEvent", {
			type: "mouseMoved",
			x: point.x,
			y: point.y,
		});
		assert.equal(
			await evaluate(
				`getComputedStyle(document.querySelector(${JSON.stringify(selector)})).textDecorationLine.includes('underline')`,
			),
			true,
			"hovering the row underlines its title",
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
	await ready(
		`location.pathname === ${JSON.stringify(path)} && document.querySelector('[data-preview-state]')`,
	);
}

async function createPreview() {
	await navigate("/previews", "form[action='/previews']");
	const pointer = await evaluate(`(() => {
		const button = document.querySelector('form[action="/previews"] button[type=submit]');
		button.scrollIntoView({ block: 'center' });
		const bounds = button.getBoundingClientRect();
		const x = bounds.x + bounds.width / 2;
		const y = bounds.y + bounds.height / 2;
		const hit = document.elementFromPoint(x, y);
		return { x, y, reachable: button === hit || button.contains(hit) };
	})()`);
	assert.equal(
		pointer.reachable,
		true,
		"preview links must not cover the create button",
	);
	for (const type of ["mousePressed", "mouseReleased"]) {
		await command("Input.dispatchMouseEvent", {
			type,
			x: pointer.x,
			y: pointer.y,
			button: "left",
			clickCount: 1,
		});
	}
	await ready(
		"location.pathname.match(/^\\/previews\\/[a-f0-9]{32}$/) && document.querySelector('[data-preview-state]')",
	);
	const path = await evaluate("location.pathname");
	assert.match(path, previewPath);
	return path;
}

async function buildPreview() {
	assert.ok(process.env.CMS_BROWSER_WORKER_CONFIG);
	execFileSync(
		"php",
		[
			"tools/conventional-media-worker.php",
			`--config=${process.env.CMS_BROWSER_WORKER_CONFIG}`,
			"--max-jobs=2",
			"--max-seconds=5",
			"--worker-id=preview-browser",
		],
		{ timeout: 10000 },
	);
	await ready(
		"document.querySelector('[data-preview-state]')?.dataset.previewState==='ready'",
	);
}

async function saveTitle(title) {
	await navigate(recordPath, "[data-editorial-form]");
	const revision = Number(
		await evaluate("document.querySelector('[name=_revision]').value"),
	);
	await evaluate(
		`document.querySelector('[name=title__en]').value=${JSON.stringify(title)};document.querySelectorAll('[name="related[]"]').forEach(input=>input.remove());document.querySelector('[data-editorial-form]').requestSubmit()`,
	);
	await ready(
		`document.querySelector('[name=_revision]')?.value===${JSON.stringify(String(revision + 1))}`,
	);
	// This journey checks fresh published snapshots; publishing is a separate intent.
	await ready(
		"document.readyState==='complete' && document.querySelector('#record-publish-form') && document.querySelector('[name=_editorial_end]')?.value==='1'",
	);
	await evaluate("document.querySelector('#record-publish-form').requestSubmit()");
	await ready(
		`document.readyState==='complete' && document.querySelector('[name=_revision]')?.value==='${revision + 2}' && !document.querySelector('[data-unpublished-changes]')`,
	);
}
try {
	for (const instance of [browser]) {
		await instance.command("Page.enable");
		await instance.command("Runtime.enable");
		await instance.command("Log.enable");
	}
	await navigate("/login", "form");
	if (await evaluate("document.querySelector('[name=login]') !== null")) {
		await evaluate(
			"document.querySelector('[name=login]').value='admin@portable.test';document.querySelector('[name=password]').value='portable-cms-local';document.querySelector('form').requestSubmit()",
		);
		await ready(
			"location.pathname==='/' && document.querySelector('.admin-navigation')",
		);
	}
	const statusPath = await createPreview();
	// The shell script runs the same bounded conventional worker used by cron.
	process.stdout.write(`PREVIEW_QUEUED ${statusPath}\n`);
	await buildPreview();
	await openListedPreview(statusPath);
	await navigate(`${statusPath}/artifact/en/projects/archive-of-mist/`, "h1");
	const originalTitle = await evaluate("document.querySelector('h1').textContent");
	const updatedTitle = `${originalTitle} updated for the next preview`;
	await saveTitle(updatedTitle);
	await command("Emulation.setDeviceMetricsOverride", {
		width: 360,
		height: 800,
		deviceScaleFactor: 1,
		mobile: false,
	});
	await command("Emulation.setScriptExecutionDisabled", { value: true });
	const nextStatusPath = await createPreview();
	assert.notEqual(
		nextStatusPath,
		statusPath,
		"each creation must produce a new preview",
	);
	await command("Emulation.setScriptExecutionDisabled", { value: false });
	await navigate(nextStatusPath, "[data-preview-state]");
	await buildPreview();
	await navigate("/previews", ".admin-list");
	assert.deepEqual(
		(
			await evaluate(
				"[...document.querySelectorAll('.admin-list a')].map(a=>a.pathname)",
			)
		).sort(),
		[statusPath, nextStatusPath].sort(),
		"both immutable snapshots remain available",
	);
	await command("Emulation.setScriptExecutionDisabled", { value: true });
	await openListedPreview(nextStatusPath);
	await openListedPreview(statusPath, true);
	await command("Emulation.setScriptExecutionDisabled", { value: false });
	await navigate(`${nextStatusPath}/artifact/en/projects/archive-of-mist/`, "h1");
	assert.equal(
		await evaluate("document.querySelector('h1').textContent"),
		updatedTitle,
	);
	await navigate(`${statusPath}/artifact/en/projects/archive-of-mist/`, "h1");
	assert.equal(
		await evaluate("document.querySelector('h1').textContent"),
		originalTitle,
	);
	await saveTitle(originalTitle);
	await navigate(nextStatusPath, "[data-preview-state]");
	await evaluate("document.querySelector('form[action$=\"/delete\"]').requestSubmit()");
	await ready("location.pathname==='/previews'");
	assert.equal(
		await evaluate("document.documentElement.scrollWidth<=innerWidth"),
		true,
	);
	const mount = `${statusPath}/artifact/`;
	const unauthorized = await fetch(origin + mount, { redirect: "manual" });
	assert.equal(unauthorized.status, 303);
	assert.equal(
		unauthorized.headers.get("location"),
		"/login",
		"a preview URL is not an authorization token",
	);
	await navigate(mount, ".studio-header");
	await evaluate("document.fonts.ready");
	await evaluate("[...document.images].forEach(image=>{image.loading='eager'})");
	await ready("[...document.images].every(i=>i.complete && i.naturalWidth>0)");
	const manifest = await evaluate("fetch('artifact.json').then(r=>r.json())");
	assert.equal(manifest.profile.id, "reference-studio");
	assert.ok(manifest.files.length >= 60);
	const styles = await evaluate(
		"[...document.querySelectorAll('link[rel=stylesheet]')].map(l=>l.href)",
	);
	assert.ok(
		styles.every((url) => url.startsWith(origin + mount)),
		"styles stay inside the immutable preview",
	);
	const response = await evaluate(
		"fetch(location.href).then(async r=>({body:await r.text(),robots:r.headers.get('x-robots-tag'),cache:r.headers.get('cache-control')}))",
	);
	assert.equal(response.robots, "noindex, nofollow, noarchive");
	assert.equal(response.cache, "no-store");
	const speculation = await evaluate(
		"fetch(location.href).then(r=>r.headers.get('speculation-rules'))",
	);
	assert.equal(speculation, `"${mount}navigation-rules.json"`);
	const privateRules = await evaluate(
		"fetch('navigation-rules.json').then(async r=>({mime:r.headers.get('content-type'),cache:r.headers.get('cache-control'),body:await r.json()}))",
	);
	assert.equal(privateRules.mime, "application/speculationrules+json");
	assert.equal(privateRules.cache, "no-store");
	assert.equal(privateRules.body.prerender[0].where.and[0].href_matches, "*");
	const rootFile = manifest.files.find((file) => file.path === "index.html");
	const actualHash = await evaluate(
		"fetch(location.href).then(r=>r.arrayBuffer()).then(b=>crypto.subtle.digest('SHA-256',b)).then(b=>[...new Uint8Array(b)].map(x=>x.toString(16).padStart(2,'0')).join(''))",
	);
	assert.equal(
		actualHash,
		rootFile.sha256,
		"served root must equal its verified artifact hash",
	);
	await navigate(`${mount}en/projects/archive-of-mist/`, "[data-layout]");
	await evaluate("document.fonts.ready");
	await evaluate("[...document.images].forEach(image=>{image.loading='eager'})");
	await ready("[...document.images].every(i=>i.complete && i.naturalWidth>0)");
	assert.ok(
		(
			await evaluate(
				"[...document.querySelectorAll('.studio-header a')].map(a=>a.href)",
			)
		).every((url) => url.startsWith(origin + mount)),
	);
	await command("Emulation.setScriptExecutionDisabled", { value: true });
	await navigate(`${mount}en/about/`, ".studio-page");
	assert.ok(
		(await evaluate("document.querySelector('main').textContent")).length > 10,
		"native preview navigation works without scripts",
	);
	await command("Emulation.setScriptExecutionDisabled", { value: false });
	await navigate(statusPath, "[data-preview-state]");
	await evaluate("document.querySelector('form[action$=\"/delete\"]').requestSubmit()");
	await ready("location.pathname==='/previews'");
	assert.deepEqual(browserErrors, []);
	assert.equal(
		await evaluate(`fetch(${JSON.stringify(mount)}).then(r=>r.status)`),
		404,
	);
	await evaluate("document.querySelector('form[action=\"/logout\"]').requestSubmit()");
	await ready("location.pathname==='/login' && document.querySelector('[name=login]')");
	process.stdout.write(
		"PASS private browser preview: row-wide pointer opening, keyboard focus/Enter, hover underline, narrow no-script lists, pointer creation with existing previews, fresh and immutable snapshots, build/status, native deep navigation, styles/fonts/media, immutable served hashes, no-store/noindex and guarded deletion.\n",
	);
} finally {
	await browser.close();
}
