import assert from "node:assert/strict";
import test from "node:test";
import {
	around,
	filled,
	listParts,
	missingFields,
	mountMissingGuide,
} from "../../engine/src/cms/conventional/client/live-preview-missing.js";

/** A small element tree: class and data selectors search descendants, others use a map. */
class Node extends EventTarget {
	constructor(name = "div", selectors = {}) {
		super();
		this.localName = name;
		this.children = [];
		this.parent = null;
		this.dataset = {};
		this.attributes = new Map();
		this.selectors = selectors;
		this.className = "";
		this.textContent = "";
		this.classList = {
			toggle: (value, force) => {
				const names = new Set(this.className.split(" ").filter(Boolean));
				if (force) names.add(value);
				else names.delete(value);
				this.className = [...names].join(" ");
			},
			contains: (value) => this.className.split(" ").includes(value),
		};
	}
	append(...nodes) {
		for (const node of nodes) {
			const child = typeof node === "string" ? { nodeValue: node } : node;
			child.parent = this;
			child.remove = () => this.detach(child);
			this.children.push(child);
		}
	}
	replaceChildren(...nodes) {
		this.children = [];
		this.append(...nodes);
	}
	before(...nodes) {
		const siblings = this.parent.children;
		for (const node of nodes) {
			const child = typeof node === "string" ? { nodeValue: node } : node;
			child.parent = this.parent;
			child.remove = () => this.parent.detach(child);
			siblings.splice(siblings.indexOf(this), 0, child);
		}
	}
	detach(child) {
		this.children.splice(this.children.indexOf(child), 1);
	}
	get previousSibling() {
		const siblings = this.parent?.children ?? [];
		return siblings[siblings.indexOf(this) - 1] ?? null;
	}
	get nextSibling() {
		const siblings = this.parent?.children ?? [];
		return siblings[siblings.indexOf(this) + 1] ?? null;
	}
	get text() {
		return this.children
			.map((child) => child.nodeValue ?? (child.text || child.textContent))
			.join("");
	}
	descendants() {
		return this.children.flatMap((child) =>
			child instanceof Node ? [child, ...child.descendants()] : [],
		);
	}
	querySelectorAll(selector) {
		if (Object.hasOwn(this.selectors, selector))
			return [this.selectors[selector]].flat();
		if (selector.startsWith("."))
			return this.descendants().filter((node) =>
				node.classList.contains(selector.slice(1)),
			);
		const data = /^\[data-([a-z-]+)\]$/.exec(selector);
		if (data) {
			const key = data[1].replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());
			return this.descendants().filter((node) => key in node.dataset);
		}
		return [];
	}
	querySelector(selector) {
		return this.querySelectorAll(selector)[0] ?? null;
	}
	setAttribute(name, value) {
		this.attributes.set(name, String(value));
	}
	hasAttribute(name) {
		return this.attributes.has(name);
	}
	toggleAttribute(name, force) {
		if (force) this.attributes.set(name, "");
		else this.attributes.delete(name);
	}
}

const control = (name, value, type = "text") =>
	Object.assign(new Node("input"), { name, value, type, disabled: false });

/** A form with a localized title that already carries its star and an image field without one. */
function form() {
	const document = {
		documentElement: { lang: "en" },
		createElement: (name) => new Node(name),
	};
	const titleLegend = new Node("legend");
	const star = new Node("span");
	star.className = "admin-field__required";
	const tag = new Node("span");
	tag.dataset.localeTag = "";
	titleLegend.append("Title ", star, " ", tag);
	const panels = {
		ca: new Node("div", { "input, textarea, select": [control("title__ca", "")] }),
		en: new Node("div", { "input, textarea, select": [control("title__en", "")] }),
	};
	const title = new Node("fieldset", {
		":scope > legend": titleLegend,
		'[data-locale-panel="ca"]': panels.ca,
		'[data-locale-panel="en"]': panels.en,
	});
	title.dataset = { field: "title" };
	const words = new Node("span");
	words.append("Main still");
	const stillControls = [
		control("_query_still", "mist", "search"),
		control("_present_still", "1", "hidden"),
	];
	const still = new Node("fieldset", {
		":scope > legend .admin-legend__text": words,
		"input, textarea, select": stillControls,
	});
	still.dataset = { field: "still" };
	const item = new Node("div");
	item.dataset = { field: "still" };
	item.setAttribute("data-field-item", "");
	const root = new Node("form", { "[data-field]": [item, title, still] });
	root.ownerDocument = document;
	return { root, title, star, still, words, panels, stillControls, titleLegend };
}

const messages = {
	"live.missing": "To update the preview, fill in {fields}.",
	"live.needed": "Needed for the preview",
};
const t = (key) => messages[key];

test("only well formed missing fields from a failed render are used", () => {
	assert.deepEqual(missingFields({ reason: "No" }), []);
	assert.deepEqual(
		missingFields({
			missing: [
				{ field: "title", locales: ["en"], label: "Title (English)" },
				{ field: "still", locales: "en", label: "Main still" },
				{ field: 3, locales: [], label: "Year" },
				null,
			],
		}),
		[{ field: "title", locales: ["en"], label: "Title (English)" }],
	);
});

