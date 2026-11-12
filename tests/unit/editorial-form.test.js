import assert from "node:assert/strict";
import test from "node:test";
import {
	localeState,
	mountEditorialForm,
} from "../../engine/src/cms/conventional/client/editorial-form.js";
import { messagesHost } from "./client-messages.js";

class Element extends EventTarget {
	constructor(dataset = {}) {
		super();
		this.dataset = dataset;
		this.attributes = new Map();
		this.hidden = false;
		this.textContent = "";
		this.classes = new Set();
		this.classList = {
			toggle: (name, force) =>
				force ? this.classes.add(name) : this.classes.delete(name),
		};
	}
	setAttribute(key, value) {
		this.attributes.set(key, value);
	}
	getAttribute(key) {
		return this.attributes.get(key) ?? null;
	}
	removeAttribute(key) {
		this.attributes.delete(key);
	}
	focus() {
		this.focused = true;
	}
}

const LOCALES = ["ca", "es", "en"];

function fixture(pending = false) {
	const window = new Element();
	window.queueMicrotask = queueMicrotask;
	const status = new Element();
	status.textContent = "Saved revision 2. No unsaved changes.";
	const indicator = new Element();
	const controls = LOCALES.map((locale) => {
		const control = new Element();
		control.name = `title__${locale}`;
		control.type = "text";
		control.value = locale === "en" ? "" : "Title";
		return control;
	});
	const field = new Element();
	field.querySelector = (selector) =>
		selector === "legend" ? { firstChild: { textContent: "Title " } } : null;
	const panels = LOCALES.map((locale, index) => {
		const panel = new Element({ localePanel: locale });
		panel.parentElement = { closest: () => field };
		panel.contains = (control) => control === controls[index];
		panel.querySelectorAll = () => [controls[index]];
		panel.querySelector = () =>
			controls[index].getAttribute("aria-invalid") === "true" ? controls[index] : null;
		controls[index].closest = () => panel;
		return panel;
	});
	const dots = LOCALES.map(() => new Element());
	const buttons = LOCALES.map((locale, index) => {
		const button = new Element({ localeTab: locale });
		button.textContent = locale;
		button.querySelector = () => dots[index];
		return button;
	});
	const switcher = new Element();
	switcher.hidden = true;
	switcher.querySelectorAll = () => buttons;
	const tags = [new Element()];
	tags[0].hidden = true;
	const coverage = LOCALES.map((locale) => new Element({ localeCoverage: locale }));
	const section = new Element({ editorialSection: "content" });
	section.contains = (control) => controls.includes(control);
	const form = new Element({ pendingInput: String(pending) });
	const host = messagesHost();
	form.closest = (selector) => (selector === "[data-messages]" ? host : null);
	form.contains = (control) => controls.includes(control);
	form.querySelectorAll = (selector) =>
		({
			"input, textarea, select": controls,
			"[data-locale-panel]": panels,
			"[data-locale-tag]": tags,
			"[data-editorial-section]": [section],
		})[selector] ?? [];
	form.querySelector = () => null;
	const document = {
		defaultView: window,
		querySelector: (selector) =>
			({
				"[data-record-locale-switcher]": switcher,
				"[data-save-status]": status,
				'[data-section-changes="content"]': indicator,
			})[selector] ?? null,
		querySelectorAll: (selector) =>
			selector === "[data-locale-coverage]" ? coverage : [],
	};
	form.ownerDocument = document;
	return {
		form,
		window,
		controls,
		buttons,
		panels,
		switcher,
		status,
		indicator,
		tags,
		coverage,
		dots,
	};
}

test("server-rendered conflicts block repeated exact openings and focus the save error", () => {
	const f = fixture(true);
	f.status.textContent = "Resolve the record conflict before saving.";
	const host = f.form.closest("[data-messages]");
	host.dataset.messages = JSON.stringify({
		...JSON.parse(host.dataset.messages),
		"exact.keep_failed": "Exact preview blocked by the save error.",
	});
	const exactForm = new Element();
	const explanation = new Element();
	const lookup = f.form.ownerDocument.querySelector;
	f.form.ownerDocument.querySelector = (selector) => {
		if (selector === "[data-conflict-summary]") return new Element();
		if (selector === "#record-exact-preview-form") return exactForm;
		return lookup(selector);
	};
	f.form.querySelector = (selector) =>
		selector === "[data-exact-status]" ? explanation : null;
	const dispose = mountEditorialForm(f.form);
	for (let attempt = 0; attempt < 2; attempt++) {
		const submit = new Event("submit", { cancelable: true });
		exactForm.dispatchEvent(submit);
		assert.equal(submit.defaultPrevented, true, "the native new tab remains closed");
		assert.equal(explanation.textContent, "Exact preview blocked by the save error.");
		assert.equal(f.status.textContent, "Resolve the record conflict before saving.");
		assert.equal(f.status.focused, true);
	}
	dispose();
	const submit = new Event("submit", { cancelable: true });
	exactForm.dispatchEvent(submit);
	assert.equal(
		submit.defaultPrevented,
		false,
		"disposing the island removes its guard",
	);
});

