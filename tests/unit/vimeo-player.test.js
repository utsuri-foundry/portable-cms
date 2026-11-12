import assert from "node:assert/strict";
import test from "node:test";
import { mountVimeoPlayer } from "../../engine/src/cms/conventional/client/vimeo-player.js";

function fixture(id = "123456789", hash = "abcdef1234", workspace = false) {
	const frames = [];
	const timers = new Map();
	const messages = [];
	const window = new EventTarget();
	window.setTimeout = (fn, delay) => {
		assert.equal(delay, 5000);
		timers.set(fn, fn);
		return fn;
	};
	window.clearTimeout = (key) => timers.delete(key);
	window.parent = { postMessage: (...args) => messages.push(args) };
	const button = new EventTarget();
	button.hidden = true;
	button.remove = () => {
		button.removed = true;
	};
	const link = Object.assign(new EventTarget(), {
		hidden: false,
		focus() {
			this.focused = true;
		},
	});
	const poster = { getAttribute: (name) => (name === "width" ? "960" : "540") };
	const document = {
		URL: "https://cms.test/live-previews/private-file",
		defaultView: window,
		querySelector: () => workspace,
		createElement: (tag) => {
			assert.equal(tag, "iframe");
			const frame = new EventTarget();
			frame.style = {};
			frame.contentWindow = { postMessage: (...args) => messages.push(args) };
			frame.focus = () => {
				frame.focused = true;
			};
			frame.remove = () => {
				frame.removed = true;
			};
			return frame;
		},
	};
	const root = {
		dataset: { vimeoId: id, vimeoHash: hash, vimeoTitle: "Evening <film>" },
		ownerDocument: document,
		getBoundingClientRect: () => ({ x: 0, y: 20, width: 960, height: 540 }),
		querySelector: (selector) =>
			selector === "[data-vimeo-play]"
				? button
				: selector === "img"
					? poster
					: selector === ".cms-vimeo__link"
						? link
						: (frames[0] ?? null),
		append: (frame) => frames.push(frame),
	};
	const message = (data, patch = {}) =>
		window.dispatchEvent(
			Object.assign(new Event("message"), {
				data,
				origin: "https://player.vimeo.com",
				source: frames[0]?.contentWindow,
				...patch,
			}),
		);
	return {
		root,
		frames,
		button,
		link,
		timers,
		messages,
		window,
		message,
		click: () => button.dispatchEvent(new Event("click")),
		expire: () => {
			for (const fn of [...timers.values()]) fn();
		},
	};
}

test("Vimeo keeps its poster and focus until the exact player acknowledges readiness", () => {
	const f = fixture();
	mountVimeoPlayer(f.root);
	assert.equal(f.frames.length, 0);
	assert.equal(f.timers.size, 0);
	assert.equal(f.button.hidden, false);
	assert.equal(f.link.hidden, true);
	f.click();
	const [frame] = f.frames;
	assert.equal(
		frame.src,
		"https://player.vimeo.com/video/123456789?h=abcdef1234&dnt=1&autoplay=1",
	);
	assert.equal(frame.allow, "autoplay; fullscreen; picture-in-picture");
	assert.equal(frame.allowFullscreen, true);
	assert.equal(frame.title, "Evening <film>");
	assert.deepEqual([frame.width, frame.height], ["960", "540"]);
	assert.equal(frame.focused, undefined);
	assert.equal(frame.style.visibility, "hidden");
	assert.equal(f.root.dataset.vimeoPlaying, undefined);
	assert.equal(f.button.removed, true);
	frame.dispatchEvent(new Event("load"));
	assert.deepEqual(f.messages, [[{ method: "ping" }, "https://player.vimeo.com"]]);
	for (const [data, patch] of [
		[{ event: "ready" }, { source: {} }],
		[{ event: "ready" }, { origin: "https://evil.test" }],
		[{ event: "ready" }, { origin: "null" }],
		["invalid JSON", {}],
		["x".repeat(4097), {}],
		[null, {}],
		[{ event: "play" }, {}],
	])
		f.message(data, patch);
	assert.equal(
		frame.style.visibility,
		"hidden",
		"load or spoofed messages do not reveal blank documents",
	);
	f.message(JSON.stringify({ event: "ready" }));
	assert.equal(frame.focused, true);
	assert.equal(frame.style.visibility, "visible");
	assert.equal(f.root.dataset.vimeoPlaying, "true");
	assert.equal(f.timers.size, 0);
	assert.equal("referrerPolicy" in frame, false);
	assert.equal("muted" in frame, false);
	f.click();
	assert.equal(f.frames.length, 1);
});

test("Vimeo timeout, iframe error and provider error restore the link without retry or late focus", () => {
	for (const failure of ["never-load", "timeout", "iframe", "provider"]) {
		const f = fixture();
		mountVimeoPlayer(f.root);
		f.click();
		const [frame] = f.frames;
		if (failure !== "never-load") frame.dispatchEvent(new Event("load"));
		if (failure === "timeout" || failure === "never-load") f.expire();
		if (failure === "iframe") frame.dispatchEvent(new Event("error"));
		if (failure === "provider")
			f.message({ event: "error", data: { method: "ready" } });
		assert.equal(f.link.hidden, false);
		assert.equal(f.link.focused, true);
		assert.equal(frame.removed, true);
		assert.equal(f.root.dataset.vimeoPlaying, undefined);
		assert.equal(f.button.removed, true);
		assert.equal(f.timers.size, 0);
		f.message({ event: "ready" });
		frame.dispatchEvent(new Event("load"));
		f.click();
		assert.equal(frame.focused, undefined);
		assert.equal(f.frames.length, 1);
	}
});

