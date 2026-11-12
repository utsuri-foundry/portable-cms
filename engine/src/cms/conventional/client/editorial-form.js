import { mountCompositionEditor } from "./composition-editor.js";
import { mountGroupRows } from "./group-rows.js";
import { mountLivePreview } from "./live-preview.js";
import { messages } from "./messages.js";
import { mountRecordAutosave } from "./record-autosave.js";
import { mountRichTextEditor } from "./rich-text-editor.js";
import { createRichPreviewEditors } from "./rich-text-preview.js";

/** Per-locale state shown on the record language switch and coverage card. */
export function localeState(panels) {
	const invalid = panels.some((panel) => panel.invalid);
	if (invalid) return "invalid";
	const missing = panels.some((panel) => !panel.filled && panel.siblingsFilled);
	if (panels.some((panel) => panel.changed))
		return missing ? "changed-missing" : "changed";
	return missing ? "missing" : "complete";
}

/**
 * Native forms remain authoritative. This island owns the record language mode,
 * local dirty feedback and coverage hints. Without it, every locale stays visible.
 */
export function mountEditorialForm(form, window = form.ownerDocument.defaultView) {
	const rollback = [];
	const own = (dispose) => {
		rollback.push(dispose);
		return dispose;
	};
	try {
		return enhanceEditorialForm(form, window, own);
	} catch (error) {
		for (const dispose of rollback.reverse()) dispose();
		throw error;
	}
}

