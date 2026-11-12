import assert from "node:assert/strict";
import test from "node:test";
import { recordValues } from "../../engine/src/cms/conventional/client/record-autosave.js";
import { createRecordKeeper } from "../../engine/src/cms/conventional/client/record-keeper.js";

test("record keeping ignores transient picker input, upload files and request authority", () => {
	const values = new URLSearchParams(
		recordValues([
			["title__en", "An authored title"],
			["_present_gallery", "1"],
			["gallery[]", "selected-image"],
			["_editorial_end", "1"],
			["_csrf", "private-csrf"],
			["_revision", "7"],
			["_edit_sequence", "22"],
			["_query_gallery", "A library search"],
			["_pick_gallery[]", "uncommitted-choice"],
			["_page_gallery", "3"],
			["original", new Blob(["uncommitted upload"])],
		]),
	);
	assert.deepEqual(
		[...values],
		[
			["title__en", "An authored title"],
			["_present_gallery", "1"],
			["gallery[]", "selected-image"],
			["_editorial_end", "1"],
		],
	);
});

function fixture(write = async () => ({ errors: {} })) {
	let value = "initial";
	let id = 0;
	const timers = [];
	const calls = [];
	const states = [];
	const saved = [];
	const conflicts = [];
	const keeper = createRecordKeeper({
		read: () => value,
		write: async (...args) => {
			calls.push(args);
			return write(...args);
		},
		identify: () => `request-${++id}`,
		onState: (...args) => states.push(args),
		onSaved: (result) => saved.push(result),
		onConflict: (result) => conflicts.push(result),
		schedule: (callback, delay) => {
			const entry = { callback, delay };
			timers.push(entry);
			return entry;
		},
		cancel: (entry) => {
			if (entry) entry.cancelled = true;
		},
	});
	return {
		keeper,
		timers,
		calls,
		states,
		saved,
		conflicts,
		change(next) {
			value = next;
			keeper.change();
		},
	};
}

test("record keeping debounces to 1.5 seconds and leaves clean forms untouched", async () => {
	const f = fixture();
	assert.equal(await f.keeper.flush(), true);
	assert.equal(f.calls.length, 0);
	f.change("one");
	f.change("two");
	assert.equal(f.timers[0].cancelled, true);
	assert.equal(f.timers.at(-1).delay, 1500);
	assert.equal(await f.keeper.flush(), true);
	assert.equal(f.calls.length, 1);
	assert.equal(f.calls[0][0], "two");
	assert.equal(f.keeper.dirty(), false);
	f.keeper.stop();
});

test("reverting a change before its save clears the pending indicator without a request", () => {
	const f = fixture();
	f.change("brief edit");
	f.change("initial");
	assert.equal(f.keeper.dirty(), false);
	assert.equal(f.timers.at(-1).cancelled, true);
	assert.deepEqual(f.states.at(-1), ["saved"]);
	assert.equal(f.calls.length, 0);
	f.keeper.stop();
});

test("native Save cancels queued automatic work and settles an in-flight write without saving twice", async () => {
	const queued = fixture();
	queued.change("the native form owns these values");
	await queued.keeper.settleForNativeSave();
	assert.equal(queued.calls.length, 0);
	assert.equal(queued.timers.at(-1).cancelled, true);
	let resolve;
	const inflight = fixture(
		() =>
			new Promise((done) => {
				resolve = done;
			}),
	);
	inflight.change("first");
	void inflight.keeper.flush();
	inflight.change("second goes in the native form");
	const settled = inflight.keeper.settleForNativeSave();
	resolve({ errors: {} });
	await settled;
	assert.equal(inflight.calls.length, 1);
	assert.equal(
		inflight.saved.length,
		1,
		"in-flight acknowledgement updates the native concurrency token",
	);
});

test("record keeping serializes writes and keeps edits typed during an in-flight request", async () => {
	let resolve;
	const f = fixture(
		() =>
			new Promise((done) => {
				resolve = done;
			}),
	);
	f.change("one");
	const first = f.keeper.flush();
	assert.equal(f.keeper.flush(), first);
	f.change("two");
	resolve({ errors: {} });
	await new Promise(setImmediate);
	assert.equal(f.calls.length, 2);
	assert.equal(f.calls[1][0], "two");
	resolve({ errors: {} });
	assert.equal(await first, true);
	f.keeper.stop();
});

