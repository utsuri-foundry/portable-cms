/**
 * Interface text for browser islands. The server renders the active catalog into the
 * nearest `data-messages` attribute; islands never carry their own user-facing text.
 */
export function messages(element) {
	const source = element?.closest?.("[data-messages]");
	let bundle = {};
	try {
		const parsed = JSON.parse(source?.dataset.messages ?? "{}");
		if (parsed && typeof parsed === "object") bundle = parsed;
	} catch {
		bundle = {};
	}
	const locale = element?.ownerDocument?.documentElement?.lang || "en";
	const format = (key, parameters = {}) => {
		const pattern = bundle[key];
		if (typeof pattern !== "string") return key;
		return pattern.replace(/\{([a-z_]+)\}/g, (match, name) =>
			Object.hasOwn(parameters, name) ? String(parameters[name]) : match,
		);
	};
	format.plural = (key, count, parameters = {}) => {
		let category = "other";
		try {
			category = new Intl.PluralRules(locale).select(count);
		} catch {
			category = count === 1 ? "one" : "other";
		}
		const chosen = Object.hasOwn(bundle, `${key}_${category}`)
			? `${key}_${category}`
			: `${key}_other`;
		return format(chosen, { count, ...parameters });
	};
	return format;
}
