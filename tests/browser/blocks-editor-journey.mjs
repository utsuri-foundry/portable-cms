import assert from "node:assert/strict";
import { writeFile } from "node:fs/promises";
import { connectLocalBrowser } from "./local-browser.mjs";

const origin = process.env.CMS_BROWSER_ORIGIN;
const browser = await connectLocalBrowser(origin, process.env.CMS_BROWSER_DEBUG_URL);
const { command, evaluate, navigate, browserErrors } = browser;
const ready = (expression) => browser.ready(expression, 30000);
const path = "/records/landing/c9000000000000000000000000000001";
const list = '[data-blocks="blocks_ca"] [data-group-list]';
const cards = `${list} > [data-group-row]`;
const ids = () =>
	evaluate(
		`[...document.querySelectorAll('${cards} input[name="blocks_ca_items[]"]')].map(i=>i.value)`,
	);
const settled =
	"!document.querySelector('[data-live-preview]').hasAttribute('aria-busy') && !!document.querySelector('[data-live-frame][data-live-active]')?.getAttribute('src')?.startsWith('/live-previews/')";
let original;
async function action(value, expected, keyboard = false) {
	const before = await evaluate("document.querySelector('[name=_revision]').value");
	await evaluate(
		`document.querySelector('[data-editorial-form]').dataset.blocksJourney='old';const button=[...document.querySelectorAll('[name=_editor_action]')].find(b=>b.value===${JSON.stringify(value)});for(let parent=button.parentElement;parent;parent=parent.parentElement)if(parent.matches('details'))parent.open=true;${keyboard ? "button.focus()" : "document.querySelector('[data-editorial-form]').requestSubmit(button)"}`,
	);
	if (keyboard) {
		assert.equal(
			await evaluate("document.activeElement?.value"),
			value,
			"keyboard action is focused in its open card",
		);
		for (const type of ["keyDown", "keyUp"])
			await command("Input.dispatchKeyEvent", {
				type,
				key: "Enter",
				code: "Enter",
				windowsVirtualKeyCode: 13,
				...(type === "keyDown" ? { text: "\r", unmodifiedText: "\r" } : {}),
			});
	}
	await ready(
		`document.readyState==='complete' && document.querySelector('[data-editorial-form]')?.dataset.blocksJourney!=='old' && (${expected})`,
	);
	assert.equal(
		await evaluate("document.querySelector('[name=_revision]').value"),
		before,
		"native action never saves",
	);
}
async function inPage(expression) {
	const source = await evaluate(
		"document.querySelector('[data-live-frame][data-live-active]').getAttribute('src')",
	);
	let target;
	for (let attempt = 0; attempt < 50 && !target; attempt++) {
		target = (await command("Target.getTargets")).targetInfos.find(
			(info) => info.type === "iframe" && info.url === origin + source,
		);
		if (!target) await new Promise((resolve) => setTimeout(resolve, 100));
	}
	assert.ok(target, "live page is isolated in its own frame");
	const { sessionId } = await command("Target.attachToTarget", {
		targetId: target.targetId,
		flatten: true,
	});
	try {
		return (
			await command(
				"Runtime.evaluate",
				{ expression, returnByValue: true, awaitPromise: true },
				sessionId,
			)
		).result.value;
	} finally {
		await command("Target.detachFromTarget", { sessionId });
	}
}
try {
	await command("Page.enable");
	await command("Emulation.setDeviceMetricsOverride", {
		width: 1440,
		height: 1000,
		deviceScaleFactor: 1,
		mobile: false,
	});
	await command("Runtime.enable");
	await command("Log.enable");
	// Cover both a fresh sign-in and the redirect of an existing shared test session.
	for (const state of ["initial", "authenticated"]) {
		await command("Page.navigate", { url: origin + "/login" });
		await ready(
			"document.readyState==='complete' && (!!document.querySelector('[name=login]') || !!document.querySelector('.admin-navigation'))",
		);
		if (await evaluate("!!document.querySelector('[name=login]')")) {
			assert.equal(state, "initial", "the authenticated session survives navigation");
			await evaluate(
				"document.querySelector('[name=login]').value='admin@portable.test';document.querySelector('[name=password]').value='portable-cms-local';document.querySelector('form').requestSubmit()",
			);
		}
		await ready(
			"location.pathname==='/' && !!document.querySelector('.admin-navigation')",
		);
	}
	await command("Emulation.setScriptExecutionDisabled", { value: true });
	await navigate(path, "[data-editorial-form]");
	original = await evaluate(
		"[...new FormData(document.querySelector('[data-editorial-form]')).entries()]",
	);
	const starting = await ids();
	for (const id of starting)
		await action(
			`remove:blocks:ca:${id}`,
			`!document.querySelector('${cards} input[value="${id}"]')`,
		);
	const added = [];
	for (const [index, type] of ["hero", "quote", "call-to-action"].entries()) {
		await evaluate(
			`document.querySelector('[name="blocks_ca__add_end"]').value=${JSON.stringify(type)};document.querySelector('[name=title__en]').value='Unsaved block journey title'`,
		);
		await action(
			"add:blocks:ca",
			`document.querySelectorAll('${cards}').length===${index + 1}`,
		);
		const id = (await ids()).at(-1);
		added.push(id);
		assert.match(id, /^[a-f0-9]{32}$/);
		if (type !== "call-to-action") {
			await evaluate(
				`(()=>{const row=[...document.querySelectorAll('${cards}')].at(-1);for(const [i,input] of [...row.querySelectorAll('input[type=text]')].entries())input.value=${JSON.stringify(type)}+' journey '+i;})()`,
			);
		} else {
			await evaluate(
				`(()=>{const row=[...document.querySelectorAll('${cards}')].at(-1);row.querySelector('input[type=text]').value='Call to action journey';})()`,
			);
			const selection = await evaluate(
				`(()=>{const row=[...document.querySelectorAll('${cards}')].at(-1);return [...row.querySelectorAll('button[name=_editor_action]')].find(b=>b.value.startsWith('select:') && b.value.endsWith('10000000000000000000000000000001')).value})()`,
			);
			await action(
				selection,
				`!!document.querySelector('${cards} input[value="10000000000000000000000000000001"]')`,
			);
		}
	}
	await action(
		`up:blocks:ca:${added[2]}`,
		`document.querySelectorAll('${cards} input[name="blocks_ca_items[]"]')[1].value==='${added[2]}'`,
		true,
	);
	assert.deepEqual(
		await ids(),
		[added[0], added[2], added[1]],
		"keyboard moves typed cards with unsaved text",
	);
	assert.equal(
		await evaluate("document.querySelector('[name=title__en]').value"),
		"Unsaved block journey title",
	);
	await command("Emulation.setScriptExecutionDisabled", { value: false });
	// Reload the unsaved native form by a harmless action so its islands mount.
	await action(
		`down:blocks:ca:${added[2]}`,
		`document.querySelectorAll('${cards} input[name="blocks_ca_items[]"]')[2].value==='${added[2]}'`,
	);
	await ready(`!document.querySelector('${cards} [data-group-grip]').hidden`);
	await evaluate("document.querySelector('[data-live-toggle]').click()");
	await ready(settled);
	assert.deepEqual(
		await inPage(
			"[...document.querySelectorAll('main > .studio-copy[data-cms-field]')].map(e=>e.getAttribute('data-cms-field').split('.')[1])",
		),
		added,
		"live page follows native order",
	);
	await evaluate(
		`for(const d of document.querySelectorAll('${cards} details.admin-group-card__details'))d.open=false;document.querySelector('${cards}:last-child [data-group-grip]').scrollIntoView({block:'center'})`,
	);
	const beforeDrag = await evaluate(
		"document.querySelector('[data-live-frame][data-live-active]').getAttribute('src')",
	);
	const points = await evaluate(
		`(()=>{const rows=[...document.querySelectorAll('${cards}')];const a=rows[2].querySelector('[data-group-grip]').getBoundingClientRect(),b=rows[0].getBoundingClientRect();return {x:a.left+a.width/2,y:a.top+a.height/2,to:b.top+b.height/2-4}})()`,
	);
	const hit = await evaluate(
		`document.elementFromPoint(${points.x},${points.y})?.closest('[data-group-grip]') !== null`,
	);
	if (!hit) {
		console.log(
			"drag position",
			points,
			await evaluate(
				`({hit:document.elementFromPoint(${points.x},${points.y})?.tagName,width:innerWidth,height:innerHeight,boxes:[...document.querySelectorAll('${cards}')].map(e=>({top:e.getBoundingClientRect().top,height:e.getBoundingClientRect().height}))})`,
			),
		);
		const screenshot = await command("Page.captureScreenshot", { format: "png" });
		await writeFile(
			process.env.CMS_BROWSER_SCREENSHOT,
			Buffer.from(screenshot.data, "base64"),
		);
	}
	assert.equal(hit, true, "drag grip is exposed at the pointer position");
	await command("Input.dispatchMouseEvent", {
		type: "mouseMoved",
		x: points.x,
		y: points.y,
	});
	await command("Input.dispatchMouseEvent", {
		type: "mousePressed",
		x: points.x,
		y: points.y,
		button: "left",
		buttons: 1,
		clickCount: 1,
	});
	for (const step of [0.5, 1])
		await command("Input.dispatchMouseEvent", {
			type: "mouseMoved",
			x: points.x,
			y: points.y + (points.to - points.y) * step,
			button: "left",
			buttons: 1,
		});
	await command("Input.dispatchMouseEvent", {
		type: "mouseReleased",
		x: points.x,
		y: points.to,
		button: "left",
		buttons: 0,
		clickCount: 1,
	});
	await ready(
		`document.querySelector('${cards} input[name="blocks_ca_items[]"]').value==='${added[2]}' && ${settled}`,
	);
	const reordered = [added[2], added[0], added[1]];
	assert.deepEqual(
		await ids(),
		reordered,
		"pointer drag changes the native item order",
	);
	await ready(
		"document.querySelector('[data-save-status]').textContent.match(/^(Saving|Saved at )/)",
	);
	await ready(
		`${settled} && document.querySelector('[data-live-frame][data-live-active]').getAttribute('src') !== ${JSON.stringify(beforeDrag)}`,
	);
	assert.deepEqual(
		await inPage(
			"[...document.querySelectorAll('main > .studio-copy[data-cms-field]')].map(e=>e.getAttribute('data-cms-field').split('.')[1])",
		),
		reordered,
		"live page follows the dragged order",
	);
	const positionLabel = await evaluate(
		`document.querySelector('${cards} [data-group-action="remove"]').getAttribute('aria-label')`,
	);
	assert.match(positionLabel, /block 1/);
	assert.match(positionLabel, /Call to action/i);
	// The profile's explicit mark selects and opens the matching card.
	await inPage(
		`document.querySelector('[data-cms-field="blocks.${added[0]}.heading"]').dispatchEvent(new MouseEvent('click',{bubbles:true}))`,
	);
	await ready(
		`document.querySelector('${cards} input[value="${added[0]}"]').closest('fieldset').querySelector('details').open`,
	);
	await action(
		`remove:blocks:ca:${added[1]}`,
		`document.querySelectorAll('${cards}').length===2`,
	);
	await evaluate(
		"document.querySelector('[data-editorial-form]').dataset.blocksJourney='saving';document.querySelector('[data-editorial-form]').requestSubmit()",
	);
	await ready(
		"document.readyState==='complete' && document.querySelector('[data-editorial-form]')?.dataset.blocksJourney!=='saving'",
	);
	await navigate(path, "[data-editorial-form]");
	assert.deepEqual(
		await ids(),
		[added[2], added[0]],
		"save persists the dragged order after remove",
	);
	await evaluate(
		"if(document.querySelector('[data-live-preview]').hidden)document.querySelector('[data-live-toggle]').click()",
	);
	await ready(settled);
	assert.deepEqual(
		await inPage(
			"[...document.querySelectorAll('main > .studio-copy[data-cms-field]')].map(e=>e.getAttribute('data-cms-field').split('.')[1])",
		),
		[added[2], added[0]],
		"saved live page keeps exact order",
	);
	assert.deepEqual(browserErrors, []);
	console.log(
		"PASS Chromium typed blocks: three native types without scripts, keyboard and pointer ordering, unsaved fields, live marks/card reveal, remove and persisted order.",
	);
} finally {
	await command("Emulation.setScriptExecutionDisabled", { value: false });
	if (original) {
		await navigate(path, "[data-editorial-form]");
		const result = await evaluate(
			`(async()=>{const initial=${JSON.stringify(original)};const form=document.querySelector('[data-editorial-form]');const body=new URLSearchParams(initial);body.set('_revision',form.querySelector('[name=_revision]').value);body.set('_edit_sequence',form.querySelector('[name=_edit_sequence]').value);body.set('_csrf',form.querySelector('[name=_csrf]').value);const response=await fetch(form.action,{method:'POST',body});return response.status})()`,
		);
		assert.equal(result, 200, "restore fictional fixture through ordinary native save");
		await navigate(path, "[data-editorial-form]");
		await evaluate(
			"document.querySelector('form[action=\"/logout\"]').requestSubmit()",
		);
		await ready("location.pathname==='/login'");
	}
	await command("Emulation.clearDeviceMetricsOverride");
	await browser.close();
}
