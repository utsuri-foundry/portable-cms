import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import {
	mountSlugFields,
	slugText,
} from "../../engine/src/cms/conventional/client/slug-fields.js";

test("slug controls make the same addresses as the server's normalizer", async () => {
	const fixture = JSON.parse(
		await readFile(
			new URL("../fixtures/text-normalization-v1.json", import.meta.url),
			"utf8",
		),
	);
	for (const item of fixture.cases)
		assert.equal(slugText(item.input), item.slug, `slug: ${item.input}`);
	assert.equal(slugText("Casa Nova "), "casa-nova");
	assert.equal(
		slugText("Casa Nova ", true),
		"casa-nova-",
		"a word can follow while typing",
	);
	assert.equal(slugText("  ¿Qué tal?", true), "que-tal-");
});

/** Just enough of the DOM: inputs that dispatch to their own listeners and bubble to the form. */
function page() {
	const formListeners = [];
	const elements = new Map();
	class Event {
		constructor(type, options = {}) {
			this.type = type;
			this.bubbles = !!options.bubbles;
		}
	}
	const document = {
		defaultView: { Event },
		getElementById: (id) => elements.get(id) ?? null,
	};
	const input = (id, value = "", dataset = {}) => {
		const listeners = new Map();
		const element = {
			id,
			value,
			dataset,
			selectionStart: null,
			selection: null,
			addEventListener: (type, handler) =>
				listeners.set(type, [...(listeners.get(type) ?? []), handler]),
			removeEventListener: (type, handler) =>
				listeners.set(
					type,
					(listeners.get(type) ?? []).filter((item) => item !== handler),
				),
			setSelectionRange(start, end) {
				this.selection = [start, end];
			},
			dispatchEvent(event) {
				event.target = element;
				for (const handler of listeners.get(event.type) ?? []) handler(event);
				if (event.bubbles)
					for (const handler of formListeners)
						if (handler.type === event.type) handler.run(event);
				return true;
			},
			type(text, caret = text.length) {
				this.value = text;
				this.selectionStart = caret;
				this.dispatchEvent(new Event("input", { bubbles: true }));
			},
		};
		elements.set(id, element);
		return element;
	};
	const slugs = [];
	const form = {
		ownerDocument: document,
		querySelectorAll: (selector) => (selector === "input[data-slug]" ? slugs : []),
	};
	return { form, input, slugs, formListeners, Event };
}

test("a slug control keeps a valid address while typed and when left", () => {
	const { form, input, slugs, Event } = page();
	const slug = input("field-slug-ca");
	slugs.push(slug);
	const dispose = mountSlugFields(form);
	slug.type("Casa Nova", 4);
	assert.equal(slug.value, "casa-nova");
	assert.deepEqual(
		slug.selection,
		[4, 4],
		"the caret stays where the editor was typing",
	);
	slug.type("casa-nova ");
	assert.equal(slug.value, "casa-nova-", "a trailing hyphen waits for the next word");
	slug.dispatchEvent(new Event("change"));
	assert.equal(slug.value, "casa-nova", "leaving the field settles the address");
	slug.value = "Àrea 51!";
	slug.dispatchEvent(Object.assign(new Event("keydown"), { key: "Enter" }));
	assert.equal(
		slug.value,
		"area-51",
		"Enter settles the address before the record is kept",
	);
	dispose();
	slug.type("Not Watched");
	assert.equal(slug.value, "Not Watched", "disposal removes every listener");
});

test("a draft's slug follows its title until the editor writes another", () => {
	const { form, input, slugs, formListeners } = page();
	const title = input("field-title-ca", "");
	const slug = input("field-slug-ca", "", { slugFrom: "field-title-ca" });
	slugs.push(slug);
	const seen = [];
	formListeners.push({ type: "input", run: (event) => seen.push(event.target.id) });
	mountSlugFields(form);
	title.type("La Casa");
	assert.equal(slug.value, "la-casa", "an empty slug takes the title's address");
	assert.ok(
		seen.includes("field-slug-ca"),
		"the proposed slug is an edit the record keeps",
	);
	title.type("La Casa Gran");
	assert.equal(slug.value, "la-casa-gran", "a proposed slug keeps following");
	slug.type("casa");
	title.type("La Casa Gran del Mar");
	assert.equal(slug.value, "casa", "a slug the editor wrote stays");
	slug.type("");
	title.type("Mar");
	assert.equal(slug.value, "mar", "clearing the slug lets the title propose again");
});

test("a slug that does not match its title is left alone", () => {
	const { form, input, slugs } = page();
	const title = input("field-title-en", "The Ivy (copy)");
	const slug = input("field-slug-en", "the-ivy-2", { slugFrom: "field-title-en" });
	slugs.push(slug);
	mountSlugFields(form);
	title.type("The Oak");
	assert.equal(slug.value, "the-ivy-2");
});
