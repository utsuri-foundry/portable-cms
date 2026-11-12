import assert from "node:assert/strict";
import test from "node:test";
import { mountPreviewVimeo } from "../../engine/src/cms/conventional/client/preview-vimeo.js";

function fixture(kind) {
	const window = Object.assign(new EventTarget(), {
		navigator: { userActivation: { isActive: true } },
	});
	const timers = new Map();
	window.setTimeout = (fn, ms) => {
		assert.equal(ms, 5000);
		timers.set(fn, fn);
		return fn;
	};
	window.clearTimeout = (fn) => timers.delete(fn);
	const states = [];
	const players = [];
	const document = Object.assign(new EventTarget(), {
		defaultView: window,
		hidden: false,
		createElement: () =>
			Object.assign(new EventTarget(), {
				dataset: {},
				style: {},
				contentWindow: { postMessage() {} },
				remove() {
					this.removed = true;
				},
				focus() {
					this.focused = true;
				},
			}),
	});
	document.body = {
		ownerDocument: document,
		hidden: false,
		append: (player) => players.push(player),
	};
	const frame = Object.assign(new EventTarget(), {
		ownerDocument: document,
		dataset: { [kind === "exact" ? "editorExactFrame" : "shareRenew"]: "" },
		contentWindow: { postMessage: (data, origin) => states.push([data, origin]) },
		offsetWidth: 1000,
		offsetHeight: 800,
		hidden: false,
		getBoundingClientRect: () => ({ left: 20, top: 40, width: 500, height: 400 }),
		parentElement: document.body,
	});
	const dispatch = (data, patch = {}) =>
		window.dispatchEvent(
			Object.assign(new Event("message"), {
				source: frame.contentWindow,
				origin: "null",
				data,
				...patch,
			}),
		);
	const data = {
		type: "cms-live",
		event: "vimeo-play",
		request: "1",
		id: "123456789",
		hash: "abcdef1234",
		title: "A film",
		rect: { x: 100, y: 100, width: 600, height: 300 },
	};
	const play = (patch = {}, event = {}) => dispatch({ ...data, ...patch }, event);
	const controller = mountPreviewVimeo(frame);
	const ready = () =>
		dispatch(
			{ event: "ready" },
			{ source: players.at(-1).contentWindow, origin: "https://player.vimeo.com" },
		);
	return {
		window,
		document,
		frame,
		states,
		players,
		timers,
		dispatch,
		play,
		ready,
		controller,
	};
}

for (const kind of ["exact", "shared"]) {
	test(`${kind} shell announces support and accepts only its opaque frame, gesture and bounded video data`, () => {
		const f = fixture(kind);
		assert.deepEqual(f.states, [[{ type: "cms-vimeo", event: "player-support" }, "*"]]);
		assert.equal(f.players.length, 0);
		for (const event of [{ source: {} }, { origin: "https://evil.test" }])
			f.play({}, event);
		for (const patch of [
			{ type: "other" },
			{ request: "bad" },
			{ id: "0" },
			{ id: "1?redirect=evil" },
			{ hash: "short" },
			{ title: "" },
			{ title: "unsafe\n" },
			{ title: "x".repeat(201) },
			{ title: "x".repeat(10000) },
			{ rect: { x: 0, y: 0, width: Infinity, height: 100 } },
			{ rect: { x: 0, y: 0, width: 2000, height: 100 } },
			{ rect: { x: 0, y: 0, width: 100, height: 17000 } },
			{ rect: { x: 0, y: 900, width: 100, height: 100 } },
		])
			f.play(patch);
		f.window.navigator.userActivation.isActive = false;
		f.play();
		f.window.navigator.userActivation.isActive = true;
		f.document.hidden = true;
		f.play();
		f.document.hidden = false;
		f.frame.hidden = true;
		f.play();
		f.frame.hidden = false;
		assert.equal(f.players.length, 0);
		f.play({ url: "https://evil.test" });
		const [player] = f.players;
		assert.equal(
			player.src,
			"https://player.vimeo.com/video/123456789?h=abcdef1234&dnt=1&autoplay=1",
		);
		assert.equal(player.referrerPolicy, "origin");
		assert.equal(player.style.visibility, "hidden");
		assert.equal(player.focused, undefined);
		assert.deepEqual(
			[
				player.style.position,
				player.style.left,
				player.style.top,
				player.style.width,
				player.style.height,
			],
			["fixed", "70px", "90px", "300px", "150px"],
		);
		f.ready();
		assert.equal(player.style.visibility, "visible");
		assert.equal(player.focused, true);
		assert.equal(f.states.at(-1)[0].state, "ready");
		assert.equal(f.timers.size, 0);
		f.controller.destroy();
	});

	test(`${kind} shell clips to its page and clears on timeout, errors, scroll, resize, navigation and disposal`, () => {
		for (const cause of [
			"timeout",
			"error",
			"scroll",
			"resize",
			"load",
			"hidden",
			"pagehide",
			"destroy",
		]) {
			const f = fixture(kind);
			f.play({ rect: { x: 0, y: -100, width: 1000, height: 300 } });
			const [player] = f.players;
			assert.equal(player.style.clipPath, "inset(50px 0px 0px 0px)");
			if (cause === "timeout") for (const fn of [...f.timers.values()]) fn();
			if (cause === "error") player.dispatchEvent(new Event("error"));
			if (cause === "scroll") f.play({ event: "scroll" });
			if (cause === "resize") f.window.dispatchEvent(new Event("resize"));
			if (cause === "load") f.frame.dispatchEvent(new Event("load"));
			if (cause === "hidden") {
				f.document.hidden = true;
				f.document.dispatchEvent(new Event("visibilitychange"));
			}
			if (cause === "pagehide") f.window.dispatchEvent(new Event("pagehide"));
			if (cause === "destroy") f.controller.destroy();
			assert.equal(player.removed, true, cause);
			assert.ok(
				f.states.some(([data]) => data.state === "failed"),
				cause,
			);
			assert.equal(f.timers.size, 0);
			f.ready();
			assert.equal(player.focused, undefined);
			f.controller.destroy();
		}
	});
}
