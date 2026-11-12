import assert from "node:assert/strict";
import test from "node:test";
import { waitForPrerenderActivation } from "../public-site/prerender-activation-poll.mjs";

const replacement = () =>
	new Error(
		'Runtime.evaluate: {"code":-32000,"message":"Inspected target navigated or closed"}',
	);

test("activation retries target replacement and unready responses", async () => {
	const outcomes = [replacement(), false, replacement(), true];
	const waits = [];
	let calls = 0;
	const result = await waitForPrerenderActivation(
		async () => {
			const value = outcomes[calls++];
			if (value instanceof Error) throw value;
			return value;
		},
		[],
		async (ms) => waits.push(ms),
	);
	assert.equal(result, true);
	assert.equal(calls, 4);
	assert.deepEqual(waits, [100, 100, 100]);
});

for (const message of [
	'Runtime.evaluate: {"code":-32000,"message":"Execution context was destroyed"}',
	'Page.navigate: {"code":-32000,"message":"Inspected target navigated or closed"}',
	"Inspected target navigated or closed",
	"Runtime.evaluate: Inspected target navigated or closed",
	'Runtime.evaluate: {"message":"Inspected target navigated or closed unexpectedly"}',
	"ReferenceError",
]) {
	test(`activation fails immediately on ${message}`, async () => {
		const failure = new Error(message);
		let calls = 0;
		let waits = 0;
		await assert.rejects(
			waitForPrerenderActivation(
				async () => {
					calls++;
					throw failure;
				},
				[],
				async () => waits++,
			),
			(error) => error === failure,
		);
		assert.equal(calls, 1);
		assert.equal(waits, 0);
	});
}

for (const rejects of [false, true]) {
	test(`activation keeps its timeout when target ${rejects ? "rejects" : "is unready"}`, async () => {
		let calls = 0;
		const waits = [];
		await assert.rejects(
			waitForPrerenderActivation(
				async () => {
					calls++;
					if (rejects) throw replacement();
					return false;
				},
				[{ diagnostic: "retained" }],
				async (ms) => waits.push(ms),
			),
			(error) =>
				error.message ===
				'click activates the prerendered document: [{"diagnostic":"retained"}]',
		);
		assert.equal(calls, 150);
		assert.deepEqual(waits, Array(150).fill(100));
	});
}
