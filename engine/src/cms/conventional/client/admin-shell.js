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

/** Opens or folds one explanation; the text stays in the page for assistive technology either way. */
export function toggleHelp(
	toggle,
	text,
	open = toggle.getAttribute("aria-expanded") !== "true",
) {
	toggle.setAttribute("aria-expanded", String(open));
	if (open) text.setAttribute("data-open", "");
	else text.removeAttribute("data-open");
	if (text.matches?.("[popover]") && open !== text.matches(":popover-open")) {
		if (open) text.showPopover();
		else text.hidePopover();
	}
	return open;
}

/**
 * Puts an open explanation just under its control, within the page's side gutters and
 * scrolling with the page. An explanation in the top layer is positioned against the
 * document, so neither the white body's overflow nor a card clips it.
 */
export function placeHelp(toggle, text, view = toggle.ownerDocument.defaultView) {
	const gutter = 16;
	const rem =
		Number.parseFloat(view.getComputedStyle(view.document.documentElement).fontSize) ||
		16;
	const width = Math.min(26 * rem, view.innerWidth - 2 * gutter);
	const anchor = toggle.getBoundingClientRect();
	const left = Math.min(
		Math.max(anchor.left - rem, gutter),
		view.innerWidth - gutter - width,
	);
	text.style.width = `${width}px`;
	text.style.left = `${left + view.scrollX}px`;
	text.style.top = `${anchor.bottom + rem / 2 + view.scrollY}px`;
	text.style.setProperty(
		"--admin-help-arrow",
		`${anchor.left + anchor.width / 2 - left}px`,
	);
}

/**
 * Works every help control in the document. Where the browser has popovers, the text
 * opens over the page under its control, without moving anything: a click toggles it,
 * a pointer that rests on the control shows it until it leaves, Escape, a click
 * elsewhere or another control folds it. Elsewhere a click or Enter opens the text
 * inline and Escape folds it.
 */
export function mountHelpControls(
	root,
	view = root.defaultView ?? root.ownerDocument?.defaultView ?? null,
) {
	const controller = new AbortController();
	const { signal } = controller;
	const control = (target) => {
		const toggle = target.closest?.("[data-help-toggle]");
		const text = toggle && root.getElementById(toggle.getAttribute("aria-controls"));
		return text ? [toggle, text] : null;
	};
	const floating = (text) => text.matches?.("[popover]") === true;
	const click = (event) => {
		const found = control(event.target);
		if (!found) return;
		const [, text] = found;
		if (!floating(text)) toggleHelp(...found);
		else if ("hover" in text.dataset) {
			// The pointer opened it; the click keeps it open rather than closing it.
			event.preventDefault();
			delete text.dataset.hover;
		}
	};
	const key = (event) => {
		if (event.key !== "Escape") return;
		const found = control(event.target);
		if (
			found &&
			!floating(found[1]) &&
			found[0].getAttribute("aria-expanded") === "true"
		)
			toggleHelp(...found, false);
	};
	root.addEventListener("click", click, { signal });
	root.addEventListener("keydown", key, { signal });
	if (typeof view?.HTMLElement?.prototype?.showPopover === "function") {
		const hover = view.matchMedia?.("(hover: hover)").matches === true;
		for (const toggle of root.querySelectorAll("[data-help-toggle]")) {
			const text = root.getElementById(toggle.getAttribute("aria-controls"));
			if (!text) continue;
			text.setAttribute("popover", "auto");
			toggle.popoverTargetElement = text;
			text.addEventListener(
				"beforetoggle",
				(event) => {
					if (event.newState === "open") placeHelp(toggle, text, view);
				},
				{ signal },
			);
			text.addEventListener(
				"toggle",
				(event) => {
					const open = event.newState === "open";
					toggle.setAttribute("aria-expanded", String(open));
					if (open) text.setAttribute("data-open", "");
					else text.removeAttribute("data-open");
					if (!open) delete text.dataset.hover;
				},
				{ signal },
			);
			if (hover) mountHelpHover(toggle, text, view, signal);
		}
	}
	return () => controller.abort();
}

/** A pointer resting on the control shows its explanation; leaving both folds it again unless a click kept it. */
function mountHelpHover(toggle, text, view, signal) {
	let timer = 0;
	const later = (action, delay) => {
		view.clearTimeout(timer);
		timer = view.setTimeout(action, delay);
	};
	const show = () =>
		later(() => {
			if (text.matches(":popover-open")) return;
			text.dataset.hover = "";
			text.showPopover();
		}, 250);
	const hide = () =>
		later(() => {
			if ("hover" in text.dataset && text.matches(":popover-open")) text.hidePopover();
		}, 200);
	const stay = () => view.clearTimeout(timer);
	toggle.addEventListener("pointerenter", show, { signal });
	toggle.addEventListener("pointerleave", hide, { signal });
	text.addEventListener("pointerenter", stay, { signal });
	text.addEventListener("pointerleave", hide, { signal });
	signal.addEventListener("abort", stay);
}

/** Keeps the account panel visible when it opens at the bottom of a scrolling rail. */
/** A Copy button puts its own value on the clipboard and says so for a moment; the text stays selectable without it. */
export function mountCopyButtons(
	root,
	navigator = root.ownerDocument?.defaultView?.navigator ?? globalThis.navigator,
	timers = globalThis,
) {
	const buttons = [...root.querySelectorAll("[data-copy]")];
	const handlers = buttons.map((button) => {
		const label = button.textContent;
		const copy = async () => {
			try {
				await navigator.clipboard.writeText(button.dataset.copy ?? "");
			} catch {
				return;
			}
			button.textContent = button.dataset.copied ?? label;
			timers.setTimeout(() => {
				button.textContent = label;
			}, 2000);
		};
		button.addEventListener("click", copy);
		return () => button.removeEventListener("click", copy);
	});
	return () => {
		for (const remove of handlers) remove();
	};
}

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
	mountHelpControls(document);
	mountCopyButtons(document);
	document.dispatchEvent(new CustomEvent("admin-enhanced", { detail: "shell" }));
}
