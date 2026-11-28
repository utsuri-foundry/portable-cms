import assert from "node:assert/strict";
import { connectLocalBrowser } from "./local-browser.mjs";

const origin = process.env.CMS_BROWSER_ORIGIN;
const debug = process.env.CMS_BROWSER_DEBUG_URL;
assert.ok(origin && debug);
const browser = await connectLocalBrowser(origin, debug);
const { command, evaluate, ready, navigate } = browser;
const editor = "[data-editorial-form][data-keeping='true']";
const recordPath = "/^\\/records\\/article\\/[a-f0-9]{32}$/.test(location.pathname)";
// A key press as a keyboard makes it; Enter carries its text so it activates the focused control.
async function key(name, code = name, keyCode = 0, text = undefined) {
	for (const type of ["keyDown", "keyUp"])
		await command("Input.dispatchKeyEvent", {
			type,
			key: name,
			code,
			windowsVirtualKeyCode: keyCode,
			...(type === "keyDown" && text ? { text } : {}),
		});
}
const enter = () => key("Enter", "Enter", 13, "\r");
const tab = () => key("Tab", "Tab", 9);
const pressEscape = () => key("Escape", "Escape", 27);
async function typeInto(name, text) {
	await evaluate(
		`(() => { const input = document.querySelector('[name="${name}"]'); input.focus(); input.select(); })()`,
	);
	await command("Input.insertText", { text });
}
const value = (name) => evaluate(`document.querySelector('[name="${name}"]').value`);
// A page that replaces itself is recognised by the mark it no longer carries.
const mark = () => evaluate("document.documentElement.dataset.journeyPrevious = '1'");
const fresh = (expression) =>
	ready(`!document.documentElement.dataset.journeyPrevious && (${expression})`);
