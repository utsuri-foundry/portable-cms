const DOCUMENT_ID_PATTERN = /^[a-f0-9]{32}$/;
const CANONICAL_ID_PATTERN = /^[a-z][a-z0-9]*(?:[-_][a-z0-9]+)*$/;
const LOCALE_PATTERN = /^[a-z]{2,3}(?:-[A-Z]{2})?$/;
const ROUTE_PATTERN = /^\/(?:[A-Za-z0-9._~-]+\/)*$/;
const TOKEN_PATTERN = /^[\p{L}\p{N}]+$/u;

const MAX_DOCUMENTS = 20_000;
const MAX_TERMS = 200_000;
const MAX_POSTINGS = 20_000;

/**
 * Decode and validate the static search index at its browser trust boundary.
 * The returned object contains fresh values so callers never retain references
 * to mutable transport data.
 *
 * @param {unknown} input
 * @returns {{
 *   formatVersion: 1,
 *   locale: string,
 *   normalization: "unicode_nfkd_accent_fold_lower_words_v1",
 *   ordering: "weight_desc_document_id_asc",
 *   documents: Array<{
 *     id: string,
 *     typeId: string,
 *     route: string,
 *     title: string,
 *     resultKind: "image" | "text",
 *     mediaId: string | null,
 *   }>,
 *   terms: Array<{
 *     token: string,
 *     postings: Array<{documentId: string, weight: number}>,
 *   }>,
 * }}
 */
export function decodeSearchIndex(input) {
	const root = exactObject(input, [
		"formatVersion",
		"locale",
		"normalization",
		"ordering",
		"documents",
		"terms",
	]);
	if (
		root.formatVersion !== 1 ||
		root.normalization !== "unicode_nfkd_accent_fold_lower_words_v1" ||
		root.ordering !== "weight_desc_document_id_asc"
	) {
		invalid();
	}
	const locale = boundedString(root.locale, LOCALE_PATTERN, 2, 6);
	const documentValues = boundedArray(root.documents, MAX_DOCUMENTS);
	const documents = [];
	const documentIds = new Set();
	for (const value of documentValues) {
		const document = exactObject(value, [
			"id",
			"typeId",
			"route",
			"title",
			"resultKind",
			"mediaId",
		]);
		const id = boundedString(document.id, DOCUMENT_ID_PATTERN, 32, 32);
		if (documentIds.has(id)) {
			invalid();
		}
		documentIds.add(id);
		const resultKind = document.resultKind;
		if (resultKind !== "image" && resultKind !== "text") {
			invalid();
		}
		const mediaId =
			document.mediaId === null
				? null
				: boundedString(document.mediaId, DOCUMENT_ID_PATTERN, 32, 32);
		if ((resultKind === "image") !== (mediaId !== null)) {
			invalid();
		}
		documents.push({
			id,
			typeId: boundedString(document.typeId, CANONICAL_ID_PATTERN, 1, 160),
			route: boundedString(document.route, ROUTE_PATTERN, 1, 500),
			title: boundedString(document.title, null, 1, 240),
			resultKind,
			mediaId,
		});
	}

	const termValues = boundedArray(root.terms, MAX_TERMS);
	const terms = [];
	let previousToken = null;
	for (const value of termValues) {
		const term = exactObject(value, ["token", "postings"]);
		const token = boundedString(term.token, TOKEN_PATTERN, 1, 100);
		if (previousToken !== null && compareText(previousToken, token) >= 0) {
			invalid();
		}
		previousToken = token;
		const postingValues = boundedArray(term.postings, MAX_POSTINGS, 1);
		const postings = [];
		const postingIds = new Set();
		let previousPosting = null;
		for (const postingValue of postingValues) {
			const posting = exactObject(postingValue, ["documentId", "weight"]);
			const documentId = boundedString(posting.documentId, DOCUMENT_ID_PATTERN, 32, 32);
			const weight = posting.weight;
			if (
				!documentIds.has(documentId) ||
				postingIds.has(documentId) ||
				!Number.isInteger(weight) ||
				weight < 1 ||
				weight > 255
			) {
				invalid();
			}
			if (
				previousPosting !== null &&
				(weight > previousPosting.weight ||
					(weight === previousPosting.weight &&
						compareText(documentId, previousPosting.documentId) <= 0))
			) {
				invalid();
			}
			postingIds.add(documentId);
			previousPosting = { documentId, weight };
			postings.push(previousPosting);
		}
		terms.push({ token, postings });
	}

	return {
		formatVersion: 1,
		locale,
		normalization: "unicode_nfkd_accent_fold_lower_words_v1",
		ordering: "weight_desc_document_id_asc",
		documents,
		terms,
	};
}

/** @param {unknown} value @param {string[]} keys @returns {Record<string, unknown>} */
function exactObject(value, keys) {
	if (value === null || typeof value !== "object" || Array.isArray(value)) {
		invalid();
	}
	const actualKeys = Object.keys(value);
	if (
		actualKeys.length !== keys.length ||
		keys.some((key) => !Object.hasOwn(value, key))
	) {
		invalid();
	}
	return value;
}

/** @param {unknown} value @param {number} maximum @param {number} [minimum] @returns {unknown[]} */
function boundedArray(value, maximum, minimum = 0) {
	if (!Array.isArray(value) || value.length < minimum || value.length > maximum) {
		invalid();
	}
	return value;
}

/**
 * @param {unknown} value
 * @param {RegExp | null} pattern
 * @param {number} minimum
 * @param {number} maximum
 * @returns {string}
 */
function boundedString(value, pattern, minimum, maximum) {
	if (
		typeof value !== "string" ||
		value.length < minimum ||
		value.length > maximum ||
		(pattern !== null && !pattern.test(value))
	) {
		invalid();
	}
	return value;
}

/** @param {string} left @param {string} right @returns {number} */
function compareText(left, right) {
	return left < right ? -1 : left > right ? 1 : 0;
}

/** @returns {never} */
function invalid() {
	throw new TypeError("Search index is invalid");
}
