import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import test from "node:test";
import { fileURLToPath } from "node:url";

const entry = fileURLToPath(
	new URL("../../tools/audit-public-site.mjs", import.meta.url),
);
const missing = fileURLToPath(
	new URL("./missing-public-site-artifact", import.meta.url),
);

test("parallel and malformed audit concurrency is rejected before artifact or browser effects", () => {
	for (const value of ["2", "3", "4", "0", "-1", "1.5", "garbage"]) {
		const result = spawnSync(
			process.execPath,
			[entry, missing, `--concurrency=${value}`],
			{ encoding: "utf8" },
		);
		assert.equal(result.status, 64, value);
		assert.match(result.stderr, /must run sequentially/);
		assert.doesNotMatch(result.stderr, /ENOENT|Chromium did not start/);
	}
});

test("default and explicit serial audit reach artifact validation", () => {
	for (const options of [[], ["--concurrency=1"]]) {
		const result = spawnSync(process.execPath, [entry, missing, ...options], {
			encoding: "utf8",
		});
		assert.equal(result.status, 1);
		assert.match(result.stderr, /ENOENT/);
		assert.doesNotMatch(result.stderr, /must run sequentially/);
	}
});
