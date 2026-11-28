import assert from "node:assert/strict";
import test from "node:test";
import { mountMediaDescriptions } from "../../engine/src/cms/conventional/client/media-descriptions.js";
import {
	descriptionState,
	reduceDescriptions,
} from "../../engine/src/cms/conventional/client/media-descriptions-model.js";

test("description requests reject stale reads and a new batch keeps cards that are not described yet", () => {
	let state = descriptionState();
	const send = (event) => {
		const next = reduceDescriptions(state, event);
		state = next.state;
		return next.effects;
	};
	assert.deepEqual(send({ type: "load", id: "first", requestId: 1, kept: true }), [
		{ type: "load", id: "first", requestId: 1 },
	]);
	assert.deepEqual(send({ type: "loaded", id: "first", requestId: 99, item: {} }), []);
	assert.deepEqual(state.ids, []);
	send({ type: "edit", id: "first" });
	assert.deepEqual(
		state.edited,
		[],
		"a card cannot be edited before its revision is read",
	);
	send({ type: "loaded", id: "first", requestId: 1, item: { id: "first" } });
	send({ type: "load", id: "second", requestId: 2, kept: true });
	send({ type: "loaded", id: "second", requestId: 2, item: { id: "second" } });
	assert.deepEqual(state.ids, ["first", "second"]);
	send({ type: "edit", id: "first" });
	send({ type: "edit", id: "first" });
	assert.deepEqual(state.edited, ["first"], "only written images are kept");
	assert.deepEqual(
		send({ type: "load", id: "third", requestId: 3, kept: true }),
		[{ type: "load", id: "third", requestId: 3 }],
		"an image nobody described yet stays on screen",
	);
	send({ type: "load-failed", id: "third", requestId: 3 });
	send({ type: "edit", id: "second" });
	assert.deepEqual(
		send({ type: "load", id: "fourth", requestId: 4, kept: false }),
		[{ type: "load", id: "fourth", requestId: 4 }],
		"descriptions still being kept are never cleared",
	);
	send({ type: "load-failed", id: "fourth", requestId: 4 });
	assert.deepEqual(send({ type: "load", id: "next", requestId: 5, kept: true }), [
		{ type: "clear" },
		{ type: "load", id: "next", requestId: 5 },
	]);
	assert.deepEqual(state.edited, []);
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
	for (const type of ["loaded", "load-failed", "edit", "dispose", "load"]) {
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
	const button = { hidden: false };
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
		ownerDocument: {
			getElementById: () => form,
			createElement: () => ({}),
			documentElement: { lang: "en" },
		},
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
		assert.equal(
			button.hidden,
			true,
			"scripts keep descriptions without a Save button",
		);
		const id = "a".repeat(32);
		const pending = island.add({ id, declaredType: "image/webp" });
		assert.equal(timers.size, 1);
		assert.equal(form.listeners + section.listeners, 4);
		island.dispose();
		island.dispose();
		assert.equal(signal.aborted, true);
		assert.equal(timers.size, 0);
		assert.equal(form.listeners + section.listeners, 0);
		assert.equal(button.hidden, false, "the native Save returns without scripts");
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
	assert.equal(timers.size, 0);
	island.dispose();
	assert.equal(form.listeners + section.listeners, 0);
});

/** One field card, its row line elsewhere on the page and the separate description form. */
function describedPage(respond) {
	const id = "c".repeat(32);
	const element = (properties = {}) => ({
		hidden: false,
		textContent: "",
		className: "",
		attributes: {},
		children: [],
		setAttribute(name, value) {
			this.attributes[name] = value;
		},
		append(...nodes) {
			this.children.push(...nodes);
		},
		...properties,
	});
	const revision = element({ value: "3" });
	const report = element({ hidden: true });
	const card = element({
		dataset: { mediaDescription: id },
		querySelector: (selector) =>
			({
				"[data-description-revision]": revision,
				"[data-description-result]": report,
			})[selector] ?? null,
	});
	const row = element({ className: "admin-field__error" });
	const status = element();
	const button = element();
	const cards = {
		querySelectorAll: () => [card],
		querySelector: (selector) =>
			selector === `[data-media-description="${id}"]` ? card : null,
	};
	const section = new EventTarget();
	section.dataset = {
		descriptionsForm: "media-descriptions-form-still",
		messages: JSON.stringify({
			"keep.saving": "Saving",
			"keep.saved": "Saved at {time}",
			"keep.failed": "Not saved: {reason}",
			"keep.network": "The connection failed.",
			"keep.sign_in": "Sign in again.",
		}),
	};
	section.closest = () => section;
	section.querySelector = (selector) =>
		({
			"[data-descriptions-status]": status,
			"[data-description-cards]": cards,
			"button[type=submit]": button,
		})[selector];
	const form = new EventTarget();
	form.action = "/media/descriptions";
	form.elements = {
		namedItem: (name) => (name === "_csrf" ? { value: "token" } : null),
	};
	const document = {
		documentElement: { lang: "en" },
		getElementById: () => form,
		querySelectorAll: (selector) =>
			selector === `[data-description-warning="${id}"]` ? [row] : [],
		createElement: () => element(),
	};
	section.ownerDocument = document;
	const panel = {
		ownerDocument: document,
		querySelector: (selector) =>
			selector === "[data-upload-descriptions]" ? section : { content: null },
	};
	const timers = [];
	const sent = [];
	let text = "";
	const island = mountMediaDescriptions(panel, {
		fetcher: async (url, options) => {
			sent.push({ url, body: new URLSearchParams(options.body) });
			return respond(sent.length);
		},
		schedule: (callback, milliseconds) => {
			timers.push({ callback, milliseconds });
			return timers.length;
		},
		cancelTimer: () => {},
		now: () => new Date(2026, 10, 28, 9, 5),
		entries: () => [
			[`description_${id}__alternativeText__ca`, text],
			[`description_${id}__revision`, revision.value],
			["_csrf", "token"],
		],
	});
	const input = (value) => {
		text = value;
		const event = new Event("input");
		Object.defineProperty(event, "target", { value: { closest: () => card } });
		section.dispatchEvent(event);
	};
	// Runs the keeper's pending wait (never a request's own timeout) and lets its promises settle.
	const settle = async () => {
		const due = timers.filter((timer) => timer.milliseconds !== 15000);
		timers.length = 0;
		for (const timer of due) timer.callback();
		for (let turn = 0; turn < 20; turn++) await Promise.resolve();
	};
	return { id, island, input, settle, sent, status, button, report, row, revision };
}

test("descriptions keep themselves like the record: Saving, Saved at, a quiet retry and a refusal in its card", async () => {
	const item = (fields) => ({
		id: "c".repeat(32),
		warning: "",
		error: null,
		...fields,
	});
	const responses = [
		() => ({
			ok: true,
			status: 200,
			json: async () => ({ items: [item({ revision: 4 })] }),
		}),
		() => ({
			ok: false,
			status: 409,
			json: async () => ({
				items: [
					item({
						revision: 6,
						error: "Someone else changed this image.",
						text: {
							decorative: false,
							translations: [
								{
									locale: "ca",
									alternativeText: "Their own words",
									caption: "",
									credit: "",
								},
							],
						},
					}),
				],
			}),
		}),
		() => {
			throw new TypeError("Failed to fetch");
		},
		() => ({
			ok: true,
			status: 200,
			json: async () => ({ items: [item({ revision: 7, warning: "Describe it." })] }),
		}),
		() => {
			throw new TypeError("Failed to fetch");
		},
		() => ({
			ok: false,
			status: 409,
			json: async () => ({
				items: [
					item({
						revision: 8,
						error: "Someone else changed this image.",
						text: {
							decorative: false,
							translations: [
								{
									locale: "ca",
									alternativeText: "Kept before the answer was lost",
									caption: "",
									credit: "",
								},
							],
						},
					}),
				],
			}),
		}),
	];
	const page = describedPage((count) => responses[count - 1]());
	assert.equal(page.button.hidden, true);
	page.input("A still from the film");
	assert.equal(page.status.textContent, "Saving");
	await page.settle();
	assert.equal(page.sent.length, 1);
	const first = page.sent[0].body;
	assert.deepEqual(first.getAll("media_ids[]"), [page.id]);
	assert.equal(
		first.get(`description_${page.id}__alternativeText__ca`),
		"A still from the film",
	);
	assert.equal(first.get(`description_${page.id}__revision`), "3");
	assert.equal(first.get("_csrf"), "token");
	assert.equal(first.get("_descriptions_end"), "1");
	assert.equal(page.status.textContent, "Saved at 09:05");
	assert.equal(page.revision.value, "4");
	assert.equal(
		page.row.hidden,
		true,
		"the field row says at once the image is described",
	);
	assert.equal(page.report.hidden, true, "a kept card says nothing of its own");
	// A refusal reads like a field error in its card, not twice in the status line.
	page.input("A still, revised");
	await page.settle();
	assert.equal(page.report.hidden, false);
	assert.equal(page.report.className, "admin-field__error");
	assert.equal(page.report.textContent, "Someone else changed this image.");
	assert.equal(page.status.textContent, "");
	assert.equal(page.revision.value, "6");
	// A dropped connection says so and tries again by itself.
	page.input("A still, again");
	await page.settle();
	assert.equal(page.status.textContent, "Not saved: The connection failed.");
	await page.settle();
	assert.equal(page.sent.length, 4, "the retry needed no action");
	assert.equal(page.sent[3].body.get(`description_${page.id}__revision`), "6");
	assert.equal(page.status.textContent, "Saved at 09:05");
	assert.equal(page.report.hidden, true);
	assert.equal(page.row.hidden, false);
	assert.equal(page.row.textContent, "Describe it.");
	// A lost answer whose save did happen is not someone else's change on the retry.
	page.input("Kept before the answer was lost");
	await page.settle();
	await page.settle();
	assert.equal(page.sent.length, 6);
	assert.equal(page.report.hidden, true);
	assert.equal(page.status.textContent, "Saved at 09:05");
	assert.equal(page.revision.value, "8");
	page.island.dispose();
	assert.equal(page.button.hidden, false);
});
