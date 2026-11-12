import assert from "node:assert/strict";
import test from "node:test";

import {
	uploadFileMaximum,
	uploadThenProcessBatch,
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