test("a correction typed while invalid input is in flight is sent even after its debounce fires", async () => {
	let resolve;
	const f = fixture(
		() =>
			new Promise((done) => {
				resolve = done;
			}),
	);
	f.change("invalid value");
	const saving = f.keeper.flush();
	f.change("corrected value");
	assert.equal(
		f.keeper.flush(),
		saving,
		"the correction's timer shares the existing request",
	);
	resolve({ errors: { count: "Invalid old value" } });
	await new Promise(setImmediate);
	assert.equal(f.calls.length, 2);
	assert.equal(f.calls[1][0], "corrected value");
	resolve({ errors: {} });
	assert.equal(await saving, true);
	f.keeper.stop();
});

test("lost responses retry the identical request before sending newer local input", async () => {
	let fail = true;
	const f = fixture(async () => {
		if (fail) throw Error("offline");
		return { errors: {} };
	});
	f.change("server may have received this");
	assert.equal(await f.keeper.flush(), false);
	assert.equal(f.keeper.dirty(), true);
	assert.equal(f.timers.at(-1).delay, 1000);
	f.change("newer input");
	fail = false;
	assert.equal(await f.keeper.flush(), true);
	assert.deepEqual(f.calls[1].slice(0, 2), f.calls[0].slice(0, 2));
	assert.equal(f.calls[2][0], "newer input");
	assert.notEqual(f.calls[2][1], f.calls[0][1]);
	f.keeper.stop();
});

test("invalid fields remain pending without a retry loop while valid edits receive their acknowledgement", async () => {
	let errors = { count: "Not a number" };
	const f = fixture(async () => ({ errors }));
	f.change("invalid count and valid title");
	assert.equal(await f.keeper.flush(), false);
	assert.equal(f.saved.length, 1);
	assert.equal(f.keeper.dirty(), true);
	assert.deepEqual(f.states.at(-1), ["error", "Not a number"]);
	assert.equal(f.timers.filter((entry) => !entry.cancelled).length, 0);
	errors = {};
	f.change("corrected value");
	assert.equal(await f.keeper.flush(), true);
	f.keeper.stop();
});

test("preparing uploaded media retries with new request identities and acknowledged fields do not block another upload", async () => {
	let preparing = true;
	const f = fixture(async () => ({
		errors: preparing ? { gallery: "Preparing images" } : {},
		retryable: preparing,
	}));
	f.change("one uploaded image waiting for preparation");
	assert.equal(await f.keeper.flush(), false);
	assert.equal(f.keeper.dirty(), true);
	assert.equal(
		f.keeper.unacknowledged(),
		false,
		"other fields and the concurrency token are acknowledged",
	);
	assert.equal(f.timers.at(-1).delay, 1000);
	await f.keeper.flush();
	assert.equal(f.timers.at(-1).delay, 2000);
	preparing = false;
	assert.equal(await f.keeper.flush(), true);
	assert.equal(
		new Set(f.calls.map((call) => call[1])).size,
		3,
		"acknowledged partial saves retry readiness with a fresh identity",
	);
	f.keeper.stop();
});

test("conflicts stop all writes and preserve the current local input for the native conflict view", async () => {
	const f = fixture(async () => ({ conflict: true }));
	f.change("my local words");
	assert.equal(await f.keeper.flush(), false);
	assert.equal(f.conflicts.length, 1);
	f.change("still local");
	assert.equal(await f.keeper.retry(), false);
	assert.equal(f.calls.length, 1);
	assert.equal(f.keeper.dirty(), true);
});

test("network retry backoff is bounded and hiding the page requests keepalive", async () => {
	const f = fixture(async () => {
		throw Error("offline");
	});
	f.change("unsent");
	for (let attempt = 0; attempt < 8; attempt++) await f.keeper.flush(true);
	assert.equal(
		f.calls.every((call) => call[2] === true),
		true,
	);
	assert.equal(f.timers.at(-1).delay, 30000);
	assert.equal(new Set(f.calls.map((call) => call[1])).size, 1);
	f.keeper.stop();
});
