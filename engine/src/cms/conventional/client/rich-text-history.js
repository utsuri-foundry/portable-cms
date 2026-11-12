/** Bounded local history for native edits and engine-owned formatting alike. */
export function createRichTextHistory(initial) {
	let entries = [JSON.stringify(initial)];
	let index = 0;
	return {
		reset(rows) {
			entries = [JSON.stringify(rows)];
			index = 0;
		},
		record(rows) {
			const entry = JSON.stringify(rows);
			if (entry === entries[index]) return;
			entries = entries.slice(0, index + 1);
			entries.push(entry);
			while (
				entries.length > 50 ||
				(entries.length > 1 &&
					entries.reduce((sum, value) => sum + value.length, 0) > 4_194_304)
			)
				entries.shift();
			index = entries.length - 1;
		},
		move(delta) {
			index = Math.max(0, Math.min(entries.length - 1, index + delta));
			return JSON.parse(entries[index]);
		},
	};
}
