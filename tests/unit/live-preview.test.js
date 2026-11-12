import assert from "node:assert/strict";
import test from "node:test";
import {
	clampShare,
	current,
	editedValue,
	FOCUSABLE,
	fit,
	inPlaceControl,
	linkedFields,
	mountLivePreview,
} from "../../engine/src/cms/conventional/client/live-preview.js";
import {
	createRichPreviewEditors,
	replaceBlockText,
} from "../../engine/src/cms/conventional/client/rich-text-preview.js";

class Element extends EventTarget {
	constructor(selectors = {}) {
		super();
		this.attributes = new Map();
		this.dataset = {};
		this.style = {
			values: new Map(),
			setProperty(name, value) {
				this.values.set(name, value);
			},
			removeProperty(name) {
				this.values.delete(name);
			},
		};
		this.selectors = selectors;
		this.hidden = false;
		const classes = new Set();
		this.classList = {
			add: (name) => classes.add(name),
			remove: (name) => classes.delete(name),
			contains: (name) => classes.has(name),
		};
	}
	setAttribute(name, value) {
		this.attributes.set(name, String(value));
	}
	getAttribute(name) {
		return this.attributes.get(name) ?? null;
	}
	hasAttribute(name) {
		return this.attributes.has(name);
	}
	removeAttribute(name) {
		this.attributes.delete(name);
	}
	toggleAttribute(name, force) {
		if (force) this.attributes.set(name, "");
		else this.attributes.delete(name);
	}
	querySelector(selector) {
		const found = this.selectors[selector];
		return Array.isArray(found) ? found[0] : (found ?? null);
	}
	querySelectorAll(selector) {
		const found = this.selectors[selector];
		return Array.isArray(found) ? found : found ? [found] : [];
	}
	focus() {
		this.focused = true;
	}
	before() {}
	prepend() {}
}

function fixture({ wide = true } = {}) {
	const timers = new Map();
	let nextTimer = 0;
	const requests = [];
	const window = new Element();
	window.innerWidth = 1440;
	window.location = { href: "http://localhost/records/project/1" };
	window.setTimeout = (callback) => {
		timers.set(++nextTimer, callback);
		return nextTimer;
	};
	window.clearTimeout = (id) => timers.delete(id);
	window.requestAnimationFrame = (callback) => callback();
	window.URL = URL;
	window.URLSearchParams = URLSearchParams;
	window.Event = Event;
	window.AbortController = AbortController;
	window.FormData = class {
		constructor(form) {
			this.values = form.values;
		}
		[Symbol.iterator]() {
			return this.values[Symbol.iterator]();
		}
	};
	window.ResizeObserver = class {
		observe() {}
		disconnect() {}
	};
	window.MutationObserver = class {
		constructor(callback) {
			window.localeChanged = callback;
		}
		observe() {}
		disconnect() {}
	};
	const media = new EventTarget();
	media.matches = wide;
	window.matchMedia = () => media;
	const stored = new Map();
	window.sessionStorage = {
		getItem: (key) => stored.get(key) ?? null,
		setItem: (key, value) => stored.set(key, value),
		removeItem: (key) => stored.delete(key),
	};
	window.localStorage = {
		getItem: () => {
			throw new Error("storage is blocked");
		},
		setItem: () => {
			throw new Error("storage is blocked");
		},
	};
	window.fetch = (url, options) =>
		new Promise((resolve) => {
			requests.push({ url, body: options.body, resolve });
		});
	const frames = [new Element(), new Element()];
	frames[1].hidden = true;
	for (const [index, frame] of frames.entries())
		frame.contentWindow = {
			index,
			messages: [],
			postMessage(message) {
				this.messages.push(message);
			},
		};
	const page = new Element();
	page.value = "record";
	page.options = [
		Object.assign(new Element(), { value: "record" }),
		Object.assign(new Element(), { value: "home" }),
	];
	const status = new Element();
	const update = new Element();
	update.hidden = true;
	const open = new Element();
	const close = new Element();
	const stage = new Element();
	stage.clientWidth = 720;
	stage.clientHeight = 600;
	const box = new Element();
	const screens = [1440, 768, 390].map((width) => {
		const button = new Element();
		button.dataset.liveScreen = String(width);
		return button;
	});
	const modes = ["edit", "preview"].map((value) => {
		const button = new Element();
		button.dataset.liveMode = value;
		return button;
	});
	const root = new Element({
		"[data-live-frame]": frames,
		"[data-live-status]": status,
		"[data-live-update]": update,
		"[data-live-open]": open,
		"[data-live-close]": close,
		"[data-live-page]": page,
		"[data-live-stage]": stage,
		"[data-live-box]": box,
		"[data-live-screen]": screens,
		"[data-live-mode]": modes,
	});
	root.hidden = true;
	root.dataset = { endpoint: "/records/project/1/live-preview", defaultLocale: "ca" };
	const toggle = new Element();
	toggle.hidden = true;
	const fallback = new Element();
	const saveBar = new Element();
	const form = new Element({
		"[data-live-toggle]": toggle,
		"[data-live-fallback]": fallback,
		".admin-save-bar": saveBar,
	});
	form.values = [
		["_csrf", "session-bound"],
		["title__ca", "Unsaved title"],
	];
	const shell = new Element();
	const separator = new Element();
	separator.hidden = true;
	const document = new Element({
		"[data-admin-shell]": shell,
		"[data-live-separator]": separator,
	});
	document.defaultView = window;
	document.visibilityState = "visible";
	document.createComment = () => ({ parentNode: null });
	root.ownerDocument = document;
	form.ownerDocument = document;
	form.closest = () => form;
	form.dataset.messages = JSON.stringify({
		"live.ready": "Showing your unsaved changes.",
		"live.drafts": "Showing your unsaved changes with the drafts.",
		"live.failed": "The preview could not be updated. {reason}",
		"live.updating": "Updating…",
	});
	const run = () => {
		const pending = [...timers.values()];
		timers.clear();
		for (const callback of pending) callback();
	};
	const message = (frame, data) =>
		window.dispatchEvent(
			Object.assign(new Event("message"), {
				source: frames[frame].contentWindow,
				origin: "null",
				data: { type: "cms-live", ...data },
			}),
		);
	const answer = async (index, value, ok = true) => {
		requests[index].resolve({ ok, json: async () => value });
		await new Promise((resolve) => setImmediate(resolve));
	};
	return {
		window,
		root,
		form,
		shell,
		separator,
		toggle,
		fallback,
		frames,
		status,
		update,
		open,
		close,
		page,
		screens,
		modes,
		requests,
		run,
		message,
		answer,
		stored,
	};
}

