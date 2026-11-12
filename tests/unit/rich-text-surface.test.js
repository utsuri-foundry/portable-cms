import assert from "node:assert/strict";
import test from "node:test";
import {
	MAX_RUN,
	normalizeRuns,
	rowsFragment,
	rowsFromNodes,
	rowsToHtml,
	transportEntries,
	validLink,
} from "../../engine/src/cms/conventional/client/rich-text-surface.js";

/** Minimal DOM-shaped nodes: enough for the pure surface mapping. */
const text = (value) => ({ nodeType: 3, nodeName: "#text", textContent: value });
const el = (name, children = [], attributes = {}) => {
	const node = {
		nodeType: 1,
		nodeName: name.toUpperCase(),
		childNodes: children,
		getAttribute: (key) => attributes[key] ?? null,
	};
	return node;
};
const ALL = {
	blocks: ["paragraph", "heading_2", "heading_3", "bullet_list", "ordered_list"],
	marks: ["bold", "italic", "underline", "smallCaps", "link"],
};

test("surface blocks, lists and marks become native rows", () => {
	const root = el("div", [
		el("h2", [text("Title")]),
		el("p", [text("Plain "), el("strong", [text("bold "), el("em", [text("both")])])]),
		el("ul", [el("li", [text("One")]), el("li", [text("Two")])]),
		el("ol", [el("li", [text("First")])]),
	]);
	assert.deepEqual(rowsFromNodes(root, ALL), [
		{ type: "heading_2", children: [{ text: "Title", marks: [] }] },
		{
			type: "paragraph",
			children: [
				{ text: "Plain ", marks: [] },
				{ text: "bold ", marks: ["bold"] },
				{ text: "both", marks: ["bold", "italic"] },
			],
		},
		{ type: "bullet_list", children: [{ text: "One", marks: [] }] },
		{ type: "bullet_list", children: [{ text: "Two", marks: [] }] },
		{ type: "ordered_list", children: [{ text: "First", marks: [] }] },
	]);
});

test("small capitals round trip with fixed markup and no original-letter substitution", () => {
	const rows = [
		{ type: "paragraph", children: [{ text: "RIBA café", marks: ["smallCaps"] }] },
	];
	assert.equal(
		rowsToHtml(rows),
		'<p><span class="cms-small-caps">RIBA café</span></p>',
	);
	const surface = el("p", [
		el("span", [text("RIBA café")], { class: "cms-small-caps" }),
	]);
	assert.deepEqual(rowsFromNodes(surface, ALL), rows);
	assert.deepEqual(rowsFromNodes(surface, { ...ALL, marks: [] }), [
		{ type: "paragraph", children: [{ text: "RIBA café", marks: [] }] },
	]);
	assert.deepEqual(
		transportEntries("body", rows).filter(([name]) => name.endsWith("__smallCaps")),
		[["body__0__0__smallCaps", "1"]],
	);
});

test("disallowed blocks, marks and links degrade to allowed text", () => {
	const limited = { blocks: ["paragraph"], marks: ["italic"] };
	const root = el("div", [
		el("h1", [el("b", [text("Loud")])]),
		el("p", [
			el("a", [text("click")], { href: "javascript:alert(1)" }),
			el("u", [el("i", [text(" quiet")])]),
		]),
		el("ul", [el("li", [text("item")])]),
	]);
	assert.deepEqual(rowsFromNodes(root, limited), [
		{ type: "paragraph", children: [{ text: "Loud", marks: [] }] },
		{
			type: "paragraph",
			children: [
				{ text: "click", marks: [] },
				{ text: " quiet", marks: ["italic"] },
			],
		},
		{ type: "paragraph", children: [{ text: "item", marks: [] }] },
	]);
	const linked = rowsFromNodes(
		el("p", [el("a", [text("safe")], { href: "https://example.test/a" })]),
		ALL,
	);
	assert.deepEqual(linked, [
		{
			type: "paragraph",
			children: [
				{ text: "safe", marks: [{ type: "link", href: "https://example.test/a" }] },
			],
		},
	]);
});

