import assert from "node:assert/strict";
import test from "node:test";
import {
	applyTheme,
	mountAccountMenu,
	mountThemeForm,
} from "../../engine/src/cms/conventional/client/admin-shell.js";

function root() {
	const attributes = new Map();
	return {
		attributes,
		setAttribute: (key, value) => attributes.set(key, value),
		removeAttribute: (key) => attributes.delete(key),
	};
}

function fixture(respond) {
	const documentElement = root();
	const apply = { hidden: false };
	const radios = ["system", "light", "dark"].map((value) => {
		const radio = new EventTarget();
		radio.value = value;
		radio.checked = value === "system";
		return radio;
	});
	const requests = [];
	const window = {
		FormData: class {
			constructor(form) {
				this.form = form;
			}
		},
		fetch: async (url, options) => {
			requests.push({ url, options });
			return respond(requests.length);
		},
	};
	const form = {
		action: "/settings/theme",
		ownerDocument: { documentElement },
		querySelector: () => apply,
		querySelectorAll: () => radios,
	};
	return { form, window, radios, apply, requests, documentElement };
}

const choose = (radio) => {
	radio.checked = true;
	radio.dispatchEvent(Object.assign(new Event("change"), {}));
};
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

test("only allowlisted themes reach the document root", () => {
	const node = root();
	assert.equal(applyTheme(node, "dark"), true);
	assert.equal(node.attributes.get("data-theme"), "dark");
	assert.equal(applyTheme(node, "neon"), false);
	assert.equal(node.attributes.get("data-theme"), "dark");
	assert.equal(applyTheme(node, "system"), true);
	assert.equal(node.attributes.has("data-theme"), false);
});

test("choosing a theme applies instantly and persists through the native form endpoint", async () => {
	const f = fixture(() => ({ ok: true }));
	const dispose = mountThemeForm(f.form, f.window);
	assert.equal(
		f.apply.hidden,
		true,
		"the native Apply button is unnecessary when enhanced",
	);
	choose(f.radios[2]);
	assert.equal(f.documentElement.attributes.get("data-theme"), "dark");
	await settle();
	assert.equal(f.requests.length, 1);
	assert.equal(f.requests[0].url, "/settings/theme");
	assert.equal(f.requests[0].options.method, "POST");
	assert.equal(f.requests[0].options.credentials, "same-origin");
	assert.equal(f.requests[0].options.headers.Accept, "application/json");
	dispose();
	assert.equal(f.apply.hidden, false);
});

test("a rejected save restores the last persisted theme and the native button", async () => {
	const f = fixture(() => ({ ok: false }));
	const dispose = mountThemeForm(f.form, f.window);
	choose(f.radios[1]);
	assert.equal(f.documentElement.attributes.get("data-theme"), "light");
	await settle();
	assert.equal(f.documentElement.attributes.has("data-theme"), false);
	assert.equal(f.radios[0].checked, true);
	assert.equal(f.apply.hidden, false);
	dispose();
});

test("account panel scrolls into view only when opened", () => {
	const details = new EventTarget();
	let scrolled = 0;
	details.lastElementChild = { scrollIntoView: () => scrolled++ };
	details.open = false;
	const dispose = mountAccountMenu(details);
	details.dispatchEvent(new Event("toggle"));
	details.open = true;
	details.dispatchEvent(new Event("toggle"));
	assert.equal(scrolled, 1);
	dispose();
	details.dispatchEvent(new Event("toggle"));
	assert.equal(scrolled, 1);
});