const ready = (sequence, overrides = {}) => ({
	sequence,
	url: `/live-previews/${"a".repeat(64)}/ca/projects/x/?render=${String(sequence).padStart(32, "0")}`,
	page: "record",
	pages: ["record", "home"],
	slow: false,
	...overrides,
});

test("pages are drawn at the screen width and scaled into the stage", () => {
	assert.deepEqual(fit(720, 600, 1440), { scale: 0.5, width: 1440, height: 1200 });
	assert.deepEqual(fit(900, 600, 390), { scale: 1, width: 390, height: 600 });
	assert.equal(clampShare(5), 20);
	assert.equal(clampShare(80), 60);
	assert.equal(clampShare("not a number"), 30);
	assert.equal(current(3, { sequence: 3 }), true);
	assert.equal(current(3, { sequence: 2 }), false);
	assert.equal(current(3, null), false);
});

test("the workspace posts unsaved values, swaps at the same scroll position and drops stale answers", async () => {
	const live = fixture();
	const dispose = mountLivePreview(live.root, live.form);
	assert.equal(
		live.toggle.hidden,
		false,
		"the workspace toggle replaces the no-script button",
	);
	assert.equal(live.fallback.hidden, true);
	live.toggle.dispatchEvent(new Event("click"));
	assert.equal(live.shell.classList.contains("admin-shell--live"), true);
	assert.equal(live.root.hidden, false);
	assert.equal(live.shell.dataset.liveView, "split");
	assert.equal(
		live.stored.size,
		1,
		"the open workspace survives server round trips in this tab",
	);
	assert.equal(live.requests.length, 1, "opening draws the page at once");
	const body = live.requests[0].body;
	assert.equal(body.get("_csrf"), "session-bound");
	assert.equal(body.get("title__ca"), "Unsaved title");
	assert.deepEqual(
		[body.get("_live_page"), body.get("_live_locale"), body.get("_live_sequence")],
		["record", "ca", "1"],
	);
	assert.equal(live.frames[0].style.transform, "scale(0.5)");

	await live.answer(0, ready(1));
	assert.equal(live.frames[1].src, ready(1).url, "the hidden frame loads the new page");
	live.message(0, { event: "scroll", x: 0, y: 1 }); // the outgoing blank frame cannot set the position
	live.message(1, { event: "ready" });
	assert.deepEqual(live.frames[1].contentWindow.messages.at(-1), {
		type: "cms-live",
		command: "scroll",
		x: 0,
		y: 0,
	});
	live.message(1, { event: "positioned" });
	assert.equal(
		live.frames[1].hasAttribute("data-live-active"),
		true,
		"the loaded page is shown",
	);
	assert.equal(live.frames[0].src, "about:blank", "the replaced page stops");
	assert.equal(live.open.href, ready(1).url);
	assert.equal(live.status.textContent, "Showing your unsaved changes.");
	live.message(1, { event: "scroll", x: 0, y: 640 });

	live.form.dispatchEvent(new Event("input"));
	live.form.dispatchEvent(new Event("input"));
	live.run();
	live.page.value = "home";
	live.page.dispatchEvent(new Event("change"));
	assert.equal(
		live.requests.length,
		3,
		"typing waits for a pause; each pause draws once",
	);
	assert.equal(
		live.requests[2].body.get("_live_token"),
		"a".repeat(64),
		"the workspace token is kept",
	);
	await live.answer(2, ready(3, { page: "home", drafts: true }));
	await live.answer(1, ready(2));
	assert.equal(
		live.frames[0].src,
		ready(3).url,
		"an older answer never replaces a newer one",
	);
	live.message(0, { event: "ready" });
	assert.deepEqual(live.frames[0].contentWindow.messages.at(-1), {
		type: "cms-live",
		command: "scroll",
		x: 0,
		y: 640,
	});
	live.message(0, { event: "positioned" });
	assert.equal(live.frames[0].hasAttribute("data-live-active"), true);
	assert.equal(
		live.status.textContent,
		"Showing your unsaved changes with the drafts.",
		"a page drawn with the site's drafts says so",
	);

	live.form.dispatchEvent(new Event("input"));
	live.run();
	await live.answer(3, { sequence: 4, reason: "Title: Enter a value." }, false);
	assert.match(live.status.textContent, /Title: Enter a value\./);
	assert.equal(
		live.frames[0].hasAttribute("data-live-active"),
		true,
		"the last good page stays",
	);
	assert.equal(live.update.hidden, false, "a failed render can be retried");

	live.close.dispatchEvent(new Event("click"));
	assert.equal(live.shell.classList.contains("admin-shell--live"), false);
	assert.equal(live.root.hidden, true);
	assert.equal(live.stored.size, 0);
	assert.equal(live.toggle.focused, true, "focus returns to the toggle");
	dispose();
	assert.equal(live.toggle.hidden, true);
	assert.equal(live.fallback.hidden, false);
});

