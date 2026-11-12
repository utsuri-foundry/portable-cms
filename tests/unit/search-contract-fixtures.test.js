import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import { decodeSearchIndex } from "../../engine/src/browser/search-contract.js";

const fixtureUrl = new URL(
	"../../schemas/fixtures/contracts/search.accepted.json",
	import.meta.url,
);

async function fixture() {
	return JSON.parse(await readFile(fixtureUrl, "utf8"));
}

test("browser decoder accepts the shared static search fixture", async () => {
	const decoded = decodeSearchIndex(await fixture());
	assert.equal(decoded.formatVersion, 1);
	assert.equal(decoded.documents.length, 2);
	assert.deepEqual(
		decoded.terms.map(({ token }) => token),
		["courtyard", "material", "wood"],
	);
});

test("browser decoder rejects unknown fields and dangling postings", async () => {
	const unknown = await fixture();
	unknown.executable = "alert(1)";
	assert.throws(() => decodeSearchIndex(unknown), /Search index is invalid/);

	const dangling = await fixture();
	dangling.terms[0].postings[0].documentId = "ffffffffffffffffffffffffffffffff";
	assert.throws(() => decodeSearchIndex(dangling), /Search index is invalid/);
});

test("browser decoder rejects duplicate and non-canonical entries", async () => {
	const duplicateDocument = await fixture();
	duplicateDocument.documents.push(structuredClone(duplicateDocument.documents[0]));
	assert.throws(() => decodeSearchIndex(duplicateDocument), /Search index is invalid/);

	const unorderedTerms = await fixture();
	unorderedTerms.terms.reverse();
	assert.throws(() => decodeSearchIndex(unorderedTerms), /Search index is invalid/);

	const unorderedPostings = await fixture();
	unorderedPostings.terms[1].postings.reverse();
	assert.throws(() => decodeSearchIndex(unorderedPostings), /Search index is invalid/);
});

test("browser decoder enforces result-media relationships", async () => {
	const invalidMedia = await fixture();
	invalidMedia.documents[1].mediaId = "99999999999999999999999999999999";
	assert.throws(() => decodeSearchIndex(invalidMedia), /Search index is invalid/);
});
