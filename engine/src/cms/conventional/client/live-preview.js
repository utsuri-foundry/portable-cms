import { mountLiveVimeo } from "./live-vimeo.js";
import { messages } from "./messages.js";
import { rowText } from "./rich-text-preview.js";

/** Typing pause before the page is drawn again. */
const PAUSE = 400;
/** A loaded page that never reports its position is shown anyway after this. */
const SWAP_LIMIT = 1500;
/** Tokens live ten minutes; an older page is drawn again when the editor returns. */
const REFRESH_AFTER = 8 * 60 * 1000;
const WIDTH_KEY = "portable-cms.live-preview.form-width";
const OPEN_KEY = "portable-cms.live-preview.open";

/** The page is drawn at the screen's real width and scaled down to the stage; it scrolls inside. */
export function fit(stageWidth, stageHeight, screen) {
	const scale = Math.min(1, Math.max(0.1, stageWidth / screen));
	return { scale, width: screen, height: Math.ceil(stageHeight / scale) };
}

/** Form width as a share of the window, kept between the separator's bounds. */
export function clampShare(value) {
	return Math.min(60, Math.max(20, Math.round(Number(value) || 30)));
}

/** A response is shown only if it answers the newest request. */
export function current(latest, response) {
	return Number.isInteger(response?.sequence) && response.sequence === latest;
}

/** Controls a field can be focused through, in the order they appear. */
export const FOCUSABLE =
	"input:not([type=hidden]), select, textarea, button, [contenteditable=true], [tabindex]:not([tabindex='-1'])";

/** The writable text control a short text field can be edited through in place, in a language. */
export function inPlaceControl(element, locale) {
	if (element?.dataset.fieldMatch !== "text") return null;
	if (element.dataset.fieldLocale && element.dataset.fieldLocale !== locale)
		return null;
	const scope = element.querySelector(`[data-locale-panel="${locale}"]`) ?? element;
	const control =
		scope.querySelector("input[type=text]") ??
		(element.hasAttribute("data-field-item")
			? scope.querySelector("textarea[data-group-line]")
			: null);
	return control && !control.disabled && !control.readOnly ? control : null;
}

/** A value typed in the page, or null: one line of text within the control's limit. */
export function editedValue(value, control) {
	if (typeof value !== "string" || /\p{Cc}/u.test(value)) return null;
	return control.maxLength > 0 && value.length > control.maxLength ? null : value;
}

/**
 * What the page needs to link each form field: its label, for fields the profile may not mark
 * the short text shown in the page's language or the images it selects, and whether the text
 * can be edited in place, within which length.
 */
export function linkedFields(form, locale, previewEditors = null) {
	return (
		[...form.querySelectorAll("[data-field]")]
			.filter(
				(element) =>
					!element.dataset.fieldLocale || element.dataset.fieldLocale === locale,
			)
			.slice(0, 500)
			.flatMap((element) => {
				const id = element.dataset.field;
				const scope =
					element.querySelector(`[data-locale-panel="${locale}"]`) ?? element;
				const match = element.dataset.fieldMatch;
				const control = inPlaceControl(element, locale);
				const adapter = previewEditors?.find(element, locale);
				const snapshot = adapter?.read();
				return [
					{
						id,
						label: element.dataset.fieldLabel || id,
						text:
							match === "text"
								? (control?.value ??
									scope.querySelector("input[type=text]")?.value ??
									"")
								: "",
						...(element.hasAttribute("data-field-item") ? { explicit: true } : {}),
						media:
							match === "media"
								? [
										...element.querySelectorAll(
											`input[type=hidden][name="${element.dataset.fieldControl || id}[]"]`,
										),
									].map((input) => input.value)
								: [],
						editable: control !== null,
						maximum: control?.maxLength > 0 ? control.maxLength : 0,
					},
					...(snapshot ?? []).slice(0, 5000).map((row, position) => ({
						id: `${id}:${position}`,
						label: element.dataset.fieldLabel || id,
						text: rowText(row),
						explicit: true,
						rich: true,
						editable: true,
						maximum: 100_000,
						media: [],
						snapshot,
					})),
				];
			})
			// Keep the existing 500 field budget plus a maximum-sized rich document's rows.
			.slice(0, 5500)
	);
}

