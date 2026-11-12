import { descriptionState, reduceDescriptions } from "./media-descriptions-model.js";
import { messages } from "./messages.js";

const PLACEHOLDER = "00000000000000000000000000000000";
const REQUEST_TIMEOUT = 15000;

/** Separate native form association keeps record content out of description saves. */
export function mountMediaDescriptions(
	panel,
	{
		fetcher = globalThis.fetch,
		schedule = globalThis.setTimeout,
		cancelTimer = globalThis.clearTimeout,
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
	const t = messages(section);
	const status = section.querySelector("[data-descriptions-status]");
	const cards = section.querySelector("[data-description-cards]");
	const button = section.querySelector("button[type=submit]");
	const controllers = new Map();
	const bodies = new Map();
	let state = descriptionState();
	state.ids = [...cards.querySelectorAll("[data-media-description]")].map(
		(card) => card.dataset.mediaDescription,
	);
	let sequence = 0;

	async function read(url, options = {}) {
		const controller = new AbortController();
		const timer = schedule(() => controller.abort(), REQUEST_TIMEOUT);
		controllers.set(controller, timer);
		try {
			const response = await fetcher(url, { ...options, signal: controller.signal });
			return { response, value: await response.json() };
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
	function sync() {
		if (state.disposed) return;
		button.disabled = state.save !== null || state.loads.length > 0;
		for (const input of cards.querySelectorAll("input"))
			input.disabled = state.save !== null;
	}
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
		warning.hidden = !item.warning;
		warning.textContent = item.warning;
		cards.append(fragment);
		section.hidden = false;
	}
	async function run(effect) {
		switch (effect.type) {
			case "clear":
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
			case "save-failed":
				status.textContent = t("failed");
				return;
			case "load": {
				try {
					const { response, value } = await read(
						`/media/descriptions?ids=${effect.id}`,
						{ headers: { Accept: "application/json" } },
					);
					if (!response.ok || value.items?.[0]?.id !== effect.id) throw new Error();
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
			case "save": {
				try {
					const { response, value } = await read(form.action, {
						method: "POST",
						headers: { Accept: "application/json" },
						body: bodies.get(effect.requestId),
					});
					if (!Array.isArray(value.items)) throw new Error();
					await send({
						type: "saved",
						requestId: effect.requestId,
						items: value.items,
						success: response.ok,
					});
				} catch {
					await send({ type: "save-failed", requestId: effect.requestId });
				} finally {
					bodies.delete(effect.requestId);
				}
				return;
			}
			case "saved":
				for (const item of effect.items) {
					if (!state.ids.includes(item.id)) continue;
					const root = cards.querySelector(`[data-media-description="${item.id}"]`);
					root.querySelector("[data-description-revision]").value = String(
						item.revision,
					);
					const report = root.querySelector("[data-description-result]");
					report.hidden = false;
					report.setAttribute("role", item.error ? "alert" : "status");
					report.textContent = item.error || t("saved");
					if (item.error) report.append(" ", link(item.id));
					const warning = root.querySelector("[data-description-warning]");
					warning.hidden = !item.warning;
					warning.textContent = item.warning;
				}
				status.textContent = effect.success ? t("saved") : t("failed");
				return;
			case "dispose":
				for (const [controller, timer] of controllers) {
					cancelTimer(timer);
					controller.abort();
				}
				controllers.clear();
				bodies.clear();
				button.disabled = false;
				for (const input of cards.querySelectorAll("input")) input.disabled = false;
				form.removeEventListener("submit", onSubmit);
				section.removeEventListener("input", onInput);
				return;
		}
	}
	async function send(event) {
		const next = reduceDescriptions(state, event);
		state = next.state;
		sync();
		for (const effect of next.effects) {
			if (state.disposed && effect.type !== "dispose") break;
			await run(effect);
		}
		sync();
	}
	function onInput() {
		void send({ type: "edit" }).catch(() => {});
	}
	async function onSubmit(event) {
		event.preventDefault();
		if (
			state.save !== null ||
			state.loads.length > 0 ||
			state.ids.length === 0 ||
			state.disposed
		)
			return;
		const requestId = ++sequence;
		bodies.set(requestId, new URLSearchParams(new FormData(form)));
		await send({ type: "save", requestId });
	}
	form.addEventListener("submit", onSubmit);
	section.addEventListener("input", onInput);
	return {
		async add(media) {
			if (
				!media?.declaredType?.startsWith("image/") ||
				!/^[a-f0-9]{32}$/.test(media.id)
			)
				return;
			await send({ type: "load", id: media.id, requestId: ++sequence });
		},
		ready(id) {
			if (state.disposed || !state.ids.includes(id)) return;
			const root = cards.querySelector(`[data-media-description="${id}"]`);
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
