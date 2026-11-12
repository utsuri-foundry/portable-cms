import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import { decodeSearchIndex } from "../../engine/src/browser/search-contract.js";

for (const locale of ["ca", "es", "en"]) {
	test(`Reference Studio ${locale} search fixture uses the browser contract`, async () => {
		const url = new URL(
			`../../profiles/reference-studio/fixtures/expected/search.${locale}.json`,
			import.meta.url,
		);
		const decoded = decodeSearchIndex(JSON.parse(await readFile(url, "utf8")));
		assert.equal(decoded.locale, locale);
		assert.deepEqual(
			decoded.documents.map(({ typeId }) => typeId),
			["project", "article", "person", "landing"],
		);
	});
}
