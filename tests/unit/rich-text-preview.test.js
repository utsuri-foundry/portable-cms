import assert from "node:assert/strict";
import test from "node:test";
import {
	createRichPreviewEditors,
	replaceBlockText,
	rowText,
} from "../../engine/src/cms/conventional/client/rich-text-preview.js";

const link = { type: "link", href: "/en/projects/" };
const run = (text, marks = []) => ({ text, marks });
const rows = () => [
	{
		type: "paragraph",
		children: [
			run("Read "),
			run("the archive", ["bold", link]),
			run(" at RIBA", ["smallCaps"]),
		],
	},
	{ type: "heading_2", children: [run("Unchanged", ["italic"])] },
];

test("replacement preserves surrounding runs, links, small capitals and other blocks without mutation", () => {
	const original = rows();
	const next = replaceBlockText(original, 0, "Read the collection at RIBA");
	assert.deepEqual(next[0].children, [
		run("Read "),
		run("the collection", ["bold", link]),
		run(" at RIBA", ["smallCaps"]),
	]);
	assert.equal(next[1], original[1]);
	assert.equal(rowText(original[0]), "Read the archive at RIBA");
	assert.deepEqual(replaceBlockText(original, 0, rowText(original[0])), original);
});

test("insertions next to marked runs use shared marks, never expand a neighbouring link", () => {
	const source = [
		{
			type: "paragraph",
			children: [run("A", ["bold"]), run("B", ["bold", link]), run("C", ["italic"])],
		},
	];
	assert.deepEqual(replaceBlockText(source, 0, "AxBC")[0].children, [
		run("Ax", ["bold"]),
		run("B", ["bold", link]),
		run("C", ["italic"]),
	]);
	assert.deepEqual(replaceBlockText(source, 0, "ABxC")[0].children, [
		run("A", ["bold"]),
		run("B", ["bold", link]),
		run("x"),
		run("C", ["italic"]),
	]);
	assert.deepEqual(replaceBlockText(source, 0, "xABC")[0].children[0], run("x"));
	assert.deepEqual(replaceBlockText(source, 0, "ABCx")[0].children.at(-1), run("x"));
});

test("deletions across adjacent marks retain the unchanged text on both sides", () => {
	const source = [
		{
			type: "paragraph",
			children: [
				run("abc", ["bold"]),
				run("def", ["italic"]),
				run("ghi", ["smallCaps", link]),
			],
		},
	];
	assert.deepEqual(replaceBlockText(source, 0, "abhi")[0].children, [
		run("ab", ["bold"]),
		run("hi", ["smallCaps", link]),
	]);
	assert.deepEqual(replaceBlockText(source, 0, "")[0], {
		type: "paragraph",
		children: [run("")],
	});
	assert.equal(replaceBlockText(source, 0, "xy").length, 1);
});

test("Unicode edits do not split surrogate pairs or confuse repeated characters", () => {
	const source = [{ type: "bullet_list", children: [run("A😀😀B", ["underline"])] }];
	assert.equal(rowText(replaceBlockText(source, 0, "A😁😀B")[0]), "A😁😀B");
	assert.deepEqual(replaceBlockText(source, 0, "A😀B")[0].children, [
		run("A😀B", ["underline"]),
	]);
	assert.equal(replaceBlockText(source, 0, "\ud800"), null);
});

test("word editing cannot introduce breaks, controls, excess bytes or non-existent blocks", () => {
	for (const value of [
		"line\nbreak",
		"line\rbreak",
		"line\tbreak",
		"\0",
		"x".repeat(100_001),
	])
		assert.equal(replaceBlockText(rows(), 0, value), null);
	for (const index of [-1, 2, 0.5, "0"])
		assert.equal(replaceBlockText(rows(), index, "text"), null);
	const source = [{ type: "paragraph", children: [run("a\nb", ["bold"])] }];
	assert.equal(rowText(replaceBlockText(source, 0, "a\nB")[0]), "a\nB");
	assert.equal(replaceBlockText(rows(), 0, {}), null);
});

test("long runs are split at the existing 10,000-code-point bound", () => {
	const source = [{ type: "paragraph", children: [run("a", ["bold"])] }];
	const next = replaceBlockText(source, 0, "😀".repeat(20_001));
	assert.deepEqual(
		next[0].children.map((item) => [...item.text].length),
		[10_000, 10_000, 1],
	);
	assert.ok(next[0].children.every((item) => item.text.isWellFormed()));
});

test("only the mounted top-level editor for the requested language exposes the adapter", () => {
	const root = {};
	const adapter = { read: rows };
	const previewEditors = createRichPreviewEditors();
	const unregister = previewEditors.register(root, adapter);
	const field = {
		dataset: { fieldMatch: "rich" },
		hasAttribute: () => false,
		querySelector: (selector) =>
			selector === '[data-locale-panel="en"]' ? { querySelector: () => root } : null,
	};
	assert.equal(previewEditors.find(field, "en"), adapter);
	assert.equal(previewEditors.find(field, "ca"), null);
	assert.equal(
		createRichPreviewEditors().find(field, "en"),
		null,
		"another form never shares editor ownership",
	);
	field.hasAttribute = () => true;
	assert.equal(previewEditors.find(field, "en"), null);
	field.hasAttribute = () => false;
	unregister();
	assert.equal(previewEditors.find(field, "en"), null);
});
