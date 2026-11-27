/**
 * Required fields the live preview still needs. The status names each one as a button that
 * leads to it in the form, and the field shows the required star until it is filled.
 */

/** The empty required fields a failed render reports, or none for any other failure. */
export function missingFields(result) {
	if (!Array.isArray(result?.missing)) return [];
	return result.missing
		.filter(
			(item) =>
				typeof item?.field === "string" &&
				typeof item.label === "string" &&
				Array.isArray(item.locales) &&
				item.locales.every((locale) => typeof locale === "string"),
		)
		.slice(0, 100);
}

/** Whether a field holds something in a language: text, a choice or a selected file. */
export function filled(element, locale) {
	const scope = element.querySelector(`[data-locale-panel="${locale}"]`) ?? element;
	const controls = [...scope.querySelectorAll("input, textarea, select")].filter(
		(control) =>
			control.name &&
			// Search boxes, pickers and form bookkeeping start with an underscore.
			!control.name.startsWith("_") &&
			!control.disabled &&
			!["checkbox", "radio", "submit", "button", "file", "search"].includes(
				control.type,
			),
	);
	const rich = controls.filter((control) => control.dataset?.richFingerprint);
	if (rich.length)
		return rich.some((control) => {
			try {
				return JSON.parse(control.value || "[]").some((row) =>
					row.children.some((run) => run.text.trim() !== ""),
				);
			} catch {
				return true;
			}
		});
	return controls.some((control) => control.value.trim() !== "");
}

/** Text before and after the list in a pattern such as "To update the preview, fill in {fields}." */
export function around(pattern) {
	const at = pattern.indexOf("{fields}");
	return at < 0
		? [pattern, ""]
		: [pattern.slice(0, at), pattern.slice(at + "{fields}".length)];
}

/** The parts of a list in the reader's language: "A, B and C". */
export function listParts(names, language) {
	try {
		return new Intl.ListFormat(language, { type: "conjunction" }).formatToParts(names);
	} catch {
		return names.flatMap((value, index) => [
			...(index ? [{ type: "literal", value: ", " }] : []),
			{ type: "element", value },
		]);
	}
}

/**
 * The guide shown while the preview waits for required values. `reveal` opens and focuses a
 * field; `choose` switches the record to a language.
 */
export function mountMissingGuide({ form, status, t, locale, reveal, choose }) {
	const document = form.ownerDocument;
	/** Field ID to the reported item and its form element. */
	let wanted = new Map();
	const element = (id) =>
		[...form.querySelectorAll("[data-field]")].find(
			(candidate) =>
				candidate.dataset.field === id && !candidate.hasAttribute("data-field-item"),
		);
	/** The field's own label words, where required fields carry their star. */
	const label = (field) =>
		field.querySelector(":scope > legend .admin-legend__text") ??
		field.querySelector(":scope > legend") ??
		field.querySelector(":scope > label") ??
		field.querySelector(":scope > .admin-field__label");
	/** The field's star, its own for a field required on every save, otherwise one added while needed. */
	const mark = (field, needed) => {
		const words = label(field);
		if (!words) return;
		field.toggleAttribute("data-live-needed", needed);
		let star = words.querySelector(".admin-field__required");
		if (needed && !star) {
			star = document.createElement("span");
			star.className = "admin-field__required";
			star.dataset.liveStar = "";
			const glyph = document.createElement("span");
			glyph.setAttribute("aria-hidden", "true");
			glyph.textContent = "*";
			const spoken = document.createElement("span");
			spoken.className = "admin-visually-hidden";
			spoken.textContent = t("live.needed");
			star.append(glyph, spoken);
			const tag = words.querySelector("[data-locale-tag]");
			if (tag) tag.before(star, " ");
			else words.append(" ", star);
		}
		if (!star) return;
		if (!needed && star.dataset.liveStar !== undefined) {
			const space =
				star.previousSibling?.nodeValue === " "
					? star.previousSibling
					: star.nextSibling;
			if (space?.nodeValue === " ") space.remove();
			star.remove();
			return;
		}
		star.classList.toggle("admin-field__required--needed", needed);
	};
	/** Marks the reported fields still empty in the language being edited; filled ones lose the mark. */
	const refresh = () => {
		for (const [id, item] of wanted) {
			const field = element(id);
			if (!field) continue;
			const here = item.locales.length === 0 || item.locales.includes(locale());
			mark(field, here && !filled(field, locale()));
		}
	};
	const clear = () => {
		for (const id of wanted.keys()) {
			const field = element(id);
			if (field) mark(field, false);
		}
		wanted = new Map();
	};
	const lead = (item) => {
		if (item.locales.length && !item.locales.includes(locale()))
			choose(item.locales[0]);
		reveal(item.field);
	};
	/** Shows the reported fields the form has; false when it has none of them. */
	const show = (items) => {
		clear();
		const known = items.filter((item) => element(item.field));
		if (!known.length) return false;
		wanted = new Map(known.map((item) => [item.field, item]));
		const [before, after] = around(t("live.missing"));
		const names = new Map(known.map((item) => [item.label, item]));
		const parts = listParts(
			[...names.keys()],
			document.documentElement?.lang || undefined,
		).map((part) => {
			if (part.type !== "element") return part.value;
			const button = document.createElement("button");
			button.type = "button";
			button.className = "admin-live__field";
			button.textContent = part.value;
			button.addEventListener("click", () => lead(names.get(part.value)));
			return button;
		});
		status.replaceChildren(before, ...parts, after);
		refresh();
		return true;
	};
	return { show, clear, refresh };
}
