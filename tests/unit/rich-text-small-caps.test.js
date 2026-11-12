import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import { safeClipboardHtml } from "../../engine/src/cms/conventional/client/rich-text-clipboard.js";
import { createRichTextHistory } from "../../engine/src/cms/conventional/client/rich-text-history.js";
import {
	clipboardRows,
	documentFromRows,
	formatTextRange,
} from "../../engine/src/cms/conventional/client/rich-text-model.js";
import { textOffsetAt } from "../../engine/src/cms/conventional/client/rich-text-selection.js";

test("versioned fixture is shared with the PHP contract without changing v1", async () => {
	const fixture = JSON.parse(
		await readFile(
			new URL(
				"../../schemas/fixtures/contracts/rich-text-small-caps.accepted.json",
				import.meta.url,
			),
			"utf8",
		),
	);
	assert.deepEqual(documentFromRows(fixture.nodes), fixture);
	const marks = ["bold", "italic", "underline", "smallCaps", "link"];
	const encode = (version) =>
		JSON.stringify({ formatVersion: version, rows: fixture.nodes });
	assert.deepEqual(clipboardRows(encode(2), ["paragraph"], marks), fixture.nodes);
	assert.equal(clipboardRows(encode(1), ["paragraph"], marks), null);
	assert.equal(
		clipboardRows(
			encode(2),
			["paragraph"],
			marks.filter((mark) => mark !== "smallCaps"),
		),
		null,
	);
});

test("selection offsets ignore empty blocks but retain soft breaks and Unicode code units", () => {
	const text = (value) => ({ nodeType: 3, textContent: value });
	const element = (nodeName, childNodes = []) => {
		const node = { nodeType: 1, nodeName, childNodes, lastChild: childNodes.at(-1) };
		for (const child of childNodes) child.parentNode = node;
		return node;
	};
	const selected = text("RIBA café");
	const empty = element("P", [element("BR")]);
	const surface = element("DIV", [
		element("P", [text("A"), element("BR"), text("B👋")]),
		empty,
		element("P", [selected]),
	]);
	assert.equal(textOffsetAt(surface, selected, 0), 5);
	assert.equal(textOffsetAt(surface, selected, 4), 9);
	assert.equal(textOffsetAt(surface, empty, 1), 5);
	assert.equal(textOffsetAt(surface, text("foreign"), 0), null);
});

test("selection formatting spans paragraphs and mixed marks without altering letters", () => {
	const initial = [
		{ type: "paragraph", children: [{ text: "Hi RIBA", marks: [] }] },
		{ type: "bullet_list", children: [{ text: "café end", marks: ["italic"] }] },
	];
	const formatted = formatTextRange(initial, 3, 11, "smallCaps");
	assert.deepEqual(formatted, [
		{
			type: "paragraph",
			children: [
				{ text: "Hi ", marks: [] },
				{ text: "RIBA", marks: ["smallCaps"] },
			],
		},
		{
			type: "bullet_list",
			children: [
				{ text: "café", marks: ["italic", "smallCaps"] },
				{ text: " end", marks: ["italic"] },
			],
		},
	]);
	const removed = formatTextRange(formatted, 3, 11, "smallCaps");
	assert.ok(
		removed.every((row) =>
			row.children.every((run) => !run.marks.includes("smallCaps")),
		),
	);
	assert.deepEqual(formatTextRange(initial, 3, 3, "smallCaps"), initial);
	assert.equal(initial[0].children.length, 1);
});

test("history combines engine formatting and paste, caps retention and invalidates redo", () => {
	const rows = [{ type: "paragraph", children: [{ text: "RIBA", marks: [] }] }];
	const marked = formatTextRange(rows, 0, 4, "smallCaps");
	const history = createRichTextHistory(rows);
	history.record(marked);
	history.record(marked);
	assert.deepEqual(history.move(-1), rows);
	assert.deepEqual(history.move(1), marked);
	history.move(-1);
	const pasted = [...rows, ...marked];
	history.record(pasted);
	assert.deepEqual(history.move(1), pasted);
	for (let index = 0; index < 70; index++)
		history.record([
			{ type: "paragraph", children: [{ text: String(index), marks: ["smallCaps"] }] },
		]);
	for (let index = 0; index < 70; index++) history.move(-1);
	assert.equal(history.move(-1)[0].children[0].text, "20");
	history.reset(rows);
	assert.deepEqual(history.move(1), rows);
});

test("foreign small-caps paste is reduced before parsing, never retaining unsafe attributes", () => {
	assert.equal(
		safeClipboardHtml(
			'<p><span style="font-variant: small-caps; color:red" onclick="evil()">RIBA &amp; café</span><img src="https://tracking.invalid/x"><iframe src="https://tracking.invalid/"></iframe></p>',
		),
		'<p><span class="cms-small-caps">RIBA &amp; café</span></p>',
	);
	assert.equal(
		safeClipboardHtml("<span style='FONT-VARIANT-CAPS: ALL-SMALL-CAPS'>ABC</span>"),
		'<span class="cms-small-caps">ABC</span>',
	);
	assert.equal(
		safeClipboardHtml('<span class="cms-small-caps" data-x="x">ABC</span>'),
		'<span class="cms-small-caps">ABC</span>',
	);
	assert.equal(
		safeClipboardHtml('<span style="font-variant: small-caps evil()">x</span>'),
		"<span>x</span>",
	);
	assert.equal(
		safeClipboardHtml(
			'<span style="font-variant: small-caps" title="<img src=x>">x</span>',
		),
		'<span class="cms-small-caps">"&gt;x</span>',
	);
	assert.equal(safeClipboardHtml("x".repeat(1_048_577)), null);
	assert.equal(safeClipboardHtml("<p>x</p>".repeat(5001)), null);
});
