import assert from "node:assert/strict";
import test from "node:test";
import {
	frameDimensions,
	frameImage,
	videoLimitProblem,
} from "../../engine/src/cms/conventional/client/video-previews.js";

test("video frame capture bounds geometry without enlarging small clips", () => {
	assert.deepEqual(frameDimensions(1920, 1080), [320, 180]);
	assert.deepEqual(frameDimensions(90, 160), [90, 160]);
	assert.deepEqual(frameDimensions(1, 4096), [1, 320]);
});

test("frame capture waits for decoded pixels and rejects unsupported or oversized encodings", () => {
	const video = { readyState: 1, videoWidth: 160, videoHeight: 90 };
	assert.equal(frameImage(video), null);
	video.readyState = 2;
	const canvas = {
		getContext: () => ({ drawImage: () => {} }),
		toDataURL: () => "data:image/png;base64,AAAA",
	};
	const document = { createElement: () => canvas };
	assert.equal(frameImage(video, document), null);
	canvas.toDataURL = () => `data:image/webp;base64,${"A".repeat(90000)}`;
	assert.equal(frameImage(video, document), null);
	canvas.toDataURL = () => "data:image/webp;base64,UklGRg==";
	assert.equal(frameImage(video, document), "UklGRg==");
	assert.equal(canvas.width, 160);
	assert.equal(canvas.height, 90);
});

test("a measured video is checked against its field before uploading, with the numbers to say it", () => {
	const limits = { duration: 15, width: 1920, height: 1080 };
	assert.equal(
		videoLimitProblem({ duration: 15.02, width: 1920, height: 1080 }, limits),
		null,
		"a container's rounding is not a longer video",
	);
	assert.deepEqual(
		videoLimitProblem({ duration: 20, width: 640, height: 360 }, limits),
		{
			key: "duration",
			limit: 15,
			actual: 20,
		},
	);
	assert.deepEqual(
		videoLimitProblem({ duration: 4, width: 2560, height: 1440 }, limits),
		{ key: "width", limit: 1920, actual: 2560 },
		"the first broken limit is said",
	);
	assert.deepEqual(
		videoLimitProblem({ duration: 4, width: 1080, height: 1920 }, limits),
		{ key: "height", limit: 1080, actual: 1920 },
	);
	assert.equal(
		videoLimitProblem({ duration: null, width: 0, height: 0 }, {}),
		null,
		"a field without limits, or a video the browser cannot read, is left to the server",
	);
});