test("slow sites switch to manual updates and narrow windows choose between the form and the page", async () => {
	const live = fixture({ wide: false });
	mountLivePreview(live.root, live.form);
	live.toggle.dispatchEvent(new Event("click"));
	assert.equal(
		live.shell.dataset.liveView,
		"preview",
		"narrow windows open on the page",
	);
	live.modes[0].dispatchEvent(new Event("click"));
	assert.equal(live.shell.dataset.liveView, "edit");
	assert.equal(live.modes[0].getAttribute("aria-pressed"), "true");
	await live.answer(0, ready(1, { slow: true }));
	live.message(1, { event: "ready" });
	live.message(1, { event: "positioned" });
	assert.equal(live.update.hidden, false, "slow renders offer Update preview");
	live.form.dispatchEvent(new Event("input"));
	live.run();
	assert.equal(live.requests.length, 1, "typing no longer draws automatically");
	live.update.dispatchEvent(new Event("click"));
	assert.equal(live.requests.length, 2, "Update preview draws on request");
	live.window.localeChanged();
	assert.equal(
		live.requests.length,
		3,
		"changing the record language draws that language",
	);
	live.screens[2].dispatchEvent(new Event("click"));
	assert.equal(live.frames[0].style.width, "390px");
	assert.equal(live.screens[2].getAttribute("aria-pressed"), "true");
});