test("line breaks, browser spacing, control characters and empty blocks normalize", () => {
	const root = el("div", [
		text("loose "),
		el("span", [text("inline")]),
		el("p", [text("a b"), el("br"), text("c​\u0007"), el("br")]),
		el("p", [el("br")]),
		el("div", [el("p", [text("nested")])]),
	]);
	assert.deepEqual(rowsFromNodes(root, ALL), [
		{ type: "paragraph", children: [{ text: "loose inline", marks: [] }] },
		{ type: "paragraph", children: [{ text: "a b\nc", marks: [] }] },
		{ type: "paragraph", children: [{ text: "nested", marks: [] }] },
	]);
	const long = normalizeRuns([{ text: "x".repeat(MAX_RUN + 5), marks: [] }]);
	assert.deepEqual(
		long.map((run) => run.text.length),
		[MAX_RUN, 5],
	);
});

test("links follow the server allowlist", () => {
	for (const href of ["/about", "https://example.test/x", "http://example.test"])
		assert.equal(validLink(href), true, href);
	for (const href of [
		"",
		"//evil.test",
		"javascript:alert(1)",
		"https://user:pass@example.test",
		"https://example.test/<x>",
		"/a b",
		"data:text/html,x",
	])
		assert.equal(validLink(href), false, href);
});

test("rows serialize to escaped HTML and exact native field names", () => {
	const rows = [
		{
			type: "paragraph",
			children: [
				{ text: "<b>&", marks: ["bold"] },
				{ text: "go", marks: [{ type: "link", href: "/x?a=1&b=2" }] },
			],
		},
		{ type: "bullet_list", children: [{ text: "a\nb", marks: [] }] },
		{ type: "bullet_list", children: [{ text: "c", marks: [] }] },
	];
	assert.equal(
		rowsToHtml(rows),
		'<p><strong>&lt;b&gt;&amp;</strong><a href="/x?a=1&amp;b=2">go</a></p><ul><li>a<br>b</li><li>c</li></ul>',
	);
	assert.deepEqual(transportEntries("body__en", rows.slice(0, 1)), [
		["body__en__rows", "1"],
		["body__en__0__type", "paragraph"],
		["body__en__0__runs", "2"],
		["body__en__0__0__text", "<b>&"],
		["body__en__0__0__bold", "1"],
		["body__en__0__1__text", "go"],
		["body__en__0__1__link", "/x?a=1&b=2"],
		["body__en__end", "1"],
	]);
	assert.deepEqual(transportEntries("body", []), [
		["body__rows", "0"],
		["body__end", "1"],
	]);
});

test("DOM rendering uses only text nodes and allowlisted elements", () => {
	const created = [];
	const node = (name) => {
		const result = {
			nodeName: name,
			childNodes: [],
			attributes: {},
			append(...children) {
				this.childNodes.push(...children);
			},
			setAttribute(key, value) {
				this.attributes[key] = value;
			},
			get lastChild() {
				return this.childNodes.at(-1);
			},
		};
		created.push(name);
		return result;
	};
	const doc = {
		createDocumentFragment: () => node("#fragment"),
		createElement: (name) => node(name.toUpperCase()),
		createTextNode: (value) => ({ nodeName: "#text", nodeType: 3, textContent: value }),
	};
	const fragment = rowsFragment(doc, [
		{ type: "heading_3", children: [{ text: "<h>", marks: ["italic"] }] },
		{
			type: "ordered_list",
			children: [{ text: "x", marks: [{ type: "link", href: "javascript:x" }] }],
		},
	]);
	assert.deepEqual(
		fragment.childNodes.map((child) => child.nodeName),
		["H3", "OL"],
	);
	const texts = (item) =>
		item.nodeName === "#text"
			? [item.textContent]
			: (item.childNodes ?? []).flatMap(texts);
	assert.deepEqual(texts(fragment.childNodes[0]), ["<h>"], "text stays a text node");
	assert.ok(!created.includes("A"), "invalid links never become anchors");
	assert.equal(rowsFragment(doc, []).childNodes[0].nodeName, "P");
});

test("browser-nested lists inside paragraphs keep every item", () => {
	const root = el("div", [
		el("p", [el("ul", [el("li", [el("em", [text("A pav")]), text("ilion")])])]),
		el("p", [text("Next")]),
	]);
	assert.deepEqual(rowsFromNodes(root, ALL), [
		{
			type: "bullet_list",
			children: [
				{ text: "A pav", marks: ["italic"] },
				{ text: "ilion", marks: [] },
			],
		},
		{ type: "paragraph", children: [{ text: "Next", marks: [] }] },
	]);
});