test("a field is filled by text, a selection or rich text with words, never by search boxes or bookkeeping", () => {
	const { title, still, stillControls, panels } = form();
	assert.equal(filled(title, "ca"), false);
	panels.ca.selectors["input, textarea, select"][0].value = "  ";
	assert.equal(filled(title, "ca"), false, "spaces are empty");
	panels.ca.selectors["input, textarea, select"][0].value = "Arxiu";
	assert.equal(filled(title, "ca"), true);
	assert.equal(filled(title, "en"), false, "each language is checked on its own");
	assert.equal(
		filled(still, "ca"),
		false,
		"searching and form bookkeeping are not values",
	);
	stillControls.push(control("still[]", "c46ebe482c1f8d82147b802ec76906e8", "hidden"));
	assert.equal(filled(still, "ca"), true, "a selected image is a value");
	const rows = control(
		"body__ca__rows",
		JSON.stringify([{ children: [{ text: " " }] }]),
		"hidden",
	);
	rows.dataset.richFingerprint = "1";
	const rich = new Node("div", { "input, textarea, select": [rows] });
	assert.equal(filled(rich, "ca"), false, "rich text needs words");
	rows.value = JSON.stringify([{ children: [{ text: "Words" }] }]);
	assert.equal(filled(rich, "ca"), true);
});

test("the list sits where the sentence places it, in the reader's language", () => {
	assert.deepEqual(around("Füllen Sie {fields} aus."), ["Füllen Sie ", " aus."]);
	assert.deepEqual(around("Fill in the fields."), ["Fill in the fields.", ""]);
	assert.deepEqual(
		listParts(["Títol", "Fotograma principal"], "ca").map((part) => part.value),
		["Títol", " i ", "Fotograma principal"],
	);
});

test("the status leads to each missing field, which shows the required star until it is filled", () => {
	const { root, title, star, still, words, panels } = form();
	const status = new Node("p");
	let language = "ca";
	const revealed = [];
	const chosen = [];
	const guide = mountMissingGuide({
		form: root,
		status,
		t,
		locale: () => language,
		reveal: (id) => revealed.push(id),
		choose: (value) => {
			chosen.push(value);
			language = value;
		},
	});
	assert.equal(
		guide.show([{ field: "retired", locales: [], label: "Retired" }]),
		false,
		"fields the form does not have leave the plain reason",
	);
	assert.equal(
		guide.show([
			{ field: "title", locales: ["en"], label: "Title (English)" },
			{ field: "still", locales: [], label: "Main still" },
		]),
		true,
	);
	assert.equal(
		status.text,
		"To update the preview, fill in Title (English) and Main still.",
	);
	const buttons = status.children.filter((child) => child.localName === "button");
	assert.deepEqual(
		buttons.map((button) => [button.type, button.className, button.textContent]),
		[
			["button", "admin-live__field", "Title (English)"],
			["button", "admin-live__field", "Main still"],
		],
	);
	assert.equal(
		title.hasAttribute("data-live-needed"),
		false,
		"a field filled in the shown language is not marked",
	);
	assert.equal(star.classList.contains("admin-field__required--needed"), false);
	assert.equal(still.hasAttribute("data-live-needed"), true);
	const added = words.querySelector(".admin-field__required");
	assert.equal(
		added.text,
		"*Needed for the preview",
		"a field without a star gets one, with its reason for assistive technology",
	);
	assert.equal(added.children[0].attributes.get("aria-hidden"), "true");
	assert.equal(words.text, "Main still *Needed for the preview");

	buttons[0].dispatchEvent(new Event("click"));
	assert.deepEqual(
		[chosen, revealed],
		[["en"], ["title"]],
		"a field missing in another language switches to it first",
	);
	guide.refresh();
	assert.equal(
		star.classList.contains("admin-field__required--needed"),
		true,
		"its own star takes the mark",
	);
	panels.en.selectors["input, textarea, select"][0].value = "A film";
	guide.refresh();
	assert.equal(
		star.classList.contains("admin-field__required--needed"),
		false,
		"the mark goes as soon as it is filled",
	);
	assert.equal(title.hasAttribute("data-live-needed"), false);

	buttons[1].dispatchEvent(new Event("click"));
	assert.deepEqual(
		[chosen, revealed],
		[["en"], ["title", "still"]],
		"a field in every language keeps the language",
	);
	still.selectors["input, textarea, select"].push(control("still[]", "c46e", "hidden"));
	guide.refresh();
	assert.equal(words.text, "Main still", "an added star leaves with its space");
	assert.equal(still.hasAttribute("data-live-needed"), false);
	still.selectors["input, textarea, select"].pop();
	guide.refresh();
	assert.equal(
		words.text,
		"Main still *Needed for the preview",
		"emptied again, it is marked again",
	);
	guide.clear();
	assert.equal(words.text, "Main still", "a drawn page clears every mark");
	assert.equal(still.hasAttribute("data-live-needed"), false);
});

test("an added star goes before the language a localized label shows", () => {
	const { root, titleLegend, star } = form();
	titleLegend.children.splice(titleLegend.children.indexOf(star), 2);
	const guide = mountMissingGuide({
		form: root,
		status: new Node("p"),
		t,
		locale: () => "ca",
		reveal() {},
		choose() {},
	});
	guide.show([{ field: "title", locales: ["ca"], label: "Title" }]);
	assert.equal(titleLegend.text, "Title *Needed for the preview ");
	assert.equal(titleLegend.children.at(-1).dataset.localeTag, "");
});
