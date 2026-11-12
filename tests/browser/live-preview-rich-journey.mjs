import assert from "node:assert/strict";

/** Runs inside the existing isolated MariaDB/Chromium live-preview journey. */
export async function richPreviewJourney({
	command,
	evaluate,
	navigate,
	ready,
	inPage,
	inPageReady,
	clickInPage,
	press,
	settled,
}) {
	const path = "/records/article/30000000000000000000000000000001";
	await navigate(path, "[data-editorial-form]");
	await evaluate("document.querySelector('[data-locale-tab=en]').click()");
	const original = await evaluate(
		"[...new FormData(document.querySelector('[data-editorial-form]'))].filter(([,v])=>typeof v==='string')",
	);
	const surface = '[data-rich-name="body__en"] .admin-rich-surface';
	const fingerprint = '[data-rich-name="body__en"] [data-rich-fingerprint]';
	// Exercise mixed emphasis and a link, not just an unformatted paragraph.
	await evaluate(
		`(()=>{const s=document.querySelector(${JSON.stringify(surface)});const p=s.querySelector('p');const a=document.createElement('a');a.href='/en/projects/';a.textContent='the archive';const em=document.createElement('em');em.textContent='Read ';const strong=document.createElement('strong');strong.textContent=' carefully.';p.replaceChildren(em,a,strong);s.dispatchEvent(new Event('input',{bubbles:true}));document.querySelector('[data-live-toggle]').click()})()`,
	);
	await ready(settled);
	const block = '[data-cms-field="body:1"]';
	assert.equal(
		await inPage(`document.querySelector(${JSON.stringify(block)}).textContent`),
		"Read the archive carefully.",
	);
	await clickInPage(block);
	await inPageReady(
		`document.querySelector(${JSON.stringify(block)}).isContentEditable`,
	);
	await press("End");
	await command("Input.insertText", { text: " Today." });
	await ready(
		`JSON.parse(document.querySelector(${JSON.stringify(fingerprint)}).value)[1].children.map(r=>r.text).join('')==='Read the archive carefully. Today.'`,
	);
	const edited = await evaluate(
		`JSON.parse(document.querySelector(${JSON.stringify(fingerprint)}).value)`,
	);
	assert.deepEqual(edited[1].children, [
		{ text: "Read ", marks: ["italic"] },
		{ text: "the archive", marks: [{ type: "link", href: "/en/projects/" }] },
		{ text: " carefully.", marks: ["bold"] },
		{ text: " Today.", marks: [] },
	]);
	// HTML in the clipboard is never consumed by this editing path.
	await inPage(
		`(()=>{const d=new DataTransfer();d.setData('text/plain',' More.');d.setData('text/html','<img src=x onerror=alert(1)>');document.querySelector(${JSON.stringify(block)}).dispatchEvent(new ClipboardEvent('paste',{bubbles:true,cancelable:true,clipboardData:d}))})()`,
	);
	await ready(
		`document.querySelector(${JSON.stringify(fingerprint)}).value.includes('Today. More.')`,
	);
	assert.equal(
		await inPage(
			`document.querySelector(${JSON.stringify(block)}).querySelector('img')===null`,
		),
		true,
	);
	await press("Enter");
	await ready(
		`document.activeElement===document.querySelector(${JSON.stringify(surface)})`,
	);
	await ready(settled);
	assert.match(
		await evaluate("document.querySelector('[data-live-status]').textContent"),
		/formatting|Showing/,
	);
	const beforeCancel = await evaluate(
		`document.querySelector(${JSON.stringify(fingerprint)}).value`,
	);
	await clickInPage(block);
	await inPageReady(
		`document.querySelector(${JSON.stringify(block)}).isContentEditable`,
	);
	await inPage(
		`(()=>{const r=document.createRange();r.selectNodeContents(document.querySelector(${JSON.stringify(block)}));const s=getSelection();s.removeAllRanges();s.addRange(r)})()`,
	);
	await command("Input.insertText", { text: "Replace every marked run" });
	await ready(
		`document.querySelector(${JSON.stringify(fingerprint)}).value.includes('Replace every marked run')`,
	);
	await press("Escape");
	await ready(
		`document.querySelector(${JSON.stringify(fingerprint)}).value===${JSON.stringify(beforeCancel)}`,
	);
	await ready(settled);
	assert.equal(
		await inPage(
			`!!document.querySelector(${JSON.stringify(block)}).querySelector('a') && !!document.querySelector(${JSON.stringify(block)}).querySelector('em')`,
		),
		true,
		"Escape restores the exact run structure, not only the original words",
	);
	await clickInPage(block);
	await inPageReady(
		`document.querySelector(${JSON.stringify(block)}).isContentEditable`,
	);
	await evaluate(
		`(()=>{const s=document.querySelector(${JSON.stringify(surface)});s.querySelector('p').append(document.createTextNode(' Form change.'));s.dispatchEvent(new Event('input',{bubbles:true}))})()`,
	);
	await press("End");
	await command("Input.insertText", { text: " Stale page." });
	await ready(
		"document.querySelector('[data-live-status]').textContent.includes('old page edit was refused')",
	);
	assert.equal(
		await evaluate(
			`document.querySelector(${JSON.stringify(fingerprint)}).value.includes('Stale page.')`,
		),
		false,
	);
	await ready(settled);
	await inPage(
		`document.querySelector(${JSON.stringify(block)}).textContent='Transformed by the profile'`,
	);
	await clickInPage(block);
	await ready(
		`document.activeElement===document.querySelector(${JSON.stringify(surface)})`,
	);
	assert.equal(
		await inPage(`document.querySelector(${JSON.stringify(block)}).isContentEditable`),
		false,
	);
	const savedRows = await evaluate(
		`document.querySelector(${JSON.stringify(fingerprint)}).value`,
	);
	const revision = await evaluate("document.querySelector('[name=_revision]').value");
	await evaluate("document.querySelector('[data-editorial-form]').requestSubmit()");
	await ready(
		`document.readyState==='complete' && document.querySelector('[name=_revision]')?.value!==${JSON.stringify(revision)} && !!document.querySelector(${JSON.stringify(fingerprint)})`,
	);
	assert.equal(
		await evaluate(`document.querySelector(${JSON.stringify(fingerprint)}).value`),
		savedRows,
		"normal native save persists the preview-edited rich document through MariaDB",
	);
	// Restore current content; the saved link revision deliberately remains in history.
	await evaluate(
		`(async()=>{const f=document.querySelector('[data-editorial-form]');const d=new URLSearchParams(${JSON.stringify(original)});d.set('_revision',f.querySelector('[name=_revision]').value);d.set('_edit_sequence',f.querySelector('[name=_edit_sequence]').value);d.set('_csrf',f.querySelector('[name=_csrf]').value);await fetch(location.pathname,{method:'POST',body:d});location.reload()})()`,
	);
	await ready(
		`document.readyState==='complete' && !!document.querySelector('[name=_editorial_end]') && document.querySelector(${JSON.stringify(fingerprint)})?.value===${JSON.stringify(original.find(([name]) => name === "body__en__fingerprint")[1])}`,
	);
	const download = await evaluate(
		"fetch('/exports',{method:'POST',body:new URLSearchParams({_csrf:document.querySelector('[name=_csrf]').value})}).then(async r=>({status:r.status,type:r.headers.get('content-type'),text:await r.text()}))",
	);
	assert.equal(download.status, 422, "historical links need v2, not a legacy 500");
	assert.match(download.type, /text\/html/);
	assert.match(download.text, /id="export-package-required"/);
	assert.match(download.text, /disabled aria-describedby="export-package-required"/);
	process.stdout.write(
		"PASS rich live preview: marked runs, plain paste, form-only structure, exact cancellation, stale refusal, transformed focus and native save/reload.\n",
	);
}
