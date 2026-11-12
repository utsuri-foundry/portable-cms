import assert from "node:assert/strict";
import test from "node:test";
import { mountPreviewSelection } from "../../engine/src/cms/conventional/client/preview-cleanup.js";

test("preview count is local bounded resettable and disposable", () => {
	const form = new EventTarget();
	const window = new EventTarget();
	const boxes = [
		{ checked: true },
		{ checked: false },
		{ checked: true, disabled: true },
	];
	const count = { dataset: { selectedLabel: "{count} selected" } };
	const submit = {};
	form.ownerDocument = { defaultView: window };
	form.querySelectorAll = () => boxes;
	form.querySelector = (selector) => (selector.includes("count") ? count : submit);
	const dispose = mountPreviewSelection(form);
	assert.equal(count.textContent, "0 selected");
	assert.equal(submit.disabled, true);
	boxes[0].checked = true;
	boxes[1].checked = true;
	boxes[2].checked = true;
	form.dispatchEvent(new Event("change"));
	assert.equal(count.textContent, "2 selected");
	assert.equal(submit.disabled, false);
	window.dispatchEvent(new Event("pageshow"));
	assert.equal(count.textContent, "0 selected");
	dispose();
	boxes[0].checked = true;
	form.dispatchEvent(new Event("change"));
	assert.equal(count.textContent, "0 selected");
});
