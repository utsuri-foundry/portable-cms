import assert from "node:assert/strict";
import { spawn } from "node:child_process";
import { mkdtemp, readFile, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";

const origin = process.env.CMS_BROWSER_ORIGIN ?? "http://localhost:42873";
const url = new URL(origin);
assert.ok(["localhost", "127.0.0.1"].includes(url.hostname));
assert.equal(url.protocol, "http:");
const profile = await mkdtemp(join(tmpdir(), "portable-design-chromium-"));
const browser = spawn(
	process.env.CHROMIUM_BIN ?? "chromium",
	[
		"--headless",
		"--no-sandbox",
		"--disable-gpu",
		"--remote-debugging-port=0",
		`--user-data-dir=${profile}`,
		"about:blank",
	],
	{ stdio: "ignore" },
);
let browserFailure;
browser.on("error", (error) => {
	browserFailure = error;
});
let socket;
const pending = new Map();
let sequence = 0;
const errors = [];
async function retry(action) {
	const deadline = Date.now() + 15000;
	while (Date.now() < deadline) {
		if (browserFailure) throw browserFailure;
		try {
			if (await action()) return;
		} catch {
			/* Readiness is observable. */
		}
		await new Promise((resolve) => setTimeout(resolve, 50));
	}
	throw new Error("Browser readiness timed out");
}
function command(method, params = {}) {
	return new Promise((resolve, reject) => {
		const id = ++sequence;
		const timeout = setTimeout(() => {
			pending.delete(id);
			reject(new Error(`Timed out: ${method}`));
		}, 10000);
		pending.set(id, { resolve, reject, timeout });
		socket.send(JSON.stringify({ id, method, params }));
	});
}
async function evaluate(expression) {
	const result = await command("Runtime.evaluate", {
		expression,
		returnByValue: true,
		awaitPromise: true,
		userGesture: true,
	});
	if (result.exceptionDetails)
		throw new Error(
			result.exceptionDetails.exception?.description ?? "Evaluation failed",
		);
	return result.result.value;
}
async function navigate(path) {
	await command("Page.navigate", { url: origin + path });
	await retry(() =>
		evaluate(
			`location.pathname === ${JSON.stringify(path)} && document.readyState === 'complete'`,
		),
	);
}
async function values() {
	return evaluate(
		"Object.fromEntries([...new FormData(document.querySelector('[data-design-form]'))].filter(([name])=>!name.startsWith('_') && name!=='font_file'))",
	);
}
async function fill(value) {
	await evaluate(
		`for (const [name,value] of Object.entries(${JSON.stringify(value)})) {const control=document.getElementsByName(name)[0];if(control && control.type!=='file') {if(control.type==='checkbox')control.checked=value==='yes';else control.value=value;}}`,
	);
}
let original;
try {
	let port;
	await retry(async () => {
		port = (await readFile(join(profile, "DevToolsActivePort"), "utf8")).split("\n")[0];
		return port;
	});
	const target = await fetch(`http://127.0.0.1:${port}/json/new?about:blank`, {
		method: "PUT",
	}).then((r) => r.json());
	socket = new WebSocket(target.webSocketDebuggerUrl);
	await new Promise((resolve, reject) => {
		socket.addEventListener("open", resolve, { once: true });
		socket.addEventListener("error", reject, { once: true });
	});
	socket.addEventListener("message", ({ data }) => {
		const message = JSON.parse(data);
		if (message.method === "Runtime.exceptionThrown")
			errors.push(message.params.exceptionDetails.text);
		if (message.id) {
			const call = pending.get(message.id);
			if (!call) return;
			pending.delete(message.id);
			clearTimeout(call.timeout);
			if (message.error) call.reject(new Error(JSON.stringify(message.error)));
			else call.resolve(message.result);
		}
	});
	await command("Page.enable");
	await command("Runtime.enable");
	await command("DOM.enable");
	await navigate("/login");
	await evaluate(
		"document.querySelector('[name=login]').value='admin@portable.test';document.querySelector('[name=password]').value='portable-cms-local';document.querySelector('form').requestSubmit()",
	);
	await retry(() =>
		evaluate("location.pathname==='/' && document.querySelector('.admin-navigation')"),
	);
	await navigate("/settings/design");
	assert.equal(
		await evaluate("document.querySelector('h1').textContent"),
		"Website design",
	);
	original = await values();
	assert.equal(
		await evaluate("document.querySelector('[data-font-unavailable]') === null"),
		true,
	);
	for (const [width, height, scale] of [
		[360, 800, 1],
		[640, 450, 2],
	]) {
		await command("Emulation.setDeviceMetricsOverride", {
			width,
			height,
			deviceScaleFactor: scale,
			mobile: false,
		});
		assert.equal(
			await evaluate("document.documentElement.scrollWidth <= innerWidth"),
			true,
			"form reflows at narrow and zoom review widths",
		);
	}
	await evaluate("document.querySelector('[name=typography__body__label]').focus()");
	assert.equal(
		await evaluate("document.activeElement.name"),
		"typography__body__label",
	);
	// The entire design editor must work with script execution disabled.
	await command("Emulation.setScriptExecutionDisabled", { value: true });
	await navigate("/settings/design");
	await fill({ typography__body__min: "20", typography__body__max: "20" });
	const initialRevision = await evaluate(
		"document.querySelector('[name=_revision]').value",
	);
	await evaluate("document.querySelector('[value=calculate]').click()");
	await retry(() =>
		evaluate(
			"document.querySelector('[data-design-specimens]')?.textContent.includes('20 px')",
		),
	);
	assert.equal(
		await evaluate("document.querySelector('[name=_revision]').value"),
		initialRevision,
		"calculation does not save",
	);
	await fill({
		responsive__wide__minWidth: "700",
		typography__body__label: "Unsaved reading text",
	});
	await evaluate("document.querySelector('[value=save]').click()");
	await retry(() => evaluate("document.querySelector('[role=alert]')"));
	assert.equal(
		await evaluate("document.querySelector('[name=responsive__wide__minWidth]').value"),
		"700",
	);
	assert.equal(
		await evaluate("document.querySelector('[name=typography__body__label]').value"),
		"Unsaved reading text",
	);
	await fill(original);
	await fill({ typography__body__label: "Browser reading text" });
	await evaluate("document.querySelector('[value=save]').click()");
	await retry(() =>
		evaluate(
			"location.search==='?saved=1' && document.querySelector('[name=typography__body__label]')?.value==='Browser reading text'",
		),
	);
	const revision = await evaluate("document.querySelector('[name=_revision]').value");
	assert.equal(Number(revision), Number(initialRevision) + 1);
	// Submit a stale snapshot deliberately: the server must return a preserved conflict.
	await fill({
		_revision: initialRevision,
		typography__body__label: "Conflicting reading text",
	});
	await evaluate("document.querySelector('[value=save]').click()");
	await retry(() =>
		evaluate(
			"document.querySelector('button[value=save]')?.textContent.includes('over revision')",
		),
	);
	assert.equal(
		await evaluate("document.querySelector('[name=typography__body__label]').value"),
		"Conflicting reading text",
	);
	assert.equal(
		await evaluate("document.querySelector('[name=_revision]').value"),
		revision,
	);
	await fill(original);
	await evaluate("document.querySelector('[value=save]').click()");
	await retry(() =>
		evaluate(
			`location.search==='?saved=1' && document.querySelector('[name=typography__body__label]')?.value===${JSON.stringify(original.typography__body__label)}`,
		),
	);
	await command("Emulation.setScriptExecutionDisabled", { value: false });
	// Native file upload: fixture bytes, never a machine path in product metadata.
	const fontId = `browser-font-${Number(revision) + 1}`;
	await fill({
		font_id: fontId,
		font_label: "Browser test font",
		font_provenance: "Adobe Source Serif 4, reviewed reference subset",
		font_license: "SIL Open Font License 1.1",
		font_permission: "yes",
	});
	const document = await command("DOM.getDocument");
	const input = await command("DOM.querySelector", {
		nodeId: document.root.nodeId,
		selector: "[name=font_file]",
	});
	await command("DOM.setFileInputFiles", {
		nodeId: input.nodeId,
		files: [
			join(process.cwd(), "profiles/reference-studio/assets/studio-serif-latin.woff2"),
		],
	});
	await evaluate("document.querySelector('[value=save]').click()");
	await retry(() =>
		evaluate(
			`location.search==='?saved=1' && document.querySelector('[name=fonts__${fontId}__label]')`,
		),
	);
	assert.equal(
		await evaluate(`document.querySelector('[name=fonts__${fontId}__label]').value`),
		"Browser test font",
	);
	const exported = await evaluate(
		"(async()=>{const form=document.querySelector('[data-design-form]');const csrf=form.querySelector('[name=_csrf]').value;const response=await fetch('/exports',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({_csrf:csrf})});return {status:response.status,document:await response.json()};})()",
	);
	assert.equal(exported.status, 200);
	const font = exported.document.designRegistries.fonts.find(
		(font) => font.id === fontId,
	);
	assert.equal(exported.document.designRegistries.formatVersion, 2);
	assert.equal(font.faces[0].origin, "upload");
	assert.match(font.faces[0].sha256, /^[a-f0-9]{64}$/);
	assert.equal(font.faces[0].license, "SIL Open Font License 1.1");
	await fill({
		font_family: fontId,
		font_face_id: "italic",
		font_face_label: "Italic",
		font_style: "italic",
		font_weight_min: "400",
		font_weight_max: "400",
		font_provenance: "Studio Serif Italic, reviewed renamed reference subset",
		font_license: "SIL Open Font License 1.1",
		font_permission: "yes",
	});
	const secondDocument = await command("DOM.getDocument");
	const secondInput = await command("DOM.querySelector", {
		nodeId: secondDocument.root.nodeId,
		selector: "[name=font_file]",
	});
	await command("DOM.setFileInputFiles", {
		nodeId: secondInput.nodeId,
		files: [
			join(
				process.cwd(),
				"profiles/reference-studio/assets/studio-serif-italic-latin.woff2",
			),
		],
	});
	await command("Emulation.setScriptExecutionDisabled", { value: true });
	await evaluate("document.querySelector('[value=save]').click()");
	await retry(() =>
		evaluate(
			`location.search==='?saved=1' && document.querySelector('[data-font-face="${fontId}:italic"]')`,
		),
	);
	assert.equal(
		await evaluate(
			`document.querySelectorAll('[data-font-face^="${fontId}:"]').length`,
		),
		2,
		"a second sanitized face joins the same family without JavaScript",
	);
	await command("Emulation.setScriptExecutionDisabled", { value: false });
	const screenshot = await command("Page.captureScreenshot", {
		format: "png",
		captureBeyondViewport: false,
	});
	await writeFile(
		process.env.CMS_BROWSER_DESIGN_SCREENSHOT ?? join(profile, "design-review.png"),
		Buffer.from(screenshot.data, "base64"),
	);
	assert.deepEqual(errors, []);
	console.log(
		"PASS real Chromium design journey: narrow/zoom, keyboard, no-script calculate/save, validation, conflict, two sanitized family faces and normalized export.",
	);
} finally {
	if (original && socket?.readyState === WebSocket.OPEN) {
		try {
			await navigate("/settings/design");
			await fill(original);
			await evaluate("document.querySelector('[value=save]').click()");
		} catch {
			/* Disposable sandbox retains evidence after failure. */
		}
	}
	for (const call of pending.values()) clearTimeout(call.timeout);
	socket?.close();
	browser.kill("SIGTERM");
	await new Promise((resolve) => {
		if (browser.exitCode !== null) resolve();
		else browser.once("exit", resolve);
	});
	await rm(profile, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 });
}
