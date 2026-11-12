import { execFile } from "node:child_process";
import { join } from "node:path";
import { promisify } from "node:util";

const run = promisify(execFile);

/** Validates every HTML document with the pinned Nu validator; returns errors per path. */
export async function validateHtml(artifact, paths) {
	const files = paths.map((path) => join(artifact, path, "index.html"));
	const { stderr } = await run(
		"vnu",
		["--format", "json", "--exit-zero-always", ...files],
		{
			maxBuffer: 64 * 1024 * 1024,
			timeout: 300_000,
		},
	);
	const report = JSON.parse(stderr || '{"messages":[]}');
	const errors = new Map();
	for (const message of report.messages) {
		if (message.type !== "error" && message.subType !== "fatal") continue;
		const file = decodeURIComponent(new URL(message.url).pathname);
		const path = paths[files.indexOf(file)] ?? file;
		const list = errors.get(path) ?? [];
		list.push(`HTML line ${message.lastLine ?? "?"}: ${message.message}`);
		errors.set(path, list);
	}
	return errors;
}
