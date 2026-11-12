import assert from "node:assert/strict";
import test from "node:test";
import { reducePreview } from "../../engine/src/cms/conventional/client/composition-model.js";

test("composition preview serializes navigations and replaces only the pending intent", () => {
	let state = { active: null, latest: 0, phase: "idle" };
	let result = reducePreview(state, { type: "requested", requestId: 1 });
	assert.deepEqual(result.effects, [{ type: "navigate", requestId: 1 }]);
	state = result.state;
	for (const requestId of [2, 3, 4]) {
		result = reducePreview(state, { type: "requested", requestId });
		assert.deepEqual(result.effects, []);
		state = result.state;
	}
	result = reducePreview(state, { type: "settled", requestId: 1, failed: true });
	assert.deepEqual(result.effects, [{ type: "navigate", requestId: 4 }]);
	assert.equal(result.state.phase, "loading");
	assert.deepEqual(
		reducePreview(result.state, { type: "settled", requestId: 3, failed: false }),
		{ state: result.state, effects: [] },
	);
	result = reducePreview(result.state, {
		type: "settled",
		requestId: 4,
		failed: false,
	});
	assert.equal(result.state.phase, "ready");
	assert.equal(result.state.active, null);
});

test("preview failure is recoverable and disposal is terminal", () => {
	let result = reducePreview(
		{ active: null, latest: 0, phase: "idle" },
		{ type: "requested", requestId: 1 },
	);
	result = reducePreview(result.state, { type: "settled", requestId: 1, failed: true });
	assert.equal(result.state.phase, "error");
	result = reducePreview(result.state, { type: "requested", requestId: 2 });
	assert.equal(result.state.phase, "loading");
	result = reducePreview(result.state, { type: "disposed" });
	assert.deepEqual(reducePreview(result.state, { type: "requested", requestId: 3 }), {
		state: result.state,
		effects: [],
	});
});
