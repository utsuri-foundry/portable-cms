import { messages } from "./messages.js";
import { safeClipboardHtml } from "./rich-text-clipboard.js";
import { createRichTextHistory } from "./rich-text-history.js";
import {
	clipboardRows,
	formatTextRange,
	rowsVersion,
	singleLineRows,
} from "./rich-text-model.js";
import { replaceBlockText } from "./rich-text-preview.js";
import { restoreTextSelection, textSelection } from "./rich-text-selection.js";
import {
	rowsFragment,
	rowsFromNodes,
	rowsToHtml,
	transportEntries,
	validLink,
} from "./rich-text-surface.js";

const CLIPBOARD = "application/x-portable-cms-text";
const FORMAT_MARKS = ["bold", "italic", "underline", "smallCaps"];
const BLOCK_TAGS = { paragraph: "p", heading_2: "h2", heading_3: "h3" };

/** Reads the server-rendered native rows; they stay the no-script editing path. */
function nativeRows(container) {
	return [...container.querySelectorAll("[data-rich-row]")].map((row) => ({
		type: row.querySelector("[data-rich-type]").value,
		children: [...row.querySelectorAll("[data-rich-run]")].map((run) => {
			const marks = [...run.querySelectorAll("[data-rich-mark]")]
				.filter((control) => control.checked)
				.map((control) => control.dataset.richMark);
			const href = run.querySelector("[data-rich-link]")?.value;
			if (href) marks.push({ type: "link", href });
			return { text: run.querySelector("textarea").value, marks };
		}),
	}));
}

/**
 * One writing surface replaces the native block rows while enhanced. Every change is
 * serialized back into the exact native field names; the server validates them again.
 */