test("the separator follows a drag and remembers where it was let go", () => {
	const live = fixture();
	mountLivePreview(live.root, live.form);
	live.toggle.dispatchEvent(new Event("click"));
	const pointer = (type, clientX) =>
		live.separator.dispatchEvent(
			Object.assign(new Event(type), { clientX, pointerId: 1 }),
		);
	pointer("pointermove", 1000);
	assert.equal(
		live.separator.getAttribute("aria-valuenow"),
		"30",
		"moving without a press does nothing",
	);
	pointer("pointerdown", 432);
	pointer("pointermove", 576);
	assert.equal(live.separator.getAttribute("aria-valuenow"), "40");
	pointer("pointerup", 1400);
	assert.equal(
		live.separator.getAttribute("aria-valuenow"),
		"60",
		"the width stays within its bounds",
	);
	pointer("pointermove", 300);
	assert.equal(
		live.separator.getAttribute("aria-valuenow"),
		"60",
		"the drag ends on release",
	);
	assert.equal(live.shell.style.values.get("--admin-live-form"), "60vw");
});

test("form fields and page elements are linked both ways", async () => {
	const live = fixture({ wide: false });
	const control = (value) =>
		Object.assign(new Element(), {
			value,
			getClientRects: () => [{}],
			scrollIntoView() {
				this.scrolled = true;
			},
		});
	const input = Object.assign(control("Arxiu de la boira"), { maxLength: 180 });
	const panel = new Element({ "input[type=text]": input, [FOCUSABLE]: [input] });
	const title = new Element({ '[data-locale-panel="ca"]': panel });
	title.dataset = { field: "title", fieldLabel: "Title", fieldMatch: "text" };
	const section = Object.assign(new Element(), { localName: "details", open: false });
	section.parentElement = live.form;
	title.parentElement = section;
	const picker = control("");
	const gallery = new Element({
		'input[type=hidden][name="gallery[]"]': [control("m1"), control("m2")],
		[FOCUSABLE]: [picker],
	});
	gallery.dataset = { field: "gallery", fieldLabel: "Gallery", fieldMatch: "media" };
	gallery.parentElement = live.form;
	const order = new Element();
	order.dataset = { field: "sortOrder", fieldLabel: "Order" };
	live.form.selectors["[data-field]"] = [title, gallery, order];
	live.form.contains = () => false;
	for (const field of [title, gallery, order]) field.closest = () => field;
	const focus = (target) =>
		live.form.dispatchEvent(
			Object.defineProperty(new Event("focusin"), "target", { value: target }),
		);

	assert.deepEqual(linkedFields(live.form, "ca"), [
		{
			id: "title",
			label: "Title",
			text: "Arxiu de la boira",
			media: [],
			editable: true,
			maximum: 180,
		},
		{
			id: "gallery",
			label: "Gallery",
			text: "",
			media: ["m1", "m2"],
			editable: false,
			maximum: 0,
		},
		{
			id: "sortOrder",
			label: "Order",
			text: "",
			media: [],
			editable: false,
			maximum: 0,
		},
	]);
	mountLivePreview(live.root, live.form);
	live.toggle.dispatchEvent(new Event("click"));
	await live.answer(0, ready(1));
	live.message(1, { event: "ready" });
	assert.deepEqual(
		live.frames[1].contentWindow.messages[0],
		{
			type: "cms-live",
			command: "fields",
			fields: linkedFields(live.form, "ca"),
			scale: 0.5,
		},
		"the new page learns which fields to link before it is shown",
	);
	live.message(1, { event: "positioned" });

	live.message(0, { event: "select", field: "title" });
	assert.equal(input.focused, undefined, "only the shown page can select a field");
	live.message(1, { event: "select", field: "missing" });
	live.message(1, { event: "select", field: "title" });
	assert.equal(
		live.shell.dataset.liveView,
		"edit",
		"narrow windows switch to the form",
	);
	assert.equal(section.open, true, "a collapsed section opens");
	assert.deepEqual(
		[input.focused, input.scrolled],
		[true, true],
		"the field is focused in the page's language",
	);

	live.modes[1].dispatchEvent(new Event("click"));
	const sent = live.frames[1].contentWindow.messages.length;
	focus(title);
	assert.equal(
		live.frames[1].contentWindow.messages.length,
		sent,
		"the selected field is not sent back",
	);
	focus(gallery);
	focus(gallery);
	assert.deepEqual(
		live.frames[1].contentWindow.messages.slice(sent),
		[{ type: "cms-live", command: "highlight", field: "gallery" }],
		"focusing another field shows it in the page once",
	);
	live.modes[0].dispatchEvent(new Event("click"));
	focus(order);
	assert.equal(
		live.frames[1].contentWindow.messages.length,
		sent + 1,
		"a hidden page is not scrolled",
	);
});

