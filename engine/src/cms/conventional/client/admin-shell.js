import { mountFocalPoint } from "./focal-point.js";
import { mountVideoPreviews } from "./video-previews.js";

const THEMES = new Set(["system", "light", "dark"]);

/** Applies a theme to the document root; "system" defers to the operating system. */
export function applyTheme(root, theme) {
	if (!THEMES.has(theme)) return false;
	if (theme === "system") root.removeAttribute("data-theme");
	else root.setAttribute("data-theme", theme);
	return true;
}

/**
 * Enhances the native appearance form. The server form stays authoritative: it
 * validates the value, origin and CSRF token, and persists the browser cookie.
 */
export function mountThemeForm(form, window = form.ownerDocument.defaultView) {
	const root = form.ownerDocument.documentElement;
	const apply = form.querySelector("[data-theme-apply]");
	const radios = [...form.querySelectorAll('input[name="theme"]')];
	const initial = radios.find((radio) => radio.checked)?.value ?? "system";
	let saved = initial;
	let pending = 0;
	if (apply) apply.hidden = true;
	const change = async (event) => {
		const theme = event.target.value;
		if (!applyTheme(root, theme)) return;
		const request = ++pending;
		try {
			const response = await window.fetch(form.action, {
				method: "POST",
				body: new window.FormData(form),
				headers: { Accept: "application/json" },
				credentials: "same-origin",
			});
			if (!response.ok) throw new Error("Theme was not saved");
			if (request === pending) saved = theme;
		} catch {
			// A later choice already superseded this request; otherwise restore the saved state.
			if (request !== pending) return;
			applyTheme(root, saved);
			for (const radio of radios) radio.checked = radio.value === saved;
			if (apply) apply.hidden = false;
		}
	};
	for (const radio of radios) radio.addEventListener("change", change);
	return () => {
		for (const radio of radios) radio.removeEventListener("change", change);
		if (apply) apply.hidden = false;
	};
}

/** Keeps the account panel visible when it opens at the bottom of a scrolling rail. */
export function mountAccountMenu(details) {
	const reveal = () => {
		if (details.open) details.lastElementChild?.scrollIntoView?.({ block: "nearest" });
	};
	details.addEventListener("toggle", reveal);
	return () => details.removeEventListener("toggle", reveal);
}

if (typeof document !== "undefined") {
	mountVideoPreviews(document);
	for (const fieldset of document.querySelectorAll("[data-focal-point]"))
		mountFocalPoint(fieldset);
	for (const form of document.querySelectorAll("[data-theme-form]"))
		mountThemeForm(form);
	for (const details of document.querySelectorAll(".admin-account"))
		mountAccountMenu(details);
	document.dispatchEvent(new CustomEvent("admin-enhanced", { detail: "shell" }));
}