test("one record language switch drives every localized panel", () => {
	const f = fixture();
	const dispose = mountEditorialForm(f.form);
	assert.equal(f.switcher.hidden, false);
	assert.equal(f.form.dataset.recordLocale, "ca");
	assert.deepEqual(
		f.panels.map((panel) => panel.hidden),
		[false, true, true],
	);
	assert.equal(f.tags[0].textContent, "CA");
	assert.equal(f.tags[0].hidden, false);
	f.buttons[2].dispatchEvent(new Event("click"));
	assert.deepEqual(
		f.panels.map((panel) => panel.hidden),
		[true, true, false],
	);
	assert.equal(f.buttons[2].getAttribute("aria-pressed"), "true");
	assert.equal(f.tags[0].textContent, "EN");
	const arrow = new Event("keydown", { cancelable: true });
	Object.defineProperty(arrow, "key", { value: "ArrowRight" });
	f.buttons[2].dispatchEvent(arrow);
	assert.equal(arrow.defaultPrevented, true);
	assert.equal(f.buttons[0].focused, true);
	assert.equal(f.controls[1].value, "Title");
	dispose();
});

test("coverage marks a translation missing only when another language has text", () => {
	const f = fixture();
	const dispose = mountEditorialForm(f.form);
	assert.equal(f.buttons[2].dataset.localeState, "missing");
	assert.equal(
		f.buttons[2].getAttribute("aria-label"),
		null,
		"the language's text remains its accessible name",
	);
	assert.ok(
		f.buttons[2].getAttribute("aria-description"),
		"coverage is separately described",
	);
	assert.ok(f.buttons[2].getAttribute("title"), "coverage remains available on hover");
	assert.equal(f.dots[2].className, "admin-dot admin-dot--missing");
	assert.equal(f.coverage[2].textContent, "Missing Title");
	assert.equal(f.coverage[0].textContent, "Complete");
	f.controls[2].value = "Title";
	f.form.dispatchEvent(new Event("input"));
	assert.equal(f.buttons[2].dataset.localeState, "changed");
	assert.equal(f.coverage[2].textContent, "Complete");
	f.controls[0].setAttribute("aria-invalid", "true");
	f.form.dispatchEvent(new Event("input"));
	assert.equal(f.buttons[0].dataset.localeState, "invalid");
	assert.equal(f.coverage[0].textContent, "Check errors");
	dispose();
});

test("locale state ranks invalid, then changes, then missing text", () => {
	assert.equal(localeState([{ invalid: true, changed: true }]), "invalid");
	assert.equal(
		localeState([{ changed: true, filled: false, siblingsFilled: true }]),
		"changed-missing",
	);
	assert.equal(localeState([{ filled: false, siblingsFilled: true }]), "missing");
	assert.equal(localeState([{ filled: false, siblingsFilled: false }]), "complete");
	assert.equal(localeState([]), "complete");
});

test("dirty feedback counts fields not locales and clears after undo", () => {
	const f = fixture();
	const dispose = mountEditorialForm(f.form);
	f.controls[0].value = "Unsaved";
	f.controls[1].value = "Also unsaved";
	f.form.dispatchEvent(new Event("input"));
	assert.equal(f.indicator.textContent, "1 changed");
	assert.equal(f.status.textContent, "Unsaved changes.");
	const unload = new Event("beforeunload", { cancelable: true });
	f.window.dispatchEvent(unload);
	assert.equal(unload.defaultPrevented, true);
	f.controls[0].value = "Title";
	f.controls[1].value = "Title";
	f.form.dispatchEvent(new Event("input"));
	assert.equal(f.indicator.textContent, "");
	assert.match(f.status.textContent, /No unsaved/);
	dispose();
});

test("controls owned by another form never make the record dirty", () => {
	const f = fixture();
	const upload = new Element();
	upload.name = "images[]";
	upload.type = "file";
	upload.value = "";
	upload.form = { id: "media-upload-form" };
	f.controls.push(upload);
	const dispose = mountEditorialForm(f.form);
	upload.value = "photo.jpg";
	f.form.dispatchEvent(new Event("change"));
	assert.match(f.status.textContent, /No unsaved/);
	dispose();
});

test("native submission and BFCache recovery maintain honest save status", () => {
	const f = fixture(true);
	const dispose = mountEditorialForm(f.form);
	assert.equal(f.status.textContent, "Unsaved changes.");
	f.form.dispatchEvent(new Event("submit"));
	assert.equal(f.status.textContent, "Saving…");
	const unload = new Event("beforeunload", { cancelable: true });
	f.window.dispatchEvent(unload);
	assert.equal(unload.defaultPrevented, false);
	f.window.dispatchEvent(new Event("pageshow"));
	assert.equal(f.status.textContent, "Unsaved changes.");
	dispose();
});

