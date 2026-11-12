import assert from "node:assert/strict";
import {
	existsSync,
	mkdirSync,
	mkdtempSync,
	readFileSync,
	rmSync,
	symlinkSync,
	writeFileSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import test from "node:test";
import {
	assertPublicDocumentation,
	checkPublicDocumentation,
} from "../../tools/publication/public-docs.mjs";
import { publicBytes, snapshot } from "../../tools/publication/snapshot.mjs";
import { git, objects, safePath } from "../../tools/publication/source.mjs";

function fixture(t) {
	const temporary = mkdtempSync(join(tmpdir(), "portable-publication-test-"));
	t.after(() => rmSync(temporary, { recursive: true, force: true }));
	const source = join(temporary, "source");
	mkdirSync(source);
	git(source, ["init", "--initial-branch=main", "--template="]);
	const write = (path, text) => {
		const target = join(source, path);
		mkdirSync(join(target, ".."), { recursive: true });
		writeFileSync(target, text);
	};
	write("AGENTS.md", "Internal instructions\n## Product invariants\nPublic rules\n");
	write(
		"README.md",
		"A [planned feature](tasks/projects/internal/tasks/example.task.md).\n",
	);
	write("architecture-exceptions.json", '{"exceptions":[]}');
	write("quality-exceptions.json", '{"exceptions":[]}');
	write(
		"architecture.json",
		JSON.stringify({
			requiredDocuments: ["AGENTS.md", "quality-exceptions.json", "tasks/README.md"],
		}),
	);
	write("tasks/README.md", "Private coordination");
	write("tasks/projects/internal/tasks/example.task.md", "Private planning");
	write(".tsuzuri.toml", "Private claim configuration");
	write(".local/private.txt", "Ignored private material");
	write(".gitignore", ".local/\n");
	const commit = () => {
		git(source, ["add", "--all"]);
		git(source, [
			"-c",
			"user.name=Private fixture",
			"-c",
			"user.email=private@example.test",
			"-c",
			"commit.gpgsign=false",
			"-c",
			"core.hooksPath=/dev/null",
			"commit",
			"-m",
			"Private fixture history",
		]);
	};
	commit();
	return {
		temporary,
		source,
		write,
		commit,
		output: join(temporary, "public"),
		authorName: "Public fixture",
		authorEmail: "public@example.test",
		date: "2026-11-11T00:00:00Z",
	};
}

test("snapshot creates only reviewed public files and a new root identity", (t) => {
	const options = fixture(t);
	const privateCommit = git(options.source, ["rev-parse", "HEAD"]).toString().trim();
	const result = snapshot(options);
	assert.notEqual(result.publicCommit, privateCommit);
	assert.equal(
		git(options.output, ["rev-list", "--all", "--count"]).toString().trim(),
		"1",
	);
	assert.equal(
		git(options.output, ["for-each-ref", "--format=%(refname)"]).toString().trim(),
		"refs/heads/main",
	);
	assert.equal(git(options.output, ["remote"]).length, 0);
	assert.throws(() => git(options.output, ["cat-file", "-e", privateCommit]));
	assert.equal(
		git(options.output, ["log", "-1", "--format=%ae"]).toString().trim(),
		options.authorEmail,
	);
	const paths = git(options.output, ["ls-files"]).toString();
	assert.doesNotMatch(paths, /\.tsuzuri|tasks\/projects|\.local/);
	assert.ok(paths.includes("quality-exceptions.json"));
	assert.doesNotMatch(
		readFileSync(join(options.output, "AGENTS.md"), "utf8"),
		/Internal instructions/,
	);
	assert.doesNotMatch(
		readFileSync(join(options.output, "README.md"), "utf8"),
		/tasks\/projects/,
	);
	assert.ok(
		objects(options.output).every(
			({ bytes }) => !bytes.includes("private@example.test"),
		),
	);
});

test("snapshot refuses dirty inputs, reused destinations and source aliases", (t) => {
	const options = fixture(t);
	assert.throws(
		() => snapshot({ ...options, output: options.source }),
		/new directory/,
	);
	const alias = join(options.temporary, "alias");
	symlinkSync(options.source, alias);
	assert.throws(
		() => snapshot({ ...options, output: join(alias, "nested") }),
		/outside/,
	);
	options.write("README.md", "Unreviewed change");
	assert.throws(() => snapshot(options), /clean source/);
	assert.throws(
		() => snapshot({ ...options, authorEmail: "private\n@example.test" }),
		/identity/,
	);
});

test("snapshot refuses symlinks and active private exception dependencies", (t) => {
	const options = fixture(t);
	symlinkSync("/etc/passwd", join(options.source, "config-link"));
	options.commit();
	assert.throws(() => snapshot(options), /Links and submodules/);
});

test("active exceptions require an explicit migration", (t) => {
	const options = fixture(t);
	options.write("quality-exceptions.json", '{"exceptions":[{"task":"private"}]}');
	options.commit();
	assert.throws(() => snapshot(options), /removal records/);
});

test("path and policy transforms fail closed", () => {
	for (const path of ["../x", "/x", ".git/config", "a/.GIT/hooks/x", "a\\x", "a\nx"])
		assert.throws(() => safePath(path));
	assert.throws(
		() => publicBytes("AGENTS.md", Buffer.from("Unexpected policy")),
		/boundary/,
	);
});

test("public policy transforms remain stable when applied again", () => {
	for (const [path, text] of [
		["AGENTS.md", "Private coordination\n## Product invariants\nPublic rules\n"],
		["tasks/README.md", "Private planning"],
		[
			"docs/development/repository-layout.md",
			"# Layout\n\n## Local checkout layout\nPrivate instructions\n",
		],
		["README.md", "[Feature](tasks/projects/private/tasks/feature.task.md)"],
	]) {
		const once = publicBytes(path, Buffer.from(text));
		assert.deepEqual(publicBytes(path, once), once);
	}
});

test("unreviewed documentation blocks export before any output is created", (t) => {
	const options = fixture(t);
	options.write("docs/new-guide.md", "Run TSUZURI claim before contributing.");
	options.commit();
	assert.throws(() => snapshot(options), /Unreviewed documentation.*docs\/new-guide/);
	assert.equal(existsSync(options.output), false);
	assert.match(
		readFileSync(join(options.source, "docs/new-guide.md"), "utf8"),
		/TSUZURI/,
	);
	// The private source retains its own instructions; the public gate is separate.
	checkPublicDocumentation(options.source);
});

test("documentation guard rejects internal links and personal workspace paths", () => {
	for (const text of [
		"See tasks/projects/private/project.md",
		"Use the canonical checkout",
		"worktree_root = '..'",
		"/home/author/dev/private-repo/main",
		"An internal planning record",
	]) {
		assert.throws(() =>
			assertPublicDocumentation("docs/new-guide.md", Buffer.from(text)),
		);
	}
	assertPublicDocumentation(
		"docs/example.md",
		Buffer.from(
			"Private CMS storage is /srv/portable/private; visitor requests use static files.",
		),
	);
});

test("public gate scans later docs and rejects private exceptions or deleted root provenance", (t) => {
	const options = fixture(t);
	snapshot(options);
	checkPublicDocumentation(options.output);
	const readme = join(options.output, "README.md");
	const before = readFileSync(readme);
	writeFileSync(readme, "Use tsuzuri next.");
	assert.throws(() => checkPublicDocumentation(options.output), /Internal workflow/);
	writeFileSync(readme, before);
	const registry = join(options.output, "quality-exceptions.json");
	writeFileSync(registry, '{"exceptions":[{"removalTask":"private-record"}]}');
	assert.throws(
		() => checkPublicDocumentation(options.output),
		/without private exceptions/,
	);
	writeFileSync(registry, '{"exceptions":[]}');
	git(options.output, ["rm", "--", "PUBLICATION.json"]);
	assert.throws(
		() => checkPublicDocumentation(options.output),
		/Retain initial source provenance/,
	);
});

test("published contributor policies are coherent, stable and retain ordinary public edits", () => {
	for (const path of [
		"AGENTS.md",
		"docs/architecture/README.md",
		"docs/architecture/decisions/0001-product-first-reference-profile.md",
		"docs/architecture/decisions/0003-repository-architecture-gate.md",
		"docs/architecture/decisions/0004-development-quality-toolchain.md",
		"docs/architecture/decisions/0008-integration-surface.md",
		"docs/development/implementation-quality-policy.md",
		"docs/development/repository-architecture-policy.md",
		"docs/development/review-checklist.md",
		"docs/development/reference-host-acceptance.md",
		"docs/development/publication.md",
		"docs/development/publication-audit.md",
	]) {
		const output = publicBytes(
			path,
			readFileSync(new URL(`../../${path}`, import.meta.url)),
		);
		assertPublicDocumentation(path, output);
		assert.deepEqual(publicBytes(path, output), output, path);
	}
	for (const path of [
		"docs/development/reference-host-acceptance.md",
		"docs/architecture/decisions/0008-integration-surface.md",
		"docs/development/publication.md",
		"docs/development/publication-audit.md",
	]) {
		const output = Buffer.concat([
			publicBytes(path, readFileSync(new URL(`../../${path}`, import.meta.url))),
			Buffer.from("\nAdditional public guidance.\n"),
		]);
		assert.deepEqual(publicBytes(path, output), output);
	}
});

test("working notes and operator journals never enter fresh public history", (t) => {
	const options = fixture(t);
	const marker = "PRIVATE_WORKING_EVIDENCE_DO_NOT_EXPORT";
	const withheld = [
		"docs/architecture/editorial-experience-roadmap.md",
		"docs/architecture/live-preview.md",
		"docs/development/fidelity-studies.md",
	];
	for (const path of withheld) options.write(path, marker);
	const journal = "docs/development/reference-host-acceptance.md";
	options.write(
		journal,
		`# Reference host publication and restore acceptance\n${marker}\n`,
	);
	options.commit();
	snapshot(options);
	for (const path of withheld) {
		assert.equal(existsSync(join(options.output, path)), false);
		assert.equal(readFileSync(join(options.source, path), "utf8"), marker);
		assert.throws(
			() => publicBytes(path, Buffer.from(marker)),
			/Internal working document/,
		);
	}
	const checklist = readFileSync(join(options.output, journal), "utf8");
	assert.match(checklist, /^# Installation acceptance checks/);
	assert.match(checklist, /compatible engine\/profile package/);
	assert.doesNotMatch(checklist, /PRIVATE_WORKING/);
	assert.ok(objects(options.output).every(({ bytes }) => !bytes.includes(marker)));
	checkPublicDocumentation(options.output);
});

test("reviewed guides still reject internal content before creating a snapshot", (t) => {
	const options = fixture(t);
	options.write("docs/development/testing.md", "Run TSUZURI claim first.");
	options.commit();
	assert.throws(
		() => snapshot(options),
		/Internal workflow.*docs\/development\/testing/,
	);
	assert.equal(existsSync(options.output), false);
});

test("public checks refuse reintroduced working notes and new unreviewed docs", (t) => {
	const options = fixture(t);
	snapshot(options);
	for (const [path, error] of [
		["docs/architecture/live-preview.md", /Internal working document/],
		["docs/unreviewed.md", /Unreviewed documentation/],
	]) {
		const target = join(options.output, path);
		mkdirSync(join(target, ".."), { recursive: true });
		writeFileSync(target, "New documentation.");
		git(options.output, ["add", "--", path]);
		assert.throws(() => checkPublicDocumentation(options.output), error);
		git(options.output, ["rm", "-f", "--", path]);
	}
});
