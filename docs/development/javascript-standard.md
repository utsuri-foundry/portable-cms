# Browser JavaScript implementation standard

The administration baseline is server-rendered semantic HTML, native navigation, native forms, and POST/redirect/GET. JavaScript is a bounded enhancement, not the authority for content, permissions, workflow, validation, routing, or durable state.

## Modules and ownership

- Use native ES modules and web-platform APIs. No framework, TypeScript build, client router, HTMX, or general DOM-swap runtime is part of the baseline.
- An island owns one interaction and can be removed without invalidating server authorization or validation.
- Imports must follow `architecture.json`. Avoid module-level mutable application state and hidden event buses.
- Biome owns parsing, formatting, and lint. Repository JavaScript uses tabs, double quotes, semicolons, trailing commas, and an 88-column target.

## State and orchestration

Local synchronous behavior may use a small closure. Non-trivial asynchronous behavior uses:

```text
pure reducer: (state, event) -> { state, effects }
effect runner: effects -> external work -> follow-up events
```

Reducer state is plain serializable data: objects, arrays, strings, numbers, booleans, and `null`. DOM nodes, functions, promises, errors, dates, maps, sets, abort controllers, streams, and file handles belong to the island runtime.

```js
// Good: stale completion is rejected by request identity.
function reduce(state, event) {
	if (event.type === "search-requested") {
		return {
			state: { ...state, requestId: event.requestId, phase: "loading" },
			effects: [{ type: "fetch-results", requestId: event.requestId, query: event.query }],
		};
	}
	if (event.type === "search-succeeded" && event.requestId === state.requestId) {
		return { state: { ...state, phase: "ready", results: event.results }, effects: [] };
	}
	return { state, effects: [] };
}
```

Events are facts and effects are declarative requests. Reducers do not fetch, mutate the DOM, read time, create random IDs, log, or use storage. Every user-driven async operation carries a unique request identity; reducers reject stale success and failure events even if cancellation races.

## Cancellation and disposal

The runtime owns `AbortController`, timers, observers, object URLs, event listeners, workers, streams, and subscriptions. A newer latest-wins request aborts the older request, but stale-result rejection remains the correctness boundary. Every island returns or owns an idempotent `dispose()` that releases all resources. Promise results must be awaited, returned, or explicitly handled; floating promises fail lint.

## DOM, storage, and rendering

- Query only inside the island root and validate required elements before attaching behavior.
- Render user-authored text with `textContent`. `innerHTML`, `outerHTML`, `insertAdjacentHTML`, `document.write`, `eval`, and the `Function` constructor fail the quality gate.
- Create richer DOM with `createElement`, typed property assignment, and `append`; URLs pass the server-owned URL policy before they reach markup.
- Browser storage is a replaceable cache for non-sensitive hints and resumable client identifiers only. It never holds credentials, private paths, CSRF tokens, authoritative workflow state, or content whose loss would corrupt a record.
- Every mutation uses same-origin credentials and the session-bound CSRF token. A disabled button is interaction feedback, not authorization.

```js
// Good.
const label = document.createElement("span");
label.textContent = record.title;
root.replaceChildren(label);

// Bad.
root.innerHTML = `<span>${record.title}</span>`;
```

## Accessibility and motion

Prefer HTML and CSS for behavior they already implement. Enhancements preserve accessible names, keyboard order, focus visibility, error association, live status where justified, and the behavior of the unenhanced form. Move focus only after a user action and to a meaningful target. Animation and idle demonstrations honor `prefers-reduced-motion`, pause when hidden, and never prevent immediate user takeover.

## Performance and hot paths

Measure before adding complexity. Coalesce pointer, resize, and scroll input at the edge with `requestAnimationFrame` when needed. Keep reducers small, avoid copying large collections on high-frequency paths, and place rebuildable caches in the runtime. Search and rendering work should be chunked or delegated only after a measured main-thread budget is exceeded. Disposal tests must prove that repeated mount/unmount does not accumulate listeners, timers, observers, or retained blobs.

## Required proof

Reducer tests use deterministic event sequences and assert exact state and effects. Runtime tests inject fetch, time, storage, cancellation, and failure. DOM journeys cover keyboard and reduced-motion behavior where an island affects interaction.

Run:

```text
nix develop -c just check-javascript
```

Analyzer suppression comments require an exact expiring exception. No exception exists today.
