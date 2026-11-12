/** Rebuild a tiny allowlist BEFORE inert parsing. No resource URL or raw attribute survives. */
export function safeClipboardHtml(html) {
	if (typeof html !== "string" || html.length > 1_048_576) return null;
	const allowed = new Set([
		"p",
		"div",
		"br",
		"strong",
		"b",
		"em",
		"i",
		"u",
		"span",
		"h1",
		"h2",
		"h3",
		"h4",
		"h5",
		"h6",
		"ul",
		"ol",
		"li",
	]);
	const escapeText = (text) => text.replaceAll("<", "&lt;").replaceAll(">", "&gt;");
	let result = "";
	let offset = 0;
	let count = 0;
	for (const token of html.matchAll(/<!--[\s\S]*?-->|<\/?[a-z][^>]*>/gi)) {
		if (++count > 10000) return null;
		result += escapeText(html.slice(offset, token.index));
		offset = token.index + token[0].length;
		const tag = /^<(\/)?([a-z][a-z0-9]*)\b/i.exec(token[0]);
		if (!tag || !allowed.has(tag[2].toLowerCase())) continue;
		const name = tag[2].toLowerCase();
		if (tag[1]) {
			if (name !== "br") result += `</${name}>`;
			continue;
		}
		// Only two quoted input attributes are interpreted, never copied into output.
		const attributes = {};
		for (const attribute of token[0].matchAll(/\s([a-z-]+)\s*=\s*("[^"]*"|'[^']*')/gi))
			attributes[attribute[1].toLowerCase()] = attribute[2].slice(1, -1);
		const variants = (attributes.style ?? "").toLowerCase().split(";");
		const small =
			(attributes.class ?? "").split(/\s+/).includes("cms-small-caps") ||
			variants.some((rule) =>
				/^\s*font-variant(?:-caps)?\s*:\s*(?:all-)?small-caps\s*$/.test(rule),
			);
		result += name !== "br" && small ? `<${name} class="cms-small-caps">` : `<${name}>`;
	}
	return result + escapeText(html.slice(offset));
}
