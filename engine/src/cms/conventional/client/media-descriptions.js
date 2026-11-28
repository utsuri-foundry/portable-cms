import { descriptionState, reduceDescriptions } from "./media-descriptions-model.js";
import { messages } from "./messages.js";
import { createRecordKeeper } from "./record-keeper.js";

const PLACEHOLDER = "00000000000000000000000000000000";
const REQUEST_TIMEOUT = 15000;
// The same 24-hour clock as the record's own "Saved at", whatever the interface language.
const CLOCK = { hour: "2-digit", minute: "2-digit", hourCycle: "h23" };

/**
 * Descriptions are media data: they keep themselves the way a record does, through their own
 * separate native form and endpoint, so record content never travels with them.
 */
export function mountMediaDescriptions(
	panel,
	{
		fetcher = globalThis.fetch,
		schedule = globalThis.setTimeout,
		cancelTimer = globalThis.clearTimeout,
		now = () => new Date(),
		entries = (form) => [
			...new (form.ownerDocument.defaultView?.FormData ?? globalThis.FormData)(form),
		],
	} = {},
) {
	const section = panel.querySelector("[data-upload-descriptions]");
	const template = panel.querySelector("[data-media-description-template]");
	const form = panel.ownerDocument.getElementById(
		section?.dataset.descriptionsForm || "media-descriptions-form",
	);
	if (!section || !template || !form)
		return { add: async () => {}, ready: () => {}, dispose: () => {} };
	const document = panel.ownerDocument;
	const view = document.defaultView ?? null;
	const t = messages(section);
	const status = section.querySelector("[data-descriptions-status]");
	const cards = section.querySelector("[data-description-cards]");
	const button = section.querySelector("button[type=submit]");
	const controllers = new Map();
	const listeners = [];
	let state = descriptionState();
	state.ids = [...cards.querySelectorAll("[data-media-description]")].map(
		(card) => card.dataset.mediaDescription,
	);
	let sequence = 0;
	let savedAt = "";
	let refused = false;
	let needsContext = false;

	async function request(url, options = {}) {
		const controller = new AbortController();
		const timer = schedule(() => controller.abort(), REQUEST_TIMEOUT);
		controllers.set(controller, timer);
		try {
			const response = await fetcher(url, { ...options, signal: controller.signal });
			let value = null;
			try {
				value = await response.json();
			} catch {
				value = null;
			}
			return { response, value };
		} finally {
			cancelTimer(timer);
			controllers.delete(controller);
		}
	}
	function link(id = null) {
		const element = document.createElement("a");
		element.href = id === null ? "/media" : `/media/${id}`;
		element.target = "_blank";
		element.rel = "noopener";
		element.textContent = t(id === null ? "title" : "review");
		return element;
	}
	const cardOf = (id) => cards.querySelector(`[data-media-description="${id}"]`);
	/** The edited cards' text; revisions join at send time, so a kept save reads as clean. */
	function values() {
		const body = new URLSearchParams();
		const fields = state.edited.length > 0 ? entries(form) : [];
		for (const id of state.edited) {
			const prefix = `description_${id}__`;
			body.append("media_ids[]", id);
			for (const [name, value] of fields)
				if (
					typeof value === "string" &&
					name.startsWith(prefix) &&
					name !== `${prefix}revision`
				)
					body.append(name, value);
		}
		return body.toString();
	}
	/** A session renewed in another tab gives this page a new form token, as the record does. */
	async function renewContext() {
		const record = panel.closest?.("[data-editorial-form]");
		if (!record) return;
		const { response, value } = await request(`${record.action}?_keep_context=1`, {
			credentials: "same-origin",
			headers: { Accept: "application/json" },
		});
		if (!response.ok || typeof value?.csrf !== "string") throw Error(t("keep.sign_in"));
		for (const input of document.querySelectorAll('[name="_csrf"]'))
			input.value = value.csrf;
		for (const upload of document.querySelectorAll("[data-media-upload]"))
			upload.dataset.csrfToken = value.csrf;
		needsContext = false;
	}
	function holds(item, sent) {
		const prefix = `description_${item.id}__`;
		return (
			item.text?.decorative === sent.has(`${prefix}decorative`) &&
			item.text.translations.every((translation) =>
				["alternativeText", "caption", "credit"].every(
					(field) =>
						(sent.get(`${prefix}${field}__${translation.locale}`) ??
							translation[field]) === translation[field],
				),
			)
		);
	}
	async function write(body, _id, keepalive) {
		// Only this answer can say a card refused; a lost one is the connection's failure.
		refused = false;
		try {
			if (needsContext) await renewContext();
			const sent = new URLSearchParams(body);
			sent.set("_csrf", form.elements.namedItem("_csrf")?.value ?? "");
			sent.set("_descriptions_end", "1");
			for (const id of sent.getAll("media_ids[]"))
				sent.set(
					`description_${id}__revision`,
					cardOf(id)?.querySelector("[data-description-revision]")?.value ?? "0",
				);
			const { response, value } = await request(form.action, {
				method: "POST",
				credentials: "same-origin",
				// Browsers refuse a keepalive body over 64 KiB; a larger one goes as an ordinary request.
				keepalive: keepalive && sent.toString().length < 65536,
				headers: { Accept: "application/json" },
				body: sent,
			});
			if (
				[401, 403].includes(response.status) ||
				(response.redirected && new URL(response.url).pathname === "/login")
			) {
				needsContext = true;
				throw Error(t("keep.sign_in"));
			}
			if (!Array.isArray(value?.items)) {
				// A refused form names its reason; the next change tries again.
				if (response.status === 422 && typeof value?.message === "string")
					return { errors: { "": value.message }, items: [] };
				throw Error(t("keep.network"));
			}
			// A retry after a lost answer meets its own save as a conflict: the same text was kept.
			const items = value.items.map((item) =>
				item.error && holds(item, sent) ? { ...item, error: null } : item,
			);
			const errors = {};
			for (const item of items) if (item.error) errors[item.id] = item.error;
			return { errors, items };
		} catch (error) {
			throw Error(
				error.message === t("keep.sign_in") ? error.message : t("keep.network"),
			);
		}
	}
	/** One line per image says what it still needs: its field row's, or its card's on its own. */
	function describe(id, warning) {
		for (const node of document.querySelectorAll(
			`[data-description-warning="${id}"]`,
		)) {
			node.hidden = !warning;
			node.textContent = warning;
		}
		const own = cardOf(id)?.querySelector("[data-description-warning]");
		if (own) {
			own.hidden = !warning;
			own.textContent = warning;
		}
	}
	function kept(result) {
		savedAt = now().toLocaleTimeString(document.documentElement.lang || "en", CLOCK);
		for (const item of result.items) {
			const root = cardOf(item.id);
			if (!root) continue;
			root.querySelector("[data-description-revision]").value = String(item.revision);
			// A refusal reads like a field error in its own card; a kept card says nothing.
			const report = root.querySelector("[data-description-result]");
			report.hidden = !item.error;
			report.className = item.error ? "admin-field__error" : "";
			report.setAttribute("role", item.error ? "alert" : "status");
			report.textContent = item.error || "";
			if (item.error) {
				refused = true;
				report.append(" ", link(item.id));
			}
			describe(item.id, item.warning);
		}
	}
	function show(name, reason) {
		if (!status || state.disposed) return;
		status.textContent =
			name === "saving"
				? t("keep.saving")
				: name === "saved"
					? savedAt
						? t("keep.saved", { time: savedAt })
						: ""
					: refused
						? ""
						: t("keep.failed", { reason });
	}
	const keep = () =>
		createRecordKeeper({
			read: values,
			write,
			identify: () => "",
			onState: show,
			onSaved: kept,
			onConflict: () => {},
			schedule,
			cancel: cancelTimer,
		});
	let keeper = keep();
	function card(item) {
		const fragment = template.content.cloneNode(true);
		for (const element of fragment.querySelectorAll("*")) {
			for (const attribute of [
				"id",
				"for",
				"name",
				"value",
				"data-media-description",
			]) {
				if (element.hasAttribute(attribute))
					element.setAttribute(
						attribute,
						element.getAttribute(attribute).replaceAll(PLACEHOLDER, item.id),
					);
			}
		}
		fragment.querySelector("[data-description-name]").textContent = item.name;
		fragment.querySelector("[data-description-revision]").value = String(item.revision);
		const prefix = `description_${item.id}__`;
		for (const translation of item.text.translations) {
			for (const field of ["alternativeText", "caption", "credit"]) {
				const input = fragment.querySelector(
					`[name="${prefix}${field}__${translation.locale}"]`,
				);
				if (input) input.value = translation[field];
			}
		}
		fragment.querySelector(`[name="${prefix}decorative"]`).checked =
			item.text.decorative;
		const warning = fragment.querySelector("[data-description-warning]");
		if (warning) {
			warning.hidden = !item.warning;
			warning.textContent = item.warning;
		}
		cards.append(fragment);
		section.hidden = false;
	}
	async function run(effect) {
		switch (effect.type) {
			case "clear":
				// Everything shown was kept: the next batch starts a fresh keeper.
				keeper.stop();
				keeper = keep();
				savedAt = "";
				if (status) status.textContent = "";
				cards.replaceChildren();
				return;
			case "card":
				card(effect.item);
				return;
			case "load-failed":
				section.hidden = false;
				status.textContent = t("load_failed");
				status.append(" ", link());
				return;
			case "load": {
				try {
					const { response, value } = await request(
						`/media/descriptions?ids=${effect.id}`,
						{ headers: { Accept: "application/json" } },
					);
					if (!response.ok || value?.items?.[0]?.id !== effect.id) throw new Error();
					await send({
						type: "loaded",
						id: effect.id,
						requestId: effect.requestId,
						item: value.items[0],
					});
				} catch {
					await send({
						type: "load-failed",
						id: effect.id,
						requestId: effect.requestId,
					});
				}
				return;
			}
			case "dispose":
				for (const [controller, timer] of controllers) {
					cancelTimer(timer);
					controller.abort();
				}
				controllers.clear();
				keeper.stop();
				for (const remove of listeners.splice(0)) remove();
				if (button) button.hidden = false;
				return;
		}
	}
	async function send(event) {
		const next = reduceDescriptions(state, event);
		state = next.state;
		for (const effect of next.effects) {
			if (state.disposed && effect.type !== "dispose") break;
			await run(effect);
		}
	}
	const listen = (target, type, handler) => {
		if (typeof target?.addEventListener !== "function") return;
		target.addEventListener(type, handler);
		listeners.push(() => target.removeEventListener(type, handler));
	};
	function onInput(event) {
		const id = event.target?.closest?.("[data-media-description]")?.dataset
			.mediaDescription;
		if (id) void send({ type: "edit", id }).catch(() => {});
		keeper.change();
	}
	const flush = (keepalive = false) => {
		if (!state.disposed && keeper.dirty()) void keeper.flush(keepalive);
	};
	// Enter, leaving a card and leaving the page keep what is written, as in the record.
	listen(form, "submit", (event) => {
		event.preventDefault();
		flush();
	});
	listen(section, "input", onInput);
	listen(section, "change", onInput);
	listen(section, "focusout", () => flush());
	listen(view, "online", () => flush());
	listen(view, "pagehide", () => flush(true));
	listen(document, "visibilitychange", () => {
		if (document.visibilityState === "hidden") flush(true);
	});
	if (button) button.hidden = true;
	return {
		async add(media) {
			if (
				!media?.declaredType?.startsWith("image/") ||
				!/^[a-f0-9]{32}$/.test(media.id)
			)
				return;
			await send({
				type: "load",
				id: media.id,
				requestId: ++sequence,
				kept: !keeper.dirty(),
			});
		},
		ready(id) {
			if (state.disposed || !state.ids.includes(id)) return;
			const root = cardOf(id);
			const image = document.createElement("img");
			image.className = "admin-thumbnail";
			image.width = 80;
			image.height = 64;
			image.alt = "";
			image.src = `/media/${id}/thumbnail`;
			root.querySelector(".admin-thumbnail")?.replaceWith(image);
		},
		dispose() {
			void send({ type: "dispose" }).catch(() => {});
		},
	};
}
