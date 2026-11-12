import assert from "node:assert/strict";
import { createHash } from "node:crypto";
import { isolatedPreviewBrowser } from "./isolated-preview-browser.mjs";
import { verifyNewestPreviewLink } from "./newest-preview-link-journey.mjs";

export async function verifySharedPreview(browser, origin, preview) {
	const { command, evaluate, navigate, ready } = browser;
	const route = `${preview}/shares`;
	async function create() {
		await navigate(route, "#share-expiry");
		await evaluate(`(() => {
			document.querySelector('[name=expiry_hours]').value='24';
			document.querySelector('form[action="${route}"]').requestSubmit();
		})()`);
		await ready("document.querySelector('#share-link')");
		return evaluate("document.querySelector('#share-link').value");
	}
	const link = await create();
	const retained = await create();
	const csrf = await evaluate("document.querySelector('[name=_csrf]').value");
	const { cookies } = await command("Network.getCookies", { urls: [origin] });
	const cookie = cookies.map((value) => `${value.name}=${value.value}`).join("; ");
	const managementHeaders = {
		Cookie: cookie,
		Origin: origin,
		"Sec-Fetch-Site": "same-origin",
		"Content-Type": "application/x-www-form-urlencoded",
	};
	await verifyNewestPreviewLink(browser, origin, managementHeaders, csrf);
	const original = await fetch(
		`${origin}${preview}/artifact/en/articles/empty-installation-article/`,
		{ headers: { Cookie: cookie } },
	).then((response) => response.text());
	await command("Network.clearBrowserCookies");
	await command("Emulation.setScriptExecutionDisabled", { value: false });
	await command("Page.navigate", { url: link });
	await ready(
		"document.querySelector('[data-share-notice]') && document.querySelector('iframe[data-share-renew]')",
	);
	assert.equal(await evaluate("location.hash"), "");
	const guest = await command("Network.getCookies", { urls: [origin] });
	assert.ok(
		guest.cookies.some(
			(value) =>
				value.name.startsWith("__Host-preview-share-") &&
				value.httpOnly &&
				value.secure &&
				value.sameSite === "Strict",
		),
	);
	assert.ok(!guest.cookies.some((value) => value.name === "__Host-cms_session"));
	await command("Emulation.setDeviceMetricsOverride", {
		width: 360,
		height: 760,
		deviceScaleFactor: 1,
		mobile: false,
	});
	assert.ok(
		await evaluate(
			"document.documentElement.scrollWidth <= innerWidth && document.querySelector('iframe').getBoundingClientRect().width === innerWidth",
		),
	);
	await evaluate("document.querySelector('[data-share-dismiss]').click()");
	assert.equal(
		await evaluate("document.querySelector('[data-share-notice]').hidden"),
		true,
	);
	const files = await evaluate("document.querySelector('iframe').src");
	assert.equal(
		await evaluate(`fetch(${JSON.stringify(new URL("renew", link).href)}, {
			method: 'POST',
			body: new URLSearchParams({token: ${JSON.stringify(new URL(files).pathname.split("/")[3])}})
		}).then(response => response.status)`),
		204,
		"the browser viewer renews its address using only the share cookie",
	);
	assert.equal(
		await evaluate("document.querySelector('iframe').contentDocument"),
		null,
		"shell cannot read the opaque page",
	);
	const deep = new URL("en/articles/empty-installation-article/", files).href;
	await evaluate(`document.querySelector('iframe').src=${JSON.stringify(deep)}`);
	let page = await isolatedPreviewBrowser(browser, deep);
	await page.ready(
		"document.querySelector('h1')?.textContent==='An article authored from an empty installation'",
	);
	assert.equal(await page.evaluate("self.origin"), "null");
	assert.deepEqual(
		await page.evaluate(
			"(()=>{const result={};for(const [name,read] of Object.entries({cookie:()=>document.cookie,storage:()=>localStorage.length,parent:()=>parent.document.title})){try{read();result[name]='read'}catch(e){result[name]=e.name}}return result})()",
		),
		{ cookie: "SecurityError", storage: "SecurityError", parent: "SecurityError" },
	);
	assert.equal(
		await page.evaluate("fetch('/records').then(()=>false,()=>true)"),
		true,
		"site script cannot read CMS routes",
	);
	assert.equal(
		await page.evaluate(
			"fetch('/records',{credentials:'include'}).then(()=>false,()=>true)",
		),
		true,
		"requesting credentials cannot bypass the opaque-origin boundary",
	);
	assert.equal(
		await page.evaluate("window.open('/')===null"),
		true,
		"site script cannot open the administration",
	);
	await page.evaluate(
		"(()=>{const form=document.createElement('form');form.action='/records';form.method='POST';document.body.append(form);form.submit()})()",
	);
	assert.equal(
		await page.evaluate("location.href"),
		deep,
		"sandbox blocks a form sent to the CMS",
	);
	assert.equal(
		await page.evaluate(
			"(()=>{try{top.location='/';return false}catch{return true}})()",
		),
		true,
		"site script cannot navigate the viewer to the administration",
	);
	await page.close();
	const listing = new URL("en/search/", files).href;
	await evaluate(`document.querySelector('iframe').src=${JSON.stringify(listing)}`);
	page = await isolatedPreviewBrowser(browser, listing);
	await page.ready(
		"document.querySelector('[data-filter-input]') && document.readyState==='complete'",
	);
	await page.evaluate(
		"(()=>{const input=document.querySelector('[data-filter-input]');input.value='no-such-fictional-article';input.dispatchEvent(new Event('input',{bubbles:true}))})()",
	);
	assert.equal(
		await page.evaluate(
			"[...document.querySelectorAll('[data-index-item]')].every(item=>item.hidden) && document.querySelector('[data-filter-status]').value.startsWith('0 ')",
		),
		true,
		"the frozen profile search script actually runs",
	);
	await page.close();
	const sharedBytes = await evaluate(
		`fetch(${JSON.stringify(deep)},{credentials:"omit"}).then(response=>response.text())`,
	);
	assert.equal(
		createHash("sha256").update(sharedBytes).digest("hex"),
		createHash("sha256").update(original).digest("hex"),
	);
	const share = new URL(link).pathname.split("/")[2];
	const revoked = await fetch(`${origin}${route}/${share}/revoke`, {
		method: "POST",
		headers: managementHeaders,
		body: new URLSearchParams({ _csrf: csrf }),
		redirect: "manual",
	});
	assert.equal(revoked.status, 303);
	assert.equal(
		await evaluate(
			`fetch(${JSON.stringify(deep)},{credentials:"omit"}).then(response=>response.status)`,
		),
		404,
	);
	await command("Page.navigate", { url: retained });
	await ready("document.querySelector('iframe[data-share-renew]')");
	const retainedFiles = await evaluate("document.querySelector('iframe').src");
	const deleted = await fetch(`${origin}${preview}/delete`, {
		method: "POST",
		headers: managementHeaders,
		body: new URLSearchParams({ _csrf: csrf }),
		redirect: "manual",
	});
	assert.equal(deleted.status, 303);
	assert.equal(
		await evaluate(
			`fetch(${JSON.stringify(retainedFiles)},{credentials:"omit"}).then(response=>response.status)`,
		),
		404,
	);
	await command("Page.navigate", { url: `${origin}/previews` });
	await ready("location.pathname==='/login' && document.querySelector('form')");
	assert.equal(await evaluate("location.pathname"), "/login");
	process.stdout.write(
		"PASS browser preview sharing: fragment exchange, scoped secure cookie, notice dismissal, narrow layout, profile scripts, opaque-origin attacks, frozen bytes, revocation, deletion and guest isolation.\n",
	);
}
