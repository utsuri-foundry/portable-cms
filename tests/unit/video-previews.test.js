import assert from "node:assert/strict";
import test from "node:test";
import {
	frameDimensions,
	frameImage,
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
