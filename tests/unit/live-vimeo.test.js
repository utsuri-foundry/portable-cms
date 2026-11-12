import assert from "node:assert/strict";
import test from "node:test";
import { mountLiveVimeo } from "../../engine/src/cms/conventional/client/live-vimeo.js";

function fixture() {
	const listeners = new Map();
	const players = [];
	const timers = new Map();
	const states = [];
	const window = {
		navigator: { userActivation: { isActive: true } },
		setTimeout(fn) {
			timers.set(fn, fn);
			return fn;
		},
		clearTimeout(fn) {
			timers.delete(fn);
		},
		addEventListener(name, fn) {
			if (!listeners.has(name)) listeners.set(name, new Set());
			listeners.get(name).add(fn);
		},
		removeEventListener(name, fn) {
			listeners.get(name)?.delete(fn);
			if (!listeners.get(name)?.size) listeners.delete(name);
		},
	};
	const root = {
		hidden: false,
		ownerDocument: {
			defaultView: window,
			createElement(tag) {
				assert.equal(tag, "iframe");
				return Object.assign(new EventTarget(), {
					dataset: {},
					style: {},
					contentWindow: { postMessage() {} },
					focus() {
						this.focused = true;
					},
					remove() {
						this.removed = true;
					},
				});
			},
		},
	};
	const frame = {
		contentWindow: { postMessage: (...args) => states.push(args) },
		offsetWidth: 1000,
		offsetHeight: 800,
		getBoundingClientRect: () => ({ width: 500 }),
		parentElement: {
			append(p) {
				players.push(p);
			},
		},
	};
	const controller = mountLiveVimeo(root, () => frame);
	const data = {
		type: "cms-live",
		event: "vimeo-play",
		request: "1",
		id: "123456789",
		hash: "abcdef1234",
		title: "Evening <film>",
		rect: { x: 0, y: 200, width: 1000, height: 600 },
	};
	const dispatch = (name, event = {}) => {
		for (const fn of [...(listeners.get(name) ?? [])]) fn(event);
	};
	const send = (patch = {}, event = {}) =>
		dispatch("message", {
			source: frame.contentWindow,
			origin: "null",
			data: { ...data, ...patch },
			...event,
		});
	const loaded = () =>
		dispatch("message", {
			source: players.at(-1).contentWindow,
			origin: "https://player.vimeo.com",
			data: { event: "ready" },
		});
	return {
		listeners,
		players,
		root,
		frame,
		controller,
		data,
		send,
		window,
		dispatch,
		states,
		timers,
		loaded,
	};
}
test("live Vimeo requires the visible opaque frame, a current gesture, bounded input and a visible poster", () => {
	const f = fixture();
	for (const event of [{ source: {} }, { origin: "https://evil.test" }])
		f.send({}, event);
	for (const patch of [
		{ type: "other" },
		{ event: "other" },
		{ request: "bad" },
		{ id: "123/evil" },
		{ hash: "short" },
		{ title: "" },
		{ title: "x".repeat(201) },
		{ title: "unsafe\n" },
		{ rect: { x: 0, y: 0, width: Infinity, height: 100 } },
		{ rect: { x: 0, y: 900, width: 100, height: 100 } },
		{ rect: { x: 0, y: 0, width: 2000, height: 100 } },
	])
		f.send(patch);
	f.window.navigator.userActivation.isActive = false;
	f.send();
	f.window.navigator.userActivation.isActive = true;
	f.root.hidden = true;
	f.send();
	f.root.hidden = false;
	assert.equal(f.players.length, 0, "no request before an accepted user gesture");
	f.send();
	const [player] = f.players;
	assert.equal(
		player.src,
		"https://player.vimeo.com/video/123456789?h=abcdef1234&dnt=1&autoplay=1",
	);
	assert.equal(player.title, f.data.title);
	assert.equal(player.allow, "autoplay; fullscreen; picture-in-picture");
	assert.equal(player.allowFullscreen, true);
	assert.equal(player.focused, undefined);
	assert.equal(player.style.visibility, "hidden");
	assert.equal(f.states.length, 0);
	f.loaded();
	assert.equal(player.focused, true);
	assert.equal(f.timers.size, 0);
	assert.deepEqual(f.states.at(-1), [
		{ type: "cms-live", event: "vimeo-state", request: "1", state: "ready" },
		"*",
	]);
	assert.equal(
		"referrerPolicy" in player,
		false,
		"the normal authoring origin referrer is inherited",
	);
	assert.deepEqual(player.style, {
		position: "absolute",
		left: "0px",
		top: "100px",
		width: "500px",
		height: "300px",
		border: "0",
		zIndex: "10",
		visibility: "visible",
	});
	f.send({ event: "scroll" });
	assert.equal(player.removed, true);
	assert.equal(f.states.at(-1)[0].state, "failed");
	f.send({ hash: "" });
	assert.equal(
		f.players.at(-1).src,
		"https://player.vimeo.com/video/123456789?dnt=1&autoplay=1",
	);
	f.dispatch("resize");
	assert.equal(f.players.at(-1).removed, true);
	f.controller.destroy();
	assert.equal(f.listeners.size, 0);
});

test("live player timeout, error and correlated cancellation remove overlays and notify the child", () => {
	for (const cause of ["timeout", "error", "cancel", "destroy"]) {
		const f = fixture();
		f.send();
		const [player] = f.players;
		f.send({ event: "vimeo-cancel", request: "2" });
		assert.equal(player.removed, undefined);
		if (cause === "timeout") for (const fn of [...f.timers.values()]) fn();
		if (cause === "error") player.dispatchEvent(new Event("error"));
		if (cause === "cancel") f.send({ event: "vimeo-cancel" });
		if (cause === "destroy") f.controller.destroy();
		assert.equal(player.removed, true);
		assert.equal(f.states.at(-1)[0].state, "failed");
		assert.equal(f.timers.size, 0);
		f.loaded();
		assert.equal(player.focused, undefined);
		f.controller.destroy();
		assert.equal(f.listeners.size, 0);
	}
});
