/** DOM selection offsets match native runs; trailing browser placeholder breaks are empty. */
const size = (node) =>
	node.nodeType === 3
		? node.textContent.length
		: node.nodeName === "BR"
			? node.parentNode?.lastChild === node &&
				/^(P|DIV|H[1-6]|LI)$/.test(node.parentNode.nodeName)
				? 0
				: 1
			: [...node.childNodes].reduce((sum, child) => sum + size(child), 0);

export function textOffsetAt(surface, container, offset) {
	let total = 0;
	const walk = (node) => {
		if (node === container) {
			total +=
				node.nodeType === 3
					? offset
					: [...node.childNodes]
							.slice(0, offset)
							.reduce((sum, child) => sum + size(child), 0);
			return true;
		}
		if (node.nodeType === 3 || node.nodeName === "BR") total += size(node);
		else for (const child of node.childNodes) if (walk(child)) return true;
		return false;
	};
	return walk(surface) ? total : null;
}

export function textSelection(surface) {
	const selection = surface.ownerDocument.defaultView.getSelection();
	if (!selection?.rangeCount) return null;
	const range = selection.getRangeAt(0);
	if (!surface.contains(range.startContainer) || !surface.contains(range.endContainer))
		return null;
	return {
		start: textOffsetAt(surface, range.startContainer, range.startOffset),
		end: textOffsetAt(surface, range.endContainer, range.endOffset),
	};
}

export function restoreTextSelection(surface, selection) {
	if (!selection) return;
	const doc = surface.ownerDocument;
	const point = (offset) => {
		const walk = (node) => {
			if (node.nodeType === 3) {
				if (offset <= node.textContent.length) return [node, offset];
				offset -= node.textContent.length;
			} else if (node.nodeName === "BR") {
				const index = [...node.parentNode.childNodes].indexOf(node);
				const length = size(node);
				if (offset <= length) return [node.parentNode, index + offset];
				offset -= length;
			} else
				for (const child of node.childNodes) {
					const found = walk(child);
					if (found) return found;
				}
			return null;
		};
		return walk(surface) ?? [surface, surface.childNodes.length];
	};
	const range = doc.createRange();
	range.setStart(...point(selection.start));
	range.setEnd(...point(selection.end));
	const current = doc.defaultView.getSelection();
	current.removeAllRanges();
	current.addRange(range);
}
