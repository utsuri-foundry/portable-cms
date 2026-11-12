/**
 * Pure mapping between an editable surface and the native rich-text row protocol.
 * Only allowlisted blocks, marks and links survive; PHP remains the validation authority.
 */
/** Characters the server rejects, plus invisible zero-width marks browsers insert. */
const stripped = (code) =>
	code <= 8 ||
	code === 11 ||
	code === 12 ||
	(code >= 14 && code <= 31) ||
	code === 127 ||
	code === 0x200b ||
	code === 0xfeff;
const clean = (text) =>
	Array.from(text)
		.filter((character) => !stripped(character.codePointAt(0)))
		.join("");
const UNSAFE_LINK = new Set(["\\", "<", ">", '"', "'"]);
const MARK_ORDER = ["bold", "italic", "underline", "smallCaps", "link"];
const INLINE_MARKS = {
	strong: "bold",
	b: "bold",
	em: "italic",
	i: "italic",
	u: "underline",
};
const BLOCK_TAGS = new Set([
	"p",
	"div",
	"h1",
	"h2",
	"h3",
	"h4",
	"h5",
	"h6",
	"blockquote",
	"section",
	"article",
	"pre",
]);
const TEXT_NODE = 3;
const ELEMENT_NODE = 1;
export const MAX_RUN = 10000;

const smallCaps = (node, options) =>
	options.marks.includes("smallCaps") &&
	(node.getAttribute?.("class") ?? "").split(/\s+/).includes("cms-small-caps")
		? ["smallCaps"]
		: [];

export function validLink(href) {
	if (typeof href !== "string" || href === "" || href.length > 2048) return false;
	if (
		Array.from(href).some(
			(character) =>
				character.codePointAt(0) <= 32 ||
				character.codePointAt(0) === 127 ||
				UNSAFE_LINK.has(character),
		)
	)
		return false;
	if (href.startsWith("/") && !href.startsWith("//")) return true;
	try {
		const url = new URL(href);
		return (
			["http:", "https:"].includes(url.protocol) &&
			url.hostname !== "" &&
			url.username === "" &&
			url.password === ""
		);
	} catch {
		return false;
	}
}

const markKey = (mark) => (typeof mark === "string" ? mark : `link:${mark.href}`);
const sameMarks = (left, right) =>
	left.length === right.length &&
	left.every((mark, index) => markKey(mark) === markKey(right[index]));
const ordered = (marks) =>
	[...marks].sort(
		(left, right) =>
			MARK_ORDER.indexOf(typeof left === "string" ? left : "link") -
			MARK_ORDER.indexOf(typeof right === "string" ? right : "link"),
	);

/** Merges equal neighbours, drops empty runs and respects the server run bound. */
export function normalizeRuns(runs) {
	const result = [];
	for (const run of runs) {
		const text = clean(run.text.replaceAll("\u00a0", " "));
		if (text === "") continue;
		const marks = ordered(run.marks);
		const last = result.at(-1);
		if (
			last &&
			sameMarks(last.marks, marks) &&
			last.text.length + text.length <= MAX_RUN
		)
			last.text += text;
		else
			for (let offset = 0; offset < text.length; offset += MAX_RUN)
				result.push({ text: text.slice(offset, offset + MAX_RUN), marks });
	}
	if (result.length) {
		const last = result.at(-1);
		last.text = last.text.replace(/\n$/, "");
		if (last.text === "") result.pop();
	}
	return result;
}

function blockType(tag, allowed) {
	const pick = (...types) => types.find((type) => allowed.includes(type)) ?? allowed[0];
	if (tag === "h1" || tag === "h2") return pick("heading_2", "heading_3", "paragraph");
	if (/^h[3-6]$/.test(tag)) return pick("heading_3", "heading_2", "paragraph");
	if (tag === "ul") return pick("bullet_list", "ordered_list", "paragraph");
	if (tag === "ol") return pick("ordered_list", "bullet_list", "paragraph");
	return pick("paragraph");
}

