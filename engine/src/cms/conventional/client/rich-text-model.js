/** Pure local editing operations. PHP remains the validation authority. */
export const emptyRun = () => ({ text: "", marks: [] });
/** One editable line; keep every run and its marks when pasting multiple blocks. */
export function singleLineRows(rows) {
	if (rows.length === 0) return [];
	return [
		{
			type: rows[0].type,
			children: rows.flatMap((row, index) => [
				...(index ? [{ text: " ", marks: [] }] : []),
				...row.children.map((run) => ({
					text: run.text.replace(/\r\n?|\n/g, " "),
					marks: [...run.marks],
				})),
			]),
		},
	];
}
export function editRows(rows, action, row = -1, span = -1, defaultType = "paragraph") {
	const next = structuredClone(rows);
	if (action === "add") next.push({ type: defaultType, children: [emptyRun()] });
	else if (next[row]) {
		if (action === "remove") next.splice(row, 1);
		if (action === "run") next[row].children.push(emptyRun());
		if (action === "delete-run") next[row].children.splice(span, 1);
		const target = row + (action === "up" ? -1 : 1);
		if (["up", "down"].includes(action) && next[target])
			[next[row], next[target]] = [next[target], next[row]];
	}
	return next;
}
export function formatSelection(rows, row, span, start, end, mark) {
	const next = structuredClone(rows);
	const run = next[row]?.children[span];
	if (!run || start === end) return next;
	const kind = typeof mark === "string" ? mark : "link";
	const present = run.marks.some(
		(item) => (typeof item === "string" ? item : item.type) === kind,
	);
	const marks = run.marks.filter(
		(item) => (typeof item === "string" ? item : item.type) !== kind,
	);
	if (!present || typeof mark !== "string") marks.push(mark);
	const pieces = [];
	if (start > 0) pieces.push({ text: run.text.slice(0, start), marks: run.marks });
	pieces.push({ text: run.text.slice(start, end), marks });
	if (end < run.text.length)
		pieces.push({ text: run.text.slice(end), marks: run.marks });
	next[row].children.splice(span, 1, ...pieces);
	return next;
}
export function documentFromRows(rows) {
	const nodes = [];
	for (const row of rows) {
		if (["bullet_list", "ordered_list"].includes(row.type)) {
			const item = { type: "list_item", children: row.children };
			if (nodes.at(-1)?.type === row.type) nodes.at(-1).children.push(item);
			else nodes.push({ type: row.type, children: [item] });
		} else nodes.push(row);
	}
	return { formatVersion: rowsVersion(rows), nodes };
}

export const rowsVersion = (rows) =>
	rows.some((row) => row.children.some((run) => run.marks.includes("smallCaps")))
		? 2
		: 1;

/** Bounded clipboard decoding only; URLs remain inert controls until PHP validation. */
export function clipboardRows(value, blocks, marks) {
	if (value.length > 1_048_576) return null;
	try {
		const input = JSON.parse(value);
		if (
			![1, 2].includes(input.formatVersion) ||
			Object.keys(input).sort().join() !== "formatVersion,rows" ||
			!Array.isArray(input.rows) ||
			input.rows.length === 0
		)
			return null;
		let count = 0;
		for (const row of input.rows) {
			if (
				Object.keys(row).sort().join() !== "children,type" ||
				!blocks.includes(row.type) ||
				!Array.isArray(row.children)
			)
				return null;
			count++;
			for (const run of row.children) {
				if (
					Object.keys(run).sort().join() !== "marks,text" ||
					typeof run.text !== "string" ||
					[...run.text].length > 10000 ||
					!Array.isArray(run.marks) ||
					run.marks.length > (input.formatVersion === 2 ? 5 : 4)
				)
					return null;
				count++;
				const seen = new Set();
				for (const mark of run.marks) {
					const kind = typeof mark === "string" ? mark : mark?.type;
					if (!marks.includes(kind) || seen.has(kind)) return null;
					if (kind === "smallCaps" && input.formatVersion !== 2) return null;
					seen.add(kind);
					if (
						typeof mark !== "string" &&
						(kind !== "link" ||
							Object.keys(mark).sort().join() !== "href,type" ||
							typeof mark.href !== "string" ||
							mark.href.length > 2048)
					)
						return null;
					if (mark === "link") return null;
				}
			}
		}
		return count <= 5000 ? input.rows : null;
	} catch {
		return null;
	}
}

/** UTF-16 offsets across text runs, including soft breaks but not block separators. */
export function formatTextRange(rows, start, end, mark) {
	const next = structuredClone(rows);
	let offset = 0;
	const pieces = [];
	for (const row of next) {
		const children = [];
		for (const run of row.children) {
			const from = Math.max(0, start - offset);
			const to = Math.min(run.text.length, end - offset);
			offset += run.text.length;
			if (from >= to) {
				children.push(run);
				continue;
			}
			if (from > 0) children.push({ text: run.text.slice(0, from), marks: run.marks });
			const selected = { text: run.text.slice(from, to), marks: [...run.marks] };
			children.push(selected);
			pieces.push(selected);
			if (to < run.text.length)
				children.push({ text: run.text.slice(to), marks: run.marks });
		}
		row.children = children;
	}
	const remove = pieces.every((run) => run.marks.includes(mark));
	for (const run of pieces) {
		run.marks = run.marks.filter((value) => value !== mark);
		if (!remove) run.marks.push(mark);
	}
	return next;
}

export function insertClipboard(rows, row, span, start, end, fragment) {
	const next = structuredClone(rows);
	const run = next[row].children[span];
	const before = next[row].children.slice(0, span);
	const after = next[row].children.slice(span + 1);
	if (start > 0) before.push({ text: run.text.slice(0, start), marks: run.marks });
	if (end < run.text.length)
		after.unshift({ text: run.text.slice(end), marks: run.marks });
	if (fragment.length === 1) {
		if (before.length === 0 && after.length === 0) next[row].type = fragment[0].type;
		next[row].children = [
			...before,
			...structuredClone(fragment[0].children),
			...after,
		];
	} else {
		const inserted = structuredClone(fragment);
		if (before.length) inserted.unshift({ type: next[row].type, children: before });
		if (after.length) inserted.push({ type: next[row].type, children: after });
		next.splice(row, 1, ...inserted);
	}
	return next;
}

export function formatBlocks(rows, selected, mark) {
	const next = structuredClone(rows);
	const runs = selected.flatMap((index) => next[index]?.children ?? []);
	const remove = runs.every((run) => run.marks.includes(mark));
	for (const run of runs) {
		run.marks = run.marks.filter((value) => value !== mark);
		if (!remove) run.marks.push(mark);
	}
	return next;
}
