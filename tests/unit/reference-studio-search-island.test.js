import assert from "node:assert/strict";
import test from "node:test";
import {
	matchesQuery,
	normalize,
} from "../../profiles/reference-studio/assets/search.js";

test("Reference Studio filtering is accent insensitive and incremental", () => {
	assert.deepEqual(normalize("Pavelló d'Arquitectura"), [
		"pavello",
		"d",
		"arquitectura",
	]);
	assert.equal(matchesQuery("pav", "Pavelló al costat de l'aigua"), true);
	assert.equal(matchesQuery("pav aigua", "Pavelló al costat de l'aigua"), true);
	assert.equal(matchesQuery("fusta", "Pavelló al costat de l'aigua"), false);
});

test("Reference Studio filtering treats an empty query as the complete index", () => {
	assert.equal(matchesQuery("", "Any searchable record"), true);
});
