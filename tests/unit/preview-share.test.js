import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import { runInNewContext } from "node:vm";

const source = readFileSync(
	new URL("../../engine/src/cms/conventional/client/preview-share.js", import.meta.url),
	"utf8",
);

function viewer(fetch, kind = "shared") {
	class Frame extends EventTarget {}
	const frame = new Frame();
	const tabs = [];
	const supports = [];
	frame.contentWindow = { postMessage: (...args) => supports.push(args) };
	frame.src = `https://cms.example.test/${kind}-preview-files/${"a".repeat(32)}/${"b".repeat(64)}/`;
	frame.dataset = { shareRenew: `/${kind}-previews/${"a".repeat(32)}/renew` };
	const listeners = new Map();
	const windowListeners = new Map();
	let tick;
	let cleared = 0;
	const window = {
		location: { hash: "" },
		navigator: { userActivation: { isActive: true } },
		open: (...args) => tabs.push(args),
		addEventListener: (name, fn) => windowListeners.set(name, fn),
		removeEventListener: (name) => windowListeners.delete(name),
	};
	const document = {
		hidden: false,
		querySelector: (selector) => (selector.startsWith("iframe[") ? frame : null),
		addEventListener: (name, fn) => listeners.set(name, fn),
		removeEventListener: (name) => listeners.delete(name),
	};
	runInNewContext(source, {
		URL,
		URLSearchParams,
		AbortController,
		HTMLIFrameElement: Frame,
		HTMLFormElement: class {},
		HTMLInputElement: class {},
		HTMLElement: class {},
		document,
		window,
		fetch,
		setInterval: (fn, milliseconds) => {
			assert.equal(milliseconds, 15 * 60 * 1000);
			tick = fn;
			return 1;
		},
		clearInterval: () => cleared++,
		setTimeout: () => 2,
		clearTimeout: () => {},
	});
	return {
		frame,
		window,
		tabs,
		supports,
		message: (data, patch = {}) =>
			windowListeners.get("message")?.({
				data,
				source: frame.contentWindow,
				origin: "null",
				...patch,
			}),
		document,
		listeners,
		tick: () => tick(),
		hide: (persisted = false) => windowListeners.get("pagehide")({ persisted }),
		show: () => windowListeners.get("pageshow")(),
		cleared: () => cleared,
	};
}

test("share viewer renews only while visible, keeps its page and releases requests on disposal", async () => {
	const requests = [];
	let resolve;
	const page = viewer((url, options) => {
		requests.push({ url, options });
		return new Promise((done) => {
			resolve = done;
		});
	});
	const address = page.frame.src;
	page.document.hidden = true;
	await page.tick();
	assert.equal(requests.length, 0);
	page.document.hidden = false;
	const pending = page.listeners.get("visibilitychange")();
	await page.tick();
	assert.equal(requests.length, 1, "renewals never overlap");
	const { url, options } = requests[0];
	assert.equal(url, page.frame.dataset.shareRenew);
	assert.equal(options.method, "POST");
	assert.equal(options.mode, "same-origin");
	assert.equal(options.credentials, "same-origin");
	assert.equal(options.body.get("token"), "b".repeat(64));
	assert.equal(page.frame.src, address, "renewing never navigates the displayed page");
	page.hide();
	assert.equal(options.signal.aborted, true);
	assert.equal(page.cleared(), 1);
	assert.equal(page.listeners.size, 0);
	resolve({ status: 204 });
	await pending;
	await page.tick();
	assert.equal(requests.length, 1, "disposed viewer cannot renew");
});

test("share viewer retries transient failures and stops at a revoked or expired address", async () => {
	let calls = 0;
	const page = viewer(async () => {
		if (++calls === 1) throw new Error("fixture connection interruption");
		return { status: 404 };
	});
	await page.tick();
	assert.equal(page.cleared(), 0);
	await page.tick();
	assert.equal(page.cleared(), 1);
	await page.tick();
	assert.equal(calls, 2);
});

test("share viewer resumes renewal after browser Back restores its document", async () => {
	let calls = 0;
	const page = viewer(async () => {
		calls++;
		return { status: 204 };
	});
	page.hide(true);
	assert.equal(page.cleared(), 0);
	await page.show();
	assert.equal(calls, 1);
	page.hide();
	assert.equal(page.cleared(), 1);
});

test("newest-preview viewer uses the same renewable file runtime", async () => {
	const requests = [];
	const page = viewer(async (url, options) => {
		requests.push({ url, options });
		return { status: 204 };
	}, "newest");
	const address = page.frame.src;
	await page.tick();
	assert.equal(requests.length, 1);
	assert.equal(requests[0].url, `/newest-previews/${"a".repeat(32)}/renew`);
	assert.equal(requests[0].options.body.get("token"), "b".repeat(64));
	assert.equal(page.frame.src, address);
	page.hide();
});

test("shared shell permits only user-activated fixed Vimeo links from its exact opaque frame", () => {
	const page = viewer(async () => ({ status: 204 }));
	const data = {
		type: "cms-vimeo",
		event: "open",
		id: "123456789",
		hash: "abcdef1234",
	};
	for (const patch of [{ source: {} }, { origin: "https://evil.test" }])
		page.message(data, patch);
	for (const patch of [
		{ type: "other" },
		{ event: "other" },
		{ id: "123/evil" },
		{ id: "0" },
		{ hash: "bad&x=1" },
		{ hash: null },
	])
		page.message({ ...data, ...patch });
	page.window.navigator.userActivation.isActive = false;
	page.message(data);
	page.window.navigator.userActivation.isActive = true;
	page.document.hidden = true;
	page.message(data);
	page.document.hidden = false;
	assert.equal(page.tabs.length, 0);
	page.message(data);
	assert.deepEqual(page.tabs, [
		["https://vimeo.com/123456789/abcdef1234", "_blank", "noopener,noreferrer"],
	]);
	page.message({ ...data, hash: "" });
	assert.equal(page.tabs.at(-1)[0], "https://vimeo.com/123456789");
	page.hide();
	page.message(data);
	assert.equal(page.tabs.length, 2, "disposed shells remove the handoff");
});