/** A short text field in the form, editable in place unless its control is read-only. */
function shortText(live, { readOnly = false } = {}) {
	const input = Object.assign(new Element(), {
		value: "Arxiu de la boira",
		maxLength: 20,
		readOnly,
		getClientRects: () => [{}],
		scrollIntoView() {
			this.scrolled = true;
		},
	});
	const panel = new Element({ "input[type=text]": input, [FOCUSABLE]: [input] });
	const title = new Element({ '[data-locale-panel="ca"]': panel });
	title.dataset = { field: "title", fieldLabel: "Title", fieldMatch: "text" };
	const section = Object.assign(new Element(), { localName: "details", open: false });
	section.parentElement = live.form;
	title.parentElement = section;
	const year = new Element({ "input[type=text]": input });
	year.dataset = { field: "year", fieldLabel: "Year" };
	live.form.selectors["[data-field]"] = [title, year];
	return { input, title, section, year };
}

test("only writable short text controls are edited in place, with one line within their limit", () => {
	const live = fixture();
	const { input, title, year } = shortText(live);
	assert.equal(inPlaceControl(title, "ca"), input);
	assert.equal(
		inPlaceControl(year, "ca"),
		null,
		"other field kinds keep click-to-focus",
	);
	assert.equal(inPlaceControl(undefined, "ca"), null);
	assert.equal(
		inPlaceControl(shortText(fixture(), { readOnly: true }).title, "ca"),
		null,
		"a read-only control is not edited from the page",
	);
	input.disabled = true;
	assert.equal(inPlaceControl(title, "ca"), null, "nor is a disabled one");
	assert.equal(editedValue("The Ivy 2", input), "The Ivy 2");
	assert.equal(editedValue("", input), "", "the text can be emptied");
	assert.equal(editedValue("x".repeat(20), input), "x".repeat(20));
	assert.equal(editedValue("x".repeat(21), input), null, "the field's limit applies");
	assert.equal(editedValue("two\nlines", input), null, "line breaks are refused");
	assert.equal(editedValue("tab\there", input), null, "control characters are refused");
	assert.equal(editedValue(42, input), null, "only text is accepted");
	assert.equal(editedValue(null, input), null);
	assert.equal(editedValue("x".repeat(500), { maxLength: -1 }), "x".repeat(500));
});

test("the shown page edits a short text in place and is not replaced while it does", async () => {
	const live = fixture();
	live.form.dataset.messages = JSON.stringify({
		"live.ready": "Showing your unsaved changes.",
		"live.updating": "Updating…",
		"live.editing": "Editing {field} in the page.",
	});
	const { input, section } = shortText(live);
	const typed = [];
	input.addEventListener("input", (event) => typed.push([input.value, event.bubbles]));
	mountLivePreview(live.root, live.form);
	live.toggle.dispatchEvent(new Event("click"));
	await live.answer(0, ready(1));
	live.message(1, { event: "ready" });
	live.message(1, { event: "positioned" });
	assert.equal(live.status.textContent, "Showing your unsaved changes.");

	live.message(0, { event: "edit-start", field: "title" });
	live.message(1, { event: "edit-start", field: "year" });
	live.message(1, { event: "edit", field: "title", value: "Ignored" });
	assert.equal(live.status.textContent, "Showing your unsaved changes.");
	assert.equal(
		input.value,
		"Arxiu de la boira",
		"edits count only from the shown page, for a field it started editing",
	);

	live.message(1, { event: "edit-start", field: "title" });
	assert.equal(live.status.textContent, "Editing Title in the page.");
	assert.equal(section.open, true, "the field is revealed in the form");
	assert.equal(input.scrolled, true);
	assert.equal(input.focused, undefined, "the page keeps the focus");

	for (const value of ["x".repeat(21), "two\nlines", 7, undefined])
		live.message(1, { event: "edit", field: "title", value });
	live.message(0, { event: "edit", field: "title", value: "From the hidden frame" });
	assert.deepEqual(typed, [], "invalid values and other frames change nothing");
	live.message(1, { event: "edit", field: "title", value: "The Ivy 2" });
	live.message(1, { event: "edit", field: "title", value: "The Ivy 2" });
	assert.deepEqual(
		typed,
		[["The Ivy 2", true]],
		"the form control follows and counts as changed, once per change",
	);

	live.form.dispatchEvent(new Event("input"));
	live.run();
	assert.equal(live.requests.length, 1, "no page is drawn while the text is edited");
	live.message(1, { event: "edit-end", field: "year" });
	assert.equal(live.requests.length, 1, "only the edited field ends editing");
	live.message(1, { event: "edit-end", field: "title" });
	assert.equal(live.requests.length, 2, "the page is drawn once editing ends");
	assert.equal(live.status.textContent, "Updating…");

	await live.answer(1, ready(2));
	live.message(1, { event: "edit-start", field: "title" });
	live.message(0, { event: "ready" });
	live.message(0, { event: "positioned" });
	assert.equal(
		live.frames[1].hasAttribute("data-live-active"),
		true,
		"a page that arrives during editing does not replace the edited one",
	);
	assert.equal(live.frames[0].src, "about:blank");
	live.message(1, { event: "edit-end", field: "title" });
	assert.equal(live.requests.length, 3, "and is drawn again afterwards");

	await live.answer(2, ready(3));
	live.message(0, { event: "ready" });
	live.message(0, { event: "positioned" });
	live.message(0, { event: "edit-start", field: "title" });
	live.message(0, { event: "edit-end", field: "title" });
	assert.equal(live.requests.length, 3, "editing without changes draws nothing");
	assert.equal(
		live.status.textContent,
		"Showing your unsaved changes.",
		"and restores the status",
	);
});

