import assert from "node:assert/strict";
import { execFileSync, spawnSync } from "node:child_process";
import {
	existsSync,
	mkdirSync,
	mkdtempSync,
	readFileSync,
	renameSync,
	rmSync,
	writeFileSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import test from "node:test";
import { browserCaseForPath, browserCases } from "../../tools/ci/browser-cases.mjs";
import { changedPaths, focusedLanes } from "../../tools/ci/plan.mjs";

test("prose still gets fast checks without heavy jobs", () => {
	assert.deepEqual(
		focusedLanes(["docs/development/testing.md", "AGENTS.md", "tasks/example.task.md"]),
		[],
	);
});

test("runtime changes retain native proof and add every relevant boundary", () => {
	assert.deepEqual(
		focusedLanes(["engine/src/cms/conventional/export/restore-repository.php"]),
		["database", "native", "portability"],
	);
	assert.deepEqual(
		focusedLanes(["engine/src/cms/conventional/client/preview-video.js"]),
		["exact-preview", "media", "native", "record-keeping"],
	);
	assert.ok(
		focusedLanes(["profiles/reference-studio/render.php"]).includes("public-site"),
	);
	assert.ok(focusedLanes(["engine/new-boundary.php"]).includes("native"));
});

test("CI changes prove hosted startup and audit rejection; mixed changes deduplicate", () => {
	assert.deepEqual(
		focusedLanes([
			".github/workflows/repository.yml",
			"flake.lock",
			"tools/ci/run-check.sh",
		]),
		["degraded-audit", "editor-start"],
	);
	assert.deepEqual(focusedLanes(["flake.nix", "engine/new.php", "engine/new.php"]), [
		"degraded-audit",
		"native",
	]);
});

test("selected harness changes retain their affected boundaries", () => {
	for (const [path, lane] of [
		["tests/php/upgrade-integration.php", "database"],
		["tests/php/content-import-integration.php", "portability"],
		["tests/browser/published-record-journey.mjs", "browser-published-record"],
		["tests/browser/blocks-editor-journey.mjs", "browser-blocks-editor"],
		["tests/browser/search-sharing-journey.mjs", "browser-search-sharing"],
		["tests/browser/local-browser.mjs", "browser-editorial-smoke"],
		["tests/browser/editorial-journey.mjs", "browser-editorial"],
		["tests/browser/editorial-controls-journey.mjs", "browser-editorial-controls"],
		["tests/browser/reload-readiness-journey.mjs", "browser-reload-readiness"],
		["tests/browser/run-editorial-journey.sh", "browser-editorial-smoke"],
		["tests/public-site/degraded-site-test.mjs", "public-site"],
	])
		assert.ok(focusedLanes([path]).includes(lane), path);
	assert.ok(focusedLanes(["tests/browser/run-editorial-journey.sh"]).includes("media"));
});

test("browser changes select their actual bounded case and never the full editorial chain", () => {
	for (const [name, item] of Object.entries(browserCases)) {
		for (const file of item.files) {
			assert.match(file, /^[a-z-]+-journey\.mjs$/);
			assert.ok(existsSync(`tests/browser/${file}`));
		}
		if (item.files.length !== 1) continue;
		assert.equal(browserCaseForPath(`tests/browser/${item.files[0]}`), name);
		assert.deepEqual(focusedLanes([`tests/browser/${item.files[0]}`]), [
			`browser-${name}`,
		]);
	}
	const all = [
		"tests/browser/run-editorial-journey.sh",
		"tests/browser/run-record-workflow-journey.sh",
	].flatMap((path) =>
		[
			...readFileSync(path, "utf8").matchAll(
				/node tests\/browser\/([a-z-]+-journey\.mjs)/g,
			),
		].map((match) => match[1]),
	);
	for (const file of new Set(all))
		assert.ok(browserCaseForPath(`tests/browser/${file}`), file);
	assert.deepEqual(
		focusedLanes([
			"tests/browser/local-browser.mjs",
			"tests/browser/edited-field-upload-journey.mjs",
		]),
		["browser-editorial-smoke", "native"],
	);
	assert.ok(
		!focusedLanes(["tests/browser/run-editorial-journey.sh"]).includes("editorial"),
	);
	assert.equal(browserCases.vimeo.env.CMS_TEST_VIMEO_ONLY, "1");
	const dispatcherLanes = focusedLanes(["tools/ci/browser-cases.mjs"]);
	for (const name of [
		"editorial-smoke",
		"vimeo",
		"video-field-limits",
		"single-line-rich-text",
		"ai-assistance",
		"account",
	])
		assert.ok(dispatcherLanes.includes(`browser-${name}`));
	const mixedLanes = focusedLanes([
		"tools/ci/browser-cases.mjs",
		"tests/browser/run-editorial-journey.sh",
	]);
	assert.ok(!mixedLanes.includes("browser-vimeo") && mixedLanes.includes("media"));
	assert.ok(!mixedLanes.includes("browser-account") && mixedLanes.includes("native"));
	assert.equal(
		browserCases["single-line-rich-text"].env.CMS_TEST_SINGLE_LINE_RICH_ONLY,
		"1",
	);
	assert.equal(browserCases["video-field-limits"].env.CMS_TEST_VIDEO_LIMITS_ONLY, "1");
	for (const mode of ["launch", "execute"])
		for (const name of ["missing", "../editorial", "__proto__"])
			assert.notEqual(
				spawnSync(process.execPath, ["tools/ci/browser-cases.mjs", mode, name]).status,
				0,
			);
});

test("unsafe paths and revision options fail closed", () => {
	for (const path of [
		"",
		"/absolute.php",
		"../outside.php",
		"a/./b",
		"a\\b",
		"a\nb",
		".git/config",
		"a//b",
		null,
	]) {
		assert.throws(() => focusedLanes([path]), /Unsafe/);
	}
	for (const revision of ["HEAD", "--all", "main\nextra", "a".repeat(39)]) {
		assert.throws(() => changedPaths(revision, "a".repeat(40)), /full commit/);
	}
});

test("Git rename/deletion comparison retains the removed runtime path", (t) => {
	const cwd = mkdtempSync(join(tmpdir(), "portable-ci-plan-"));
	t.after(() => rmSync(cwd, { recursive: true, force: true }));
	const git = (...args) =>
		execFileSync(
			"git",
			[
				"-c",
				"core.hooksPath=/dev/null",
				"-c",
				"commit.gpgsign=false",
				"-c",
				"user.name=CI fixture",
				"-c",
				"user.email=ci@example.test",
				...args,
			],
			{ cwd, encoding: "utf8" },
		).trim();
	git("init", "--initial-branch=main", "--template=");
	mkdirSync(join(cwd, "engine"));
	writeFileSync(join(cwd, "engine", "import.php"), "fixture\n");
	git("add", ".");
	git("commit", "-m", "before");
	const base = git("rev-parse", "HEAD");
	renameSync(join(cwd, "engine", "import.php"), join(cwd, "README.md"));
	git("add", "--all");
	git("commit", "-m", "after");
	const paths = changedPaths(base, git("rev-parse", "HEAD"), cwd);
	assert.deepEqual(paths.sort(), ["README.md", "engine/import.php"]);
	assert.ok(focusedLanes(paths).includes("portability"));
});

test("required aggregate rejects failed, cancelled, missing and unexpectedly skipped jobs", () => {
	const passes = (overrides = {}) =>
		spawnSync("sh", ["tools/ci/require-results.sh"], {
			env: {
				...process.env,
				PLAN_RESULT: "success",
				FAST_RESULT: "success",
				FOCUSED_RESULT: "success",
				LANES: '["native"]',
				...overrides,
			},
		}).status === 0;
	assert.ok(passes());
	assert.ok(passes({ LANES: "[]", FOCUSED_RESULT: "skipped" }));
	for (const key of ["PLAN_RESULT", "FAST_RESULT", "FOCUSED_RESULT"]) {
		for (const value of ["failure", "cancelled", "skipped", ""])
			assert.equal(passes({ [key]: value }), false, `${key}=${value}`);
	}
	assert.equal(passes({ LANES: "" }), false);
	assert.equal(passes({ LANES: "[]" }), false);
	assert.equal(
		passes({ PLAN_RESULT: "failure", LANES: "[]", FOCUSED_RESULT: "skipped" }),
		false,
	);
});
