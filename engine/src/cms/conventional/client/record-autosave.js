import { messages } from "./messages.js";
import { createRecordKeeper } from "./record-keeper.js";

/** Native values and presence markers; picker searches and unfinished uploads are transient. */
export function recordValues(entries, SearchParams = URLSearchParams) {
	const body = new SearchParams();
	for (const [name, value] of entries)
		if (
			typeof value === "string" &&
			(!name.startsWith("_") ||
				name.startsWith("_present_") ||
				name === "_editorial_end")
		)
			body.append(name, value);
	return body.toString();
}

/** Keeps native record values only; no DOM replacement, local content store, or navigation runtime. */
export function mountRecordAutosave(form, window = form.ownerDocument.defaultView) {
	if (
		form.dataset.autosave !== "true" ||
		!window.fetch ||
		!window.crypto?.getRandomValues
	)
		return null;
	const document = form.ownerDocument;
	if (document.querySelector("[data-conflict-summary]")) return null;
	const t = messages(form);
	const status = form.querySelector("[data-save-status]");
	const exactForm = document.getElementById("record-exact-preview-form");
	const exactStatus = form.querySelector("[data-exact-status]");
	const initialStatus =
		status?.querySelector?.('[data-script-fallback="editor"]')?.textContent ??
		status?.textContent;
	const announce = form.querySelector("[data-keep-announcement]");
	const retry = form.querySelector("[data-keep-retry]");
	if (retry) retry.textContent = t("keep.retry");
	const save = form.querySelector("[data-record-save]");
	const identify = () =>
		[...window.crypto.getRandomValues(new Uint8Array(16))]
			.map((value) => value.toString(16).padStart(2, "0"))
			.join("");
	const sessionKey = (value) =>
		`portable.record.edit:${new window.URL(value, window.location.href).pathname}`;
	let session = identify();
	try {
		const stored = window.sessionStorage.getItem(sessionKey(form.action));
		if (/^[a-f0-9]{32}$/.test(stored || "")) session = stored;
		window.sessionStorage.setItem(sessionKey(form.action), session);
	} catch {
		/* Storage denial only starts a fresh editing session. No content is stored. */
	}
	const newId = identify();
	if (save) save.hidden = true;
	status?.removeAttribute("role");
	form.noValidate = true;
	let sequence = form.elements.namedItem("_edit_sequence")?.value || "0";
	let path = form.action;
	let needsContext = false;
	let failed = false;
	let created = false;
	let navigating = false;
	let allowSubmit = false;
	let allowUpload = false;
	let openingExact = false;
	let savedAt = form.dataset.savedAt
		? new window.Date(form.dataset.savedAt).toLocaleTimeString(
				document.documentElement.lang,
				{ hour: "2-digit", minute: "2-digit" },
			)
		: null;
	if (status)
		status.textContent =
			savedAt && form.dataset.pendingInput !== "true"
				? t("keep.saved", { time: savedAt })
				: initialStatus;
	const listeners = [];
	const listen = (target, event, handler, capture = false) => {
		target.addEventListener(event, handler, capture);
		listeners.push(() => target.removeEventListener(event, handler, capture));
	};
	const read = () => recordValues(new window.FormData(form), window.URLSearchParams);
	const csrf = () => form.elements.namedItem("_csrf").value;
	const context = async () => {
		const response = await window.fetch(`${path}?_keep_context=1`, {
			credentials: "same-origin",
			redirect: "follow",
			headers: { Accept: "application/json" },
		});
		if (
			!response.ok ||
			!response.headers.get("content-type")?.includes("application/json")
		)
			throw Error(t("keep.sign_in"));
		const result = await response.json();
		for (const input of document.querySelectorAll('[name="_csrf"]'))
			input.value = result.csrf;
		for (const panel of document.querySelectorAll("[data-media-upload]"))
			panel.dataset.csrfToken = result.csrf;
		needsContext = false;
	};
	const fieldErrors = (errors) => {
		for (const node of form.querySelectorAll("[data-keep-error]")) node.remove();
		for (const control of form.querySelectorAll("[data-keep-invalid]")) {
			control.removeAttribute("aria-invalid");
			control.removeAttribute("data-keep-invalid");
			const description = (control.getAttribute("aria-describedby") || "")
				.split(" ")
				.filter((id) => !id.startsWith("keep-error-"));
			control.setAttribute("aria-describedby", description.join(" "));
		}
		for (const [id, message] of Object.entries(errors)) {
			const field = document.getElementById(`field-${id}`);
			if (!field || !form.contains(field)) continue;
			const note = document.createElement("p");
			note.dataset.keepError = "true";
			note.className = "admin-field-error";
			note.textContent = message;
			note.id = `keep-error-${id}`;
			const control = field.matches("input,textarea,select")
				? field
				: field.querySelector("input:not([type=hidden]),textarea,select");
			control?.setAttribute("aria-invalid", "true");
			control?.setAttribute("data-keep-invalid", "true");
			control?.setAttribute(
				"aria-describedby",
				`${control.getAttribute("aria-describedby") || ""} ${note.id}`.trim(),
			);
			(field.closest(".admin-field,fieldset") || field.parentElement).append(note);
			const details = field.closest("details");
			if (details) details.open = true;
		}
	};
	const keeper = createRecordKeeper({
		read,
		identify,
		initiallyDirty: form.dataset.pendingInput === "true",
		schedule: window.setTimeout.bind(window),
		cancel: window.clearTimeout.bind(window),
		write: async (values, id, keepalive) => {
			try {
				if (needsContext) await context();
				const body = new window.URLSearchParams(values);
				body.set("_csrf", csrf());
				body.set("_revision", form.elements.namedItem("_revision")?.value || "1");
				body.set("_edit_sequence", sequence);
				body.set("_editing_session", session);
				body.set("_editing_request", id);
				body.set("_new_record_id", newId);
				body.set("_keep", "1");
				const response = await window.fetch(path, {
					method: "POST",
					body,
					credentials: "same-origin",
					redirect: "follow",
					keepalive,
					headers: { Accept: "application/json" },
				});
				if (
					[401, 403].includes(response.status) ||
					(response.redirected && new window.URL(response.url).pathname === "/login")
				) {
					needsContext = true;
					throw Error(t("keep.sign_in"));
				}
				if (!response.headers.get("content-type")?.includes("application/json"))
					throw Error(t("keep.network"));
				const result = await response.json();
				if (!response.ok && !result.conflict) throw Error(t("keep.network"));
				return result;
			} catch (error) {
				needsContext = true;
				throw Error(
					error.message === t("keep.sign_in") ? error.message : t("keep.network"),
				);
			}
		},
		onSaved: (result) => {
			sequence = String(result.sequence);
			created ||= !form.elements.namedItem("_revision");
			path = result.path;
			try {
				window.sessionStorage.setItem(sessionKey(path), session);
			} catch {
				/* Optional opaque session key only. */
			}
			form.action = result.path;
			if (exactForm) exactForm.action = `${result.path}/exact-preview`;
			if (window.location.pathname !== result.path)
				window.history.replaceState(null, "", result.path);
			for (const input of document.querySelectorAll(
				'[name="_revision"], [name="_editor_base_revision"]',
			))
				input.value = String(result.revision);
			for (const input of document.querySelectorAll('[name="_edit_sequence"]'))
				input.value = sequence;
			for (const [name, value] of [
				["_revision", String(result.revision)],
				["_edit_sequence", sequence],
			]) {
				if (form.elements.namedItem(name)) continue;
				const input = document.createElement("input");
				input.type = "hidden";
				input.name = name;
				input.value = value;
				form.append(input);
			}
			const publish = document.querySelector("[data-publish-changes]");
			if (publish) publish.hidden = result.state !== "draft" && !result.pending;
			const discard = document.querySelector("[data-keep-discard]");
			if (discard) discard.hidden = !result.pending;
			for (const node of document.querySelectorAll("[data-record-state]"))
				node.textContent = result.statusLabel;
			for (const node of document.querySelectorAll("[data-record-revision-number]"))
				node.textContent = String(result.revision);
			for (const panel of document.querySelectorAll("[data-media-upload]"))
				panel.dataset.recordRevision = String(result.revision);
			form.dataset.pendingInput = "false";
			savedAt = new window.Date(result.savedAt).toLocaleTimeString(
				document.documentElement.lang,
				{ hour: "2-digit", minute: "2-digit" },
			);
			fieldErrors(result.errors);
			form.dispatchEvent(new window.CustomEvent("record-kept", { detail: result }));
		},
		onState: (state, reason) => {
			if (status)
				status.textContent =
					state === "saved"
						? savedAt
							? t("keep.saved", { time: savedAt })
							: initialStatus
						: state === "error"
							? t("keep.failed", { reason })
							: t("keep.saving");
			if (retry) retry.hidden = state !== "error";
			if (state === "error") {
				const message = t("keep.failed", { reason });
				if (announce && announce.textContent !== message)
					announce.textContent = message;
				failed = true;
			} else if (state === "saved") {
				form.dispatchEvent(new window.Event("record-saved"));
				if (failed && announce)
					announce.textContent = t("keep.saved", { time: savedAt || "" });
				failed = false;
				if (created && !navigating && !openingExact) {
					navigating = true;
					window.location.replace(path);
				}
			}
		},
		onConflict: (result) => {
			navigating = true;
			form.action = result.path;
			const intent = document.createElement("input");
			intent.type = "hidden";
			intent.name = "_keep_conflict";
			intent.value = "1";
			form.append(intent);
			window.HTMLFormElement.prototype.submit.call(form);
		},
	});
	form.dataset.keeping = "true";
	listen(form, "input", () => keeper.change());
	listen(form, "change", () => keeper.change());
	listen(form, "focusout", () => {
		if (keeper.dirty()) void keeper.flush();
	});
	listen(form, "keydown", (event) => {
		const control = event.target;
		if (
			event.key !== "Enter" ||
			event.isComposing ||
			event.ctrlKey ||
			event.metaKey ||
			event.altKey ||
			control.form !== form ||
			!["text", "url", "number", "email", "search"].includes(control.type)
		)
			return;
		event.preventDefault();
		const search =
			control.type === "search"
				? control
						.closest(".admin-picker__search")
						?.querySelector('button[type="submit"]')
				: null;
		if (search) form.requestSubmit(search);
		else if (keeper.dirty()) void keeper.flush();
	});
	if (retry) listen(retry, "click", () => void keeper.retry());
	listen(
		form,
		"click",
		(event) => {
			const start = event.target.closest?.("[data-media-upload-start]");
			if (!start || allowUpload || !keeper.dirty()) return;
			event.preventDefault();
			event.stopImmediatePropagation();
			void keeper.flush().then((clean) => {
				if (clean || !keeper.unacknowledged()) {
					allowUpload = true;
					try {
						start.click();
					} finally {
						allowUpload = false;
					}
				}
			});
		},
		true,
	);
	listen(window, "online", () => {
		if (keeper.dirty()) void keeper.flush();
	});
	listen(window, "focus", () => {
		if (needsContext && keeper.dirty()) void keeper.flush();
	});
	listen(document, "visibilitychange", () => {
		if (document.visibilityState === "hidden" && keeper.dirty())
			void keeper.flush(true);
	});
	listen(window, "pagehide", () => {
		if (keeper.dirty()) void keeper.flush(true);
	});
	listen(window, "beforeunload", (event) => {
		if (!keeper.dirty() || navigating) return;
		void keeper.flush(true);
		event.preventDefault();
		event.returnValue = "";
	});
	listen(
		document,
		"submit",
		(event) => {
			const target = event.target;
			if (allowSubmit || !(target === form || target.id?.startsWith("record-"))) return;
			if (target === exactForm) {
				const prepare = () => {
					exactForm.action = `${path}/exact-preview`;
					exactForm.elements.namedItem("_exact_locale").value =
						form.dataset.recordLocale ||
						exactForm.elements.namedItem("_exact_locale").value;
				};
				// A clean form can submit natively. Reentrant requestSubmit from an
				// already-resolved promise is suppressed by the browser's submit guard.
				if (!openingExact && !keeper.dirty()) {
					prepare();
					if (exactStatus) exactStatus.textContent = "";
					return;
				}
				event.preventDefault();
				event.stopImmediatePropagation();
				if (openingExact) return;
				openingExact = true;
				const button = event.submitter;
				if (button) {
					button.disabled = true;
					button.setAttribute("aria-busy", "true");
				}
				if (exactStatus)
					exactStatus.textContent = keeper.dirty() ? t("exact.saving") : "";
				void keeper.flush().then((clean) => {
					openingExact = false;
					if (button) {
						button.disabled = false;
						button.removeAttribute("aria-busy");
					}
					if (!clean) {
						if (exactStatus) exactStatus.textContent = t("exact.keep_failed");
						status?.focus();
						return;
					}
					if (exactStatus) exactStatus.textContent = "";
					prepare();
					allowSubmit = true;
					try {
						exactForm.requestSubmit(button);
					} finally {
						allowSubmit = false;
					}
					if (created) {
						navigating = true;
						window.location.replace(path);
					}
				});
				return;
			}
			if (event.submitter?.name === "_composition_preview") return;
			// A native Save already persists the complete form. Settle any in-flight
			// request and cancel its queued successor rather than writing twice.
			if (
				target === form &&
				!event.submitter?.name &&
				!event.submitter?.hasAttribute("formaction")
			) {
				event.preventDefault();
				event.stopImmediatePropagation();
				void keeper.settleForNativeSave().then(() => {
					allowSubmit = true;
					navigating = true;
					target.requestSubmit(event.submitter);
				});
				return;
			}
			if (!keeper.dirty()) return;
			event.preventDefault();
			event.stopImmediatePropagation();
			void keeper.flush().then((clean) => {
				const localAction =
					target === form &&
					[
						"_editor_action",
						"_rich_action",
						"_composition_action",
						"_upload_field",
						"_ai_action",
					].includes(event.submitter?.name);
				if ((!clean && !(localAction && !keeper.unacknowledged())) || created) return;
				allowSubmit = true;
				try {
					target.requestSubmit(event.submitter);
				} finally {
					allowSubmit = false;
				}
			});
		},
		true,
	);
	listen(document, "click", (event) => {
		const link = event.target.closest?.("a[href]");
		if (
			!link ||
			event.defaultPrevented ||
			event.ctrlKey ||
			event.metaKey ||
			event.shiftKey ||
			event.altKey ||
			link.target ||
			link.hash ||
			!keeper.dirty()
		)
			return;
		const url = new window.URL(link.href, window.location.href);
		if (url.origin !== window.location.origin) return;
		event.preventDefault();
		void keeper.flush().then((clean) => {
			if (clean) {
				navigating = true;
				window.location.assign(url.href);
			}
		});
	});
	keeper.change();
	return {
		flush: keeper.flush,
		dirty: keeper.dirty,
		dispose() {
			keeper.stop();
			for (const remove of listeners) remove();
			if (save) save.hidden = false;
			if (status) {
				status.textContent = initialStatus;
				status.setAttribute("role", "status");
			}
			form.noValidate = false;
			delete form.dataset.keeping;
		},
	};
}
