import { execFileSync } from "node:child_process";
import { resolve } from "node:path";
import { pathToFileURL } from "node:url";
import { browserCaseForPath, browserCases } from "./browser-cases.mjs";

const rules = [
	[
		/compiler|profiles\/|public-site|schemas\/|design|font|holding|focal|responsive|speculation/i,
		["public-site"],
	],
	[
		/record|content|client\/|view|form|editor|session|identity|account/i,
		["record-keeping"],
	],
	[/preview|snapshot|worker|job|queue|lease/i, ["exact-preview"]],
	[/media|upload|video|vimeo|poster|image|ffmpeg/i, ["media"]],
	[/export|restore|transfer|handover|import|backup/i, ["portability"]],
	[/database|migration|schema|upgrade|repository|storage|sql|pdo/i, ["database"]],
	[/publish|publication|release|schedule|feed|sequence/i, ["publication"]],
	[/block|composition/i, ["blocks"]],
	[/search|sharing|metadata|seo/i, ["search-sharing"]],
];

export function focusedLanes(paths) {
	const lanes = new Set();
	for (const path of paths) {
		if (
			typeof path !== "string" ||
			!path ||
			path.includes("\\") ||
			Array.from(path).some(
				(character) =>
					character.codePointAt(0) < 32 || character.codePointAt(0) === 127,
			) ||
			path.split("/").some((part) => !part || [".", "..", ".git"].includes(part))
		)
			throw new Error("Unsafe CI change path");
		// Prose and internal planning still receive the entire fast gate.
		if (/\.md$/.test(path) || path.startsWith("tasks/") || path === ".tsuzuri.toml")
			continue;
		const browserCase = browserCaseForPath(path);
		if (browserCase) {
			lanes.add(`browser-${browserCase}`);
			continue;
		}
		if (/^(\.github\/|tools\/ci\/|tools\/check-.*\.sh$|flake\.)/.test(path)) {
			lanes.add("editor-start");
			lanes.add("degraded-audit");
			if (path.startsWith("tools/ci/browser-cases."))
				for (const name of [
					"editorial-smoke",
					"vimeo",
					"video-field-limits",
					"single-line-rich-text",
					"ai-assistance",
					"account",
				])
					lanes.add(`browser-${name}`);
			continue;
		}
		// Unit and static-analysis changes are exercised by the fast gate itself.
		if (
			/^(tests\/unit\/|tools\/(publication|quality|architecture-gate|release-gate)\/)/.test(
				path,
			) ||
			/^(biome\.json|phpstan\.neon\.dist|architecture(-exceptions)?\.json|quality-exceptions\.json|package\.json|justfile)$/.test(
				path,
			)
		)
			continue;
		lanes.add("native");
		for (const [pattern, selected] of rules) {
			if (pattern.test(path)) for (const lane of selected) lanes.add(lane);
		}
		if (/^tests\/browser\//.test(path)) {
			if (
				/\/(local-browser|editorial(?:-controls)?-journey|reload-readiness-journey)\.mjs$|\/run-editorial-journey\.sh$/.test(
					path,
				)
			)
				lanes.add("browser-editorial-smoke");
			// Harness changes can affect all focused browser flags.
			if (/\.sh$/.test(path))
				for (const lane of ["record-keeping", "exact-preview", "media", "blocks"])
					lanes.add(lane);
		}
	}
	if (lanes.has("native")) lanes.delete("editor-start");
	for (const [lane, covered] of Object.entries({
		native: ["editor-start", "record-workflow", "account"],
		media: ["vimeo", "video-field-limits"],
		"record-keeping": ["record-keeping", "single-line-rich-text"],
		"exact-preview": ["editor-exact-preview"],
		blocks: ["blocks-editor", "composition"],
		publication: ["published-record", "release-schedule"],
		"search-sharing": ["search-sharing"],
	}))
		if (lanes.has(lane)) for (const name of covered) lanes.delete(`browser-${name}`);
	if (lanes.has("browser-editorial-smoke"))
		for (const file of browserCases["editorial-smoke"].files)
			lanes.delete(`browser-${browserCaseForPath(`tests/browser/${file}`)}`);
	return [...lanes].sort();
}

export function changedPaths(base, head, cwd = process.cwd()) {
	for (const revision of [base, head]) {
		if (!/^[a-f0-9]{40}$/.test(revision))
			throw new Error("CI requires full commit IDs");
	}
	// Disable rename detection so both deleted and added paths are classified.
	const output = execFileSync(
		"git",
		["diff", "--no-renames", "--name-only", "-z", base, head, "--"],
		{
			cwd,
			maxBuffer: 16 * 1024 * 1024,
			encoding: "utf8",
		},
	);
	return output.split("\0").filter(Boolean);
}

if (
	process.argv[1] &&
	import.meta.url === pathToFileURL(resolve(process.argv[1])).href
) {
	const [, , base, head] = process.argv;
	console.log(JSON.stringify(focusedLanes(changedPaths(base, head))));
}
