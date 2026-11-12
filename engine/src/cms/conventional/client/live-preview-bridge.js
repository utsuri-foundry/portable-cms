/**
 * Engine-owned script added only to live preview documents, never to published pages. It keeps
 * the page's scroll position across updates and links page elements with the record's form:
 * hovering an element names its field, clicking it asks the workspace to focus the field, and
 * the workspace asks for a field's element to be shown. A short text shown exactly as typed is
 * edited in place instead: each change is sent to the form, which owns the value. It runs in
 * the page's opaque origin and talks only to the parent window at the CMS origin that served it.
 */
(() => {
	const script = document.currentScript;
	if (!(script instanceof HTMLScriptElement) || window.parent === window) return;
	const origin = new URL(script.src).origin;
	const post = (message) =>
		window.parent.postMessage({ type: "cms-live", ...message }, origin);
	const reduced = window.matchMedia("(prefers-reduced-motion: reduce)");
	let target = null;
	let frame = 0;
	/** Field labels by ID, as the form names them. */
	let labels = new Map();
	/** Elements recognised by their value where the profile marked nothing for that field. */
	let found = new Map();
	let hovered = null;
	let highlighted = null;
	let fade = 0;
	/** The workspace draws the page scaled down; the outline keeps its size on screen. */
	let zoom = 1;
	/** Short texts that can be edited in place, by field: value in the page's language and limit. */
	let editable = new Map();
	/** The element edited in place, its field, other elements showing it and the text so far. */
	let editing = null;
	/** Elements that hold controls, code or graphics, which are never edited as text. */
	const FIXED =
		"button, input, select, option, textarea, svg, math, script, style, template, noscript, [contenteditable]";

	// Fixed colours, a light ring inside a dark outline, stay visible on any page background,
	// and the label names the field, so colour is never the only signal.
	const mark = document.createElement("cms-live-mark");
	const name = document.createElement("cms-live-label");
	mark.append(name);
	const style = (element, rules) => {
		for (const [property, value] of Object.entries(rules))
			element.style.setProperty(property, value, "important");
	};
	style(mark, {
		position: "fixed",
		"z-index": "2147483647",
		"pointer-events": "none",
		display: "none",
		margin: "0",
		padding: "0",
		border: "0",
		"box-sizing": "border-box",
	});
	style(name, {
		position: "absolute",
		display: "block",
		margin: "0",
		background: "#0b57d0",
		color: "#ffffff",
		"letter-spacing": "normal",
		"text-transform": "none",
		"white-space": "nowrap",
	});

	const draw = () => {
		// While a text is edited in place, its outline stays on it rather than following the pointer.
		const shown = editing ?? hovered ?? highlighted;
		if (!shown?.element.isConnected) {
			mark.style.setProperty("display", "none", "important");
			return;
		}
		if (!mark.isConnected) document.documentElement.append(mark);
		const box = shown.element.getBoundingClientRect();
		const px = (value) => `${value * zoom}px`;
		style(mark, {
			display: "block",
			left: `${box.left}px`,
			top: `${box.top}px`,
			width: `${box.width}px`,
			height: `${box.height}px`,
			outline: `${px(3)} solid #0b57d0`,
			"outline-offset": px(2),
			"box-shadow": `0 0 0 ${px(2)} #ffffff`,
			"border-radius": px(2),
		});
		name.textContent = labels.get(shown.field) ?? shown.field;
		// The label sits above the element unless that would leave the window, and never past its left edge.
		const above = box.top >= 28 * zoom;
		style(name, {
			left: box.left < 5 * zoom ? "0" : px(-5),
			top: above ? "auto" : `calc(100% + ${px(5)})`,
			bottom: above ? `calc(100% + ${px(5)})` : "auto",
			padding: `${px(2)} ${px(6)}`,
			font: `600 ${px(12)}/1.4 system-ui, sans-serif`,
			"border-radius": px(3),
		});
	};
	const fieldOf = (start) => {
		for (let node = start; node instanceof Element; node = node.parentElement) {
			const marked = node.getAttribute("data-cms-field");
			if (marked && labels.has(marked)) return { element: node, field: marked };
			const field = found.get(node);
			if (field) return { element: node, field };
		}
		return null;
	};
	/** A field's entry when the element shows exactly its value as plain text, otherwise null. */
	const inPlace = (element, field) => {
		const entry = editable.get(field);
		if (!entry || element.closest(FIXED)) return null;
		if (entry.rich) {
			if (
				!["P", "H2", "H3", "LI"].includes(element.tagName) ||
				[...element.querySelectorAll("*")].some(
					(node) => !["STRONG", "EM", "U", "SPAN", "A"].includes(node.tagName),
				)
			)
				return null;
			return element.textContent === entry.value ? entry : null;
		}
		if (element.childElementCount > 0) return null;
		return text(element.textContent ?? "") === entry.value ? entry : null;
	};
	const elementsOf = (field) => {
		const marked = [...document.querySelectorAll("[data-cms-field]")].filter(
			(element) => element.getAttribute("data-cms-field") === field,
		);
		return marked.length > 0
			? marked
			: [...found].filter(([, value]) => value === field).map(([element]) => element);
	};
	const text = (value) => value.replace(/\s+/g, " ").trim();
	/** The innermost elements whose whole text is one of the values, in one pass over the page. */
	const byText = (values) => {
		const longest = Math.max(0, ...[...values.keys()].map((value) => value.length));
		const matches = [];
		if (longest === 0 || !document.body) return matches;
		for (const element of document.body.querySelectorAll("*")) {
			const raw = element.textContent ?? "";
			if (raw.length > longest * 4 + 200) continue;
			const value = text(raw);
			if (values.has(value) && !element.closest("script, style, template, noscript"))
				matches.push({ element, value });
		}
		return matches.filter(
			(match) =>
				!matches.some(
					(other) =>
						other !== match &&
						other.value === match.value &&
						match.element.contains(other.element),
				),
		);
	};
	const showsMedia = (image, id) =>
		[
			image.getAttribute("src") ?? "",
			...(image.getAttribute("srcset") ?? "").split(","),
		]
			.map((source) => source.trim().split(/\s+/)[0].split("/").pop() ?? "")
			.some((file) => file.startsWith(`${id}-`));
	/** Links fields the profile did not mark by their short text or their images. */
	const recognise = (fields) => {
		labels = new Map(fields.map((field) => [field.id, field.label]));
		// A value whose spacing the page would change is not shown exactly, so it is not edited here.
		editable = new Map(
			fields
				.filter(
					(field) =>
						field.editable &&
						(field.rich || (field.value !== "" && field.value === text(field.value))),
				)
				.map((field) => [
					field.id,
					{ value: field.value, maximum: field.maximum, rich: field.rich },
				]),
		);
		found = new Map();
		const marked = new Set(
			[...document.querySelectorAll("[data-cms-field]")].map((element) =>
				element.getAttribute("data-cms-field"),
			),
		);
		const unmarked = fields.filter((field) => !field.explicit && !marked.has(field.id));
		const values = new Map();
		for (const field of unmarked)
			if (field.text !== "" && !values.has(field.text))
				values.set(field.text, field.id);
		// Inside an element the profile marked, that field's mark wins over a guess.
		const free = (element) => !element.closest("[data-cms-field]");
		for (const { element, value } of byText(values))
			if (free(element)) found.set(element, values.get(value));
		for (const image of [...document.images].filter(free)) {
			const field = unmarked.find((candidate) =>
				candidate.media.some((id) => showsMedia(image, id)),
			);
			if (field && !found.has(image)) found.set(image, field.id);
		}
	};
	const fields = (value) =>
		(Array.isArray(value) ? value.slice(0, 5500) : [])
			.filter(
				(field) =>
					typeof field?.id === "string" &&
					/^[A-Za-z][A-Za-z0-9_-]{0,79}(?::[0-9]{1,4}|\.[a-f0-9]{32}\.[A-Za-z][A-Za-z0-9_-]{0,79})?$/.test(
						field.id,
					),
			)
			.map((field) => ({
				id: field.id,
				label: typeof field.label === "string" ? field.label.slice(0, 400) : field.id,
				explicit: field.explicit === true,
				text: typeof field.text === "string" ? text(field.text).slice(0, 1000) : "",
				value:
					typeof field.text === "string" &&
					field.text.length <= (field.rich === true ? 100_000 : 1000)
						? field.text
						: "",
				rich: field.rich === true && /:[0-9]+$/.test(field.id),
				editable: field.editable === true,
				maximum:
					Number.isInteger(field.maximum) && field.maximum > 0
						? Math.min(field.maximum, 100_000)
						: 0,
				media: Array.isArray(field.media)
					? field.media.filter(
							(id) => typeof id === "string" && /^[A-Za-z0-9_-]{1,64}$/.test(id),
						)
					: [],
			}));
	const highlight = (field) => {
		window.clearTimeout(fade);
		const element = elementsOf(field).find(
			(candidate) => candidate.getClientRects().length > 0,
		);
		// The newest action wins: a focused field replaces the outline left by the pointer.
		hovered = null;
		highlighted = element ? { element, field } : null;
		draw();
		if (!element) return;
		element.scrollIntoView({
			block: "center",
			behavior: reduced.matches ? "instant" : "smooth",
		});
		fade = window.setTimeout(() => {
			highlighted = null;
			draw();
		}, 1600);
	};

	/** Puts the caret where the editor clicked, or at the end of the text. */
	const caret = (element, x, y) => {
		const selection = window.getSelection();
		if (!selection) return;
		const range = document.createRange();
		const point = document.caretPositionFromPoint?.(x, y);
		const legacy = point ? null : document.caretRangeFromPoint?.(x, y);
		const node = point?.offsetNode ?? legacy?.startContainer;
		if (node && element.contains(node))
			range.setStart(node, point?.offset ?? legacy?.startOffset ?? 0);
		else {
			range.selectNodeContents(element);
			range.collapse(false);
		}
		range.collapse(true);
		selection.removeAllRanges();
		selection.addRange(range);
	};
	const start = (element, field, entry, event) => {
		const others = elementsOf(field).filter(
			(other) => other !== element && inPlace(other, field),
		);
		editing = { element, field, entry, others, last: entry.value };
		editing.original = [...element.childNodes].map((node) => node.cloneNode(true));
		editing.label = element.getAttribute("aria-label");
		if (element.textContent !== entry.value) element.textContent = entry.value;
		try {
			element.contentEditable = "plaintext-only";
		} catch {
			element.contentEditable = "true";
		}
		element.setAttribute("aria-label", labels.get(field) ?? field);
		element.focus({ preventScroll: true });
		caret(element, event.clientX, event.clientY);
		window.clearTimeout(fade);
		highlighted = null;
		draw();
		post({ event: "edit-start", field });
	};
	/** Shows a value in every element of the field and sends it to the form. */
	const write = (value) => {
		editing.last = value;
		if (
			(!editing.entry.rich && editing.element.childElementCount > 0) ||
			editing.element.textContent !== value
		) {
			editing.element.textContent = value;
			caret(editing.element, -1, -1);
		}
		for (const other of editing.others) other.textContent = value;
		// The text grows, shrinks or wraps as it is typed; the outline follows its box.
		draw();
		post({ event: "edit", field: editing.field, value });
	};
	/** One line within the field's limit; anything longer keeps the text it had. */
	const changed = () => {
		if (!editing) return;
		const raw = editing.element.textContent ?? "";
		const value = editing.entry.rich ? raw : raw.replace(/\p{Cc}+/gu, " ");
		const { maximum } = editing.entry;
		if (maximum > 0 && value.length > maximum) write(editing.last);
		else if (
			value !== editing.last ||
			(!editing.entry.rich && editing.element.childElementCount > 0) ||
			editing.element.textContent !== value
		)
			write(value);
	};
	const finish = () => {
		if (!editing) return;
		const { element, field, label } = editing;
		editing.entry.value = editing.last;
		editing = null;
		element.removeAttribute("contenteditable");
		if (label === null) element.removeAttribute("aria-label");
		else element.setAttribute("aria-label", label);
		draw();
		post({ event: "edit-end", field });
	};
	const within = (event) => editing?.element.contains(event.target) === true;
	const formEdit = () => post({ event: "form-edit", field: editing.field });

	window.addEventListener(
		"scroll",
		() => {
			if (frame) return;
			frame = window.requestAnimationFrame(() => {
				frame = 0;
				draw();
				post({ event: "scroll", x: window.scrollX, y: window.scrollY });
			});
		},
		{ passive: true },
	);
	window.addEventListener("resize", draw);
	document.addEventListener("pointerover", (event) => {
		hovered = fieldOf(event.target);
		draw();
	});
	document.documentElement.addEventListener("pointerleave", () => {
		hovered = null;
		draw();
	});
	// A linked element opens its field instead of following a link inside it.
	document.addEventListener(
		"click",
		(event) => {
			if (within(event)) {
				event.preventDefault();
				event.stopPropagation();
				return;
			}
			const hit = fieldOf(event.target);
			if (!hit) return;
			event.preventDefault();
			event.stopPropagation();
			const entry = inPlace(hit.element, hit.field);
			if (entry) start(hit.element, hit.field, entry, event);
			else post({ event: "select", field: hit.field });
		},
		true,
	);
	// While a text is edited, its keys belong to it rather than to the page's own shortcuts.
	window.addEventListener(
		"keydown",
		(event) => {
			if (!within(event)) return;
			event.stopPropagation();
			if (event.isComposing) return;
			if (event.key === "Escape") {
				event.preventDefault();
				if (editing.entry.rich) {
					editing.last = editing.entry.value;
					editing.element.replaceChildren(...editing.original);
					post({ event: "edit-cancel", field: editing.field });
				} else write(editing.entry.value);
				editing.element.blur();
			} else if (event.key === "Enter") {
				event.preventDefault();
				if (editing.entry.rich) formEdit();
				else editing.element.blur();
			} else if (
				editing.entry.rich &&
				(event.ctrlKey || event.metaKey) &&
				["b", "i", "u", "k"].includes(event.key.toLowerCase())
			) {
				event.preventDefault();
				formEdit();
			} else if (editing.entry.rich && ["Backspace", "Delete"].includes(event.key)) {
				const range = window.getSelection()?.rangeCount
					? window.getSelection().getRangeAt(0)
					: null;
				if (range?.collapsed) {
					const before = range.cloneRange();
					before.selectNodeContents(editing.element);
					before.setEnd(range.startContainer, range.startOffset);
					if (
						(event.key === "Backspace" && before.toString().length === 0) ||
						(event.key === "Delete" && before.toString().length === editing.last.length)
					) {
						event.preventDefault();
						formEdit();
					}
				}
			}
		},
		true,
	);
	window.addEventListener(
		"beforeinput",
		(event) => {
			if (!within(event)) return;
			const kind = event.inputType;
			const selection = window.getSelection();
			if (
				editing.entry.rich &&
				selection?.rangeCount &&
				!editing.element.contains(selection.getRangeAt(0).commonAncestorContainer)
			) {
				event.preventDefault();
				formEdit();
				return;
			}
			if (
				kind === "insertParagraph" ||
				kind === "insertLineBreak" ||
				kind.startsWith("format")
			) {
				event.preventDefault();
				if (editing.entry.rich) formEdit();
			}
		},
		true,
	);
	window.addEventListener(
		"input",
		(event) => {
			if (within(event)) changed();
		},
		true,
	);
	// Pasted text arrives as one plain line; dropped content is refused.
	window.addEventListener(
		"paste",
		(event) => {
			if (!within(event)) return;
			event.preventDefault();
			const selection = window.getSelection();
			if (!selection?.rangeCount) return;
			const range = selection.getRangeAt(0);
			const plain = event.clipboardData?.getData("text/plain") ?? "";
			if (editing.entry.rich && /[\r\n\t]/u.test(plain)) return formEdit();
			if (
				editing.entry.rich &&
				plain.length + editing.last.length - range.toString().length >
					editing.entry.maximum
			)
				return formEdit();
			if (!editing.element.contains(range.commonAncestorContainer)) return;
			const pasted = document.createTextNode(editing.entry.rich ? plain : text(plain));
			range.deleteContents();
			range.insertNode(pasted);
			range.setStartAfter(pasted);
			range.collapse(true);
			selection.removeAllRanges();
			selection.addRange(range);
			editing.element.normalize();
			changed();
		},
		true,
	);
	window.addEventListener(
		"drop",
		(event) => {
			if (within(event)) {
				event.preventDefault();
				if (editing.entry.rich) formEdit();
			}
		},
		true,
	);
	window.addEventListener("focusout", (event) => {
		if (editing && event.target === editing.element) finish();
	});
	window.addEventListener("message", (event) => {
		const data = event.data;
		if (event.source !== window.parent || event.origin !== origin) return;
		if (data?.type !== "cms-live") return;
		if (data.command === "reject-edit" && editing?.field === data.field) {
			editing.element.replaceChildren(...editing.original);
			finish();
		}
		if (data.command === "fields") recognise(fields(data.fields));
		if (data.command === "fields" || data.command === "scale") {
			zoom = Math.min(10, Math.max(1, 1 / (Number(data.scale) || 1)));
			draw();
		}
		if (data.command === "highlight" && typeof data.field === "string")
			highlight(data.field);
		if (data.command !== "scroll") return;
		target = {
			left: Number(data.x) || 0,
			top: Number(data.y) || 0,
			behavior: "instant",
		};
		window.scrollTo(target);
		post({ event: "positioned" });
	});
	// Images can still grow the page after the first position, which then fell short.
	window.addEventListener("load", () => {
		if (target && window.scrollY < target.top) window.scrollTo(target);
	});
	post({ event: "ready" });
})();
