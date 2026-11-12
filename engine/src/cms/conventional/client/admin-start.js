/* Parser-blocking, same-origin shell bootstrap. No imports or inline policy exceptions. */
(() => {
	const root = document.documentElement;
	const pending = new Set(["editor", "shell"]);
	const update = () => {
		if (pending.size) root.setAttribute("data-admin-pending", [...pending].join(" "));
		else root.removeAttribute("data-admin-pending");
	};
	const finish = (scope) => {
		pending.delete(scope);
		update();
	};
	// Older/limited browsers keep their native controls until the modules mount.
	if (!window.fetch || !window.crypto?.getRandomValues) return;
	update();
	// Keep pre-handoff edits out of the keeper's initial saved baseline. Values
	// stay in their native fields; the ordinary keeper owns the eventual save.
	const rememberInput = (event) => {
		if (!pending.has("editor")) return;
		const form = event.target.closest?.("[data-editorial-form]");
		if (form) form.dataset.pendingInput = "true";
	};
	for (const type of ["input", "change"])
		document.addEventListener(type, rememberInput, true);
	document.addEventListener("admin-enhanced", (event) => finish(event.detail));
	// Module load fires after evaluation, including an uncaught startup exception.
	// A failed dependency instead reports error on its entry script.
	for (const type of ["load", "error"])
		document.addEventListener(
			type,
			(event) => {
				const scope = event.target.dataset?.adminEnhancement;
				if (scope) finish(scope);
			},
			true,
		);
	window.addEventListener(
		"load",
		() => {
			pending.clear();
			update();
		},
		{ once: true },
	);
})();