test("invalid native controls become focusable and focus restores their language", () => {
	const f = fixture();
	const dispose = mountEditorialForm(f.form);
	const invalid = new Event("invalid");
	Object.defineProperty(invalid, "target", { value: f.controls[2] });
	f.form.dispatchEvent(invalid);
	assert.deepEqual(
		f.panels.map((panel) => panel.hidden),
		[false, false, false],
	);
	const focus = new Event("focusin");
	Object.defineProperty(focus, "target", { value: f.controls[2] });
	f.form.dispatchEvent(focus);
	assert.deepEqual(
		f.panels.map((panel) => panel.hidden),
		[true, true, false],
	);
	assert.equal(f.form.dataset.recordLocale, "en");
	dispose();
});

test("repeated disposal removes listeners and restores no-script panels", () => {
	const f = fixture();
	const dispose = mountEditorialForm(f.form);
	dispose();
	dispose();
	assert.equal(f.switcher.hidden, true);
	assert.equal(f.tags[0].hidden, true);
	assert.equal(f.form.dataset.recordLocale, undefined);
	assert.deepEqual(
		f.panels.map((panel) => panel.hidden),
		[false, false, false],
	);
	f.controls[0].value = "Changed after disposal";
	f.form.dispatchEvent(new Event("input"));
	assert.match(f.status.textContent, /No unsaved/);
	f.buttons[1].dispatchEvent(new Event("click"));
	assert.deepEqual(
		f.panels.map((panel) => panel.hidden),
		[false, false, false],
	);
});

test("server-preserved pending fields retain their section count after validation", () => {
	const f = fixture(true);
	f.indicator.dataset.pendingFields = "title summary";
	const dispose = mountEditorialForm(f.form);
	assert.equal(f.indicator.textContent, "2 changed");
	f.controls[0].value = "Another unsaved title";
	f.form.dispatchEvent(new Event("input"));
	assert.equal(f.indicator.textContent, "2 changed");
	dispose();
});

test("optional per-language overrides are never reported as missing translations", () => {
	const f = fixture();
	const field = f.panels[0].parentElement.closest("fieldset");
	field.hasAttribute = (name) => name === "data-locale-optional";
	const dispose = mountEditorialForm(f.form);
	assert.equal(f.coverage[2].textContent, "Complete");
	assert.notEqual(f.buttons[2].dataset.localeState, "missing");
	dispose();
});

test("aligned group rows compare a translated cell's languages within its row only", () => {
	const f = fixture();
	/** One row's translated cell: its language panels and their text controls. */
	const cell = (values) => {
		const group = new Element({ localeGroup: "Festivals: Section" });
		return LOCALES.map((locale, index) => {
			const control = new Element();
			control.name = `festivals_i${values.join("")}_selection__${locale}`;
			control.type = "text";
			control.value = values[index];
			const panel = new Element({ localePanel: locale });
			panel.parentElement = {
				closest: (selector) =>
					selector === "[data-locale-group], fieldset" ? group : null,
			};
			panel.contains = (candidate) => candidate === control;
			panel.querySelectorAll = () => [control];
			panel.querySelector = () => null;
			control.closest = () => panel;
			return { panel, control };
		});
	};
	const rows = [cell(["Panorama", "Panorama", "Panorama"]), cell(["", "", ""])];
	f.controls[2].value = "Title";
	const panels = rows.flat();
	const formQuery = f.form.querySelectorAll;
	f.form.querySelectorAll = (selector) =>
		selector === "[data-locale-panel]"
			? panels.map((item) => item.panel)
			: selector === "input, textarea, select"
				? [...f.controls, ...panels.map((item) => item.control)]
				: formQuery(selector);
	const dispose = mountEditorialForm(f.form);
	assert.deepEqual(
		f.coverage.map((node) => node.textContent),
		["Complete", "Complete", "Complete"],
		"a row whose optional cell is empty in every language is not missing anything",
	);
	panels[5].control.value = "Radar";
	f.form.dispatchEvent(new Event("input"));
	assert.deepEqual(
		f.coverage.map((node) => node.textContent),
		["Missing Festivals: Section", "Missing Festivals: Section", "Complete"],
		"a cell with text in one language is missing in the others, named by group and child",
	);
	dispose();
});

test("multiple file inputs in the editing form are transport controls, not record values", () => {
	const f = fixture();
	const upload = new Element();
	upload.name = "images_gallery[]";
	upload.type = "file";
	upload.multiple = true;
	upload.value = "";
	upload.form = f.form;
	f.controls.push(upload);
	const dispose = mountEditorialForm(f.form);
	upload.value = "photo.jpg";
	f.form.dispatchEvent(new Event("change"));
	assert.match(
		f.status.textContent,
		/No unsaved/,
		"picking files does not change canonical content",
	);
	f.controls[0].value = "Still unsaved";
	f.form.dispatchEvent(new Event("input"));
	assert.equal(
		f.status.textContent,
		"Unsaved changes.",
		"ordinary input remains tracked",
	);
	dispose();
});
