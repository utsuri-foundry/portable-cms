const WORD_PATTERN = /[\p{L}\p{N}]+/gu;
const COMBINING_MARKS = /\p{M}+/gu;

/**
 * Normalize human-authored search text without applying a language-specific stemmer.
 * Accent folding makes unaccented keyboard input useful in all initial locales.
 *
 * @param {string} value
 * @returns {string}
 */
export function normalizeSearchText(value) {
	return (
		String(value)
			.normalize("NFKD")
			.replace(COMBINING_MARKS, "")
			.toLocaleLowerCase("und")
			.match(WORD_PATTERN)
			?.join(" ") ?? ""
	);
}

/**
 * @param {string} value
 * @returns {string[]}
 */
export function tokenize(value) {
	const normalized = normalizeSearchText(value);
	return normalized === "" ? [] : normalized.split(" ");
}

/**
 * @param {string} value
 * @param {string} locale
 * @returns {string[]}
 */
export function splitGraphemes(value, locale) {
	if (typeof Intl.Segmenter === "function") {
		const segmenter = new Intl.Segmenter(locale, { granularity: "grapheme" });
		return Array.from(segmenter.segment(value), ({ segment }) => segment);
	}

	return Array.from(value);
}
