import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import { normalizeSearchText } from "../../engine/src/browser/normalize.js";
import { slugFromTitle } from "../../engine/src/browser/slug.js";

test("browser search and slug normalization match the shared fixtures", async () => {
	const fixture = JSON.parse(
		await readFile(
			new URL("../fixtures/text-normalization-v1.json", import.meta.url),
			"utf8",
		),
	);
	assert.equal(fixture.formatVersion, 1);
	for (const item of fixture.cases) {
		assert.equal(normalizeSearchText(item.input), item.search, `search: ${item.input}`);
		assert.equal(slugFromTitle(item.input), item.slug, `slug: ${item.input}`);
	}
});