function inlineRuns(node, active, options, out) {
	for (const child of node.childNodes ?? []) {
		if (child.nodeType === TEXT_NODE) {
			out.push({ text: child.textContent ?? "", marks: active });
			continue;
		}
		if (child.nodeType !== ELEMENT_NODE) continue;
		const tag = child.nodeName.toLowerCase();
		if (tag === "br") {
			out.push({ text: "\n", marks: active });
			continue;
		}
		if (tag === "ul" || tag === "ol") continue;
		let marks = active;
		const mark = INLINE_MARKS[tag];
		if (mark && options.marks.includes(mark) && !active.includes(mark))
			marks = [...active, mark];
		if (smallCaps(child, options).length && !active.includes("smallCaps"))
			marks = [...marks, "smallCaps"];
		if (tag === "a" && options.marks.includes("link")) {
			const href = child.getAttribute?.("href") ?? "";
			if (validLink(href))
				marks = [
					...active.filter((item) => typeof item === "string"),
					{ type: "link", href },
				];
		}
		inlineRuns(child, marks, options, out);
	}
	return out;
}

/** Converts a surface subtree to native rows. `options` = { blocks, marks }. */
export function rowsFromNodes(root, options) {
	const rows = [];
	let loose = [];
	const push = (type, runs) => {
		const children = normalizeRuns(runs);
		if (children.length) rows.push({ type, children });
	};
	const flush = () => {
		if (loose.length) push(blockType("p", options.blocks), loose);
		loose = [];
	};
	const list = (node, type) => {
		for (const item of node.childNodes ?? []) {
			if (item.nodeType !== ELEMENT_NODE) continue;
			const tag = item.nodeName.toLowerCase();
			if (tag === "li") {
				push(type, inlineRuns(item, smallCaps(item, options), options, []));
				for (const nested of item.childNodes ?? [])
					if (
						nested.nodeType === ELEMENT_NODE &&
						["ul", "ol"].includes(nested.nodeName.toLowerCase())
					)
						list(nested, blockType(nested.nodeName.toLowerCase(), options.blocks));
			} else if (tag === "ul" || tag === "ol")
				list(item, blockType(tag, options.blocks));
		}
	};
	const walk = (node) => {
		for (const child of node.childNodes ?? []) {
			if (child.nodeType === TEXT_NODE) {
				loose.push({ text: child.textContent ?? "", marks: [] });
				continue;
			}
			if (child.nodeType !== ELEMENT_NODE) continue;
			const tag = child.nodeName.toLowerCase();
			if (tag === "ul" || tag === "ol") {
				flush();
				list(child, blockType(tag, options.blocks));
			} else if (tag === "li") {
				flush();
				push(
					blockType("ul", options.blocks),
					inlineRuns(child, smallCaps(child, options), options, []),
				);
			} else if (
				["blockquote", "section", "article"].includes(tag) ||
				(BLOCK_TAGS.has(tag) && hasBlocks(child))
			) {
				flush();
				walk(child);
			} else if (BLOCK_TAGS.has(tag)) {
				flush();
				push(
					blockType(tag, options.blocks),
					inlineRuns(child, smallCaps(child, options), options, []),
				);
			} else if (tag === "br") {
				flush();
			} else loose.push(...inlineRuns({ childNodes: [child] }, [], options, []));
		}
	};
	walk(root);
	flush();
	return rows;
}

function hasBlocks(node) {
	return [...(node.childNodes ?? [])].some(
		(child) =>
			child.nodeType === ELEMENT_NODE &&
			(BLOCK_TAGS.has(child.nodeName.toLowerCase()) ||
				["ul", "ol", "li"].includes(child.nodeName.toLowerCase())),
	);
}

const escapeHtml = (text) =>
	text
		.replaceAll("&", "&amp;")
		.replaceAll("<", "&lt;")
		.replaceAll(">", "&gt;")
		.replaceAll('"', "&quot;");

