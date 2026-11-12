const roots = typeof document === "undefined" ? [] : document.querySelectorAll("[data-filter-form]");

for (const root of roots) {
	const input = root.querySelector("[data-filter-input]");
	const status = root.querySelector("[data-filter-status]");
	const singular = root.querySelector("[data-result-singular]")?.textContent ?? "result";
	const plural = root.querySelector("[data-result-plural]")?.textContent ?? "results";
	const results = document.querySelector("[data-filter-results]");
	if (
		!(input instanceof HTMLInputElement) ||
		!(status instanceof HTMLOutputElement) ||
		!(results instanceof HTMLUListElement)
	) {
		continue;
	}
	const items = Array.from(results.querySelectorAll("[data-index-item]")).filter(
		(item) => item instanceof HTMLLIElement,
	);
	const initial = new URL(window.location.href).searchParams.get("q") ?? "";
	input.value = initial;

	const render = (query) => {
		const tokens = normalize(query);
		let visible = 0;
		for (const item of items) {
			const matches = matchesQuery(query, item.dataset.searchText ?? "");
			item.hidden = !matches;
			if (matches) {
				visible += 1;
			}
		}
		status.value = `${visible} ${visible === 1 ? singular : plural}`;
		const url = new URL(window.location.href);
		if (query.trim() === "") {
			url.searchParams.delete("q");
		} else {
			url.searchParams.set("q", query);
		}
		window.history.replaceState(null, "", url);
	};

	input.addEventListener("input", () => render(input.value));
	render(initial);
}


if (
	typeof window !== "undefined" &&
	window.matchMedia("(hover: hover) and (pointer: fine)").matches &&
	!window.matchMedia("(prefers-reduced-motion: reduce)").matches
) {
	for (const trigger of document.querySelectorAll("[data-preview-trigger]")) {
		if (!(trigger instanceof HTMLAnchorElement)) {
			continue;
		}
		const item = trigger.closest("[data-index-item]");
		if (!(item instanceof HTMLLIElement) || !item.querySelector(".studio-preview")) {
			continue;
		}
		const activate = () => item.setAttribute("data-preview-active", "");
		const deactivate = () => item.removeAttribute("data-preview-active");
		trigger.addEventListener("pointerenter", activate);
		trigger.addEventListener("pointerleave", deactivate);
		trigger.addEventListener("focus", activate);
		trigger.addEventListener("blur", deactivate);
		trigger.addEventListener("pointermove", (event) => {
			const x = Math.min(event.clientX + 20, window.innerWidth - 420);
			const y = Math.min(event.clientY + 20, window.innerHeight - 300);
			item.style.setProperty("--preview-x", `${Math.max(8, x)}px`);
			item.style.setProperty("--preview-y", `${Math.max(8, y)}px`);
		});
	}
}

export function matchesQuery(query, source) {
	const queryTokens = normalize(query);
	const sourceTokens = normalize(source);
	return queryTokens.every((token) =>
		sourceTokens.some((sourceToken) => sourceToken.includes(token)),
	);
}

export function normalize(value) {
	return value
		.normalize("NFKD")
		.replace(/\p{Mark}/gu, "")
		.toLocaleLowerCase()
		.split(/[^\p{Letter}\p{Number}]+/u)
		.filter(Boolean);
}
