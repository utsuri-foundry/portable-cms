import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import {
	documentFromRows,
	editRows,
	formatSelection,
	singleLineRows,
} from "../../engine/src/cms/conventional/client/rich-text-model.js";

const rows = [{ type: "paragraph", children: [{ text: "Hello café 👋", marks: [] }] }];
test("single-line paste retains every name and link without adding a block or mutating the source", () => {
	const input = [
		{
			type: "paragraph",
			children: [
				{
					text: "Nora\r\nStudio",
					marks: [{ type: "link", href: "https://example.invalid/" }],
				},
			],
		},
		{ type: "paragraph", children: [{ text: "Sales", marks: [] }] },
	];
	const result = singleLineRows(input);
	assert.equal(result.length, 1);
	assert.equal(result[0].children.map((run) => run.text).join(""), "Nora Studio Sales");
	assert.deepEqual(result[0].children[0].marks, input[0].children[0].marks);
	assert.equal(input[0].children[0].text, "Nora\r\nStudio");
	assert.deepEqual(singleLineRows([]), []);
});
test("shared rich-text fixture survives browser serialization unchanged", async () => {
	const fixture = JSON.parse(
		await readFile(
			new URL(
				"../../schemas/fixtures/contracts/rich-text.accepted.json",
				import.meta.url,
			),
			"utf8",
		),
	);
	const flattened = fixture.nodes.flatMap((node) =>
		["bullet_list", "ordered_list"].includes(node.type)
			? node.children.map((item) => ({ type: node.type, children: item.children }))
			: [node],
	);
	assert.deepEqual(documentFromRows(flattened), fixture);
});
test("selection formatting splits runs without changing text or the input", () => {
	const formatted = formatSelection(rows, 0, 0, 6, 10, "bold");
	assert.deepEqual(formatted[0].children, [
		{ text: "Hello ", marks: [] },
		{ text: "café", marks: ["bold"] },
		{ text: " 👋", marks: [] },
	]);
	assert.deepEqual(rows[0].children, [{ text: "Hello café 👋", marks: [] }]);
	const removed = formatSelection(formatted, 0, 1, 0, 4, "bold");
	assert.deepEqual(removed[0].children[1].marks, []);
	assert.deepEqual(formatSelection(rows, 0, 0, 0, 0, "bold"), rows);
});
test("native structure actions preserve order and text with predictable boundaries", () => {
	const added = editRows(rows, "add");
	const moved = editRows(added, "up", 1);
	assert.deepEqual(moved, [added[1], added[0]]);
	assert.deepEqual(editRows(moved, "down", 0), added);
	assert.deepEqual(editRows(added, "remove", 1), rows);
	assert.deepEqual(editRows(rows, "up", 0), rows);
	assert.deepEqual(editRows(rows, "run", 0)[0].children[1], { text: "", marks: [] });
	assert.deepEqual(editRows(rows, "delete-run", 0, 0)[0].children, []);
});
test("ordered lists serialize deterministically and retain formatted links", () => {
	const list = rows.map((row) => ({ ...row, type: "bullet_list" }));
	const linked = formatSelection(list, 0, 0, 0, 5, { type: "link", href: "/en/" });
	const document = documentFromRows([...linked, ...list, ...rows]);
	assert.equal(document.formatVersion, 1);
	assert.equal(document.nodes.length, 2);
	assert.equal(document.nodes[0].children.length, 2);
	assert.deepEqual(document.nodes[0].children[0].children[0], {
		text: "Hello",
		marks: [{ type: "link", href: "/en/" }],
	});
	assert.equal(
		JSON.stringify(document),
		JSON.stringify(documentFromRows([...linked, ...list, ...rows])),
	);
});

test("portable clipboard preserves semantic blocks and refuses arbitrary payloads", async () => {
	const { clipboardRows, insertClipboard, formatBlocks } = await import(
		"../../engine/src/cms/conventional/client/rich-text-model.js"
	);
	const copied = [{ type: "heading_2", children: [{ text: "café", marks: ["bold"] }] }];
	const encoded = JSON.stringify({ formatVersion: 1, rows: copied });
	assert.deepEqual(
		clipboardRows(encoded, ["heading_2", "paragraph"], ["bold"]),
		copied,
	);
	assert.equal(clipboardRows(encoded, ["paragraph"], ["bold"]), null);
	assert.equal(
		clipboardRows(
			'{"formatVersion":1,"rows":[{"type":"script","children":[]}]}',
			["paragraph"],
			[],
		),
		null,
	);
	assert.equal(clipboardRows("{".repeat(1_048_577), [], []), null);
	const empty = [{ type: "paragraph", children: [{ text: "", marks: [] }] }];
	assert.deepEqual(insertClipboard(empty, 0, 0, 0, 0, copied), copied);
	assert.deepEqual(formatBlocks(copied, [0], "bold")[0].children[0].marks, []);
	assert.deepEqual(formatBlocks(copied, [0], "italic")[0].children[0].marks, [
		"bold",
		"italic",
	]);
});
