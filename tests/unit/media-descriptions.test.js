import assert from "node:assert/strict";
import test from "node:test";
import { mountMediaDescriptions } from "../../engine/src/cms/conventional/client/media-descriptions.js";
import {
	descriptionState,
	reduceDescriptions,
} from "../../engine/src/cms/conventional/client/media-descriptions-model.js";

test("description requests reject stale reads and keep later images and edits after a save finishes", () => {
	let state = descriptionState();
	const send = (event) => {
		const next = reduceDescriptions(state, event);
		state = next.state;
		return next.effects;
	};
	assert.deepEqual(send({ type: "load", id: "first", requestId: 1 }), [
		{ type: "load", id: "first", requestId: 1 },
	]);
	assert.deepEqual(send({ type: "loaded", id: "first", requestId: 99, item: {} }), []);
	assert.deepEqual(state.ids, []);
	assert.deepEqual(
		send({ type: "save", requestId: 2 }),
		[],
		"a description cannot save before its real revision is read",
	);
	send({ type: "loaded", id: "first", requestId: 1, item: { id: "first" } });
	assert.deepEqual(send({ type: "save", requestId: 2 }), [
		{ type: "save", requestId: 2 },
	]);
	send({ type: "load", id: "second", requestId: 3 });
	send({ type: "loaded", id: "second", requestId: 3, item: { id: "second" } });
	send({ type: "saved", requestId: 2, success: true, items: [] });
	assert.notEqual(
		state.version,
		state.savedVersion,
		"a newly uploaded image is not part of the earlier save",
	);
	assert.deepEqual(send({ type: "load", id: "third", requestId: 4 }), [
		{ type: "load", id: "third", requestId: 4 },
	]);
	assert.deepEqual(
		state.ids,
		["first", "second"],
		"the new batch must not discard unsaved descriptions",
	);
	send({ type: "load-failed", id: "third", requestId: 4 });
	send({ type: "save", requestId: 5 });
	send({ type: "edit" });
	send({ type: "saved", requestId: 5, success: true, items: [] });
	assert.notEqual(
		state.version,
		state.savedVersion,
		"a late success never claims later edits were saved",
	);
	send({ type: "save", requestId: 6 });
	assert.deepEqual(
		send({ type: "save-failed", requestId: 5 }),
		[],
		"old failures cannot replace the current request",
	);
	send({ type: "saved", requestId: 6, success: true, items: [] });
	assert.deepEqual(send({ type: "load", id: "next", requestId: 7 }), [
		{ type: "clear" },
		{ type: "load", id: "next", requestId: 7 },
	]);
});

test("description state bounds pending images and disposal invalidates every completion", () => {
	let state = descriptionState();
	for (let index = 0; index < 8; index++)
		state = reduceDescriptions(state, {
			type: "load",
			id: String(index),
			requestId: index,
		}).state;
	assert.deepEqual(
		reduceDescriptions(state, { type: "load", id: "extra", requestId: 9 }).effects,
		[{ type: "load-failed" }],
	);
	assert.deepEqual(
		reduceDescriptions(state, { type: "load", id: "0", requestId: 10 }).effects,
		[],
		"a resumed upload does not duplicate controls",
	);
	const disposed = reduceDescriptions(state, { type: "dispose" });
	assert.deepEqual(disposed.effects, [{ type: "dispose" }]);
	for (const type of [
		"loaded",
		"load-failed",
		"saved",
		"save-failed",
		"dispose",
		"load",
		"save",
	]) {
		assert.deepEqual(
			reduceDescriptions(disposed.state, { type, id: "0", requestId: 0 }),
			{ state: disposed.state, effects: [] },
		);
	}
});

test("description island disposal aborts reads, clears timers and removes listeners on repeated mounts", async () => {
	class Target extends EventTarget {
		listeners = 0;
		addEventListener(...args) {
			this.listeners++;
			super.addEventListener(...args);
		}
		removeEventListener(...args) {
			this.listeners--;
			super.removeEventListener(...args);
		}
	}
	const form = new Target();
	const section = new Target();
	const status = { append: () => {} };
	const button = { disabled: false };
	let inserted = 0;
	const cards = {
		querySelectorAll: () => [],
		append: () => {
			inserted++;
		},
	};
	section.closest = () => section;
	section.dataset = { messages: "{}" };
	section.ownerDocument = { documentElement: { lang: "en" } };
	section.querySelector = (selector) =>
		({
			"[data-descriptions-status]": status,
			"[data-description-cards]": cards,
			"button[type=submit]": button,
		})[selector];
	const panel = {
		ownerDocument: { getElementById: () => form, createElement: () => ({}) },
		querySelector: (selector) =>
			selector === "[data-upload-descriptions]" ? section : {},
	};
	for (let iteration = 0; iteration < 3; iteration++) {
		const timers = new Map();
		let finish;
		let signal;
		const island = mountMediaDescriptions(panel, {
			fetcher: (_url, options) => {
				signal = options.signal;
				return new Promise((resolve) => {
					finish = resolve;
				});
			},
			schedule: (callback, milliseconds) => {
				assert.equal(milliseconds, 15000);
				timers.set(1, callback);
				return 1;
			},
			cancelTimer: (id) => timers.delete(id),
		});
		const id = "a".repeat(32);
		const pending = island.add({ id, declaredType: "image/webp" });
		assert.equal(timers.size, 1);
		assert.equal(form.listeners + section.listeners, 2);
		island.dispose();
		island.dispose();
		assert.equal(signal.aborted, true);
		assert.equal(timers.size, 0);
		assert.equal(form.listeners + section.listeners, 0);
		assert.equal(button.disabled, false);
		finish({ ok: true, json: async () => ({ items: [{ id }] }) });
		await pending;
		assert.equal(
			inserted,
			0,
			"a response arriving after disposal cannot insert controls",
		);
	}
	const timers = new Map();
	const island = mountMediaDescriptions(panel, {
		fetcher: (_url, { signal }) =>
			new Promise((_resolve, reject) => {
				signal.addEventListener("abort", () => reject(new Error("request timed out")), {
					once: true,
				});
			}),
		schedule: (callback) => {
			timers.set(1, callback);
			return 1;
		},
		cancelTimer: (id) => timers.delete(id),
	});
	const pending = island.add({ id: "b".repeat(32), declaredType: "image/webp" });
	timers.get(1)();
	await pending;
	assert.equal(
		status.textContent,
		"load_failed",
		"timed-out hydration leaves a native library recovery link",
	);
	assert.equal(button.disabled, false);
	assert.equal(timers.size, 0);
	island.dispose();
	assert.equal(form.listeners + section.listeners, 0);
});
