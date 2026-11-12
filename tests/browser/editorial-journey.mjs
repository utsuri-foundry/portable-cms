import assert from "node:assert/strict";
import { writeFile } from "node:fs/promises";
import { connectLocalBrowser } from "./local-browser.mjs";

// Explicit local sandbox only. This journey edits fictional content and restores its values.
const origin = process.env.CMS_BROWSER_ORIGIN ?? "http://localhost:4182";
const debug = process.env.CMS_BROWSER_DEBUG_URL ?? "http://127.0.0.1:9223";
const browser = await connectLocalBrowser(origin, debug);
const {
	command,
	evaluate,
	readyPage: ready,
	navigate,
	reload,
	browserErrors,
} = browser;
const switcherReady =
	"document.querySelector('[data-record-locale-switcher]') && !document.querySelector('[data-record-locale-switcher]').hidden";

try {
	await command("Page.enable");
	await command("Runtime.enable");
	await command("Log.enable");
	await command("Page.navigate", { url: `${origin}/login` });
	await ready(
		"document.readyState === 'complete' && (document.querySelector('[name=login]') || document.querySelector('.admin-navigation'))",
	);
	if (await evaluate("document.querySelector('[name=login]') !== null")) {
		await evaluate(
			`document.querySelector('[name=login]').value = 'admin@portable.test'; document.querySelector('[name=password]').value = 'portable-cms-local'; document.querySelector('form').requestSubmit();`,
		);
	}
	await ready(
		`location.pathname === '/' && document.querySelector('.admin-navigation')`,
	);
	await navigate("/records/project", ".admin-table__title");
	const recordPath = await evaluate(
		"document.querySelector('.admin-table__title').getAttribute('href')",
	);
	await navigate(recordPath, "[data-editorial-form]");
	await ready(switcherReady);
	const originalYear = await evaluate("document.querySelector('[name=year]').value");
	const original = await evaluate(
		"Object.fromEntries([...document.querySelectorAll('[data-editorial-form] input,[data-editorial-form] textarea')].filter(c => c.name.startsWith('title__')).map(c => [c.name,c.value]))",
	);
	assert.equal(
		await evaluate(
			"document.querySelectorAll('#field-title [data-locale-panel]:not([hidden])').length",
		),
		1,
	);
	assert.equal(
		await evaluate("document.querySelector('[data-structured-field]') === null"),
		true,
	);
	// One record-level switch changes every localized field at once.
	await evaluate(
		"document.querySelector('[data-record-locale-switcher] [data-locale-tab=en]').click()",
	);
	assert.equal(
		await evaluate(
			"[...document.querySelectorAll('[data-editorial-form] [data-locale-panel]:not([hidden])')].every(p => p.dataset.localePanel === 'en')",
		),
		true,
	);
	assert.equal(
		await evaluate("document.querySelector('[data-locale-tag]').textContent"),
		"EN",
	);
	await evaluate(
		"document.querySelector('[data-record-locale-switcher] [data-locale-tab=en]').dispatchEvent(new KeyboardEvent('keydown',{key:'Home',bubbles:true,cancelable:true}))",
	);
	assert.equal(await evaluate("document.activeElement.dataset.localeTab"), "ca");
	const feedback = await evaluate(
		"(() => {document.querySelector('[data-record-locale-switcher] [data-locale-tab=en]').click();document.querySelector('[name=title__en]').value='Unsaved browser journey';document.querySelector('[name=title__en]').dispatchEvent(new Event('input',{bubbles:true}));return {status:document.querySelector('[data-save-status]').textContent,changed:document.querySelector('[data-section-changes=content]').textContent};})()",
	);
	assert.equal(feedback.status, "Saving");
	assert.equal(feedback.changed, "1 changed");
	await evaluate(
		`document.querySelector('[name=title__en]').value = ${JSON.stringify(original.title__en)}; document.querySelector('[name=title__en]').dispatchEvent(new Event('input',{bubbles:true}))`,
	);
	await ready(
		"document.querySelector('[data-save-status]').textContent.startsWith('Saved at ')",
	);

	// Rich text: one writing surface serialized back into the native block protocol.
	const rich = (expression) =>
		evaluate(
			`(()=>{const root=document.querySelector('[data-rich-name="body__en"]');const surface=root.querySelector('.admin-rich-surface');const firstText=(scope)=>document.createTreeWalker(scope,NodeFilter.SHOW_TEXT).nextNode();const select=(start,end,node=firstText(surface))=>{const range=document.createRange();range.setStart(node,start);range.setEnd(node,end);const selection=getSelection();selection.removeAllRanges();selection.addRange(range);};${expression}})()`,
		);
	const transport = () =>
		rich(
			"return Object.fromEntries([...root.querySelectorAll('[data-rich-transport] input')].map(i=>[i.name,i.value]))",
		);
	assert.equal(
		await rich("return root.querySelector('[data-rich-rows]').hidden"),
		true,
	);
	assert.equal(
		await rich(
			"return [...root.querySelectorAll('[data-rich-rows] textarea')].every(c => c.disabled)",
		),
		true,
	);
	const originalForm = await evaluate(
		"[...new FormData(document.querySelector('[data-editorial-form]')).entries()]",
	);
	const initialRich = await rich(
		"return root.querySelector('[data-rich-fingerprint]').value",
	);
	await rich(
		"surface.focus();select(0,5);root.querySelector('[data-rich-tool=bold]').dispatchEvent(new MouseEvent('click',{bubbles:true}));",
	);
	assert.equal(await rich("return surface.querySelector('strong,b') !== null"), true);
	assert.equal((await transport()).body__en__0__0__bold, "1");
	assert.equal(
		await rich("return document.activeElement === surface"),
		true,
		"formatting keeps the writing focus",
	);
	await rich(
		"root.querySelector('[data-rich-tool=undo]').dispatchEvent(new MouseEvent('click',{bubbles:true}))",
	);
	assert.equal(
		await rich("return root.querySelector('[data-rich-fingerprint]').value"),
		initialRich,
	);
	// Small capitals use original letters, a localized control and one shared history.
	assert.deepEqual(browserErrors, [], "native formatting must preserve strict CSP");
	assert.equal(
		await rich(
			"return root.querySelector('[data-rich-tool=smallCaps]').getAttribute('aria-label')",
		),
		"Small capitals",
	);
	await rich(
		"surface.focus();select(0,5);surface.dispatchEvent(new KeyboardEvent('keydown',{key:'K',ctrlKey:true,shiftKey:true,bubbles:true,cancelable:true}));",
	);
	assert.equal(
		await rich("return surface.querySelector('.cms-small-caps') === null"),
		true,
	);
	await rich("root.querySelector('[data-rich-tool=undo]').click()");
	assert.equal(
		await rich("return surface.querySelector('.cms-small-caps').textContent"),
		"RIBA ",
	);
	await rich("root.querySelector('[data-rich-tool=redo]').click()");
	assert.equal(
		await rich("return surface.querySelector('.cms-small-caps') === null"),
		true,
	);
	await rich("root.querySelector('[data-rich-tool=undo]').click()");
	assert.equal(
		await rich("return root.querySelector('[data-rich-fingerprint]').value"),
		initialRich,
	);
	await rich(
		"surface.focus();const range=document.createRange();range.selectNodeContents(surface);range.collapse(false);getSelection().removeAllRanges();getSelection().addRange(range);const data=new DataTransfer();data.setData('text/plain','RIBA café');data.setData('text/html','<p><span style=\"font-variant: small-caps; background:url(https://tracking.invalid/)\" onclick=\"evil()\">RIBA café</span><img src=\"https://tracking.invalid/\"></p>');surface.dispatchEvent(new ClipboardEvent('paste',{clipboardData:data,bubbles:true,cancelable:true}));",
	);
	assert.equal(
		await rich(
			"return [...surface.querySelectorAll('.cms-small-caps')].some(node => node.textContent === 'RIBA café')",
		),
		true,
	);
	assert.equal(
		await rich("return surface.querySelector('[style],[onclick],img') === null"),
		true,
	);
	await rich("root.querySelector('[data-rich-tool=undo]').click()");
	assert.equal(
		await rich("return root.querySelector('[data-rich-fingerprint]').value"),
		initialRich,
	);
	await rich("root.querySelector('[data-rich-tool=redo]').click()");
	assert.equal(await rich("return surface.textContent.includes('RIBA café')"), true);
	await rich("root.querySelector('[data-rich-tool=undo]').click()");

	// Semantic copy and paste keeps allowed marks; unrelated foreign HTML stays plain text.
	assert.deepEqual(
		browserErrors,
		[],
		"small capitals and safe paste must preserve strict CSP",
	);
	await rich(
		"surface.focus();select(0,5);root.querySelector('[data-rich-tool=italic]').dispatchEvent(new MouseEvent('click',{bubbles:true}));select(0,5,firstText(surface.querySelector('em,i')));const data=new DataTransfer();surface.dispatchEvent(new ClipboardEvent('copy',{clipboardData:data,bubbles:true,cancelable:true}));window.__semantic=data.getData('application/x-portable-cms-text');const range=document.createRange();range.selectNodeContents(surface);range.collapse(false);getSelection().removeAllRanges();getSelection().addRange(range);surface.dispatchEvent(new ClipboardEvent('paste',{clipboardData:data,bubbles:true,cancelable:true}));",
	);
	assert.match(await evaluate("window.__semantic"), /"italic"/);
	assert.ok(
		(await rich("return surface.querySelectorAll('em,i').length")) >= 2,
		"semantic paste preserves italic",
	);
	await rich(
		"surface.focus();const range=document.createRange();range.selectNodeContents(surface);range.collapse(false);getSelection().removeAllRanges();getSelection().addRange(range);const data=new DataTransfer();data.setData('text/html','<script>throw new Error(\"unsafe\")</script><img src=x onerror=alert(1)>');data.setData('text/plain','Safe <text> café 👋');surface.dispatchEvent(new ClipboardEvent('paste',{clipboardData:data,bubbles:true,cancelable:true}));",
	);
	assert.match(await rich("return surface.textContent"), /Safe <text> café 👋/);
	assert.equal(await rich("return surface.querySelector('script,img') === null"), true);
	// Block style applies through the toolbar select.
	await rich(
		"surface.focus();select(0,1);const style=root.querySelector('.admin-rich-toolbar select');style.value='bullet_list';style.dispatchEvent(new Event('change',{bubbles:true}));",
	);
	assert.equal(await rich("return surface.querySelector('ul li') !== null"), true);
	assert.match(JSON.stringify(await transport()), /bullet_list/);
	// Links follow the server allowlist before reaching the document.
	await rich(
		"surface.focus();select(0,3,firstText(surface.querySelector('li')));root.querySelector('[data-rich-tool=link]').dispatchEvent(new MouseEvent('click',{bubbles:true}));const input=root.querySelector('.admin-rich-link input');input.value='javascript:alert(1)';root.querySelector('.admin-rich-link button').click();",
	);
	assert.match(
		await rich("return root.querySelector('.admin-rich-status').textContent"),
		/https/,
	);
	assert.equal(await rich("return surface.querySelector('a') === null"), true);
	await rich(
		"const input=root.querySelector('.admin-rich-link input');input.value='/about';root.querySelector('.admin-rich-link button').click();",
	);
	assert.equal(
		await rich("return surface.querySelector('a')?.getAttribute('href')"),
		"/about",
	);
	assert.ok(Object.values(await transport()).includes("/about"));
	const ax = await command("Accessibility.getFullAXTree");
	assert.ok(
		ax.nodes.some(
			(node) =>
				node.role?.value === "textbox" && /Body, English/.test(node.name?.value),
		),
		"the writing surface is a named multiline textbox",
	);
	await ready(
		"document.querySelector('[data-save-status]').textContent.startsWith('Saved at ')",
	);
	const keptRich = await rich(
		"return root.querySelector('[data-rich-fingerprint]').value",
	);
	assert.notEqual(keptRich, initialRich);
	await navigate(recordPath, "[data-editorial-form]");
	await ready("document.querySelector('[data-rich-fingerprint]')");
	assert.equal(
		await rich("return root.querySelector('[data-rich-fingerprint]').value"),
		keptRich,
		"rich surface edits survive reload without a Save action",
	);

	// Rich text persists through MariaDB, reload and stale revision conflicts.
	assert.deepEqual(
		browserErrors,
		[],
		"native lists and links must preserve strict CSP",
	);
	const richRevision = await evaluate(
		"document.querySelector('[name=_revision]').value",
	);
	const replace = (value) =>
		`document.querySelector('[data-record-locale-switcher] [data-locale-tab=en]').click();surface.focus();const range=document.createRange();range.selectNodeContents(surface);getSelection().removeAllRanges();getSelection().addRange(range);document.execCommand('insertText',false,${JSON.stringify(value)});`;
	await rich(
		`${replace("Browser rich text café 👋")}surface.querySelectorAll('.cms-small-caps').forEach(node => node.replaceWith(...node.childNodes));surface.dispatchEvent(new InputEvent('input',{bubbles:true}));select(0,7);surface.dispatchEvent(new KeyboardEvent('keydown',{key:'K',ctrlKey:true,shiftKey:true,bubbles:true,cancelable:true}));document.querySelector('[data-editorial-form]').requestSubmit()`,
	);
	await ready(
		"document.querySelector('[data-save-status]')?.textContent.match(/Saved at |No unsaved changes/) && document.querySelector('[name=_revision]').value !== " +
			JSON.stringify(richRevision),
	);
	await ready("document.querySelector('[data-rich-fingerprint]')");
	assert.equal(await rich("return surface.textContent"), "Browser rich text café 👋");
	assert.equal(
		await rich("return surface.querySelector('.cms-small-caps').textContent"),
		"Browser",
	);
	await rich(
		`${replace("Conflicted rich input")}document.querySelector('[name=_revision]').value=${JSON.stringify(richRevision)};document.querySelector('[data-editorial-form]').requestSubmit()`,
	);
	await ready(
		"document.querySelector('[data-conflict-summary]') && document.querySelector('[data-rich-name=body__en] .admin-rich-surface')",
	);
	assert.equal(await rich("return surface.textContent"), "Conflicted rich input");
	await command("Emulation.setScriptExecutionDisabled", { value: true });
	await navigate("/media", ".admin-media-grid");
	await navigate(recordPath, "[data-editorial-form]");
	assert.deepEqual(
		await evaluate(
			`(async()=>{const form=document.querySelector('[data-editorial-form]');const data=new URLSearchParams(${JSON.stringify(originalForm)});for(const name of ['_revision','_edit_sequence','_csrf'])data.set(name,form.elements.namedItem(name).value);const response=await fetch(location.pathname,{method:'POST',body:data});return {status:response.status,redirected:response.redirected}})()`,
		),
		{ status: 200, redirected: true },
	);
	await command("Emulation.setScriptExecutionDisabled", { value: false });
	await reload();
	await ready(
		"document.querySelector('[data-rich-fingerprint]') && document.querySelector('[data-rich-name=body__en] .admin-rich-surface').textContent !== 'Browser rich text café 👋'",
	);

	// Native validation: hidden required locales must become focusable with multiple errors.
	await evaluate(
		"document.querySelectorAll('#field-title input').forEach(c => {c.required=true;c.value='';}); document.querySelector('[data-editorial-form]').reportValidity();",
	);
	assert.equal(await evaluate("document.activeElement.id"), "field-title-ca");
	assert.equal(
		await evaluate(
			"document.querySelector('#field-title [data-locale-panel=ca]').hidden",
		),
		false,
	);
	assert.equal(
		browserErrors.some((text) => /not focusable/.test(text)),
		false,
	);
	await evaluate(
		`for (const [name,value] of Object.entries(${JSON.stringify(original)})) document.getElementsByName(name)[0].value=value; document.querySelectorAll('#field-title input').forEach(c => c.required=false); document.querySelector('[name=title__en]').dispatchEvent(new Event('input',{bubbles:true}));`,
	);

	// Invalid server response must retain every language, issues and the submitted revision.
	const revision = await evaluate("document.querySelector('[name=_revision]').value");
	await evaluate(
		"document.querySelector('[name=year]').value='1800'; document.querySelector('[data-editorial-form]').requestSubmit()",
	);
	await ready("document.querySelector('[data-validation-summary]')");
	assert.equal(
		await evaluate("document.querySelector('[name=year]').value"),
		"1800",
		"the rejected year remains visible for correction",
	);
	assert.equal(
		await evaluate("document.querySelector('[name=_revision]').value"),
		revision,
	);
	assert.deepEqual(
		await evaluate(
			"Object.fromEntries([...document.querySelectorAll('#field-title input')].map(c=>[c.name,c.value]))",
		),
		original,
	);
	assert.equal(
		await evaluate("document.querySelector('#section-details').dataset.issueCount"),
		"1",
	);
	await navigate(recordPath, "[data-editorial-form]");
	await ready(switcherReady);
	assert.equal(
		await evaluate("document.querySelector('[name=year]').value"),
		originalYear,
		"leaving a rejected edit retains the original saved year for later journeys",
	);
	await command("Emulation.setDeviceMetricsOverride", {
		width: 360,
		height: 800,
		deviceScaleFactor: 1,
		mobile: false,
	});
	assert.equal(
		await evaluate("document.documentElement.scrollWidth <= innerWidth"),
		true,
	);
	await evaluate(
		"document.querySelector('[data-save-status]').scrollIntoView({block:'end'})",
	);
	assert.equal(
		await evaluate(
			"document.querySelector('.admin-save-bar').getBoundingClientRect().bottom <= innerHeight + 1",
		),
		true,
	);
	assert.equal(
		await evaluate(
			"getComputedStyle(document.querySelector('.admin-sidebar')).display === 'none' && getComputedStyle(document.querySelector('.admin-menu-button')).display !== 'none'",
		),
		true,
		"phone navigation waits behind the Menu button",
	);
	await evaluate("document.querySelector('.admin-menu-button').click()");
	assert.equal(
		await evaluate("document.querySelector('.admin-sidebar').matches(':popover-open')"),
		true,
	);
	await evaluate("document.querySelector('.admin-sidebar').hidePopover()");
	await command("Emulation.setDeviceMetricsOverride", {
		width: 640,
		height: 450,
		deviceScaleFactor: 2,
		mobile: false,
	});
	assert.equal(
		await evaluate("document.documentElement.scrollWidth <= innerWidth"),
		true,
	);
	await evaluate(
		"document.querySelector('#field-body').scrollIntoView({block:'start'})",
	);
	const screenshot = await command("Page.captureScreenshot", {
		format: "png",
		captureBeyondViewport: false,
	});
	await writeFile(
		process.env.CMS_BROWSER_SCREENSHOT ?? "/tmp/portable-cms-editorial-review.png",
		Buffer.from(screenshot.data, "base64"),
	);
	await command("Emulation.clearDeviceMetricsOverride");

	// Disposal restores the complete form. Remount is safe and does not duplicate listeners.
	assert.equal(
		await evaluate(
			`(async()=>{const {mountEditorialForm}=await import('/assets/editorial-form.js?v=7'); const form=document.querySelector('[data-editorial-form]');const dispose=mountEditorialForm(form);dispose();dispose();return [...form.querySelectorAll('[data-locale-panel]')].every(p=>!p.hidden);})()`,
		),
		true,
	);
	await command("Emulation.setScriptExecutionDisabled", { value: true });
	await reload();
	await ready(
		"document.readyState === 'complete' && document.querySelector('[data-editorial-form]') && document.querySelector('[data-record-locale-switcher]').hidden",
	);
	assert.equal(
		await evaluate(
			"[...document.querySelectorAll('[data-locale-panel]')].every(p=>!p.hidden)",
		),
		true,
	);
	const noScriptRevision = await evaluate(
		"document.querySelector('[name=_revision]').value",
	);
	assert.equal(
		await evaluate(
			"document.querySelector('[name=body__en__0__0__smallCaps]').checked",
		),
		true,
	);
	await evaluate(
		"document.querySelector('[data-rich-name=body__en] [data-rich-action=add]').click()",
	);
	await ready(
		"document.readyState === 'complete' && document.querySelector('[name=body__en__rows]')?.value === '2' && document.querySelector('[data-save-status]')",
	);
	assert.equal(
		await evaluate("document.querySelector('[name=_revision]').value"),
		noScriptRevision,
	);
	assert.equal(
		await evaluate("document.querySelector('[data-save-status]').textContent"),
		"Unsaved changes.",
	);
	await navigate(recordPath, "[data-editorial-form]");
	await command("Emulation.setScriptExecutionDisabled", { value: false });
	await reload();
	await ready(switcherReady);
	await navigate("/records/article", ".admin-table__title");
	const articlePath = await evaluate(
		"document.querySelector('.admin-table__title').getAttribute('href')",
	);
	await navigate(articlePath, "[data-editorial-form]");
	await ready(switcherReady);
	await evaluate("document.querySelector('[data-locale-tab=en]').click()");
	assert.equal(
		await rich("return root.querySelector('[data-rich-tool=smallCaps]') === null"),
		true,
	);
	await rich(
		"surface.focus();const range=document.createRange();range.selectNodeContents(surface);range.collapse(false);getSelection().removeAllRanges();getSelection().addRange(range);const data=new DataTransfer();data.setData('text/plain','RIBA café');data.setData('text/html','<p><span style=\"font-variant: small-caps\">RIBA café</span></p>');surface.dispatchEvent(new ClipboardEvent('paste',{clipboardData:data,bubbles:true,cancelable:true}));",
	);
	assert.equal(
		await rich(
			"return surface.textContent.includes('RIBA café') && surface.querySelector('.cms-small-caps') === null",
		),
		true,
	);
	await rich("root.querySelector('[data-rich-tool=undo]').click()");
	await navigate("/settings/terminology", '[name="project__plural"]');
	const names = await evaluate(
		"({singular:document.querySelector('[name=project__singular]').value,plural:document.querySelector('[name=project__plural]').value})",
	);
	await evaluate(
		"document.querySelector('[name=project__singular]').value='Case';document.querySelector('[name=project__plural]').value='Cases'; document.querySelector('form.admin-form').requestSubmit()",
	);
	await ready(
		"document.querySelector('[name=project__plural]')?.value === 'Cases' && document.querySelector('.admin-navigation').textContent.includes('Cases')",
	);
	await navigate(recordPath, "[data-editorial-form]");
	assert.match(
		await evaluate("document.querySelector('.admin-breadcrumbs').textContent"),
		/Cases/,
	);
	await navigate("/settings/terminology", '[name="project__plural"]');
	await evaluate(
		`document.querySelector('[name=project__singular]').value=${JSON.stringify(names.singular)};document.querySelector('[name=project__plural]').value=${JSON.stringify(names.plural)};document.querySelector('form.admin-form').requestSubmit()`,
	);
	await ready(
		`document.querySelector('[name=project__plural]')?.value === ${JSON.stringify(names.plural)} && !document.querySelector('.admin-navigation').textContent.includes('Cases')`,
	);
	assert.deepEqual(browserErrors, []);
	console.log(
		"PASS native browser editorial journey: record language mode, writing surface, formatting, links, semantic and hostile paste, MariaDB save/reload/conflict, accessibility, phone menu, 360px/zoom, no-script, naming and disposal.",
	);
} finally {
	await browser.close();
}