export function mountRichTextEditor(root, previewEditors = null) {
	const doc = root.ownerDocument;
	const window = doc.defaultView;
	const name = root.dataset.richName;
	const blocks = root.dataset.richBlocks.split(" ");
	const marks = root.dataset.richMarks.split(" ").filter(Boolean);
	const options = { blocks, marks };
	const singleLine = root.dataset.richMaximumBlocks === "1";
	const container = root.querySelector("[data-rich-rows]");
	const nativeControls = [
		...container.querySelectorAll("input, textarea, select, button"),
		...root.querySelectorAll(":scope > [data-rich-action]"),
	];
	const field = root.closest("fieldset");
	const help = field?.querySelector("[data-rich-fallback-help]");
	const initial = nativeRows(container);
	const history = createRichTextHistory(initial);
	const listeners = [];
	const listen = (target, event, handler) => {
		target.addEventListener(event, handler);
		listeners.push(() => target.removeEventListener(event, handler));
	};
	const element = (tag, properties = {}, text = "") => {
		const node = doc.createElement(tag);
		Object.assign(node, properties);
		if (text) node.textContent = text;
		return node;
	};
	const t = messages(root);
	const legend = field?.querySelector("legend")?.firstChild?.textContent?.trim() ?? "";
	const locale = root
		.closest("[data-locale-panel]")
		?.querySelector(".admin-locale__label")?.textContent;
	const box = element("div", { className: "admin-rich-box" });
	const toolbar = element("div", { className: "admin-rich-toolbar" });
	toolbar.setAttribute("role", "toolbar");
	toolbar.setAttribute("aria-label", t("rich.toolbar", { field: legend }));
	const surface = element("div", {
		className: "admin-rich-surface",
		contentEditable: "true",
	});
	surface.setAttribute("role", "textbox");
	surface.setAttribute("aria-multiline", String(!singleLine));
	surface.setAttribute(
		"aria-label",
		locale ? t("rich.surface", { field: legend, language: locale }) : legend,
	);
	surface.spellcheck = true;
	const blockSelect = element("select", { title: t("rich.text_style") });
	blockSelect.setAttribute("aria-label", t("rich.text_style"));
	for (const type of blocks)
		blockSelect.append(element("option", { value: type }, t(`rich.block.${type}`)));
	if (!singleLine) toolbar.append(blockSelect);
	const tool = (label, title, action) => {
		const button = element("button", { type: "button", title }, label);
		button.setAttribute("aria-label", title);
		button.dataset.richTool = action;
		toolbar.append(button);
		return button;
	};
	const separator = () =>
		toolbar.append(element("span", { className: "admin-rich-toolbar__separator" }));
	const markButtons = {};
	if (marks.some((mark) => FORMAT_MARKS.includes(mark))) separator();
	for (const mark of marks.filter((mark) => FORMAT_MARKS.includes(mark))) {
		const key = mark === "smallCaps" ? "small_caps" : mark;
		markButtons[mark] = tool(t(`rich.mark.${key}_short`), t(`rich.mark.${key}`), mark);
		markButtons[mark].setAttribute("aria-pressed", "false");
		if (mark === "smallCaps")
			markButtons[mark].setAttribute(
				"aria-keyshortcuts",
				"Control+Shift+K Meta+Shift+K",
			);
	}
	const linkButton = marks.includes("link")
		? tool(t("rich.link"), t("rich.link_title"), "link")
		: null;
	toolbar.append(element("span", { className: "admin-rich-toolbar__spacer" }));
	tool("↶", t("rich.undo"), "undo");
	tool("↷", t("rich.redo"), "redo");
	const linkBar = element("div", { className: "admin-rich-link", hidden: true });
	const linkInput = element("input", {
		type: "url",
		maxLength: 2048,
		placeholder: t("rich.link_placeholder"),
	});
	linkInput.setAttribute("aria-label", t("rich.link_address"));
	const linkApply = element("button", { type: "button" }, t("rich.apply_link"));
	const linkRemove = element("button", { type: "button" }, t("rich.remove_link"));
	const linkCancel = element(
		"button",
		{ type: "button", className: "admin-button--quiet" },
		t("rich.cancel"),
	);
	linkBar.append(linkInput, linkApply, linkRemove, linkCancel);
	const status = element("p", { className: "admin-rich-status" });
	status.setAttribute("role", "status");
	const transport = element("div", { hidden: true });
	transport.dataset.richTransport = "true";
	const fingerprint = element("input", {
		type: "text",
		name: `${name}__fingerprint`,
		hidden: true,
	});
	fingerprint.dataset.richFingerprint = "true";
	box.append(toolbar, surface, linkBar);
	root.prepend(box, status, transport, fingerprint);
	container.hidden = true;
	if (help) help.hidden = true;
	for (const control of nativeControls) {
		control.disabled = true;
		if (control.matches("button")) control.hidden = true;
	}
	let rows = initial;
	let disposed = false;
	let savedRange = null;
	const exec = (command, value = null) => doc.execCommand(command, false, value);
	const write = (next, signal = true) => {
		rows = next;
		if (signal) history.record(next);
		transport.replaceChildren(
			...transportEntries(name, rows).map(([key, value]) =>
				element("input", { type: "hidden", name: key, value }),
			),
		);
		fingerprint.value = JSON.stringify(rows);
		if (signal) fingerprint.dispatchEvent(new window.Event("input", { bubbles: true }));
	};
	const render = (next) => {
		surface.replaceChildren(
			rowsFragment(doc, singleLine ? singleLineRows(next) : next),
		);
		write(next, false);
	};
	const sync = () => {
		const next = rowsFromNodes(surface, options);
		write(singleLine ? singleLineRows(next) : next);
		if (
			singleLine &&
			(next.length > 1 ||
				next.some((row) => row.children.some((run) => /[\r\n]/.test(run.text))))
		) {
			const selection = textSelection(surface);
			surface.replaceChildren(rowsFragment(doc, rows));
			if (selection) restoreTextSelection(surface, selection);
		}
	};
	const inside = (node) => node && (node === surface || surface.contains(node));
	const currentBlock = () => {
		let node = window.getSelection()?.anchorNode;
		while (node && node !== surface) {
			const tag = node.nodeName?.toLowerCase();
			if (tag === "li")
				return node.parentNode?.nodeName.toLowerCase() === "ol"
					? "ordered_list"
					: "bullet_list";
			if (tag === "h2" || tag === "h1") return "heading_2";
			if (/^h[3-6]$/.test(tag)) return "heading_3";
			if (tag === "p" || tag === "div") return "paragraph";
			node = node.parentNode;
		}
		return "paragraph";
	};
	const refreshToolbar = () => {
		const selection = window.getSelection();
		if (!selection || !inside(selection.anchorNode)) return;
		const type = currentBlock();
		if (blocks.includes(type)) blockSelect.value = type;
		for (const [mark, button] of Object.entries(markButtons)) {
			const anchor =
				selection.anchorNode.nodeType === 1
					? selection.anchorNode
					: selection.anchorNode.parentElement;
			button.setAttribute(
				"aria-pressed",
				String(
					mark === "smallCaps"
						? Boolean(anchor?.closest(".cms-small-caps"))
						: doc.queryCommandState(mark),
				),
			);
		}
	};
	const toggleSmallCaps = () => {
		const selection = textSelection(surface);
		if (!selection || selection.start === selection.end) {
			status.textContent = t("rich.select_first");
			return;
		}
		render(
			formatTextRange(
				rowsFromNodes(surface, options),
				selection.start,
				selection.end,
				"smallCaps",
			),
		);
		sync();
		restoreTextSelection(surface, selection);
		refreshToolbar();
	};
	const moveHistory = (delta) => {
		const selection = textSelection(surface);
		render(history.move(delta));
		fingerprint.dispatchEvent(new window.Event("input", { bubbles: true }));
		restoreTextSelection(surface, selection);
		refreshToolbar();
	};
	const setBlock = (type) => {
		surface.focus();
		const current = currentBlock();
		for (const [list, command] of [
			["bullet_list", "insertUnorderedList"],
			["ordered_list", "insertOrderedList"],
		])
			if (current === list && type !== list) exec(command);
		if (type === "bullet_list" || type === "ordered_list") {
			if (current !== type)
				exec(type === "bullet_list" ? "insertUnorderedList" : "insertOrderedList");
		} else exec("formatBlock", `<${BLOCK_TAGS[type] ?? "p"}>`);
		sync();
		refreshToolbar();
	};
	const openLink = () => {
		const selection = window.getSelection();
		if (!selection?.rangeCount || !inside(selection.anchorNode)) {
			status.textContent = t("rich.select_first");
			return;
		}
		savedRange = selection.getRangeAt(0).cloneRange();
		let node = selection.anchorNode;
		while (node && node !== surface && node.nodeName?.toLowerCase() !== "a")
			node = node.parentNode;
		linkInput.value = node && node !== surface ? (node.getAttribute("href") ?? "") : "";
		linkBar.hidden = false;
		linkInput.focus();
	};
	const restore = () => {
		surface.focus();
		if (!savedRange) return;
		const selection = window.getSelection();
		selection.removeAllRanges();
		selection.addRange(savedRange);
	};
	const closeLink = (focus = true) => {
		linkBar.hidden = true;
		if (focus) restore();
		savedRange = null;
	};
	listen(linkApply, "click", () => {
		const href = linkInput.value.trim();
		if (!validLink(href)) {
			status.textContent = t("rich.invalid_link");
			linkInput.focus();
			return;
		}
		restore();
		exec("createLink", href);
		closeLink(false);
		sync();
		status.textContent = t("rich.link_added");
	});
	listen(linkRemove, "click", () => {
		restore();
		exec("unlink");
		closeLink(false);
		sync();
		status.textContent = t("rich.link_removed");
	});
	listen(linkCancel, "click", () => closeLink());
	listen(linkInput, "keydown", (event) => {
		if (event.key === "Enter") {
			event.preventDefault();
			linkApply.click();
		} else if (event.key === "Escape") {
			event.preventDefault();
			closeLink();
		}
	});
	listen(toolbar, "mousedown", (event) => {
		// Keep the text selection while a toolbar button is pressed.
		if (event.target.closest("button")) event.preventDefault();
	});
	listen(toolbar, "click", (event) => {
		const button = event.target.closest("[data-rich-tool]");
		if (!button) return;
		const action = button.dataset.richTool;
		if (action === "link") return openLink();
		surface.focus();
		if (action === "smallCaps") return toggleSmallCaps();
		if (action === "undo" || action === "redo")
			return moveHistory(action === "undo" ? -1 : 1);
		exec(action);
		sync();
		refreshToolbar();
	});
	listen(blockSelect, "change", () => setBlock(blockSelect.value));
	listen(surface, "input", () => {
		sync();
		status.textContent = "";
	});
	listen(surface, "blur", (event) => {
		// Canonicalize stray browser markup once editing leaves the editor.
		if (!box.contains(event.relatedTarget))
			surface.replaceChildren(rowsFragment(doc, rows));
	});
	listen(surface, "keydown", (event) => {
		if (singleLine && event.key === "Enter") {
			event.preventDefault();
			return;
		}
		if (!(event.ctrlKey || event.metaKey) || event.altKey) return;
		const key = event.key.toLowerCase();
		if (key === "z" || key === "y") {
			event.preventDefault();
			moveHistory(key === "y" || event.shiftKey ? 1 : -1);
			return;
		}
		if (key === "k" && event.shiftKey) {
			event.preventDefault();
			if (marks.includes("smallCaps")) toggleSmallCaps();
			return;
		}
		const mark = { b: "bold", i: "italic", u: "underline" }[key];
		if (mark && !marks.includes(mark)) event.preventDefault();
		if (key === "k" && linkButton) {
			event.preventDefault();
			openLink();
		}
	});
	listen(surface, "beforeinput", (event) => {
		if (
			singleLine &&
			["insertParagraph", "insertLineBreak"].includes(event.inputType)
		) {
			event.preventDefault();
			return;
		}
		if (["historyUndo", "historyRedo"].includes(event.inputType)) {
			event.preventDefault();
			moveHistory(event.inputType === "historyUndo" ? -1 : 1);
		}
	});
	listen(doc, "selectionchange", refreshToolbar);
	const insertRows = (fragment) => {
		const selection = window.getSelection();
		if (!selection?.rangeCount || !inside(selection.anchorNode)) return;
		const range = selection.getRangeAt(0);
		range.deleteContents();
		const nodes = rowsFragment(doc, singleLine ? singleLineRows(fragment) : fragment);
		const last = nodes.lastChild;
		range.insertNode(nodes);
		if (last) {
			range.setStartAfter(last);
			range.collapse(true);
			selection.removeAllRanges();
			selection.addRange(range);
		}
		sync();
		surface.replaceChildren(rowsFragment(doc, rows));
	};
	listen(surface, "paste", (event) => {
		event.preventDefault();
		const semantic = clipboardRows(
			event.clipboardData.getData(CLIPBOARD),
			blocks,
			marks,
		);
		if (semantic) {
			insertRows(semantic);
			status.textContent = t("rich.pasted_semantic");
			return;
		}
		const html = safeClipboardHtml(event.clipboardData.getData("text/html"));
		if (html?.includes(' class="cms-small-caps"')) {
			const parsed = new window.DOMParser().parseFromString(html, "text/html");
			const fragment = rowsFromNodes(parsed.body, options);
			if (fragment.length) {
				insertRows(fragment);
				status.textContent = t("rich.pasted_semantic");
				return;
			}
		}
		const text = event.clipboardData.getData("text/plain").replaceAll("\r\n", "\n");
		exec("insertText", singleLine ? text.replaceAll(/[\r\n]/g, " ") : text);
		sync();
		status.textContent = t("rich.pasted_plain");
	});
	listen(surface, "drop", (event) => {
		event.preventDefault();
		status.textContent = t("rich.no_drop");
	});
	const copy = (event, cut) => {
		const selection = window.getSelection();
		if (!selection?.rangeCount || selection.isCollapsed) return;
		const holder = doc.createElement("div");
		const range = selection.getRangeAt(0);
		// A selection inside formatting excludes its ancestors; restore them as shallow clones.
		let content = range.cloneContents();
		for (
			let node = range.commonAncestorContainer;
			node && node !== surface;
			node = node.parentNode
		) {
			if (node.nodeType !== 1) continue;
			const wrapper = node.cloneNode(false);
			wrapper.append(content);
			content = wrapper;
		}
		holder.append(content);
		const fragment = rowsFromNodes(holder, options);
		if (!fragment.length) return;
		event.preventDefault();
		event.clipboardData.setData("text/plain", holder.textContent);
		event.clipboardData.setData("text/html", rowsToHtml(fragment));
		event.clipboardData.setData(
			CLIPBOARD,
			JSON.stringify({ formatVersion: rowsVersion(fragment), rows: fragment }),
		);
		if (cut) {
			exec("delete");
			sync();
		}
	};
	listen(surface, "copy", (event) => copy(event, false));
	listen(surface, "cut", (event) => copy(event, true));
	const form = root.closest("form");
	if (form)
		listen(form, "reset", () =>
			window.queueMicrotask(() => {
				if (!disposed) {
					history.reset(initial);
					render(initial);
					fingerprint.dispatchEvent(new window.Event("input", { bubbles: true }));
				}
			}),
		);
	try {
		exec("defaultParagraphSeparator", "p");
		exec("styleWithCSS", false);
	} catch {
		/* Older engines ignore these hints; serialization still normalizes output. */
	}
	render(initial);
	const unregisterPreview = previewEditors?.register(root, {
		read: () => rows,
		restore: (expected, original) => {
			if (disposed || JSON.stringify(rows) !== expected) return false;
			history.record(original);
			render(original);
			fingerprint.dispatchEvent(new window.Event("input", { bubbles: true }));
			return true;
		},
		replace: (expected, position, value) => {
			if (disposed || JSON.stringify(rows) !== expected) return false;
			const next = replaceBlockText(rows, position, value);
			if (!next) return false;
			history.record(next);
			render(next);
			fingerprint.dispatchEvent(new window.Event("input", { bubbles: true }));
			return true;
		},
	});
	return () => {
		if (disposed) return;
		disposed = true;
		unregisterPreview?.();
		history.reset([]);
		for (const remove of listeners) remove();
		box.remove();
		status.remove();
		transport.remove();
		fingerprint.remove();
		container.hidden = false;
		if (help) help.hidden = false;
		for (const control of nativeControls) {
			control.disabled = false;
			if (control.matches("button")) control.hidden = false;
		}
	};
}