test("player errors after readiness restore the poster and link too", () => {
	for (const provider of [false, true]) {
		const f = fixture();
		mountVimeoPlayer(f.root);
		f.click();
		f.message({ event: "ready" });
		assert.equal(f.root.dataset.vimeoPlaying, "true");
		if (provider) f.message({ event: "error" });
		else f.frames[0].dispatchEvent(new Event("error"));
		assert.equal(f.root.dataset.vimeoPlaying, undefined);
		assert.equal(f.link.hidden, false);
		assert.equal(f.frames[0].removed, true);
		f.message({ event: "ready" });
		assert.equal(f.root.dataset.vimeoPlaying, undefined);
	}
});

test("an opaque preview pins readiness to the inherited opaque Vimeo frame", () => {
	const f = fixture();
	f.window.origin = "null";
	mountVimeoPlayer(f.root);
	f.click();
	f.frames[0].dispatchEvent(new Event("load"));
	assert.deepEqual(f.messages.at(-1), [{ method: "ping" }, "*"]);
	f.message({ event: "ready" });
	f.message({ event: "ready" }, { origin: "null", source: {} });
	assert.equal(f.root.dataset.vimeoPlaying, undefined);
	f.message({ event: "ready" }, { origin: "null" });
	assert.equal(f.root.dataset.vimeoPlaying, "true");
});

test("shared links delegate only after a capability from the actual parent origin", () => {
	const f = fixture();
	f.window.origin = "null";
	mountVimeoPlayer(f.root);
	const click = () => {
		const event = new Event("click", { cancelable: true });
		f.link.dispatchEvent(event);
		return event;
	};
	const support = { type: "cms-vimeo", event: "link-support" };
	f.message(support, { origin: "https://cms.test", source: {} });
	f.message(support, { origin: "https://evil.test", source: f.window.parent });
	assert.equal(click().defaultPrevented, false);
	f.message(support, { origin: "https://cms.test", source: f.window.parent });
	assert.equal(click().defaultPrevented, true);
	assert.deepEqual(f.messages.at(-1), [
		{ type: "cms-vimeo", event: "open", id: "123456789", hash: "abcdef1234" },
		"https://cms.test",
	]);
});

test("Vimeo public videos omit the hash and accept ping; invalid attributes preserve the ordinary link", () => {
	const f = fixture("123456789", "");
	mountVimeoPlayer(f.root);
	f.click();
	assert.equal(
		f.frames[0].src,
		"https://player.vimeo.com/video/123456789?dnt=1&autoplay=1",
	);
	f.message({ method: "ping" });
	assert.equal(f.root.dataset.vimeoPlaying, "true");
	for (const [id, hash] of [
		["123?redirect=evil", ""],
		["123", "bad&h=foo"],
		["0", ""],
	]) {
		const invalid = fixture(id, hash);
		mountVimeoPlayer(invalid.root);
		assert.equal(invalid.button.hidden, true);
		assert.equal(invalid.link.hidden, false);
		invalid.click();
		assert.equal(invalid.frames.length, 0);
	}
});

test("workspace messages require the parent, origin and request; close and timeout restore the link", () => {
	for (const timeout of [false, true]) {
		const f = fixture("123456789", "", true);
		mountVimeoPlayer(f.root);
		f.click();
		assert.equal(f.frames.length, 0);
		const [[request, target]] = f.messages;
		assert.equal(target, "https://cms.test");
		assert.equal(request.event, "vimeo-play");
		assert.equal(f.button.removed, true);
		const state = {
			type: "cms-live",
			event: "vimeo-state",
			request: request.request,
			state: "ready",
		};
		f.message(state);
		f.message(state, { source: f.window.parent, origin: "https://evil.test" });
		f.message(
			{ ...state, request: "wrong" },
			{ source: f.window.parent, origin: target },
		);
		assert.equal(f.root.dataset.vimeoPlaying, undefined);
		if (timeout) f.expire();
		else {
			f.message(state, { source: f.window.parent, origin: target });
			assert.equal(f.root.dataset.vimeoPlaying, "true");
			assert.equal(f.timers.size, 0);
			f.message(
				{ ...state, state: "failed" },
				{ source: f.window.parent, origin: target },
			);
		}
		assert.equal(f.link.hidden, false);
		assert.equal(f.root.dataset.vimeoPlaying, undefined);
		assert.equal(f.messages.at(-1)[0].event, "vimeo-cancel");
		f.message(state, { source: f.window.parent, origin: target });
		assert.equal(f.root.dataset.vimeoPlaying, undefined);
	}
});

test("opaque pages delegate only after trusted shell support, and scrolling cancels the sibling player", () => {
	for (const cancel of ["scroll", "resize", "timeout"]) {
		const f = fixture();
		f.window.origin = "null";
		mountVimeoPlayer(f.root);
		const support = { type: "cms-vimeo", event: "player-support" };
		f.message(support, { origin: "https://evil.test", source: f.window.parent });
		f.message(support, { origin: "https://cms.test", source: {} });
		assert.equal(f.root.dataset.vimeoShell, undefined);
		f.message(support, { origin: "https://cms.test", source: f.window.parent });
		f.click();
		assert.equal(f.frames.length, 0);
		const request = f.messages.at(-1)[0];
		assert.equal(request.event, "vimeo-play");
		if (cancel === "timeout") f.expire();
		else {
			f.message(
				{
					type: "cms-live",
					event: "vimeo-state",
					request: request.request,
					state: "ready",
				},
				{ origin: "https://cms.test", source: f.window.parent },
			);
			assert.equal(f.root.dataset.vimeoPlaying, "true");
			f.window.dispatchEvent(new Event(cancel));
		}
		assert.equal(f.link.hidden, false);
		assert.equal(f.root.dataset.vimeoPlaying, undefined);
		assert.equal(f.messages.at(-1)[0].event, "vimeo-cancel");
	}
});
