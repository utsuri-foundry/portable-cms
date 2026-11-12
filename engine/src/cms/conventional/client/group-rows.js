/** Where a dragged row lands: before the first other row whose middle is below the pointer. */
export function dropIndex(middles, y) {
	const index = middles.findIndex((middle) => y < middle);
	return index === -1 ? middles.length : index;
}

/** Texts that name a row position carry a `{position}` template rendered by the server. */
export function renumber(list) {
	const rows = [...list.children].filter((row) => row.hasAttribute("data-group-row"));
	for (const [index, row] of rows.entries()) {
		const position = String(index + 1);
		for (const node of row.querySelectorAll("[data-group-label]"))
			node.setAttribute(
				"aria-label",
				node.dataset.groupLabel.replaceAll("{position}", position),
			);
		for (const node of row.querySelectorAll("[data-group-text]"))
			node.textContent = node.dataset.groupText.replaceAll("{position}", position);
		for (const node of row.querySelectorAll("[data-group-field-label]"))
			node.dataset.fieldLabel = node.dataset.groupFieldLabel.replaceAll(
				"{position}",
				position,
			);
		const up = row.querySelector('[data-group-action="up"]');
		const down = row.querySelector('[data-group-action="down"]');
		if (up) up.disabled = index === 0;
		if (down) down.disabled = index === rows.length - 1;
	}
}

const order = (list) =>
	[...list.querySelectorAll('input[type="hidden"][name$="_items[]"]')]
		.map((input) => input.value)
		.join(" ");

/**
 * Rows stay a native form: every action is a submit button in a native disclosure. This island
 * adds drag reordering through the grips, closes open menus on Escape or a click elsewhere and
 * keeps the wrapping text areas of short texts on one line.
 */
export function mountGroupRows(form, window = form.ownerDocument.defaultView) {
	const document = form.ownerDocument;
	const lists = [...form.querySelectorAll("[data-group-list]")];
	if (!lists.length) return () => {};
	const listeners = [];
	const listen = (target, event, handler, options) => {
		target.addEventListener(event, handler, options);
		listeners.push(() => target.removeEventListener(event, handler, options));
	};
	const menus = () => [...form.querySelectorAll("[data-group-menu][open]")];
	for (const container of form.querySelectorAll("[data-group-rows]"))
		container.toggleAttribute("data-group-drag", true);
	for (const grip of form.querySelectorAll("[data-group-grip]")) grip.hidden = false;

	let drag = null;
	const move = (event) => {
		if (!drag || event.pointerId !== drag.pointer) return;
		const others = [...drag.list.children].filter((row) => row !== drag.row);
		const middles = others.map((row) => {
			const box = row.getBoundingClientRect();
			return box.top + box.height / 2;
		});
		const before = others[dropIndex(middles, event.clientY)] ?? null;
		if (drag.row.nextElementSibling !== before)
			drag.list.insertBefore(drag.row, before);
	};
	const drop = (event) => {
		if (!drag || event.pointerId !== drag.pointer) return;
		const { row, list, before } = drag;
		drag = null;
		row.removeAttribute("data-group-dragging");
		if (order(list) === before) return;
		renumber(list);
		// The new order is unsaved until the form is saved; the live preview follows it.
		form.dataset.pendingInput = "true";
		row.dispatchEvent(new window.Event("input", { bubbles: true }));
	};
	for (const list of lists) {
		listen(list, "pointerdown", (event) => {
			const grip = event.target.closest?.("[data-group-grip]");
			if (!grip || event.button !== 0 || drag) return;
			const row = grip.closest("[data-group-row]");
			event.preventDefault();
			grip.setPointerCapture?.(event.pointerId);
			row.toggleAttribute("data-group-dragging", true);
			drag = { row, list, pointer: event.pointerId, before: order(list) };
		});
		listen(list, "pointermove", move);
		listen(list, "pointerup", drop);
		listen(list, "pointercancel", drop);
	}

	listen(
		form,
		"toggle",
		(event) => {
			const menu = event.target;
			if (!menu.matches?.("[data-group-menu]") || !menu.open) return;
			for (const other of menus()) if (other !== menu) other.open = false;
		},
		true,
	);
	listen(document, "click", (event) => {
		for (const menu of menus()) if (!menu.contains(event.target)) menu.open = false;
	});
	listen(document, "keydown", (event) => {
		if (event.key !== "Escape") return;
		for (const menu of menus()) {
			const focused = menu.contains(document.activeElement);
			menu.open = false;
			if (focused) menu.querySelector("summary")?.focus();
		}
	});
	listen(form, "keydown", (event) => {
		if (event.key === "Enter" && event.target.matches?.("textarea[data-group-line]"))
			event.preventDefault();
	});
	listen(form, "input", (event) => {
		const field = event.target;
		if (!field.matches?.("textarea[data-group-line]") || !/[\r\n]/.test(field.value))
			return;
		const end = field.selectionEnd;
		field.value = field.value.replace(/[ \t]*[\r\n]+[ \t]*/g, " ");
		field.setSelectionRange?.(end, end);
	});

	return () => {
		for (const remove of listeners) remove();
		for (const container of form.querySelectorAll("[data-group-rows]"))
			container.removeAttribute("data-group-drag");
		for (const grip of form.querySelectorAll("[data-group-grip]")) grip.hidden = true;
	};
}
