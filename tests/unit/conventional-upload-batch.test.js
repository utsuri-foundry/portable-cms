import assert from "node:assert/strict";
import test from "node:test";

import {
	uploadFileMaximum,
	uploadThenProcessBatch,
	watchPreparation,
} from "../../engine/src/cms/conventional/client/resumable-upload.js";

test("upload preflight selects the server image or video ceiling before creating a session", () => {
	const limits = { imageMaxBytes: "41943040", videoMaxBytes: "268435456" };
	assert.equal(
		uploadFileMaximum({ name: "trailer.mp4", type: "video/mp4" }, limits),
		268435456,
	);
	assert.equal(
		uploadFileMaximum({ name: "TRAILER.WEBM", type: "" }, limits),
		268435456,
	);
	assert.equal(
		uploadFileMaximum({ name: "poster.jpg", type: "image/jpeg" }, limits),
		41943040,
	);
	assert.equal(
		uploadFileMaximum({ name: "poster.mp4", type: "image/jpeg" }, limits),
		41943040,
	);
	assert.equal(
		uploadFileMaximum(
			{ name: "trailer.mp4", type: "video/mp4" },
			{ ...limits, videoMaxBytes: "41943040" },
		),
		41943040,
	);
});

test("conventional image batches queue every upload before waiting for derivatives", async () => {
	const calls = [];
	const files = [{ name: "one.jpg" }, { name: "two.jpg" }, { name: "three.jpg" }];
	const result = await uploadThenProcessBatch({
		files,
		async upload(file) {
			calls.push(`upload:${file.name}`);
			return { job: { id: `job-${file.name}` } };
		},
		async wait(jobId) {
			calls.push(`wait:${jobId}`);
			return true;
		},
	});

	assert.deepEqual(calls, [
		"upload:one.jpg",
		"upload:two.jpg",
		"upload:three.jpg",
		"wait:job-one.jpg",
		"wait:job-two.jpg",
		"wait:job-three.jpg",
	]);
	assert.deepEqual(result, {
		queuedCount: 3,
		readyCount: 3,
		everyDerivativeReady: true,
	});
});

test("conventional image batches do not start derivative waits after an upload failure", async () => {
	const calls = [];
	const completedUploads = [];
	await assert.rejects(
		uploadThenProcessBatch({
			files: [{ name: "one.jpg" }, { name: "broken.jpg" }, { name: "three.jpg" }],
			async upload(file) {
				calls.push(`upload:${file.name}`);
				if (file.name === "broken.jpg") throw new Error("upload failed");
				return {
					job: { id: `job-${file.name}` },
					upload: { id: `upload-${file.name}` },
				};
			},
			onQueued: ({ completed }) => completedUploads.push(completed.upload.id),
			async wait(jobId) {
				calls.push(`wait:${jobId}`);
				return true;
			},
		}),
		/upload failed/,
	);

	assert.deepEqual(calls, ["upload:one.jpg", "upload:broken.jpg"]);
	assert.deepEqual(
		completedUploads,
		["upload-one.jpg"],
		"partial failure retains completed receipts in upload order",
	);
});

function waitingRow(id) {
	const events = [];
	const state = {
		textContent: "Being prepared.",
		className: "admin-meta",
		removed: false,
		remove() {
			this.removed = true;
		},
	};
	const thumbnail = {
		textContent: "…",
		replacement: null,
		replaceWith(node) {
			this.replacement = node;
		},
	};
	const input = {
		dispatchEvent(event) {
			events.push(event);
		},
	};
	const row = {
		dataset: { mediaPending: id },
		ownerDocument: {
			createElement: (tag) => ({ tag }),
			defaultView: {
				Event: class {
					constructor(type, init) {
						this.type = type;
						this.bubbles = init.bubbles;
					}
				},
			},
		},
		querySelector(selector) {
			return (
				{
					"[data-media-state]": state.removed ? null : state,
					".admin-thumbnail": thumbnail,
					"input[type=hidden]": input,
				}[selector] ?? null
			);
		},
	};
	return { row, state, thumbnail, events };
}

test("rows of images being prepared update themselves until each is ready or failed", async () => {
	const first = waitingRow("a".repeat(32));
	const second = waitingRow("b".repeat(32));
	const root = {
		querySelectorAll: () =>
			[first, second].filter((r) => r.row.dataset.mediaPending).map((r) => r.row),
	};
	const timers = [];
	const requests = [];
	const answers = [
		{
			poll: 15,
			items: [
				{
					id: "a".repeat(32),
					state: "ready",
					text: null,
					thumbnail: "/media/a/thumbnail",
				},
				{
					id: "b".repeat(32),
					state: "preparing",
					text: "Being prepared. Ready in about 4 minutes.",
					thumbnail: null,
				},
			],
		},
		{
			poll: 15,
			items: [
				{
					id: "b".repeat(32),
					state: "failed",
					text: "This file could not be prepared. Upload it again.",
					thumbnail: null,
				},
			],
		},
	];
	watchPreparation(root, {
		request: async (url) => {
			requests.push(url);
			return { ok: true, json: async () => answers.shift() };
		},
		schedule: (callback, delay) => timers.push({ callback, delay }),
	});
	assert.equal(timers.length, 1);
	assert.equal(timers[0].delay, 3000, "the first check comes after a short wait");
	await timers[0].callback();
	assert.equal(requests[0], `/media/status?ids=${"a".repeat(32)},${"b".repeat(32)}`);
	assert.equal(first.thumbnail.replacement.src, "/media/a/thumbnail");
	assert.equal(first.state.removed, true, "a ready image carries no state word");
	assert.deepEqual(
		first.events.map((event) => [event.type, event.bubbles]),
		[["change", true]],
		"the ready image is offered to record keeping",
	);
	assert.equal(second.state.textContent, "Being prepared. Ready in about 4 minutes.");
	assert.equal(timers[1].delay, 15000, "the server sets how often to ask");
	await timers[1].callback();
	assert.equal(requests[1], `/media/status?ids=${"b".repeat(32)}`);
	assert.equal(second.state.className, "admin-field__error");
	assert.equal(second.thumbnail.textContent, "!");
	assert.equal(second.events.length, 0, "a failed image is never kept");
	assert.equal(timers.length, 2, "nothing is left to ask about");
});
