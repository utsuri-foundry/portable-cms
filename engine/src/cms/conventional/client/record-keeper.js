/** One in-flight save, stable retry identities, and explicit effects for deterministic tests. */
export function createRecordKeeper({
	read,
	write,
	identify,
	onState,
	onSaved,
	onConflict,
	schedule = setTimeout,
	cancel = clearTimeout,
	initiallyDirty = false,
}) {
	let baseline = read();
	let forced = initiallyDirty;
	let invalid = false;
	let problem = "";
	let stopped = false;
	let pending = null;
	let running = null;
	let timer = null;
	let failures = 0;
	let displayedChanges = false;
	let waiting = false;
	const dirty = () => forced || invalid || pending !== null || read() !== baseline;
	const later = (delay) => {
		cancel(timer);
		if (!stopped) timer = schedule(() => void flush(), delay);
	};
	async function send(keepalive) {
		while (!stopped && (pending || forced || read() !== baseline)) {
			// A quiet retry for files still being prepared keeps the saved state on screen.
			if (!waiting || pending || read() !== baseline) onState("saving");
			pending ??= { values: read(), id: identify() };
			try {
				const result = await write(pending.values, pending.id, keepalive);
				if (result.conflict) {
					stopped = true;
					onConflict(result);
					return false;
				}
				baseline = pending.values;
				pending = null;
				forced = false;
				if (!result.retryable) failures = 0;
				waiting = false;
				invalid = Object.keys(result.errors).length > 0;
				onSaved(result);
				if (invalid) {
					problem = Object.values(result.errors)[0];
					onState("error", problem);
					if (read() !== baseline) continue;
					if (result.retryable) {
						forced = true;
						later(Math.min(30000, 1000 * 2 ** Math.min(failures++, 5)));
					}
					return false;
				}
				if (result.retryable) {
					// Everything else is kept. Files still being prepared join once they are
					// ready; their rows say when, so the page reads as saved while it retries.
					forced = true;
					if (read() !== baseline) continue;
					waiting = true;
					onState("saved");
					later(Math.min(30000, 1000 * 2 ** Math.min(failures++, 5)));
					return false;
				}
			} catch (error) {
				onState("error", error.message);
				later(Math.min(30000, 1000 * 2 ** Math.min(failures++, 5)));
				return false;
			}
		}
		if (!stopped && !invalid) {
			displayedChanges = false;
			onState("saved");
		}
		return !dirty();
	}
	function flush(keepalive = false) {
		cancel(timer);
		if (stopped) return Promise.resolve(false);
		if (!dirty()) return Promise.resolve(true);
		if (!running)
			running = send(keepalive).finally(() => {
				running = null;
			});
		return running;
	}
	return {
		dirty,
		flush,
		unacknowledged: () => pending !== null || read() !== baseline,
		/** Everything is kept except files that are still being prepared. */
		waiting: () => waiting && pending === null && read() === baseline,
		settleForNativeSave() {
			stopped = true;
			cancel(timer);
			return running || Promise.resolve();
		},
		change() {
			// Retyping the refused value changes nothing to keep: the refusal stands.
			if (invalid && !forced && pending === null && read() === baseline) {
				cancel(timer);
				if (!running) onState("error", problem);
			} else if (dirty()) {
				displayedChanges = true;
				onState("saving");
				later(1500);
			} else if (displayedChanges && !running) {
				cancel(timer);
				displayedChanges = false;
				onState("saved");
			}
		},
		retry() {
			forced = true;
			invalid = false;
			return flush();
		},
		stop() {
			stopped = true;
			cancel(timer);
		},
	};
}