test("rich blocks use local document snapshots, reject stale edits and never send document runs to the frame", async () => {
	const live = fixture();
	let rows = [{ type: "paragraph", children: [{ text: "Words", marks: ["italic"] }] }];
	const surface = Object.assign(new Element(), {
		scrollIntoView() {},
		getClientRects: () => [{}],
	});
	const root = new Element();
	const field = new Element({
		"[data-rich-editor]": root,
		".admin-rich-surface": surface,
	});
	field.dataset = { field: "body", fieldLabel: "Body", fieldMatch: "rich" };
	field.parentElement = live.form;
	live.form.selectors["[data-field]"] = [field];
	const previewEditors = createRichPreviewEditors();
	const unregister = previewEditors.register(root, {
		read: () => rows,
		restore: (expected, original) => {
			if (JSON.stringify(rows) !== expected) return false;
			rows = original;
			live.form.dispatchEvent(new Event("input"));
			return true;
		},
		replace: (expected, index, value) => {
			if (JSON.stringify(rows) !== expected) return false;
			const next = replaceBlockText(rows, index, value);
			if (!next) return false;
			rows = next;
			live.form.dispatchEvent(new Event("input"));
			return true;
		},
	});
	const dispose = mountLivePreview(live.root, live.form, previewEditors);
	live.toggle.dispatchEvent(new Event("click"));
	await live.answer(0, ready(1));
	live.message(1, { event: "ready" });
	const announced = live.frames[1].contentWindow.messages.find(
		(entry) => entry.command === "fields",
	).fields;
	assert.deepEqual(
		announced.map((entry) => entry.id),
		["body", "body:0"],
	);
	assert.equal(announced[1].text, "Words");
	assert.equal(Object.hasOwn(announced[1], "snapshot"), false);
	live.message(1, { event: "positioned" });
	live.message(0, { event: "edit-start", field: "body:0" });
	live.message(0, { event: "edit", field: "body:0", value: "Hidden frame" });
	assert.equal(rows[0].children[0].text, "Words");
	live.message(1, { event: "edit-start", field: "body:0" });
	live.message(1, { event: "edit", field: "body:0", value: "More words" });
	assert.equal(rows[0].children.map((run) => run.text).join(""), "More words");
	assert.ok(rows[0].children.every((run) => run.marks.includes("italic")));
	live.message(1, { event: "edit-cancel", field: "body:0" });
	assert.deepEqual(rows[0].children, [{ text: "Words", marks: ["italic"] }]);
	live.message(1, { event: "edit-end", field: "body:0" });
	live.message(1, { event: "edit-start", field: "body:0" });
	live.message(1, { event: "edit", field: "body:0", value: "After cancellation" });
	assert.equal(
		rows[0].children.map((run) => run.text).join(""),
		"After cancellation",
		"cancelling updates the shown snapshot so the same page can be edited again",
	);
	rows = [
		{ type: "paragraph", children: [{ text: "New form words", marks: ["bold"] }] },
	];
	live.message(1, { event: "edit", field: "body:0", value: "Stale overwrite" });
	assert.deepEqual(rows[0].children, [{ text: "New form words", marks: ["bold"] }]);
	assert.equal(live.frames[1].contentWindow.messages.at(-1).command, "reject-edit");
	assert.equal(surface.focused, true);
	assert.equal(
		live.requests.length,
		3,
		"a stale rich edit redraws without saving over the form",
	);
	dispose();
	unregister();
	const longRows = Array.from({ length: 550 }, () => ({
		type: "paragraph",
		children: [{ text: "Word", marks: [] }],
	}));
	previewEditors.register(root, { read: () => longRows });
	assert.equal(
		linkedFields(live.form, "ca", previewEditors).length,
		551,
		"rich rows do not consume the older 500 top-level field budget",
	);
});

