/**
 * The address segment a text makes, as the server's `TextNormalizer::slug`: accents folded,
 * lowercase, and every other run of characters one hyphen. While typing, a trailing hyphen
 * stays so the next word can follow it.
 */
export function slugText(text, typing = false) {
	const slug = text
		.normalize("NFKD")
		.replace(/\p{Mark}/gu, "")
		.toLowerCase()
		.replace(/[^a-z0-9]+/g, "-")
		.replace(/^-+/, "");
	return typing ? slug : slug.replace(/-+$/, "");
}

/**
 * Slug controls keep a valid address as the editor types and when the field is left. A draft's
 * slug follows its title, language by language, until the editor writes a different one.
 */
export function mountSlugFields(form) {
	const document = form.ownerDocument;
	const window = document.defaultView;
	const listeners = [];
	const listen = (target, type, handler) => {
		target.addEventListener(type, handler);
		listeners.push(() => target.removeEventListener(type, handler));
	};
	const settle = (input) => {
		const next = slugText(input.value);
		if (next !== input.value) input.value = next;
	};
	for (const input of form.querySelectorAll("input[data-slug]")) {
		listen(input, "input", (event) => {
			if (event.isComposing) return;
			const value = input.value;
			const next = slugText(value, true);
			if (next === value) return;
			const caret = input.selectionStart ?? value.length;
			const at = Math.min(slugText(value.slice(0, caret), true).length, next.length);
			input.value = next;
			input.setSelectionRange?.(at, at);
		});
		listen(input, "change", () => settle(input));
		// Enter keeps the record at once, so the address must be whole before it goes.
		listen(input, "keydown", (event) => {
			if (event.key === "Enter" && !event.isComposing) settle(input);
		});
		const source = input.dataset.slugFrom
			? document.getElementById(input.dataset.slugFrom)
			: null;
		if (!source) continue;
		let proposed = slugText(source.value);
		listen(source, "input", () => {
			const next = slugText(source.value);
			if ((input.value === "" || input.value === proposed) && input.value !== next) {
				input.value = next;
				input.dispatchEvent(new window.Event("input", { bubbles: true }));
			}
			proposed = next;
		});
	}
	return () => {
		for (const remove of listeners.splice(0)) remove();
	};
}