function enhanceEditorialForm(form, window, own) {
	const t = messages(form);
	const document = form.ownerDocument;
	const previewEditors = createRichPreviewEditors();
	const richEditors = [...form.querySelectorAll("[data-rich-editor]")].map((root) =>
		own(mountRichTextEditor(root, previewEditors)),
	);
	const autosave = mountRecordAutosave(form, window);
	if (autosave) own(() => autosave.dispose());
	const compositionEditors = [
		...form.querySelectorAll("[data-composition-editor]"),
	].map((root) => own(mountCompositionEditor(root, autosave?.flush)));
	const disposeRows = own(mountGroupRows(form, window));
	const livePane = document.querySelector("[data-live-preview]");
	const disposeLive = livePane
		? own(mountLivePreview(livePane, form, previewEditors, autosave?.flush))
		: () => {};
	// File transfers and controls owned by another form are not canonical record values.
	const controls = [...form.querySelectorAll("input, textarea, select")].filter(
		(control) =>
			control.name &&
			control.type !== "hidden" &&
			control.type !== "file" &&
			control.type !== "submit" &&
			(control.form ?? form) === form,
	);
	const controlValue = (control) => {
		if (control.type === "checkbox" || control.type === "radio") return control.checked;
		if (control.multiple)
			return [...control.selectedOptions].map((option) => option.value).join("\u0000");
		return control.value;
	};
	const baseline = new Map(controls.map((control) => [control, controlValue(control)]));
	const switcher = document.querySelector("[data-record-locale-switcher]");
	const buttons = switcher ? [...switcher.querySelectorAll("[data-locale-tab]")] : [];
	const locales = buttons.map((button) => button.dataset.localeTab);
	const panels = [...form.querySelectorAll("[data-locale-panel]")];
	const tags = [...form.querySelectorAll("[data-locale-tag]")];
	own(() => {
		if (switcher) switcher.hidden = true;
		delete form.dataset.recordLocale;
		for (const panel of panels) panel.hidden = false;
		for (const tag of tags) tag.hidden = true;
	});
	const coverage = new Map(
		[...document.querySelectorAll("[data-locale-coverage]")].map((node) => [
			node.dataset.localeCoverage,
			node,
		]),
	);
	const listeners = [];
	own(() => {
		for (const remove of listeners) remove();
	});
	const listen = (target, event, handler, capture = false) => {
		target.addEventListener(event, handler, capture);
		listeners.push(() => target.removeEventListener(event, handler, capture));
	};
	let disposed = false;
	let dirty = form.dataset.pendingInput === "true";
	let submitting = false;
	let active = null;
	const status = document.querySelector("[data-save-status]");
	const initialStatus = status?.textContent;
	if (!autosave && document.querySelector("[data-conflict-summary]")) {
		const exactForm = document.querySelector("#record-exact-preview-form");
		if (exactForm)
			listen(exactForm, "submit", (event) => {
				event.preventDefault();
				const explanation = form.querySelector("[data-exact-status]");
				if (explanation) explanation.textContent = t("exact.keep_failed");
				if (status) status.textContent = initialStatus;
				status?.focus();
			});
	}
	const sections = [...form.querySelectorAll("[data-editorial-section]")].map(
		(section) => ({
			controls: controls.filter((control) => section.contains(control)),
			indicator: document.querySelector(
				`[data-section-changes="${section.dataset.editorialSection}"]`,
			),
		}),
	);
	const changed = (control) =>
		baseline.has(control) &&
		form.contains(control) &&
		baseline.get(control) !== controlValue(control);
	const panelControls = (panel) =>
		[...panel.querySelectorAll("input, textarea, select")].filter(
			(control) =>
				control.type !== "file" &&
				(control.type !== "hidden" || control.dataset.richFingerprint),
		);
	const filled = (panel) => {
		const fields = panelControls(panel);
		const rich = fields.filter((control) => control.dataset.richFingerprint);
		if (rich.length)
			return rich.some((control) =>
				JSON.parse(control.value || "[]").some((row) =>
					row.children.some((run) => run.text.trim() !== ""),
				),
			);
		return fields.some((control) =>
			control.type === "text" || control.tagName === "TEXTAREA"
				? control.value.trim() !== ""
				: false,
		);
	};
	const activate = (locale, focus = false) => {
		if (!locales.includes(locale)) return;
		active = locale;
		form.dataset.recordLocale = locale;
		for (const button of buttons) {
			const selected = button.dataset.localeTab === locale;
			button.setAttribute("aria-pressed", String(selected));
			if (selected && focus) button.focus();
		}
		for (const panel of panels) panel.hidden = panel.dataset.localePanel !== locale;
		for (const tag of tags) {
			tag.textContent = locale.toUpperCase();
			tag.hidden = false;
		}
	};
	const reveal = (control) => {
		const panel = control.closest?.("[data-locale-panel]");
		if (panel && panel.dataset.localePanel !== active)
			activate(panel.dataset.localePanel);
	};
	const update = () => {
		dirty = form.dataset.pendingInput === "true" || controls.some(changed);
		if (status && !autosave)
			status.textContent = submitting
				? t("editor.saving")
				: dirty
					? t("editor.unsaved")
					: initialStatus;
		for (const section of sections) {
			const changedFields = new Set(
				section.controls.filter(changed).map((control) => control.name.split("__")[0]),
			);
			const indicator = section.indicator;
			for (const name of (indicator?.dataset.pendingFields ?? "")
				.split(" ")
				.filter(Boolean))
				changedFields.add(name);
			if (indicator)
				indicator.textContent = changedFields.size
					? t("editor.changed", { count: changedFields.size })
					: "";
		}
		if (!locales.length) return;
		// Group sibling panels by their owning field, or by the row cell of a translated child in
		// aligned group rows, then derive one state per locale.
		const byField = new Map();
		for (const panel of panels) {
			const field =
				panel.parentElement?.closest("[data-locale-group], fieldset") ??
				panel.parentElement;
			if (!byField.has(field)) byField.set(field, []);
			byField.get(field).push(panel);
		}
		const summaries = new Map(locales.map((locale) => [locale, []]));
		for (const [field, siblings] of byField) {
			const facts = siblings.map((panel) => ({
				locale: panel.dataset.localePanel,
				filled: filled(panel),
				invalid: panelControls(panel).some(
					(control) => control.getAttribute("aria-invalid") === "true",
				),
				changed: panelControls(panel).some(changed),
			}));
			// Optional per-language overrides are never a missing translation.
			const any =
				!field?.hasAttribute?.("data-locale-optional") &&
				facts.some((fact) => fact.filled);
			const label =
				field?.dataset?.localeGroup ??
				field?.querySelector?.("legend")?.firstChild?.textContent?.trim() ??
				"";
			for (const fact of facts)
				summaries.get(fact.locale)?.push({ ...fact, siblingsFilled: any, label });
		}
		for (const button of buttons) {
			const state = localeState(summaries.get(button.dataset.localeTab) ?? []);
			button.dataset.localeState = state;
			const dot = button.querySelector("[data-locale-dot]");
			if (dot)
				dot.className = `admin-dot${
					state === "invalid"
						? " admin-dot--danger"
						: state.startsWith("changed")
							? " admin-dot--changed"
							: state === "missing"
								? " admin-dot--missing"
								: ""
				}`;
			const label = button.textContent.trim();
			button.setAttribute(
				"title",
				t("editor.state_label", {
					language: label,
					state: t(`editor.state.${state.replace("-", "_")}`),
				}),
			);
			button.setAttribute(
				"aria-description",
				t(`editor.state.${state.replace("-", "_")}`),
			);
			const fact = coverage.get(button.dataset.localeTab);
			if (fact) {
				const missing = (summaries.get(button.dataset.localeTab) ?? [])
					.filter((item) => !item.filled && item.siblingsFilled && item.label)
					.map((item) => item.label);
				fact.textContent =
					state === "invalid"
						? t("editor.check_errors")
						: missing.length
							? t("editor.missing", { fields: missing.join(", ") })
							: t("editor.complete");
				fact.classList?.toggle(
					"admin-facts__missing",
					state !== "complete" && state !== "changed",
				);
			}
		}
	};
	if (switcher && locales.length) {
		switcher.hidden = false;
		const invalidPanel = panels.find((panel) =>
			panel.querySelector('[aria-invalid="true"]'),
		);
		activate(invalidPanel?.dataset.localePanel ?? locales[0]);
		for (const [index, button] of buttons.entries()) {
			listen(button, "click", () => activate(button.dataset.localeTab));
			listen(button, "keydown", (event) => {
				const next = {
					ArrowRight: (index + 1) % buttons.length,
					ArrowLeft: (index + buttons.length - 1) % buttons.length,
					Home: 0,
					End: buttons.length - 1,
				}[event.key];
				if (next === undefined) return;
				event.preventDefault();
				activate(buttons[next].dataset.localeTab, true);
			});
		}
	}
	// Collapsed optional areas open when the outline points at them or a control inside needs attention.
	listen(
		form,
		"invalid",
		(event) => {
			const details = event.target.closest?.("details");
			if (details) details.open = true;
		},
		true,
	);
	listen(form, "click", (event) => {
		const link = event.target.closest?.("[data-section-link]");
		const toggle = link
			? form.querySelector?.(
					`#section-${link.dataset.sectionLink} [data-section-toggle]`,
				)
			: null;
		if (toggle) toggle.open = true;
	});
	listen(form, "record-saved", () => {
		for (const control of controls) baseline.set(control, controlValue(control));
		form.dataset.pendingInput = "false";
		update();
	});
	listen(form, "input", update);
	listen(form, "change", update);
	listen(form, "reset", () =>
		window.queueMicrotask(() => {
			if (!disposed) update();
		}),
	);
	listen(
		form,
		"invalid",
		(event) => {
			// Native validation focuses the first invalid field after all invalid events.
			// Keep every panel focusable until that focus arrives, even with several errors.
			if (event.target.closest?.("[data-locale-panel]"))
				for (const panel of panels) panel.hidden = false;
		},
		true,
	);
	listen(form, "focusin", (event) => reveal(event.target));
	const summary = document.querySelector("[data-validation-summary]");
	if (summary) {
		listen(summary, "click", (event) => {
			const link = event.target.closest("a");
			if (!link) return;
			const field = document.getElementById(link.hash.slice(1));
			const control = field?.matches("input, textarea, select")
				? field
				: (field?.querySelector("textarea") ??
					field?.querySelector(
						"input[name]:not([type=hidden]):not([hidden]), select[name], button, [contenteditable]",
					));
			if (control) {
				event.preventDefault();
				reveal(control);
				control.focus();
			}
		});
		const firstField = form.querySelector('[aria-invalid="true"]');
		if (firstField) {
			reveal(firstField);
			firstField.focus();
		} else summary.focus();
	}
	if (!summary) document.querySelector("[data-conflict-summary]")?.focus();
	listen(form, "submit", (event) => {
		if (event.submitter?.name === "_composition_preview") return;
		submitting = true;
		update();
	});
	listen(window, "pageshow", () => {
		submitting = false;
		update();
	});
	listen(window, "beforeunload", (event) => {
		if (autosave || !dirty || submitting) return;
		event.preventDefault();
		event.returnValue = "";
	});
	update();
	const saveFirst = document.querySelector("[data-exact-save-first]");
	if (saveFirst) saveFirst.hidden = !!autosave;
	return () => {
		if (disposed) return;
		disposed = true;
		autosave?.dispose();
		for (const remove of listeners) remove();
		for (const dispose of richEditors) dispose();
		for (const dispose of compositionEditors) dispose();
		disposeRows();
		disposeLive();
		if (switcher) switcher.hidden = true;
		delete form.dataset.recordLocale;
		for (const panel of panels) panel.hidden = false;
		for (const tag of tags) tag.hidden = true;
		if (saveFirst) saveFirst.hidden = false;
	};
}

if (typeof document !== "undefined") {
	for (const form of document.querySelectorAll("[data-editorial-form]"))
		mountEditorialForm(form);
	document.dispatchEvent(new CustomEvent("admin-enhanced", { detail: "editor" }));
}
