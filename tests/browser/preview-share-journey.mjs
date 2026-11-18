import assert from "node:assert/strict";
import { createHash } from "node:crypto";
import { isolatedPreviewBrowser } from "./isolated-preview-browser.mjs";
import { verifyNewestPreviewLink } from "./newest-preview-link-journey.mjs";

export async function verifySharedPreview(browser, origin, preview) {
	const { command, evaluate, navigate, ready } = browser;
	async function create(note, days = "7") {
		await navigate(preview, "[data-share-panel]");
		await evaluate(`(() => {
			const form = document.querySelector('[data-share-panel] form');
			form.elements.namedItem('days').value = ${JSON.stringify(days)};
			form.elements.namedItem('note').value = ${JSON.stringify(note)};
			form.requestSubmit();
		})()`);
		await ready(
			"document.readyState==='complete' && document.querySelector('[data-share-created] #share-link')",
		);
		return evaluate("document.querySelector('#share-link').value");
	}
	const link = await create("Designer");
	assert.ok(
		await evaluate(
			"document.querySelector('[data-share-created]').textContent.includes('Designer') && document.querySelector('[data-share-created]').textContent.includes('until')",
		),
		"the new link is named by its note and says until when it works",
	);
	const retained = await create("Client", "1");
	assert.ok(
		await evaluate(
			"document.querySelectorAll('[data-shared-link^=\"preview:\"]').length >= 2",
		),
		"both links are listed under the previews",
	);
	assert.equal(
		await evaluate(
			`[...document.querySelectorAll('[data-shared-link] [data-copy]')].some(button => button.dataset.copy === ${JSON.stringify(link)})`,
		),
		true,
		"a listed link can be copied again in full",
	);
	assert.equal(
		await evaluate(
			"(() => { const text = document.querySelector('#shared-links').textContent; return /[a-f0-9]{32}/.test(text) || text.includes('UTC'); })()",
		),
		false,
		"the list shows neither identities nor UTC",
	);
	const share = new URL(link).pathname.split("/")[2];
	const untilBefore = await evaluate(
		`document.querySelector('[data-shared-link="preview:${share}"] .admin-meta').textContent`,
	);
	await evaluate(`(() => {
		const row = document.querySelector('[data-shared-link="preview:${share}"]');
		const form = row.querySelector('form[action$="/extend"]');
		form.elements.namedItem('days').value = '30';
		form.requestSubmit();
	})()`);
	await ready(
		`location.pathname==='/previews' && document.querySelector('[data-shared-link="preview:${share}"] .admin-meta').textContent !== ${JSON.stringify(untilBefore)}`,
	);
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
		"document.querySelector('iframe[data-share-renew]')",
	);
	assert.equal(await evaluate("location.hash"), "");
	assert.equal(
		await evaluate("document.querySelector('header')"),
		null,
		"nothing sits above the frame",
	);
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
			"document.documentElement.scrollWidth <= innerWidth && (r => r.x === 0 && r.y === 0 && r.width === innerWidth && r.height === innerHeight)(document.querySelector('iframe').getBoundingClientRect())",
		),
		"the frame fills the window",
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
	const stopped = await fetch(`${origin}/previews/shares/preview/${share}/stop`, {
		method: "POST",
		headers: managementHeaders,
		body: new URLSearchParams({ _csrf: csrf }),
		redirect: "manual",
	});
	assert.equal(stopped.status, 303);
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
		"PASS browser preview sharing: named links from the Share panel, copy again, extend, fragment exchange, scoped secure cookie, bar with site and sharer, language choice, narrow layout, profile scripts, opaque-origin attacks, frozen bytes, stop, deletion and guest isolation.\n",
	);
}
