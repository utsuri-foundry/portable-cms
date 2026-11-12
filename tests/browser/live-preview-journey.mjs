import assert from "node:assert/strict";
import { richPreviewJourney } from "./live-preview-rich-journey.mjs";
import { connectLocalBrowser } from "./local-browser.mjs";

const origin = process.env.CMS_BROWSER_ORIGIN;
const debug = process.env.CMS_BROWSER_DEBUG_URL;
assert.ok(origin && debug);
const browser = await connectLocalBrowser(origin, debug);
const { command, evaluate, navigate, browserErrors } = browser;
// The single-process PHP fixture serializes authenticated frame assets after each
// navigation. Keep a finite render budget without weakening any state assertions.
const ready = (expression) => browser.ready(expression, 30000);
const path = "/records/project/10000000000000000000000000000001";
const settled =
	"!document.querySelector('[data-live-preview]').hasAttribute('aria-busy') && !!document.querySelector('[data-live-frame][data-live-active]')?.getAttribute('src')?.startsWith('/live-previews/')";
const activeSource = () =>
	evaluate(
		"document.querySelector('[data-live-frame][data-live-active]').getAttribute('src')",
	);
/** Evaluates inside the shown page, which runs in its own process and opaque origin. */
async function inPage(expression) {
	const source = await activeSource();
	let target;
	for (let attempt = 0; attempt < 50 && !target; attempt++) {
		const { targetInfos } = await command("Target.getTargets");
		target = targetInfos.find(
			(info) => info.type === "iframe" && info.url === origin + source,
		);
		if (!target) await new Promise((resolve) => setTimeout(resolve, 100));
	}
	assert.ok(target, `the live page runs as its own frame target: ${source}`);
	const { sessionId } = await command("Target.attachToTarget", {
		targetId: target.targetId,
		flatten: true,
	});
	try {
		const result = await command(
			"Runtime.evaluate",
			{ expression, returnByValue: true, awaitPromise: true },
			sessionId,
		);
		return result.result.value;
	} finally {
		await command("Target.detachFromTarget", { sessionId });
	}
}
/** Polls the shown page until the expression is truthy and returns its value. */
async function inPageReady(expression) {
	for (let attempt = 0; attempt < 50; attempt++) {
		const value = await inPage(expression);
		if (value) return value;
		await new Promise((resolve) => setTimeout(resolve, 100));
	}
	const state = await inPage(
		"({active:document.activeElement?.tagName,field:document.activeElement?.getAttribute('data-cms-field'),editing:document.querySelector('[contenteditable]')?.getAttribute('data-cms-field')})",
	);
	assert.fail(
		`the page never satisfied ${expression}; workspace: ${await status()}; page: ${JSON.stringify(state)}`,
	);
}
/** The field outline in the page, and whether it surrounds the given element. */
const outline = (selector) =>
	`(()=>{const m=document.querySelector('cms-live-mark');const e=document.querySelector(${JSON.stringify(selector)});if(!m||getComputedStyle(m).display==='none'||!e)return null;const a=m.getBoundingClientRect(),b=e.getBoundingClientRect();return {label:m.textContent,covers:Math.abs(a.top-b.top)<1&&Math.abs(a.left-b.left)<1&&Math.abs(a.width-b.width)<1}})()`;
const hidden =
	"(()=>{const m=document.querySelector('cms-live-mark');return !m||getComputedStyle(m).display==='none'})()";
const isolation = `(()=>{const t=(f)=>{try{return String(f())}catch(e){return e.name}};return {origin:self.origin,cookie:t(()=>document.cookie),storage:t(()=>localStorage.getItem('admin-secret')),parent:t(()=>window.parent.document.title),bridge:!!document.querySelector('script[src$="_cms/live-bridge.js"]'),heading:document.querySelector('h1')?.textContent.trim()}})()`;
/** Clicks the middle of an element in the shown page with the mouse, as an editor would. */
async function clickInPage(selector) {
	const point = await inPage(
		`(()=>{const e=document.querySelector(${JSON.stringify(selector)});e.scrollIntoView({block:'center'});const r=e.getBoundingClientRect();return {x:r.left+r.width/2,y:r.top+r.height/2}})()`,
	);
	const frame = await evaluate(
		"(()=>{const f=document.querySelector('[data-live-frame][data-live-active]');const r=f.getBoundingClientRect();return {x:r.left,y:r.top,scale:r.width/f.offsetWidth}})()",
	);
	const x = frame.x + point.x * frame.scale;
	const y = frame.y + point.y * frame.scale;
	await command("Input.dispatchMouseEvent", { type: "mouseMoved", x, y });
	for (const type of ["mousePressed", "mouseReleased"])
		await command("Input.dispatchMouseEvent", {
			type,
			x,
			y,
			button: "left",
			clickCount: 1,
		});
}
const keyCodes = { Enter: 13, Escape: 27, End: 35 };
async function press(key) {
	for (const type of ["keyDown", "keyUp"])
		await command("Input.dispatchKeyEvent", {
			type,
			key,
			code: key,
			windowsVirtualKeyCode: keyCodes[key],
		});
}
const status = () =>
	evaluate("document.querySelector('[data-live-status]').textContent");