function runHtml(run) {
	let html = escapeHtml(run.text).replaceAll("\n", "<br>");
	for (const mark of [...run.marks].reverse()) {
		if (typeof mark === "string") {
			if (mark === "smallCaps") {
				html = `<span class="cms-small-caps">${html}</span>`;
				continue;
			}
			const tag = { bold: "strong", italic: "em", underline: "u" }[mark];
			if (tag) html = `<${tag}>${html}</${tag}>`;
		} else if (mark?.type === "link" && validLink(mark.href))
			html = `<a href="${escapeHtml(mark.href)}">${html}</a>`;
	}
	return html;
}

/** Escaped, allowlisted HTML for rows; used to render and to insert semantic clipboard data. */
export function rowsToHtml(rows) {
	let html = "";
	let open = null;
	for (const row of rows) {
		const listTag = { bullet_list: "ul", ordered_list: "ol" }[row.type];
		if (open && open !== listTag) {
			html += `</${open}>`;
			open = null;
		}
		const content = row.children.map(runHtml).join("") || "<br>";
		if (listTag) {
			if (!open) html += `<${listTag}>`;
			open = listTag;
			html += `<li>${content}</li>`;
		} else {
			const tag = { heading_2: "h2", heading_3: "h3" }[row.type] ?? "p";
			html += `<${tag}>${content}</${tag}>`;
		}
	}
	if (open) html += `</${open}>`;
	return html || "<p><br></p>";
}

function runNode(doc, run) {
	let node = doc.createDocumentFragment();
	const lines = run.text.split("\n");
	for (const [index, line] of lines.entries()) {
		if (index > 0) node.append(doc.createElement("br"));
		if (line) node.append(doc.createTextNode(line));
	}
	for (const mark of [...run.marks].reverse()) {
		let wrapper = null;
		if (typeof mark === "string") {
			if (mark === "smallCaps") {
				wrapper = doc.createElement("span");
				wrapper.setAttribute("class", "cms-small-caps");
			}
			const tag = { bold: "strong", italic: "em", underline: "u" }[mark];
			if (tag) wrapper = doc.createElement(tag);
		} else if (mark?.type === "link" && validLink(mark.href)) {
			wrapper = doc.createElement("a");
			wrapper.setAttribute("href", mark.href);
		}
		if (wrapper) {
			wrapper.append(node);
			node = wrapper;
		}
	}
	return node;
}

/** DOM equivalent of rowsToHtml, built only from text nodes and allowlisted elements. */
export function rowsFragment(doc, rows) {
	const fragment = doc.createDocumentFragment();
	let list = null;
	for (const row of rows) {
		const listTag = { bullet_list: "ul", ordered_list: "ol" }[row.type];
		if (list && list.nodeName.toLowerCase() !== listTag) list = null;
		const block = doc.createElement(
			listTag ? "li" : ({ heading_2: "h2", heading_3: "h3" }[row.type] ?? "p"),
		);
		for (const run of row.children) block.append(runNode(doc, run));
		if (!block.childNodes.length) block.append(doc.createElement("br"));
		if (listTag) {
			if (!list) {
				list = doc.createElement(listTag);
				fragment.append(list);
			}
			list.append(block);
		} else fragment.append(block);
	}
	if (!fragment.childNodes.length) {
		const empty = doc.createElement("p");
		empty.append(doc.createElement("br"));
		fragment.append(empty);
	}
	return fragment;
}

/** Native field names exactly as RichTextForm decodes them. */
export function transportEntries(name, rows) {
	const entries = [[`${name}__rows`, String(rows.length)]];
	for (const [index, row] of rows.entries()) {
		const prefix = `${name}__${index}`;
		entries.push(
			[`${prefix}__type`, row.type],
			[`${prefix}__runs`, String(row.children.length)],
		);
		for (const [span, run] of row.children.entries()) {
			const runName = `${prefix}__${span}`;
			entries.push([`${runName}__text`, run.text]);
			for (const mark of run.marks) {
				if (typeof mark === "string") entries.push([`${runName}__${mark}`, "1"]);
				else entries.push([`${runName}__link`, mark.href]);
			}
		}
	}
	entries.push([`${name}__end`, "1"]);
	return entries;
}
