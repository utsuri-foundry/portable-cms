import assert from "node:assert/strict";
import { connectLocalBrowser } from "./local-browser.mjs";

const browser = await connectLocalBrowser(
	process.env.CMS_BROWSER_ORIGIN,
	process.env.CMS_BROWSER_DEBUG_URL,
);
const { command, evaluate, navigate, ready, browserErrors } = browser;
const record = "/records/project/10000000000000000000000000000001";
const editor = '[data-rich-name="salesAgent"]';
const surface = `${editor} .admin-rich-surface`;
async function save() {
	const revision = await evaluate("document.querySelector('[name=_revision]').value");
	await evaluate(
		"document.documentElement.dataset.singleLineSaving='1';document.querySelector('[data-editorial-form]').requestSubmit()",
	);
	await ready(
		`!document.documentElement.dataset.singleLineSaving && document.readyState==='complete' && !document.querySelector('[role=alert]') && document.querySelector('[name=_revision]').value!==${JSON.stringify(revision)}`,
	);
}
async function compact() {
	assert.equal(
		await evaluate(
			`document.querySelector(${JSON.stringify(surface)}).getAttribute('aria-multiline')`,
		),
		"false",
	);
	assert.equal(
		await evaluate(
			`document.querySelector(${JSON.stringify(`${editor} .admin-rich-toolbar select`)}) === null`,
		),
		true,
	);
	assert.equal(
		await evaluate(
			`(()=>{const line=document.querySelector(${JSON.stringify(`${editor} .admin-rich-box`)}).getBoundingClientRect(), short=document.querySelector('[name=year]').getBoundingClientRect();return line.height<=short.height+2 && document.documentElement.scrollWidth<=innerWidth})()`,
		),
		true,
		"the writing surface and its mark toolbar fit a short input row",
	);
}
try {
	await command("Page.enable");
	await command("Runtime.enable");
	await command("Log.enable");
	await navigate("/login", "[name=login]");
	await evaluate(
		"document.querySelector('[name=login]').value='admin@portable.test';document.querySelector('[name=password]').value='portable-cms-local';document.querySelector('form').requestSubmit()",
	);
	await ready("document.querySelector('.admin-navigation')");
	await navigate(record, editor);
	await ready(`document.querySelector(${JSON.stringify(surface)})`);
	await compact();
	await evaluate(`document.querySelector(${JSON.stringify(surface)}).focus()`);
	await command("Input.insertText", { text: "Nora Sales" });
	for (const modifiers of [0, 8]) {
		await command("Input.dispatchKeyEvent", {
			type: "keyDown",
			key: "Enter",
			code: "Enter",
			windowsVirtualKeyCode: 13,
			modifiers,
		});
		await command("Input.dispatchKeyEvent", {
			type: "keyUp",
			key: "Enter",
			code: "Enter",
			windowsVirtualKeyCode: 13,
			modifiers,
		});
	}
	assert.equal(
		await evaluate(
			"new FormData(document.querySelector('[data-editorial-form]')).get('salesAgent__rows')",
		),
		"1",
		"Enter and Shift+Enter cannot create another block",
	);
	await evaluate(
		`const s=document.querySelector(${JSON.stringify(surface)}),r=document.createRange();r.selectNodeContents(s);getSelection().removeAllRanges();getSelection().addRange(r);document.querySelector(${JSON.stringify(`${editor} [data-rich-tool="link"]`)}).click()`,
	);
	await evaluate(
		`document.querySelector(${JSON.stringify(`${editor} .admin-rich-link input`)}).value='https://agency.example.invalid/';document.querySelector(${JSON.stringify(`${editor} .admin-rich-link button`)}).click()`,
	);
	assert.equal(
		await evaluate(`document.querySelector(${JSON.stringify(`${surface} a`)}).href`),
		"https://agency.example.invalid/",
	);
	await save();
	await navigate(record, editor);
	await ready(`document.querySelector(${JSON.stringify(`${surface} a`)})`);
	assert.equal(
		await evaluate(`document.querySelector(${JSON.stringify(surface)}).textContent`),
		"Nora Sales",
	);
	await evaluate(
		`const s=document.querySelector(${JSON.stringify(surface)}),r=document.createRange();r.selectNodeContents(s);getSelection().removeAllRanges();getSelection().addRange(r);const data=new DataTransfer();data.setData('text/plain',${JSON.stringify("One\nTwo")});s.dispatchEvent(new ClipboardEvent('paste',{clipboardData:data,bubbles:true,cancelable:true}))`,
	);
	assert.equal(
		await evaluate(`document.querySelector(${JSON.stringify(surface)}).textContent`),
		"One Two",
		"multiline paste keeps both names on one line",
	);
	assert.equal(
		await evaluate(
			"new FormData(document.querySelector('[data-editorial-form]')).get('salesAgent__rows')",
		),
		"1",
	);
	await command("Emulation.setDeviceMetricsOverride", {
		width: 360,
		height: 800,
		deviceScaleFactor: 1,
		mobile: false,
	});
	await compact();
	await ready(
		"document.querySelector('[data-save-status]').textContent.startsWith('Saved at ')",
	);
	await command("Emulation.setScriptExecutionDisabled", { value: true });
	await navigate(record, `${editor} textarea`);
	assert.equal(
		await evaluate(
			`document.querySelector(${JSON.stringify(`${editor} textarea`)}).rows`,
		),
		1,
	);
	assert.equal(
		await evaluate(
			`document.querySelector(${JSON.stringify(`${editor} [data-rich-action="add"]`)}) === null`,
		),
		true,
	);
	await evaluate(
		`document.querySelector(${JSON.stringify(`${editor} textarea`)}).value='Native agent';document.querySelector(${JSON.stringify(`${editor} [data-rich-link]`)}).value='https://native.example.invalid/'`,
	);
	await save();
	await navigate(record, `${editor} textarea`);
	assert.equal(
		await evaluate(
			`document.querySelector(${JSON.stringify(`${editor} textarea`)}).value`,
		),
		"Native agent",
	);
	assert.equal(
		await evaluate(
			`document.querySelector(${JSON.stringify(`${editor} [data-rich-link]`)}).value`,
		),
		"https://native.example.invalid/",
	);
	const revision = await evaluate("document.querySelector('[name=_revision]').value");
	const rejection = await evaluate(
		`(async()=>{const data=new FormData(document.querySelector('[data-editorial-form]'));for(const [key,value] of Object.entries({'salesAgent__rows':'2','salesAgent__1__type':'paragraph','salesAgent__1__runs':'1','salesAgent__1__0__text':'Forged extra block'}))data.set(key,value);const response=await fetch(${JSON.stringify(record)},{method:'POST',body:data});return {status:response.status,body:await response.text()}})()`,
	);
	assert.equal(rejection.status, 422);
	assert.ok(rejection.body.includes("single text block"));
	await navigate(record, `${editor} textarea`);
	assert.equal(
		await evaluate("document.querySelector('[name=_revision]').value"),
		revision,
		"forged multi-block saves leave the stored revision unchanged",
	);
	assert.equal(
		await evaluate(
			`document.querySelector(${JSON.stringify(`${editor} textarea`)}).value`,
		),
		"Native agent",
	);
	assert.deepEqual(browserErrors, []);
	console.log(
		"PASS single-line rich text: short input height, Link, Enter/Shift+Enter refusal, multiline paste, saved marks, 360px, no-script save/reload and forged extra-block refusal.",
	);
} finally {
	await command("Emulation.setScriptExecutionDisabled", { value: false });
	await browser.close();
}
