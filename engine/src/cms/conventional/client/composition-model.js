/** One active native iframe navigation and one replaceable latest intent. */
export function reducePreview(state, event) {
	if (state.phase === "disposed") return { state, effects: [] };
	switch (event.type) {
		case "requested": {
			const next = { ...state, latest: event.requestId };
			if (state.active !== null) return { state: next, effects: [] };
			return {
				state: { ...next, active: event.requestId, phase: "loading" },
				effects: [{ type: "navigate", requestId: event.requestId }],
			};
		}
		case "settled": {
			if (event.requestId !== state.active) return { state, effects: [] };
			if (state.latest !== state.active)
				return {
					state: { ...state, active: state.latest, phase: "loading" },
					effects: [{ type: "navigate", requestId: state.latest }],
				};
			return {
				state: { ...state, active: null, phase: event.failed ? "error" : "ready" },
				effects: [],
			};
		}
		case "disposed":
			return { state: { ...state, active: null, phase: "disposed" }, effects: [] };
		default:
			throw new Error("Unknown composition preview event");
	}
}
