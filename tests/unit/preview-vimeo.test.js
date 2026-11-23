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
	const told = [];
	const document = Object.assign(new EventTarget(), {
		defaultView: window,
		hidden: false,
		createElement: () =>
			Object.assign(new EventTarget(), {
				dataset: {},
				style: {},
				contentWindow: { postMessage: (data, origin) => told.push([data, origin]) },
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
		told,
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

	test(`${kind} shell clips to its page and ends the player on timeout, errors, page scroll, navigation and disposal`, () => {
		for (const cause of ["timeout", "error", "scroll", "load", "pagehide", "destroy"]) {
			const f = fixture(kind);
			f.play({ rect: { x: 0, y: -100, width: 1000, height: 300 } });
			const [player] = f.players;
			assert.equal(player.style.clipPath, "inset(50px 0px 0px 0px)");
			if (cause === "timeout") for (const fn of [...f.timers.values()]) fn();
			if (cause === "error") player.dispatchEvent(new Event("error"));
			if (cause === "scroll") f.play({ event: "scroll" });
			if (cause === "load") f.frame.dispatchEvent(new Event("load"));
			if (cause === "pagehide") f.window.dispatchEvent(new Event("pagehide"));
			if (cause === "destroy") f.controller.destroy();
			assert.equal(player.removed, true, cause);
			const expected = cause === "timeout" || cause === "error" ? "failed" : "closed";
			assert.ok(
				f.states.some(([data]) => data.state === expected),
				`${cause} reports ${expected}`,
			);
			assert.equal(f.timers.size, 0);
			f.ready();
			assert.equal(player.focused, undefined);
			f.controller.destroy();
		}
	});

	test(`${kind} shell removes the player when the page reports its poster gone`, () => {
		const f = fixture(kind);
		f.play();
		const [player] = f.players;
		f.ready();
		f.play({ event: "vimeo-close", request: "2" });
		assert.equal(player.removed, undefined, "another request cannot close it");
		f.play({ event: "vimeo-close", request: "2" }, { origin: "https://evil.test" });
		f.play({ event: "vimeo-close" });
		assert.equal(player.removed, true);
		assert.equal(f.states.at(-1)[0].state, "closed");
		f.controller.destroy();
	});

	test(`${kind} shell follows the poster as the page reports its moves and keeps playing while hidden`, () => {
		const f = fixture(kind);
		f.play();
		const [player] = f.players;
		f.ready();
		// The page scrolled: the poster now starts above the frame and the player follows, clipped.
		f.play({ event: "vimeo-move", rect: { x: 100, y: -50, width: 600, height: 300 } });
		assert.deepEqual(
			[player.style.left, player.style.top, player.style.clipPath],
			["70px", "15px", "inset(25px 0px 0px 0px)"],
		);
		assert.equal(player.removed, undefined, "a move keeps the player");
		// Moves of another request, of a bad rectangle or from a stranger are ignored.
		for (const [patch, event] of [
			[
				{
					event: "vimeo-move",
					request: "2",
					rect: { x: 0, y: 0, width: 600, height: 300 },
				},
				{},
			],
			[{ event: "vimeo-move", rect: { x: 0, y: 0, width: 0, height: 300 } }, {}],
			[
				{ event: "vimeo-move", rect: { x: 0, y: 0, width: 600, height: 300 } },
				{ origin: "https://evil.test" },
			],
		])
			f.play(patch, event);
		assert.equal(player.style.left, "70px");
		// The shell's own window resizing does not end the player; the page reports its rectangle.
		f.window.dispatchEvent(new Event("resize"));
		assert.equal(player.removed, undefined);
		// A hidden viewer leaves the player playing, as the published site does.
		f.document.hidden = true;
		f.document.dispatchEvent(new Event("visibilitychange"));
		assert.equal(player.removed, undefined);
		assert.deepEqual(f.told, [], "the shell never pauses or resumes the provider");
		f.document.hidden = false;
		f.document.dispatchEvent(new Event("visibilitychange"));
		assert.equal(player.removed, undefined);
		assert.ok(
			!f.states.some(([data]) => data.state === "failed" || data.state === "closed"),
		);
		f.controller.destroy();
		assert.equal(player.removed, true);
	});
}