/** Browser storage can be missing or throw; the workspace works without it. */
const attempt = (action) => {
	try {
		return action();
	} catch {
		return null;
	}
};

/**
 * The live preview workspace. PHP validates, renders and isolates the page; this island only
 * arranges the workspace, posts unsaved values after a pause, swaps two frames and links
 * form fields with page elements through the page bridge.
 */
export function mountLivePreview(
	root,
	form,
	previewEditors = null,
	beforePreview = null,
) {
	const richPreview = (field, language) =>
		previewEditors?.find(field, language) ?? null;
	const t = messages(form);
	const document = root.ownerDocument;
	const window = document.defaultView;
	const shell = document.querySelector("[data-admin-shell]");
	const toggle = form.querySelector("[data-live-toggle]");
	const fallback = form.querySelector("[data-live-fallback]");
	const separator = document.querySelector("[data-live-separator]");
	const frames = [...root.querySelectorAll("[data-live-frame]")];
	const status = root.querySelector("[data-live-status]");
	const update = root.querySelector("[data-live-update]");
	const open = root.querySelector("[data-live-open]");
	const page = root.querySelector("[data-live-page]");
	const stage = root.querySelector("[data-live-stage]");
	const box = root.querySelector("[data-live-box]");
	const screens = [...root.querySelectorAll("[data-live-screen]")];
	const modes = [...root.querySelectorAll("[data-live-mode]")];
	const saveBar = form.querySelector(".admin-save-bar");
	const switcher = document.querySelector("[data-record-locale-switcher]");
	if (!shell || !toggle || frames.length !== 2 || !status || !stage || !box || !page)
		return () => {};
	const wide = window.matchMedia("(min-width: 68.75rem)");
	const reduced = window.matchMedia("(prefers-reduced-motion: reduce)");
	const session = attempt(() => window.sessionStorage);
	const local = attempt(() => window.localStorage);
	const recordKey = `${OPEN_KEY}:${root.dataset.endpoint}`;
	const placeholder = document.createComment("record language switch");
	const removers = [];
	const listen = (target, type, handler, options) => {
		target.addEventListener(type, handler, options);
		removers.push(() => target.removeEventListener(type, handler, options));
	};
	let opened = false;
	let disposed = false;
	let sequence = 0;
	let visible = 0;
	let pending = null;
	let controller = null;
	let pause;
	let swapLimit;
	let screen = 1440;
	let manual = false;
	let renderedAt = 0;
	let token = "";
	let scroll = { x: 0, y: 0 };
	/** The field last shown in the page, so moving within one field does not scroll it again. */
	let focused = "";
	/** Field/value snapshot announced to the shown frame, including its content language. */
	let shown = { locale: "", fields: [] };
	/** How much the page is scaled down, so its field outline can keep its size on screen. */
	let scale = 1;
	/** The field being edited in the page, its control and the status shown before. */
	let editing = null;
	/** Values changed while a field was edited in the page; the page is drawn again after. */
	let stale = false;
	let share = clampShare(attempt(() => local?.getItem(WIDTH_KEY)) ?? 30);

	const say = (text) => {
		status.textContent = text;
	};
	const locale = () => form.dataset.recordLocale || root.dataset.defaultLocale;
	const vimeo = mountLiveVimeo(root, () => frames[visible]);
	const layout = () => {
		vimeo.clear();
		const width = stage.clientWidth;
		const height = stage.clientHeight;
		if (!width || !height) return;
		const size = fit(width, height, screen);
		for (const frame of frames) {
			frame.style.width = `${size.width}px`;
			frame.style.height = `${size.height}px`;
			frame.style.transform = `scale(${size.scale})`;
		}
		box.style.width = `${Math.floor(size.width * size.scale)}px`;
		if (size.scale === scale) return;
		scale = size.scale;
		frames[visible].contentWindow?.postMessage(
			{ type: "cms-live", command: "scale", scale },
			"*",
		);
	};
	const applyShare = (value, remember = false) => {
		share = clampShare(value);
		shell.style.setProperty("--admin-live-form", `${share}vw`);
		separator?.setAttribute("aria-valuenow", String(share));
		separator?.setAttribute("aria-valuetext", `${share}%`);
		if (remember) attempt(() => local?.setItem(WIDTH_KEY, String(share)));
		layout();
	};
	const mode = (value) => {
		shell.dataset.liveView = value;
		for (const button of modes)
			button.setAttribute("aria-pressed", String(button.dataset.liveMode === value));
		window.requestAnimationFrame(layout);
	};
	const swap = () => {
		if (!pending) return;
		window.clearTimeout(swapLimit);
		const { index, result, fields, language } = pending;
		pending = null;
		// A new page would take the text out from under the editor's cursor; it is drawn after.
		if (editing) {
			frames[index].src = "about:blank";
			root.removeAttribute("aria-busy");
			stale = true;
			return;
		}
		const previous = frames[visible];
		frames[index].toggleAttribute("data-live-active", true);
		frames[index].hidden = false;
		previous.toggleAttribute("data-live-active", false);
		// The replaced page stops at once: its media and scripts must not keep running unseen.
		previous.src = "about:blank";
		visible = index;
		shown = { locale: language, fields };
		renderedAt = Date.now();
		root.removeAttribute("aria-busy");
		if (open) {
			open.href = result.url;
			open.removeAttribute("aria-disabled");
		}
		for (const option of page.options)
			option.disabled = !(result.pages ?? []).includes(option.value);
		manual = manual || result.slow === true;
		if (update) update.hidden = !manual;
		// Drafts are drawn only when nothing published can show the page; the editor is told so.
		const drafts = result.drafts === true ? t("live.drafts") : "";
		say(
			manual
				? t("live.slow")
				: result.page !== page.value
					? [t("live.home_instead"), drafts].join(" ").trim()
					: drafts || t("live.ready"),
		);
	};
	const fail = (reason) => {
		root.removeAttribute("aria-busy");
		say(reason ? t("live.failed", { reason }) : t("live.unavailable"));
		if (update) update.hidden = false;
	};
	let keeping = false;
	const request = async () => {
		if (!opened || disposed || keeping) return;
		window.clearTimeout(pause);
		if (editing) {
			stale = true;
			return;
		}
		const id = ++sequence;
		controller?.abort();
		controller = new window.AbortController();
		if (beforePreview) {
			keeping = true;
			root.setAttribute("aria-busy", "true");
			say(t("live.updating"));
			let kept;
			try {
				kept = await beforePreview();
			} finally {
				keeping = false;
			}
			if (!kept) {
				if (!disposed) fail();
				return;
			}
		}
		vimeo.clear();
		if (!opened || disposed) return;
		const body = new window.URLSearchParams();
		const language = locale();
		const fields = linkedFields(form, language, previewEditors);
		for (const [name, value] of new window.FormData(form))
			if (typeof value === "string") body.append(name, value);
		body.set("_live_page", page.value);
		body.set("_live_locale", language);
		body.set("_live_sequence", String(id));
		// The same workspace token keeps asset addresses stable, so the browser reuses them.
		if (token) body.set("_live_token", token);
		root.setAttribute("aria-busy", "true");
		say(t("live.updating"));
		window
			.fetch(root.dataset.endpoint, {
				method: "POST",
				body,
				headers: { Accept: "application/json" },
				credentials: "same-origin",
				signal: controller.signal,
			})
			.then(async (response) => {
				const result = await response.json().catch(() => null);
				if (disposed || id !== sequence) return;
				if (
					!response.ok ||
					!current(sequence, result) ||
					typeof result.url !== "string"
				)
					return fail(typeof result?.reason === "string" ? result.reason : "");
				token =
					new window.URL(result.url, window.location.href).pathname.split("/")[2] ?? "";
				const index = 1 - visible;
				pending = { index, id, result, fields, language };
				frames[index].src = result.url;
			})
			.catch((error) => {
				if (!disposed && id === sequence && error?.name !== "AbortError") fail("");
			});
	};
	const later = () => {
		if (!opened || manual) return;
		window.clearTimeout(pause);
		// Remember synchronously: finishing before the debounce fires must not leave a
		// replacement page scheduled under the editor's next click.
		if (editing) {
			stale = true;
			return;
		}
		pause = window.setTimeout(request, PAUSE);
	};
	const message = (event) => {
		const index = frames.findIndex((frame) => frame.contentWindow === event.source);
		const data = event.data;
		if (index < 0 || event.origin !== "null" || data?.type !== "cms-live") return;
		if (data.event === "scroll" && index === visible && !pending)
			scroll = { x: Number(data.x) || 0, y: Number(data.y) || 0 };
		if (
			index === visible &&
			typeof data.field === "string" &&
			shown.locale === locale() &&
			shown.fields.some((field) => field.id === data.field)
		) {
			if (data.event === "select") reveal(data.field);
			if (data.event === "edit-start") startEdit(data.field);
			if (data.event === "edit") edit(data.field, data.value);
			if (data.event === "edit-end") endEdit(data.field);
			if (
				data.event === "edit-cancel" &&
				editing?.id === data.field &&
				editing.adapter
			) {
				if (!editing.adapter.restore(editing.expected, editing.original))
					rejectEdit(data.field);
				else rememberRich(data.field, editing.adapter);
			}
			if (data.event === "form-edit") {
				if (editing) editing.status = t("live.rich_form");
				reveal(data.field);
				say(t("live.rich_form"));
			}
		}
		if (pending && index === pending.index) {
			if (data.event === "ready") {
				event.source.postMessage(
					{
						type: "cms-live",
						command: "fields",
						fields: pending.fields.map(({ snapshot, ...field }) => field),
						scale,
					},
					"*",
				);
				event.source.postMessage(
					{ type: "cms-live", command: "scroll", ...scroll },
					"*",
				);
			}
			if (data.event === "positioned") swap();
		}
	};
	const fieldElement = (id) =>
		[...form.querySelectorAll("[data-field]")].find(
			(element) =>
				element.dataset.field === id.split(":")[0] &&
				(!element.dataset.fieldLocale || element.dataset.fieldLocale === locale()),
		);
	/**
	 * Opens the field's section and shows it in the form; unless the page keeps the focus to
	 * edit the field in place, its control in the page's language receives focus.
	 */
	const reveal = (id, focus = true) => {
		const element = fieldElement(id);
		if (!element) return;
		if (focus && shell.dataset.liveView === "preview") mode("edit");
		for (
			let node = element.parentElement;
			node && node !== form;
			node = node.parentElement
		)
			if (node.localName === "details") node.open = true;
		if (element.localName === "details") element.open = true;
		const scope = element.querySelector(`[data-locale-panel="${locale()}"]`) ?? element;
		let control =
			scope.querySelector(".admin-rich-surface") ??
			[...scope.querySelectorAll(FOCUSABLE)].find(
				(candidate) => candidate.getClientRects().length > 0,
			);
		if (!control) {
			if (!element.hasAttribute("tabindex")) element.setAttribute("tabindex", "-1");
			control = element;
		}
		// The page already shows this element; focusing the field must not send it back there.
		focused = id;
		if (focus) control.focus({ preventScroll: true });
		control.scrollIntoView({
			block: "center",
			behavior: reduced.matches ? "auto" : "smooth",
		});
	};
	/** The page edits a short text in place: the form follows, and the page is not replaced. */
	const startEdit = (id) => {
		const element = fieldElement(id);
		const announced = shown.fields.find((field) => field.id === id);
		if (announced?.rich) {
			const adapter = richPreview(element, locale());
			const expected = JSON.stringify(announced.snapshot);
			if (!adapter || JSON.stringify(adapter.read()) !== expected)
				return rejectEdit(id);
			editing = {
				id,
				adapter,
				expected,
				original: announced.snapshot,
				locale: locale(),
				status: status.textContent,
			};
			reveal(id, false);
			say(t("live.rich_editing", { field: announced.label }));
			return;
		}
		const control = element ? inPlaceControl(element, locale()) : null;
		if (!control || !announced?.editable || announced.text !== control.value) return;
		editing = {
			id,
			control,
			locale: locale(),
			status: editing?.status ?? status.textContent,
		};
		reveal(id, false);
		say(t("live.editing", { field: element.dataset.fieldLabel || id }));
	};
	const edit = (id, value) => {
		if (editing?.id !== id) return;
		if (editing.adapter) {
			const adapter = richPreview(fieldElement(id), locale());
			if (
				editing.locale !== locale() ||
				adapter !== editing.adapter ||
				!adapter.replace(editing.expected, Number(id.split(":")[1]), value)
			)
				return rejectEdit(id);
			editing.expected = JSON.stringify(adapter.read());
			rememberRich(id, adapter);
			return;
		}
		// Removed/replaced items and language changes invalidate the old frame's editing intent.
		if (
			editing.locale !== locale() ||
			inPlaceControl(fieldElement(id), locale()) !== editing.control
		)
			return;
		const accepted = editedValue(value, editing.control);
		if (accepted === null || accepted === editing.control.value) return;
		const announced = shown.fields.find((field) => field.id === id);
		if (announced) announced.text = accepted;
		editing.control.value = accepted;
		editing.control.dispatchEvent(new window.Event("input", { bubbles: true }));
	};
	const rememberRich = (id, adapter) => {
		const rows = adapter.read();
		for (const field of shown.fields.filter((field) =>
			field.id.startsWith(`${id.split(":")[0]}:`),
		)) {
			field.snapshot = rows;
			field.text = rowText(rows[Number(field.id.split(":")[1])]);
		}
	};
	const rejectEdit = (id) => {
		editing = null;
		stale = false;
		frames[visible].contentWindow?.postMessage(
			{ type: "cms-live", command: "reject-edit", field: id },
			"*",
		);
		reveal(id);
		void request();
		say(t("live.rich_stale"));
	};
	const endEdit = (id) => {
		if (editing?.id !== id) return;
		const before = editing.status;
		editing = null;
		if (!stale) return say(before);
		stale = false;
		void request();
	};
	/** Focusing a field shows its element in the page, once per field. */
	const focusField = (event) => {
		const id = event.target.closest?.("[data-field]")?.dataset.field;
		if (!opened || !id || id === focused) return;
		focused = id;
		if (shell.dataset.liveView === "edit") return;
		frames[visible].contentWindow?.postMessage(
			{ type: "cms-live", command: "highlight", field: id },
			"*",
		);
	};
	const loaded = (index) => {
		if (!pending || index !== pending.index) return;
		window.clearTimeout(swapLimit);
		swapLimit = window.setTimeout(swap, SWAP_LIMIT);
	};
	const moveSwitcher = (inside) => {
		if (!switcher || !saveBar) return;
		if (inside) {
			switcher.before(placeholder);
			saveBar.prepend(switcher);
		} else if (placeholder.parentNode) placeholder.replaceWith(switcher);
	};
	const show = () => {
		if (opened) return;
		opened = true;
		shell.classList.add("admin-shell--live");
		root.hidden = false;
		if (separator) separator.hidden = false;
		toggle.setAttribute("aria-pressed", "true");
		moveSwitcher(true);
		mode(wide.matches ? "split" : "preview");
		applyShare(share);
		attempt(() => session?.setItem(recordKey, "1"));
		void request();
	};
	const hide = () => {
		vimeo.clear();
		if (!opened) return;
		opened = false;
		window.clearTimeout(pause);
		window.clearTimeout(swapLimit);
		controller?.abort();
		pending = null;
		editing = null;
		stale = false;
		shell.classList.remove("admin-shell--live");
		delete shell.dataset.liveView;
		shell.style.removeProperty("--admin-live-form");
		root.hidden = true;
		root.removeAttribute("aria-busy");
		if (separator) separator.hidden = true;
		toggle.setAttribute("aria-pressed", "false");
		moveSwitcher(false);
		for (const frame of frames) frame.src = "about:blank";
		attempt(() => session?.removeItem(recordKey));
	};

	toggle.hidden = false;
	if (fallback) fallback.hidden = true;
	frames[0].toggleAttribute("data-live-active", true);
	frames[1].hidden = false;
	listen(toggle, "click", () => (opened ? hide() : show()));
	const closer = root.querySelector("[data-live-close]");
	if (closer)
		listen(closer, "click", () => {
			hide();
			toggle.focus();
		});
	for (const button of screens)
		listen(button, "click", () => {
			screen = Number(button.dataset.liveScreen) || 1440;
			for (const other of screens)
				other.setAttribute("aria-pressed", String(other === button));
			layout();
		});
	for (const button of modes)
		listen(button, "click", () => mode(button.dataset.liveMode));
	listen(page, "change", request);
	if (update)
		listen(update, "click", () => {
			update.hidden = manual;
			void request();
		});
	listen(form, "record-saved", () => {
		if (opened) void request();
	});
	listen(form, "input", later);
	listen(form, "change", later);
	listen(form, "focusin", focusField);
	listen(form, "focusout", (event) => {
		if (!form.contains(event.relatedTarget)) focused = "";
	});
	listen(window, "message", message);
	for (const [index, frame] of frames.entries())
		listen(frame, "load", () => loaded(index));
	listen(wide, "change", () => {
		if (opened) mode(wide.matches ? "split" : "preview");
	});
	listen(document, "visibilitychange", () => {
		if (
			opened &&
			document.visibilityState === "visible" &&
			Date.now() - renderedAt > REFRESH_AFTER
		)
			void request();
	});
	const locales = new window.MutationObserver(() => {
		// A language switch supersedes in-page typing, even if its final blur message is late.
		editing = null;
		stale = false;
		void request();
	});
	locales.observe(form, { attributes: true, attributeFilter: ["data-record-locale"] });
	const resize = new window.ResizeObserver(layout);
	resize.observe(stage);
	if (separator) {
		let dragging = false;
		const position = (event) => (event.clientX / window.innerWidth) * 100;
		listen(separator, "pointerdown", (event) => {
			dragging = true;
			separator.setPointerCapture?.(event.pointerId);
		});
		listen(separator, "pointermove", (event) => {
			if (dragging) applyShare(position(event));
		});
		listen(separator, "pointerup", (event) => {
			if (!dragging) return;
			dragging = false;
			applyShare(position(event), true);
		});
		listen(separator, "keydown", (event) => {
			const next = {
				ArrowLeft: share - 2,
				ArrowRight: share + 2,
				Home: 20,
				End: 60,
			}[event.key];
			if (next === undefined) return;
			event.preventDefault();
			applyShare(next, true);
		});
	}
	// Server round trips (adding a reference, a layout action) reload the page; the workspace stays open.
	if (attempt(() => session?.getItem(recordKey)) === "1") show();

	return () => {
		if (disposed) return;
		hide();
		disposed = true;
		vimeo.destroy();
		for (const remove of removers) remove();
		locales.disconnect();
		resize.disconnect();
		toggle.hidden = true;
		if (fallback) fallback.hidden = false;
		for (const frame of frames) frame.removeAttribute("style");
		frames[0].toggleAttribute("data-live-active", false);
		frames[1].toggleAttribute("data-live-active", false);
		frames[1].hidden = true;
		box.removeAttribute("style");
	};
}
