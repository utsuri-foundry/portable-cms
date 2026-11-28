export function descriptionState() {
	return {
		ids: [],
		loads: [],
		edited: [],
		disposed: false,
	};
}

/** Request identity, eight-card bounds and edited cards are independent of DOM and network. */
export function reduceDescriptions(state, event) {
	if (state.disposed) return { state, effects: [] };
	const result = (change = {}, effects = []) => ({
		state: { ...state, ...change },
		effects,
	});
	switch (event.type) {
		case "edit":
			// Only images the editor has written in are kept, so an untouched card never writes.
			if (!state.ids.includes(event.id) || state.edited.includes(event.id))
				return result();
			return result({ edited: [...state.edited, event.id] });
		case "load": {
			if (
				state.ids.includes(event.id) ||
				state.loads.some((load) => load.id === event.id)
			)
				return result();
			// A new batch replaces the cards once each was described and everything is kept.
			const clear =
				event.kept === true &&
				state.ids.length > 0 &&
				state.loads.length === 0 &&
				state.ids.every((id) => state.edited.includes(id));
			const ids = clear ? [] : state.ids;
			if (ids.length + state.loads.length >= 8)
				return result({}, [{ type: "load-failed" }]);
			return result(
				{
					ids,
					edited: clear ? [] : state.edited,
					loads: [...state.loads, { id: event.id, requestId: event.requestId }],
				},
				[
					...(clear ? [{ type: "clear" }] : []),
					{ type: "load", id: event.id, requestId: event.requestId },
				],
			);
		}
		case "loaded":
		case "load-failed": {
			if (
				!state.loads.some(
					(load) => load.id === event.id && load.requestId === event.requestId,
				)
			)
				return result();
			const loads = state.loads.filter((load) => load.requestId !== event.requestId);
			return event.type === "loaded"
				? result({ loads, ids: [...state.ids, event.id] }, [
						{ type: "card", item: event.item },
					])
				: result({ loads }, [{ type: "load-failed" }]);
		}
		case "dispose":
			return result({ disposed: true, loads: [] }, [{ type: "dispose" }]);
		default:
			return result();
	}
}
