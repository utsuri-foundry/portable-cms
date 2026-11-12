import { existsSync, mkdirSync, realpathSync, writeFileSync } from "node:fs";
import { basename, dirname, resolve } from "node:path";
import { pathToFileURL } from "node:url";
import { publicDocument } from "./documentation.mjs";
import { assertPublicDocumentation, publicProse } from "./public-docs.mjs";
import { digest, entries, git } from "./source.mjs";

const files = new Set([
	".editorconfig",
	".gitignore",
	"AGENTS.md",
	"CHANGELOG.md",
	"CONTRIBUTING.md",
	"PROVENANCE.md",
	"README.md",
	"SECURITY.md",
	"LICENSE",
	"NOTICE",
	"THIRD_PARTY.md",
	"architecture-exceptions.json",
	"architecture.json",
	"biome.json",
	"composer.json",
	"composer.lock",
	"engine-release-policy.json",
	"flake.lock",
	"flake.nix",
	"justfile",
	"package.json",
	"phpstan.neon.dist",
	"quality-exceptions.json",
]);
const roots = new Set([
	".github",
	"config",
	"docs",
	"engine",
	"profiles",
	"schemas",
	"tests",
	"tools",
]);
export function admitted(path) {
	if (
		path.startsWith("tools/publication/private/") ||
		path.startsWith("docs/publication-private/")
	)
		return false;
	if (path.startsWith("docs/")) return publicDocument(path);
	return files.has(path) || path === "tasks/README.md" || roots.has(path.split("/")[0]);
}
function projectedBytes(path, bytes) {
	if (path === "AGENTS.md") {
		const source = bytes.toString("utf8");
		const product = source.indexOf("## Product invariants");
		if (product < 0) throw new Error("Missing public agent policy boundary");
		return Buffer.from(
			`# Portable CMS contributor guide\n\nUse a branch and pull request for one focused outcome. Read CONTRIBUTING.md,\nthe affected area READMEs and the product rules below. Run fast checks and the\naffected lanes before review; full qualification precedes release/publication.\nDo not commit client content, credentials or local state.\nPrivate maintainer coordination is not required. Push, merge, deployment and\npublication still require explicit authorization from the repository owner.\n\n${source
				.slice(product)
				.replaceAll("a Tsuzuri task", "a reviewed issue")
				.replaceAll("nonterminal Tsuzuri removal task", "nonterminal removal record")
				.replace(
					"Tsuzuri release is only for an unused claim and must preserve implementation work. ",
					"Preserve other contributors’ implementation work. ",
				)}`,
		);
	}
	if (path === "tasks/README.md")
		return Buffer.from(
			"# Work coordination\n\nPublic work uses GitHub issues and pull requests. Internal historical task and\nclaim records remain in a private archive and are not required to build or test.\nArchitecture and quality exceptions require a reviewed removal record; the\npublication snapshot admits no active exceptions.\n",
		);
	if (path === "docs/development/repository-layout.md") {
		return Buffer.from(
			`${bytes.toString("utf8").split(/## (?:Local checkout layout|Contributor checkouts)/)[0]}## Contributor checkouts\n\nUse an ordinary clone, a focused branch and a pull request. See\n[CONTRIBUTING](../../CONTRIBUTING.md). Private archive worktrees and ownership\nrecords are maintained separately and are not required in a public checkout.\n`,
		);
	}
	if (path.endsWith(".md")) {
		// Retain the task's human description without a link into withheld records.
		return Buffer.from(
			bytes
				.toString("utf8")
				.replace(
					/\[([^\]]+)\]\((?:\.\.\/)*tasks\/projects\/[^)]+\)/g,
					"$1 (internal planning record)",
				),
		);
	}
	return bytes;
}
export function publicBytes(path, bytes) {
	if (path.startsWith("docs/") && !publicDocument(path))
		throw new Error(`Internal working document cannot enter public source: ${path}`);
	const result = publicProse(path, projectedBytes(path, bytes));
	assertPublicDocumentation(path, result);
	return result;
}
export function snapshot({ source, output, authorName, authorEmail, date }) {
	const root = realpathSync(source);
	const destination = resolve(realpathSync(dirname(resolve(output))), basename(output));
	if (
		destination === root ||
		destination.startsWith(`${root}/`) ||
		existsSync(destination)
	)
		throw new Error("Output must be a new directory outside the source checkout");
	if (
		!authorName ||
		/[<>\r\n]/.test(authorName) ||
		!/^[^\s<>@]+@[^\s<>@]+\.[^\s<>@]+$/.test(authorEmail) ||
		!/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/.test(date) ||
		!Number.isFinite(Date.parse(date))
	)
		throw new Error("Explicit public author identity and UTC commit date required");
	if (git(root, ["status", "--porcelain", "--untracked-files=all"]).length)
		throw new Error("Commit and review the clean source snapshot first");
	for (const path of ["architecture-exceptions.json", "quality-exceptions.json"]) {
		const registry = JSON.parse(git(root, ["show", `HEAD:${path}`]).toString("utf8"));
		if (registry.exceptions?.length !== 0)
			throw new Error(
				"Active private removal records require a separate public migration decision",
			);
	}
	const inventory = [];
	const selected = entries(root, "HEAD").filter((entry) => admitted(entry.path));
	const required = JSON.parse(
		git(root, ["show", "HEAD:architecture.json"]).toString("utf8"),
	).requiredDocuments;
	if (
		!Array.isArray(required) ||
		required.some((path) => !selected.some((entry) => entry.path === path))
	) {
		throw new Error(
			"Publication admission must retain every required architecture document",
		);
	}
	const prepared = selected.map(({ path, mode, oid }) => ({
		path,
		mode,
		bytes: publicBytes(path, git(root, ["cat-file", "blob", oid])),
	}));
	mkdirSync(destination, { mode: 0o700 });
	for (const { path, mode, bytes } of prepared) {
		const target = resolve(destination, path);
		mkdirSync(dirname(target), { recursive: true });
		writeFileSync(target, bytes, {
			mode: mode === "100755" ? 0o755 : 0o644,
			flag: "wx",
		});
		inventory.push({ path, mode, sha256: digest(bytes) });
	}
	writeFileSync(
		resolve(destination, "PUBLICATION.json"),
		`${JSON.stringify({ schema: 1, history: "fresh-root", files: inventory }, null, 2)}\n`,
		{ flag: "wx" },
	);
	git(destination, ["init", "--initial-branch=main", "--template="]);
	git(destination, ["add", "--all"]);
	const identity = {
		GIT_AUTHOR_NAME: authorName,
		GIT_AUTHOR_EMAIL: authorEmail,
		GIT_COMMITTER_NAME: authorName,
		GIT_COMMITTER_EMAIL: authorEmail,
		GIT_AUTHOR_DATE: date,
		GIT_COMMITTER_DATE: date,
	};
	git(
		destination,
		[
			"-c",
			"core.hooksPath=/dev/null",
			"-c",
			"commit.gpgsign=false",
			"commit",
			"-m",
			"Publish reviewed Portable CMS source under Apache-2.0",
		],
		undefined,
		identity,
	);
	if (
		git(destination, ["rev-list", "--all", "--count"]).toString().trim() !== "1" ||
		git(destination, ["remote"]).length
	)
		throw new Error("Fresh history verification failed");
	return {
		files: inventory.length,
		publicCommit: git(destination, ["rev-parse", "HEAD"]).toString().trim(),
		inventorySha256: digest(Buffer.from(JSON.stringify(inventory))),
	};
}
if (
	process.argv[1] &&
	import.meta.url === pathToFileURL(resolve(process.argv[1])).href
) {
	try {
		if (process.argv.length !== 7)
			throw new Error(
				"Usage: node tools/publication/snapshot.mjs SOURCE NEW_OUTPUT PUBLIC_AUTHOR_NAME PUBLIC_AUTHOR_EMAIL UTC_DATE",
			);
		const [source, output, authorName, authorEmail, date] = process.argv.slice(2);
		console.log(
			JSON.stringify(snapshot({ source, output, authorName, authorEmail, date })),
		);
	} catch (error) {
		console.error(error.message);
		process.exitCode = 1;
	}
}
