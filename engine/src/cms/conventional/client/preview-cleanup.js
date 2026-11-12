/** Native form remains usable without JavaScript. Selection never leaves this page. */
export function mountPreviewSelection(form) {
	const checkboxes = [...form.querySelectorAll('input[name="preview_ids[]"]')];
	const count = form.querySelector("[data-preview-selected-count]");
	const submit = form.querySelector("[data-preview-delete-selected]");
	const update = () => {
		const selected = checkboxes.filter((item) => !item.disabled && item.checked).length;
		if (count)
			count.textContent = count.dataset.selectedLabel.replace(
				"{count}",
				String(selected),
			);
		if (submit) submit.disabled = selected === 0;
	};
	const reset = () => {
		for (const item of checkboxes) item.checked = false;
		update();
	};
	const window = form.ownerDocument.defaultView;
	form.addEventListener("change", update);
	window?.addEventListener("pageshow", reset);
	reset();
	return () => {
		form.removeEventListener("change", update);
		window?.removeEventListener("pageshow", reset);
	};
}

if (typeof document !== "undefined") {
	const form = document.querySelector("[data-preview-selection]");
	if (form) {
		let dispose = mountPreviewSelection(form);
		window.addEventListener("pagehide", () => dispose());
		window.addEventListener("pageshow", (event) => {
			if (event.persisted) dispose = mountPreviewSelection(form);
		});
	}
}