const editable = (selector) =>
	`document.querySelector(${JSON.stringify(selector)})?.isContentEditable`;
/** Types without focusing, which would also scroll the page to the field. */
async function type(value) {
	const before = await activeSource();
	await evaluate(
		`(()=>{const i=document.querySelector('#field-title-ca');i.value=${JSON.stringify(value)};i.dispatchEvent(new Event('input',{bubbles:true}));})()`,
	);
	await ready(
		`${settled} && document.querySelector('[data-live-frame][data-live-active]').getAttribute('src') !== ${JSON.stringify(before)}`,
	);
}

try {
	await command("Page.enable");
	await command("Runtime.enable");
	await command("Log.enable");
	await command("Network.enable");
	// Focus events fire as in a focused window, so fields can be focused from the test.
	await command("Emulation.setFocusEmulationEnabled", { enabled: true });
	await command("Network.clearBrowserCookies");
	await command("Emulation.setDeviceMetricsOverride", {
		width: 1440,
		height: 900,
		deviceScaleFactor: 1,
		mobile: false,
	});
	await navigate("/login", "[name=login]");
	await evaluate(
		"document.querySelector('[name=login]').value='admin@portable.test';document.querySelector('[name=password]').value='portable-cms-local';document.querySelector('form').requestSubmit()",
	);
	await ready("location.pathname==='/' && document.querySelector('.admin-navigation')");
	await evaluate("localStorage.setItem('admin-secret','administration only')");
	await navigate(path, "[data-editorial-form]");
	assert.deepEqual(
		await evaluate(
			"[document.querySelector('[data-live-toggle]').hidden, document.querySelector('[data-live-fallback]').hidden, document.querySelector('[data-live-preview]').hidden]",
		),
		[false, true, true],
		"with scripts the workspace toggle replaces the new-tab button",
	);

	await evaluate("document.querySelector('[data-live-toggle]').click()");
	await ready(settled);
	assert.deepEqual(
		await evaluate(
			"(()=>{const shell=document.querySelector('[data-admin-shell]');const pane=document.querySelector('[data-live-preview]').getBoundingClientRect();const form=document.querySelector('[data-editorial-form]').getBoundingClientRect();return {live:shell.classList.contains('admin-shell--live'),sidebar:getComputedStyle(document.querySelector('.admin-sidebar')).display,pressed:document.querySelector('[data-live-toggle]').getAttribute('aria-pressed'),beside:pane.left>=form.right-1 && pane.top===0 && pane.bottom===innerHeight,separator:!document.querySelector('[data-live-separator]').hidden,switcher:!!document.querySelector('.admin-save-bar [data-record-locale-switcher]'),status:document.querySelector('[data-live-status]').getAttribute('role')}})()",
		),
		{
			live: true,
			sidebar: "none",
			pressed: "true",
			beside: true,
			separator: true,
			switcher: true,
			status: "status",
		},
		"the workspace folds the menu, puts the page beside the form and the language switch in the save bar",
	);
	assert.equal(
		await evaluate(
			"document.querySelector('[data-live-frame][data-live-active]').contentDocument",
		),
		null,
		"the administration cannot reach into the page",
	);
	assert.deepEqual(
		await inPage(isolation),
		{
			origin: "null",
			cookie: "SecurityError",
			storage: "SecurityError",
			parent: "SecurityError",
			bridge: true,
			heading: "Arxiu de la boira",
		},
		"the page runs in an opaque origin that cannot read administration cookies, storage or document",
	);
	const frameStyle = await evaluate(
		"(()=>{const f=document.querySelector('[data-live-frame][data-live-active]');const s=document.querySelector('[data-live-stage]');return {width:f.style.width,scale:Number(/scale\\(([0-9.]+)\\)/.exec(f.style.transform)[1]),stage:s.clientWidth}})()",
	);
	assert.equal(
		frameStyle.width,
		"1440px",
		"desktop pages are drawn at their real width",
	);
	assert.ok(
		Math.abs(frameStyle.scale - frameStyle.stage / 1440) < 0.01,
		"and scaled to the stage",
	);

	await inPage("window.scrollTo(0, 700)");
	await new Promise((resolve) => setTimeout(resolve, 300));
	await type("Arxiu de la boira, sense desar");
	assert.equal(
		(await inPage(isolation)).heading,
		"Arxiu de la boira, sense desar",
		"the page shows the unsaved title after a pause in typing",
	);
	const kept = await inPage("window.scrollY");
	assert.ok(
		Math.abs(kept - 700) <= 2,
		`the updated page keeps the scroll position (${kept})`,
	);
	assert.match(
		await evaluate("document.querySelector('[data-save-status]').textContent"),
		/^Saved at /,
		"live preview waits for the edit to be kept",
	);

	await inPage(
		"document.querySelector('h1').dispatchEvent(new PointerEvent('pointerover',{bubbles:true}))",
	);
	assert.deepEqual(
		await inPage(outline("h1")),
		{ label: "Title", covers: true },
		"hovering a marked element outlines it and names its field",
	);
	await inPage("document.querySelector('h1').textContent='Shown differently'");
	await inPage("document.querySelector('h1').click()");
	await ready("document.activeElement?.id === 'field-title-ca'");
	assert.equal(
		await inPage(editable("h1")),
		false,
		"an element that does not show the field's value exactly keeps click-to-focus",
	);
	await inPage("window.scrollTo(0, document.documentElement.scrollHeight)");
	const bottom = await inPageReady("window.scrollY");
	await evaluate("document.querySelector('#field-year').focus()");
	assert.deepEqual(
		await inPageReady(outline('[data-cms-field="year"]')),
		{ label: "Year", covers: true },
		"focusing a field outlines its element in the page, names it and scrolls to it",
	);
	await inPageReady(`window.scrollY < ${bottom}`);
	await evaluate("document.querySelector('#field-sortOrder').focus()");
	await new Promise((resolve) => setTimeout(resolve, 300));
	assert.equal(
		await inPage(hidden),
		true,
		"a field the page does not show highlights nothing",
	);
	await clickInPage('[data-cms-field="year"]');
	await ready("document.activeElement?.id === 'field-year'");
	assert.equal(
		await inPage(editable('[data-cms-field="year"]')),
		false,
		"other field kinds still focus the field in the form",
	);

	await evaluate(
		"(()=>{const s=document.querySelector('[data-live-page]');s.value='home';s.dispatchEvent(new Event('change'));})()",
	);
	await ready(
		`${settled} && document.querySelector('[data-live-frame][data-live-active]').getAttribute('src').includes('/ca/?render=')`,
	);
	assert.match(
		await inPage("document.querySelector('[data-filter-status]')?.value ?? ''"),
		/^\d+ /,
		"the site's own script runs in the page",
	);
	const home = await activeSource();
	await evaluate("document.querySelector('#field-title-ca').focus()");
	assert.deepEqual(
		await inPageReady(
			outline("[data-filter-results] li:nth-child(2) .studio-index__title"),
		),
		{ label: "Title", covers: true },
		"without a mark, the home page card is recognised by the title's text",
	);
	await evaluate("document.querySelector('#field-year').focus()");
	const card = "[data-filter-results] li:nth-child(2) .studio-index__title";
	await clickInPage(card);
	await inPageReady(editable(card));
	await press("Escape");
	await inPageReady(`!${editable(card)}`);
	assert.equal(
		await activeSource(),
		home,
		"clicking a recognised title inside a link edits it in place instead of following the link",
	);
	await evaluate("document.querySelector('[data-live-screen=\"390\"]').click()");
	assert.equal(
		await evaluate(
			"document.querySelector('[data-live-frame][data-live-active]').style.width",
		),
		"390px",
		"the phone screen draws the page at phone width",
	);

	const share = await evaluate(
		"document.querySelector('[data-live-separator]').getAttribute('aria-valuenow')",
	);
	await evaluate(
		"(()=>{const s=document.querySelector('[data-live-separator]');s.focus();s.dispatchEvent(new KeyboardEvent('keydown',{key:'ArrowRight',bubbles:true}));})()",
	);
	assert.equal(
		Number(
			await evaluate(
				"document.querySelector('[data-live-separator]').getAttribute('aria-valuenow')",
			),
		),
		Number(share) + 2,
		"the separator moves with the keyboard",
	);
	assert.equal(
		await evaluate("localStorage.getItem('portable-cms.live-preview.form-width')"),
		String(Number(share) + 2),
		"the width is remembered for this person on this browser",
	);

	const full = await evaluate(
		"document.querySelector('[data-live-open]').getAttribute('href')",
	);
	const tab = await connectLocalBrowser(origin, debug);
	try {
		await tab.command("Runtime.enable");
		await tab.command("Page.navigate", { url: origin + full });
		await tab.ready(
			"document.readyState === 'complete' && !!document.querySelector('h1')",
		);
		assert.deepEqual(
			await tab.evaluate(
				"(()=>{const t=(f)=>{try{return String(f())}catch(e){return e.name}};return {origin:self.origin,cookie:t(()=>document.cookie)}})()",
			),
			{ origin: "null", cookie: "SecurityError" },
			"opened full size, the page keeps its sandbox",
		);
	} finally {
		await tab.close();
	}

	await command("Emulation.setDeviceMetricsOverride", {
		width: 1000,
		height: 760,
		deviceScaleFactor: 1,
		mobile: false,
	});
	await ready(
		"document.querySelector('[data-admin-shell]').dataset.liveView === 'preview'",
	);
	assert.deepEqual(
		await evaluate(
			"(()=>{const r=document.querySelector('[data-live-preview]').getBoundingClientRect();return [r.left,r.top,r.width===document.documentElement.clientWidth,getComputedStyle(document.querySelector('[data-live-separator]')).display]})()",
		),
		[0, 0, true, "none"],
		"narrow windows show the page over the form",
	);
	await evaluate("document.querySelector('[data-live-mode=\"edit\"]').click()");
	assert.deepEqual(
		await evaluate(
			"(()=>{const r=document.querySelector('[data-live-preview]').getBoundingClientRect();return {bar:r.top>600,stage:getComputedStyle(document.querySelector('[data-live-stage]')).display,edit:document.querySelector('[data-live-mode=\"edit\"]').getAttribute('aria-pressed')}})()",
		),
		{ bar: true, stage: "none", edit: "true" },
		"Edit keeps only the switch at the bottom of the form",
	);
	await evaluate("document.querySelector('[data-live-toggle]').click()");
	assert.deepEqual(
		await evaluate(
			"[document.querySelector('[data-admin-shell]').classList.contains('admin-shell--live'), document.querySelector('[data-live-preview]').hidden, !!document.querySelector('.admin-page-header [data-record-locale-switcher]')]",
		),
		[false, true, true],
		"closing restores the record page",
	);

	// Automatic keeping retained the earlier title; reset it before in-place editing.
	await evaluate(
		"(()=>{const f=document.querySelector('#field-title-ca');f.value='Arxiu de la boira';f.dispatchEvent(new Event('input',{bubbles:true}));})()",
	);
	await ready(
		"document.querySelector('[data-save-status]')?.textContent.startsWith('Saved at ')",
	);
	// The title is saved and then restored, as later journeys expect it.
	await command("Emulation.setDeviceMetricsOverride", {
		width: 1440,
		height: 900,
		deviceScaleFactor: 1,
		mobile: false,
	});
	await navigate(path, "[data-editorial-form]");
	await evaluate("document.querySelector('[data-live-toggle]').click()");
	await ready(settled);
	const drawn = await activeSource();
	// A title only as wide as its text, so the outline must follow it as it grows.
	await inPage("document.querySelector('h1').style.display='inline-block'");
	await clickInPage("h1");
	await inPageReady(editable("h1"));
	assert.deepEqual(
		await evaluate(
			"(()=>{const f=document.querySelector('#field-title');return {frame:document.activeElement?.matches('[data-live-frame]'),shown:f.getBoundingClientRect().height>0}})()",
		),
		{ frame: true, shown: true },
		"clicking the title edits it in the page, with the field shown in the form",
	);
	assert.match(await status(), /Title/, "editing is announced with the field's name");
	await press("End");
	await command("Input.insertText", { text: " nou" });
	await ready(
		"document.querySelector('#field-title-ca').value === 'Arxiu de la boira nou'",
	);
	assert.deepEqual(
		await inPageReady(outline("h1")),
		{ label: "Title", covers: true },
		"the outline follows the text as it grows",
	);
	assert.match(
		await evaluate("document.querySelector('[data-save-status]').textContent"),
		/^(Saving|Saved at )/,
		"in-place edits enter automatic keeping",
	);
	await new Promise((resolve) => setTimeout(resolve, 1500));
	assert.equal(
		await activeSource(),
		drawn,
		"no new page replaces the one being edited",
	);
	await press("Escape");
	await inPageReady(`!${editable("h1")}`);
	assert.deepEqual(
		[
			await inPage("document.querySelector('h1').textContent"),
			await evaluate("document.querySelector('#field-title-ca').value"),
		],
		["Arxiu de la boira", "Arxiu de la boira"],
		"Escape restores the text editing started with",
	);

	await clickInPage("h1");
	await inPageReady(editable("h1"));
	await press("End");
	await command("Input.insertText", { text: " nou" });
	await press("Enter");
	await inPageReady(`!${editable("h1")}`);
	await ready(
		`${settled} && document.querySelector('[data-live-frame][data-live-active]').getAttribute('src') !== ${JSON.stringify(drawn)}`,
	);
	assert.equal(
		(await inPage(isolation)).heading,
		"Arxiu de la boira nou",
		"Enter finishes editing without a line break, and the page is drawn again",
	);
	const revision = await evaluate("document.querySelector('[name=_revision]').value");
	await evaluate("document.querySelector('[data-editorial-form]').requestSubmit()");
	await ready(
		`document.querySelector('[data-save-status]')?.textContent.match(/Saved at |No unsaved changes/) && document.querySelector('[name=_revision]').value !== ${JSON.stringify(revision)}`,
	);
	assert.equal(
		await evaluate("document.querySelector('#field-title-ca').value"),
		"Arxiu de la boira nou",
		"saving keeps the text edited in the page",
	);
	const saved = await evaluate("document.querySelector('[name=_revision]').value");
	await evaluate(
		"(()=>{document.querySelector('#field-title-ca').value='Arxiu de la boira';document.querySelector('[data-editorial-form]').requestSubmit();})()",
	);
	await ready(
		`document.querySelector('[data-save-status]')?.textContent.match(/Saved at |No unsaved changes/) && document.querySelector('[name=_revision]').value !== ${JSON.stringify(saved)}`,
	);
	// Native navigation exposes the saved HTML before the workspace script has mounted.
	await ready(settled);
	await evaluate("document.querySelector('[data-live-toggle]').click()");

	// Repeatable children are linked by identity, including native insert/reorder/remove actions.
	await evaluate("document.querySelector('[data-live-toggle]').click()");
	await ready(settled);
	const creditFields = () =>
		evaluate(
			`Array.from(document.querySelectorAll('[data-field-item][data-field-locale="ca"]')).filter(e=>e.dataset.field.endsWith('.name')).map(e=>({field:e.dataset.field,value:e.querySelector('textarea').value}))`,
		);
	const [originalCredit] = await creditFields();
	assert.ok(originalCredit, "the fixture has a credit");
	const markedCredit = `[data-cms-field="${originalCredit.field}"]`;
	await inPage(
		`document.querySelector(${JSON.stringify(markedCredit)}).dispatchEvent(new PointerEvent('pointerover',{bubbles:true}))`,
	);
	assert.deepEqual(
		await inPage(outline(markedCredit)),
		{ label: "Credits · Item 1 · Name", covers: true },
		"hovering a child names its group, position and field",
	);
	await inPage(
		`document.querySelector(${JSON.stringify(markedCredit)}).textContent='Transformed name'`,
	);
	await inPage(`document.querySelector(${JSON.stringify(markedCredit)}).click()`);
	await ready(
		`document.activeElement?.closest('[data-field-item]')?.dataset.field === ${JSON.stringify(originalCredit.field)}`,
	);
	assert.equal(
		await evaluate("document.activeElement.value"),
		originalCredit.value,
		"clicking a transformed child focuses only its native control",
	);
	await inPage(
		`document.querySelector(${JSON.stringify(markedCredit)}).textContent=${JSON.stringify(originalCredit.value)}`,
	);
	await clickInPage(markedCredit);
	await inPageReady(editable(markedCredit));
	await press("End");
	await command("Input.insertText", { text: " live" });
	await ready(
		`document.querySelector('[data-field="${originalCredit.field}"][data-field-locale="ca"] textarea').value === ${JSON.stringify(`${originalCredit.value} live`)}`,
	);
	await press("Escape");
	await ready(
		`document.querySelector('[data-field="${originalCredit.field}"][data-field-locale="ca"] textarea').value === ${JSON.stringify(originalCredit.value)}`,
	);
	await ready(settled);
	await evaluate(
		`document.querySelector('[name="_editor_action"][value="add:credits:ca"]').click()`,
	);
	await ready(
		`document.querySelectorAll('[data-field-item][data-field-locale="ca"]').length === 4 && ${settled}`,
	);
	const [, addedCredit] = await creditFields();
	const addedId = addedCredit.field.split(".")[1];
	const addedControl = `[data-field="${addedCredit.field}"][data-field-locale="ca"] textarea`;
	const beforeCredit = await activeSource();
	await evaluate(
		`(()=>{const c=document.querySelector(${JSON.stringify(addedControl)});c.value='New photographer';c.dispatchEvent(new Event('input',{bubbles:true}));})()`,
	);
	await ready(
		`${settled} && document.querySelector('[data-live-frame][data-live-active]').getAttribute('src') !== ${JSON.stringify(beforeCredit)}`,
	);
	await inPageReady(
		`document.querySelector('[data-cms-field="${addedCredit.field}"]')?.textContent === 'New photographer'`,
	);
	await evaluate(
		`document.querySelector('[value="up:credits:ca:${addedId}"]').click()`,
	);
	await ready(
		`document.querySelector('[data-field-item][data-field-locale="ca"]')?.dataset.field === 'credits.${addedId}.role' && ${settled}`,
	);
	assert.deepEqual(
		(await creditFields()).map((e) => e.field),
		[addedCredit.field, originalCredit.field],
		"reordering retains the items' identities",
	);
	const addedMark = `[data-cms-field="${addedCredit.field}"]`;
	await clickInPage(addedMark);
	await inPageReady(editable(addedMark));
	assert.match(
		await status(),
		/Credits · Item 1 · Name/,
		"the new position is announced after redraw",
	);
	await press("End");
	await command("Input.insertText", { text: " changed" });
	await ready(
		`document.querySelector(${JSON.stringify(addedControl)}).value === 'New photographer changed'`,
	);
	assert.equal(
		(await creditFields())[1].value,
		originalCredit.value,
		"editing the reordered item does not touch its neighbour",
	);
	await press("Enter");
	await ready(settled);
	await evaluate(
		`document.querySelector('[value="remove:credits:ca:${addedId}"]').click()`,
	);
	await ready(
		`document.querySelectorAll('[data-field-item][data-field-locale="ca"]').length === 2 && ${settled}`,
	);
	assert.equal(
		await inPage(`document.querySelector(${JSON.stringify(addedMark)}) === null`),
		true,
		"a removed item loses its mark after redraw",
	);
	assert.deepEqual(
		await creditFields(),
		[originalCredit],
		"the remaining item's identity and value are preserved",
	);
	await evaluate("document.querySelector('[data-live-toggle]').click()");

	await richPreviewJourney({
		command,
		evaluate,
		navigate,
		ready,
		inPage,
		inPageReady,
		clickInPage,
		press,
		settled,
	});
	await command("Emulation.setScriptExecutionDisabled", { value: true });
	await navigate(path, "[data-editorial-form]");
	assert.deepEqual(
		await evaluate(
			"(()=>{const b=document.querySelector('[data-live-fallback]');return [b.hidden,b.getAttribute('formtarget'),b.getAttribute('formaction'),document.querySelector('[data-live-toggle]').hidden]})()",
		),
		[false, "_blank", `${path}/live-preview`, true],
		"without scripts the same render opens in a new tab",
	);
	await command("Emulation.setScriptExecutionDisabled", { value: false });
	assert.deepEqual(browserErrors, [], "the workspace runs without browser errors");
	process.stdout.write(
		"PASS live preview journey: workspace, isolation, updates, scroll, field linking both ways, editing in place, own scripts, screens, separator, full size, narrow and no-script\n",
	);
} catch (error) {
	throw new Error(
		`${error.message}\nWorkspace status: ${await evaluate("document.querySelector('[data-live-status]')?.textContent")}\nBrowser errors: ${JSON.stringify(browserErrors)}`,
		{ cause: error },
	);
} finally {
	await browser.close();
}
