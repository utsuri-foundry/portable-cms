export function descriptionState() {
	return {
		ids: [],
		loads: [],
		version: 0,
		savedVersion: -1,
		save: null,
		disposed: false,
	};
}

/** Request identity, eight-card bounds and dirty state are independent of DOM and network. */
export function reduceDescriptions(state, event) {
	if (state.disposed) return { state, effects: [] };
	const result = (change = {}, effects = []) => ({
		state: { ...state, ...change },
		effects,
	});
	switch (event.type) {
		case "edit":
			return result({ version: state.version + 1 });
		case "load": {
			if (
				state.ids.includes(event.id) ||
				state.loads.some((load) => load.id === event.id)
			)
				return result();
			const clear =
				state.savedVersion === state.version &&
				state.save === null &&
				state.loads.length === 0;
			const ids = clear ? [] : state.ids;
			if (ids.length + state.loads.length >= 8)
				return result({}, [{ type: "load-failed" }]);
			return result(
				{
					ids,
					savedVersion: clear ? -1 : state.savedVersion,
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
				? result({ loads, ids: [...state.ids, event.id], version: state.version + 1 }, [
						{ type: "card", item: event.item },
					])
				: result({ loads }, [{ type: "load-failed" }]);
		}
		case "save":
			if (state.save !== null || state.loads.length > 0 || state.ids.length === 0)
				return result();
			return result({ save: { requestId: event.requestId, version: state.version } }, [
				{ type: "save", requestId: event.requestId },
			]);
		case "saved":
			if (state.save?.requestId !== event.requestId) return result();
			return result(
				{ save: null, savedVersion: event.success ? state.save.version : -1 },
				[{ type: "saved", items: event.items, success: event.success }],
			);
		case "save-failed":
			if (state.save?.requestId !== event.requestId) return result();
			return result({ save: null, savedVersion: -1 }, [{ type: "save-failed" }]);
		case "dispose":
			return result({ disposed: true, save: null, loads: [] }, [{ type: "dispose" }]);
		default:
			return result();
	}
}