try {
	await command("Page.enable");
	await command("Runtime.enable");
	await command("Emulation.setDeviceMetricsOverride", {
		width: 1280,
		height: 900,
		deviceScaleFactor: 1,
		mobile: false,
	});
	// A session left by an earlier journey in the same lane goes straight to the overview.
	await mark();
	await command("Page.navigate", { url: `${origin}/login` });
	await fresh(
		"!!document.querySelector('[name=login]') || !!document.querySelector('.admin-navigation')",
	);
	if (await evaluate("!!document.querySelector('[name=login]')")) {
		await evaluate(
			"document.querySelector('[name=login]').value='admin@portable.test';document.querySelector('[name=password]').value='portable-cms-local';document.querySelector('form').requestSubmit()",
		);
		await ready(
			"location.pathname==='/' && document.querySelector('.admin-navigation')",
		);
	}

	// A new record proposes its address from the title, language by language.
	await navigate("/records/article/new", editor);
	await mark();
	await typeInto("title__ca", "Casa Nova del Port");
	assert.equal(
		await value("slug__ca"),
		"casa-nova-del-port",
		"the title proposes the slug",
	);
	assert.equal(await value("slug__es"), "", "only the language being written");
	await fresh(
		`${recordPath} && document.querySelector("${editor}") && document.querySelector('[name=slug__ca]').value === 'casa-nova-del-port'`,
	);
	const first = await evaluate("location.pathname");

	// Typing keeps an address; leaving the field settles it.
	await typeInto("slug__ca", "Àrea Nord del Port ");
	assert.equal(
		await value("slug__ca"),
		"area-nord-del-port-",
		"a word can follow while typing",
	);
	await tab();
	assert.equal(
		await value("slug__ca"),
		"area-nord-del-port",
		"leaving the field settles it",
	);
	await ready(
		"document.querySelector('[data-save-status]')?.textContent.startsWith('Saved at ')",
	);

	// Another record cannot take that address, and the refusal names the record using it.
	await navigate("/records/article/new", editor);
	await typeInto("title__ca", "Àrea Nord del Port");
	assert.equal(await value("slug__ca"), "area-nord-del-port");
	await ready(
		"document.querySelector('#keep-error-slug')?.textContent.includes('“Casa Nova del Port” already uses this address')",
	);
	assert.equal(
		await evaluate(
			"document.querySelector('[data-save-status]').textContent.startsWith('Not saved')",
		),
		true,
		"the save state says the address was not kept",
	);

	// Duplicate on the record page, with the keyboard.
	await navigate(first, editor);
	await mark();
	await evaluate("document.querySelector('[form=record-duplicate-form]').focus()");
	assert.equal(await evaluate("document.activeElement.textContent"), "Duplicate");
	await enter();
	await fresh(
		`${recordPath} && location.pathname !== '${first}' && document.querySelector("${editor}")`,
	);
	assert.deepEqual(
		await evaluate(
			"[document.querySelector('h1').textContent, document.querySelector('[name=title__ca]').value, document.querySelector('[name=slug__ca]').value, document.querySelector('[data-record-state]').textContent]",
		),
		[
			"Casa Nova del Port (còpia)",
			"Casa Nova del Port (còpia)",
			"area-nord-del-port-2",
			"Draft",
		],
		"the copy opens as a draft with a marked title and a free address",
	);

	// Duplicate from the list row: a native menu that Escape folds back to its control.
	const list = "/records/article";
	await navigate(list, "tbody");
	const row = `[...document.querySelectorAll('tbody tr')].find((row) => row.querySelector('.admin-table__title').textContent === 'Casa Nova del Port')`;
	await evaluate(`${row}.querySelector('summary').focus()`);
	assert.equal(
		await evaluate("document.activeElement.getAttribute('aria-label')"),
		"Actions for “Casa Nova del Port”",
	);
	await enter();
	assert.equal(await evaluate(`${row}.querySelector('details').open`), true);
	await pressEscape();
	assert.deepEqual(
		await evaluate(
			`[${row}.querySelector('details').open, document.activeElement === ${row}.querySelector('summary')]`,
		),
		[false, true],
		"Escape folds the menu back to its control",
	);
	await enter();
	await tab();
	assert.equal(await evaluate("document.activeElement.textContent"), "Duplicate");
	await mark();
	await enter();
	await fresh(`${recordPath} && document.querySelector("${editor}")`);
	assert.equal(
		await value("slug__ca"),
		"area-nord-del-port-3",
		"each copy takes the next address",
	);

	// Without scripts the same forms work natively.
	await command("Emulation.setScriptExecutionDisabled", { value: true });
	await navigate(list, "tbody");
	await mark();
	await evaluate(
		`(() => { const details = ${row}.querySelector('details'); details.open = true; details.querySelector('button').click(); })()`,
	);
	await fresh(`${recordPath} && document.querySelector('[name=slug__ca]')`);
	assert.equal(
		await value("slug__ca"),
		"area-nord-del-port-4",
		"the native form duplicates",
	);
	await command("Emulation.setScriptExecutionDisabled", { value: false });

	// A singleton is never duplicated.
	await mark();
	await command("Page.navigate", { url: `${origin}/records/site-settings` });
	await fresh(
		"/^\\/records\\/site-settings\\/[a-f0-9]{32}$/.test(location.pathname) && document.querySelector('[data-editorial-form]')",
	);
	assert.equal(
		await evaluate(
			"document.querySelector('#record-duplicate-form, [form=record-duplicate-form]')",
		),
		null,
	);

	// At phone width the row menu stays inside the screen.
	await command("Emulation.setDeviceMetricsOverride", {
		width: 360,
		height: 780,
		deviceScaleFactor: 2,
		mobile: true,
	});
	await navigate(list, "tbody");
	assert.deepEqual(
		await evaluate(
			`(() => { const details = ${row}.querySelector('details'); details.open = true; const button = details.querySelector('button').getBoundingClientRect(); return [document.documentElement.scrollWidth <= innerWidth, button.left >= 0 && button.right <= innerWidth]; })()`,
		),
		[true, true],
		"no sideways scrolling and the open menu fits",
	);
	assert.deepEqual(browser.browserErrors ?? [], [], "no browser errors");
	console.log(
		"PASS browser record slugs: title proposal, typing normalization, taken-address refusal, keyboard and native duplicate from the page and the list, no singleton copies, phone width.",
	);
} finally {
	await browser.close();
}
