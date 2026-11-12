import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import { readFileSync } from "node:fs";
import test from "node:test";

const lanes = JSON.parse(
	readFileSync(new URL("../../tools/ci/qualification.json", import.meta.url)),
);
test("complete qualification preserves every original command and environment exactly once", () => {
	const commands = lanes.flatMap((l) =>
		l.commands.map((c) =>
			[...Object.entries(c.env).map(([k, v]) => `${k}=${v}`), ...c.argv].join(" "),
		),
	);
	assert.deepEqual(commands, [
		"sh tools/check-foundation.sh",
		"sh tests/php/run-mariadb-integration.sh",
		"php tests/php/public-delivery-integration.php",
		"sh tests/public-site/run-public-site-audit.sh",
		"CMS_TEST_EDITOR_START_ONLY=1 sh tests/browser/run-editorial-journey.sh",
		"sh tests/browser/run-editorial-journey.sh",
		"CMS_TEST_EDITOR_EXACT_ONLY=1 sh tests/browser/run-editorial-journey.sh",
		"CMS_TEST_VIDEO_LIMITS_ONLY=1 sh tests/browser/run-editorial-journey.sh",
		"CMS_TEST_VIMEO_ONLY=1 sh tests/browser/run-editorial-journey.sh",
		"CMS_TEST_PUBLISHED_ONLY=1 sh tests/browser/run-editorial-journey.sh",
		"CMS_TEST_RECORD_KEEPING_ONLY=1 sh tests/browser/run-editorial-journey.sh",
		"CMS_TEST_SINGLE_LINE_RICH_ONLY=1 sh tests/browser/run-editorial-journey.sh",
		"sh tests/browser/run-record-workflow-journey.sh",
		"tools/check-extraction-boundary.sh",
		"git diff --check",
	]);
	assert.equal(new Set(lanes.map((l) => l.id)).size, lanes.length);
	const listed = spawnSync(process.execPath, ["tools/ci/qualification.mjs", "list"], {
		encoding: "utf8",
	});
	assert.equal(listed.status, 0);
	assert.deepEqual(
		JSON.parse(listed.stdout),
		lanes.map((l) => l.id),
	);
	assert.notEqual(
		spawnSync(process.execPath, ["tools/ci/qualification.mjs", "missing"]).status,
		0,
	);
});
test("complete aggregate rejects failures, cancellations and skipped or missing lanes", () => {
	const check = (plan, matrix) =>
		spawnSync("sh", ["tools/ci/require-qualification.sh"], {
			env: { ...process.env, PLAN_RESULT: plan, QUALIFICATION_RESULT: matrix },
		}).status;
	assert.equal(check("success", "success"), 0);
	for (const state of ["failure", "cancelled", "skipped", ""]) {
		assert.notEqual(check(state, "success"), 0);
		assert.notEqual(check("success", state), 0);
	}
});
