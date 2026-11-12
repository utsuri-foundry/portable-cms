import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { isolatedPreviewBrowser } from "./isolated-preview-browser.mjs";

/** Uses only the ephemeral workflow fixture and its explicitly captured publisher session. */
export async function verifyNewestPreviewLink(
	browser,
	origin,
	managementHeaders,
	csrf,
) {
	const { command, evaluate, navigate, ready } = browser;
	await navigate("/previews", "#newest-share-expiry");
	await evaluate(
		"document.querySelector('#newest-share-expiry').value='720';document.querySelector('form[action=\"/previews/shares/newest\"]').requestSubmit()",
	);
	await ready("document.querySelector('#newest-share-link')");
	const link = await evaluate("document.querySelector('#newest-share-link').value");
	const manifest = await evaluate(
		"document.querySelector('#newest-share-manifest').value",
	);
	assert.ok(
		await evaluate(
			"document.querySelector('#newest-preview-links').textContent.includes('including drafts')",
		),
	);
	const secret = new URL(link).hash.slice(1);
	const bearer = { Authorization: `Bearer ${secret}` };
	const firstManifest = await fetch(manifest, { headers: bearer });
	assert.equal(firstManifest.status, 200);
	assert.equal(firstManifest.headers.get("cache-control"), "no-store");
	const firstPinnedUrl = firstManifest.url;
	assert.match(
		new URL(firstPinnedUrl).pathname,
		/\/pinned\/[a-f0-9]{32}\/artifact\/artifact\.json$/,
	);
	const firstBytes = await firstManifest.text();
	await command("Network.clearBrowserCookies");
	await command("Emulation.setScriptExecutionDisabled", { value: false });
	await command("Page.navigate", { url: link });
	await ready(
		"document.querySelector('[data-share-notice]') && document.querySelector('iframe[data-share-renew]')",
	);
	assert.equal(await evaluate("location.hash"), "");
	const frameBefore = await evaluate("document.querySelector('iframe').src");
	assert.match(
		new URL(frameBefore).pathname,
		/^\/newest-preview-files\/[a-f0-9]{32}\/[a-f0-9]{64}\/$/,
	);
	assert.equal(
		await evaluate("document.querySelector('iframe').contentDocument"),
		null,
	);
	assert.equal(
		await fetch(new URL("artifact.json", frameBefore)).then((response) =>
			response.text(),
		),
		firstBytes,
		"browser address serves exactly the resolved bearer build",
	);
	const isolated = await isolatedPreviewBrowser(browser, frameBefore);
	await isolated.ready(
		"document.querySelector('main') && document.readyState==='complete'",
	);
	assert.equal(await isolated.evaluate("self.origin"), "null");
	assert.deepEqual(
		await isolated.evaluate(
			"import(new URL('assets/search.js',location.href)).then(module=>module.normalize('Été light'))",
		),
		["ete", "light"],
		"the actual profile module can execute in a newest-preview page",
	);
	assert.equal(
		await isolated.evaluate(
			"(()=>{try{return document.cookie}catch(error){return error.name}})()",
		),
		"SecurityError",
	);
	await isolated.close();
	await evaluate("document.querySelector('[data-share-dismiss]').click()");
	assert.equal(
		await evaluate("document.querySelector('[data-share-notice]').hidden"),
		true,
	);
	const guest = await command("Network.getCookies", { urls: [origin] });
	assert.ok(
		guest.cookies.some(
			(cookie) =>
				cookie.name.startsWith("__Host-preview-follow-") &&
				cookie.secure &&
				cookie.httpOnly &&
				cookie.sameSite === "Strict",
		),
	);
	assert.ok(!guest.cookies.some((cookie) => cookie.name === "__Host-cms_session"));
	const queued = await fetch(`${origin}/previews`, {
		method: "POST",
		headers: managementHeaders,
		body: new URLSearchParams({ _csrf: csrf, include_drafts: "1" }),
		redirect: "manual",
	});
	assert.equal(queued.status, 202);
	const preview = queued.headers
		.get("refresh")
		?.match(/url=(\/previews\/[a-f0-9]{32})/)?.[1];
	assert.ok(preview);
	execFileSync(
		"php",
		[
			"tools/conventional-media-worker.php",
			`--config=${process.env.CMS_BROWSER_WORKER_CONFIG}`,
			"--max-jobs=1",
			"--max-seconds=10",
			"--worker-id=newest-preview-browser",
		],
		{ timeout: 15000 },
	);
	const state = await fetch(`${origin}${preview}`, { headers: managementHeaders }).then(
		(response) => response.text(),
	);
	assert.ok(state.includes('data-preview-state="ready"'));
	assert.equal(
		await evaluate("document.querySelector('iframe').src"),
		frameBefore,
		"an already open viewer remains on its pinned build",
	);
	const parentBase = new URL(link).pathname;
	assert.equal(
		await evaluate(
			`fetch(${JSON.stringify(`${parentBase}renew`)}, {method:'POST',body:new URLSearchParams({token:${JSON.stringify(new URL(frameBefore).pathname.split("/")[3])}})}).then(response=>response.status)`,
		),
		204,
		"cookie renewal keeps the existing file address after a newer build arrives",
	);
	assert.equal(
		await fetch(new URL("artifact.json", frameBefore)).then((response) =>
			response.text(),
		),
		firstBytes,
		"renewal keeps the original resolved build",
	);
	assert.equal(
		await fetch(firstPinnedUrl, { headers: bearer }).then((response) =>
			response.text(),
		),
		firstBytes,
		"tool fetch keeps the original manifest after a new arrival",
	);
	await command("Page.reload");
	await ready(
		`document.querySelector('iframe[data-share-renew]')?.src.startsWith(${JSON.stringify(`${origin}/newest-preview-files/`)}) && document.querySelector('iframe').src!==${JSON.stringify(frameBefore)} && document.readyState==='complete'`,
	);
	const frameAfter = await evaluate("document.querySelector('iframe').src");
	assert.notEqual(frameAfter, frameBefore);
	const fresh = await fetch(manifest, { headers: bearer });
	assert.notEqual(
		fresh.url,
		firstPinnedUrl,
		"the same manifest address follows a new ready preview",
	);
	assert.ok(fresh.url.includes(`/pinned/${preview.split("/")[2]}/artifact/`));
	assert.equal(
		await fetch(new URL("artifact.json", frameAfter)).then((response) =>
			response.text(),
		),
		await fresh.text(),
		"browser reload and bearer fetch resolve the same newest build",
	);
	await command("Emulation.setDeviceMetricsOverride", {
		width: 360,
		height: 760,
		deviceScaleFactor: 1,
		mobile: false,
	});
	assert.ok(await evaluate("document.documentElement.scrollWidth<=innerWidth"));
	const id = new URL(link).pathname.split("/")[2];
	const revoked = await fetch(`${origin}/previews/shares/newest/${id}/revoke`, {
		method: "POST",
		headers: managementHeaders,
		body: new URLSearchParams({ _csrf: csrf }),
		redirect: "manual",
	});
	assert.equal(revoked.status, 303);
	for (const url of [manifest, firstPinnedUrl, fresh.url, frameBefore, frameAfter]) {
		assert.equal(
			await fetch(url, { headers: bearer }).then((response) => response.status),
			404,
		);
	}
	console.log(
		"PASS newest preview browser sharing: profile scripts in an opaque frame, parent-cookie renewal, pinned browser/bearer bytes, notice dismissal, newer build and reload, 360px and immediate revocation.",
	);
}
