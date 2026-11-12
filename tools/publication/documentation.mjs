import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { safePath } from "./source.mjs";

// Every documentation file needs an explicit audience review before publication.
export const documentation = Object.freeze(
	JSON.parse(readFileSync(new URL("./documentation.json", import.meta.url))),
);
const audiences = new Set(["index", "build", "operate", "contribute", "private"]);
for (const [path, audience] of Object.entries(documentation)) {
	safePath(path);
	assert.ok(path.startsWith("docs/") && audiences.has(audience));
}

export function publicDocument(path) {
	assert.ok(Object.hasOwn(documentation, path), `Unreviewed documentation: ${path}`);
	return documentation[path] !== "private";
}
