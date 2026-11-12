import assert from "node:assert/strict";
import test from "node:test";
import {
	dropIndex,
	renumber,
} from "../../engine/src/cms/conventional/client/group-rows.js";

test("a dragged row lands before the first row whose middle is below the pointer", () => {
	assert.equal(dropIndex([10, 30, 50], 5), 0);
	assert.equal(dropIndex([10, 30, 50], 29), 1);
	assert.equal(dropIndex([10, 30, 50], 30), 2);
	assert.equal(dropIndex([10, 30, 50], 90), 3);
	assert.equal(dropIndex([], 90), 0);
});

/** Just enough of an element for the renumbering helper. */
function node(dataset = {}) {
	const attributes = new Map();
	return {
		dataset,
		textContent: "",
		disabled: false,
		setAttribute: (name, value) => attributes.set(name, value),
		getAttribute: (name) => attributes.get(name),
	};
}

function row() {
	const label = node({ groupLabel: "Move up, row {position} of Credits" });
	const text = node({ groupText: ", row {position}" });
	const up = node();
	const down = node();
	const field = node({
		groupFieldLabel: "Credits · Item {position} · Name",
		field: "credits.aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.name",
	});
	return {
		label,
		text,
		up,
		down,
		field,
		hasAttribute: (name) => name === "data-group-row",
		querySelectorAll: (selector) =>
			selector === "[data-group-label]"
				? [label]
				: selector === "[data-group-text]"
					? [text]
					: selector === "[data-group-field-label]"
						? [field]
						: [],
		querySelector: (selector) =>
			selector.includes('"up"') ? up : selector.includes('"down"') ? down : null,
	};
}

test("renumbering rewrites every position text and the available moves", () => {
	const rows = [row(), row(), row()];
	const hidden = { hasAttribute: () => false };
	renumber({ children: [rows[0], hidden, rows[1], rows[2]] });
	assert.deepEqual(
		rows.map((item) => [item.label.getAttribute("aria-label"), item.text.textContent]),
		[
			["Move up, row 1 of Credits", ", row 1"],
			["Move up, row 2 of Credits", ", row 2"],
			["Move up, row 3 of Credits", ", row 3"],
		],
	);
	assert.deepEqual(
		rows.map((item) => [item.up.disabled, item.down.disabled]),
		[
			[true, false],
			[false, false],
			[false, true],
		],
	);
	assert.deepEqual(
		rows.map((item) => item.field.dataset.fieldLabel),
		["Credits · Item 1 · Name", "Credits · Item 2 · Name", "Credits · Item 3 · Name"],
	);
	assert.equal(
		rows[1].field.dataset.field,
		"credits.aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.name",
		"renumbering changes the label, never the identity",
	);
});
