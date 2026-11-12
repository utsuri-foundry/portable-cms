/** Explicit adapter ownership: only a mounted rich editor may accept preview changes. */
export function createRichPreviewEditors() {
	const editors = new WeakMap();
	return {
		register(root, adapter) {
			editors.set(root, adapter);
			return () => editors.delete(root);
		},
		find(field, locale) {
			if (field?.dataset.fieldMatch !== "rich" || field.hasAttribute("data-field-item"))
				return null;
			const scope = field.querySelector(`[data-locale-panel="${locale}"]`) ?? field;
			return editors.get(scope.querySelector("[data-rich-editor]")) ?? null;
		},
	};
}

export const rowText = (row) => row.children.map((run) => run.text).join("");

/**
 * One contiguous replacement, found on code points, never UTF-16 halves. Unchanged pieces
 * retain their marks. Replacement inherits the first removed character's marks; insertion
 * between runs inherits only shared marks, so typing outside a link does not extend it.
 * PHP remains the save-time validation authority.
 */
export function replaceBlockText(rows, position, value) {
	if (
		!Number.isInteger(position) ||
		!rows[position] ||
		typeof value !== "string" ||
		value.length > 100_000
	)
		return null;
	if (
		!value.isWellFormed() ||
		/\p{Cc}/u.test(value.replaceAll("\n", "").replaceAll("\r", "").replaceAll("\t", ""))
	)
		return null;
	const row = rows[position];
	const before = [...rowText(row)];
	const after = [...value];
	if (after.length > 100_000) return null;
	let prefix = 0;
	while (
		prefix < before.length &&
		prefix < after.length &&
		before[prefix] === after[prefix]
	)
		prefix++;
	let suffix = 0;
	while (
		suffix < before.length - prefix &&
		suffix < after.length - prefix &&
		before[before.length - 1 - suffix] === after[after.length - 1 - suffix]
	)
		suffix++;
	const inserted = after.slice(prefix, after.length - suffix).join("");
	if (/[\r\n\t]/u.test(inserted)) return null;
	if (prefix === before.length && prefix === after.length) return rows;
	const start = before.slice(0, prefix).join("").length;
	const end = before.slice(0, before.length - suffix).join("").length;
	const slice = (from, to) => {
		let offset = 0;
		return row.children.flatMap((run) => {
			const text = run.text.slice(Math.max(0, from - offset), Math.max(0, to - offset));
			offset += run.text.length;
			return text ? [{ text, marks: run.marks }] : [];
		});
	};
	const left = slice(0, start);
	const right = slice(end, before.join("").length);
	const at = slice(start, start + 1)[0]?.marks ?? [];
	const marks =
		start !== end
			? at
			: (left.at(-1)?.marks ?? []).filter((mark) =>
					right[0]?.marks.some(
						(other) => JSON.stringify(mark) === JSON.stringify(other),
					),
				);
	const pieces = [...left, ...(inserted ? [{ text: inserted, marks }] : []), ...right];
	const children = [];
	for (const run of pieces) {
		const points = [...run.text];
		for (let index = 0; index < points.length; index += 10_000) {
			const text = points.slice(index, index + 10_000).join("");
			const last = children.at(-1);
			if (
				last &&
				JSON.stringify(last.marks) === JSON.stringify(run.marks) &&
				[...last.text, ...text].length <= 10_000
			)
				last.text += text;
			else children.push({ text, marks: structuredClone(run.marks) });
		}
	}
	const next = rows.map((item, index) =>
		index === position
			? {
					type: item.type,
					children: children.length ? children : [{ text: "", marks: [] }],
				}
			: item,
	);
	if (
		new TextEncoder().encode(JSON.stringify(next)).length > 1_048_576 ||
		next.reduce((count, item) => count + 1 + item.children.length, 0) > 5000
	)
		return null;
	return next;
}