test("group children link by identity in the page language and accept edits only while their own control remains writable", async () => {
	const live = fixture();
	const make = (id, language, row = true) => {
		const input = Object.assign(new Element(), {
			value: id,
			maxLength: 160,
			scrollIntoView() {},
			getClientRects: () => [{}],
		});
		const field = new Element({
			[row ? "textarea[data-group-line]" : "input[type=text]"]: input,
			[FOCUSABLE]: [input],
		});
		field.dataset = {
			field: `credits.${id}.name`,
			fieldLabel: "Credits · Item 2 · Name",
			fieldMatch: "text",
			fieldLocale: language,
		};
		field.setAttribute("data-field-item", "");
		field.parentElement = live.form;
		return { field, input };
	};
	const first = make("a".repeat(32), "ca");
	const second = make("b".repeat(32), "ca");
	const otherLanguage = make("b".repeat(32), "es");
	const card = make("c".repeat(32), "", false);
	live.form.selectors["[data-field]"] = [
		first.field,
		otherLanguage.field,
		second.field,
		card.field,
	];
	assert.equal(inPlaceControl(otherLanguage.field, "ca"), null);
	assert.equal(
		inPlaceControl(second.field, "ca"),
		second.input,
		"row short texts use the wrapping one-line control",
	);
	assert.equal(inPlaceControl(card.field, "ca"), card.input, "cards use the same link");
	assert.deepEqual(
		linkedFields(live.form, "ca").map((field) => field.id),
		[first.field.dataset.field, second.field.dataset.field, card.field.dataset.field],
	);
	assert.equal(
		linkedFields(live.form, "ca")[1].explicit,
		true,
		"children require explicit marks, never ambiguous text guessing",
	);
	mountLivePreview(live.root, live.form);
	live.toggle.dispatchEvent(new Event("click"));
	await live.answer(0, ready(1));
	live.message(1, { event: "ready" });
	live.message(1, { event: "positioned" });
	const field = second.field.dataset.field;
	live.message(1, { event: "select", field });
	assert.equal(
		second.input.focused,
		true,
		"only the addressed child's control is focused",
	);
	assert.equal(otherLanguage.input.focused, undefined);
	live.message(1, { event: "edit-start", field });
	live.message(1, { event: "edit", field, value: "Edited name" });
	assert.equal(second.input.value, "Edited name");
	assert.equal(first.input.value, "a".repeat(32));
	assert.equal(otherLanguage.input.value, "b".repeat(32));
	live.form.selectors["[data-field]"] = [first.field, card.field];
	live.message(1, { event: "edit", field, value: "Removed item" });
	assert.equal(
		second.input.value,
		"Edited name",
		"an old frame cannot edit a removed item",
	);
	live.form.selectors["[data-field]"] = [second.field];
	second.input.readOnly = true;
	live.message(1, { event: "edit", field, value: "Read-only item" });
	assert.equal(second.input.value, "Edited name");
	second.input.readOnly = false;
	live.form.dataset.recordLocale = "es";
	live.message(1, { event: "edit", field, value: "Stale language" });
	assert.equal(second.input.value, "Edited name");
	live.window.localeChanged();
	assert.equal(
		live.requests.length,
		2,
		"a language switch redraws instead of waiting forever for the old field's blur",
	);
});
