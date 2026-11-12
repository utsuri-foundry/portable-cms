import { reducePreview } from "./composition-model.js";
import { messages } from "./messages.js";

/** Logical viewport height for measuring a page before it is fitted. */
const MEASURE_HEIGHT = 900;
const DEFAULT_WIDTH = 1440;

/** Enhances the native row form; PHP alone projects, validates and renders the layout. */
export function mountCompositionEditor(root, beforePreview = null) {
	const t = messages(root);
	const document = root.ownerDocument;
	const window = document.defaultView;
	const form = root.closest("form");
	const frame = root.querySelector("[data-composition-frame]");
	const button = root.querySelector("[data-composition-preview-button]");
	const stage = root.querySelector("[data-composition-stage]");
	const status = root.querySelector("[data-composition-status]");
	const box = root.querySelector("[data-composition-box]");
	const label = root.querySelector("[data-composition-scale]");
	const workspace = root.querySelector("[data-layout-workspace]");
	const name = root.dataset.compositionName;
	if (!form || !frame || !button || !stage || !status || !name) return () => {};
	// Proportional row map: CSSOM sizing is permitted by the administration style policy.
	const cells = [...root.querySelectorAll("[data-share]")];
	for (const cell of cells) {
		const share = Number(cell.dataset.share);
		if (Number.isFinite(share) && share > 0) cell.style.flexGrow = String(share);
	}
	const initialStatus = status.textContent;
	const initialWidth = frame.getAttribute("width");
	let state = { active: null, latest: 0, phase: "idle" };
	const snapshots = new Map();
	let sequence = 0;
	let debounce;
	let deadline;
	let disposed = false;
	let keeping = false;
	const removers = [];
	const listen = (target, type, handler, capture = false) => {
		target.addEventListener(type, handler, capture);
		removers.push(() => target.removeEventListener(type, handler, capture));
	};
	const viewport = () => root.querySelector(`[name="${name}__viewport"]:checked`);
	/** Fits the whole rendered page into the stage: the smaller of the width and height ratios. */
	function scale() {
		const width = Math.max(240, Number(viewport()?.value) || DEFAULT_WIDTH);
		frame.setAttribute("width", String(width));
		frame.style.width = `${width}px`;
		frame.style.transformOrigin = "top left";
		let height = MEASURE_HEIGHT;
		const page = frame.contentDocument;
		if (page?.querySelector?.('meta[name="composition-sequence"]')) {
			frame.style.height = `${MEASURE_HEIGHT}px`;
			height = Math.max(
				200,
				Math.ceil(
					Math.max(
						page.documentElement?.scrollHeight ?? 0,
						page.body?.scrollHeight ?? 0,
					),
				),
			);
		}
		frame.style.height = `${height}px`;
		if (!stage.clientWidth) return;
		const available = Math.min(window.innerHeight * 0.72, 720);
		const padding = window.getComputedStyle?.(stage);
		const inner =
			stage.clientWidth -
			(Number.parseFloat(padding?.paddingLeft) || 0) -
			(Number.parseFloat(padding?.paddingRight) || 0);
		const ratio = Math.min(1, inner / width, available / height);
		frame.style.transform = `scale(${ratio})`;
		if (box) {
			box.style.width = `${Math.floor(width * ratio)}px`;
			box.style.height = `${Math.floor(height * ratio)}px`;
		}
		if (label) {
			label.hidden = false;
			label.textContent = t("composition.scale", {
				width,
				percent: Math.round(ratio * 100),
			});
		}
	}
	function dispatch(event) {
		if (disposed) return;
		const result = reducePreview(state, event);
		state = result.state;
		frame.hidden = state.phase === "loading" || keeping;
		status.textContent =
			state.phase === "loading" || keeping
				? t("composition.updating")
				: state.phase === "ready"
					? t("composition.ready")
					: t("composition.failed");
		for (const effect of result.effects) {
			if (effect.type !== "navigate") throw new Error("Unknown preview effect");
			const snapshot = snapshots.get(effect.requestId);
			if (!snapshot) throw new Error("Missing preview intent");
			for (const key of snapshots.keys())
				if (key < effect.requestId) snapshots.delete(key);
			const transport = document.createElement("form");
			transport.method = "post";
			transport.action = form.action;
			transport.target = frame.name;
			transport.hidden = true;
			for (const [key, value] of snapshot) {
				const input = document.createElement("input");
				input.type = "hidden";
				input.name = key;
				input.value = value;
				transport.append(input);
			}
			document.body.append(transport);
			try {
				transport.submit();
			} finally {
				transport.remove();
			}
			window.clearTimeout(deadline);
			deadline = window.setTimeout(() => {
				if (disposed || state.active !== effect.requestId) return;
				frame.src = "about:blank";
				dispatch({ type: "settled", requestId: effect.requestId, failed: true });
			}, 15000);
		}
	}
	async function request() {
		if (disposed || (workspace && !workspace.open) || keeping) return;
		if (beforePreview) {
			keeping = true;
			frame.hidden = true;
			status.textContent = t("composition.updating");
			let kept;
			try {
				kept = await beforePreview();
			} finally {
				keeping = false;
			}
			if (!kept) {
				if (!disposed) {
					frame.hidden = false;
					status.textContent = t("composition.failed");
				}
				return;
			}
		}
		if (disposed) return;
		window.clearTimeout(debounce);
		const requestId = ++sequence;
		const snapshot = [...new window.FormData(form)].filter(
			([, value]) => typeof value === "string",
		);
		snapshot.push(
			["_composition_preview", name],
			[`${name}__sequence`, String(requestId)],
		);
		// No file objects, content or CSRF tokens are retained outside this bounded runtime.
		for (const key of snapshots.keys()) if (key !== state.active) snapshots.delete(key);
		snapshots.set(requestId, snapshot);
		scale();
		dispatch({ type: "requested", requestId });
	}
	// One inspector: the selected column of the active row, chosen by tab or by its cell.
	const tabs = root.querySelector("[data-column-tabs]");
	const tabButtons = tabs ? [...tabs.querySelectorAll("[data-column-tab]")] : [];
	const panels = [...root.querySelectorAll("[data-column-panel]")];
	const activeStrip = root.querySelector(".admin-composition-strip[aria-current]");
	const activeCells = activeStrip
		? [...activeStrip.querySelectorAll("[data-share]")]
		: [];
	const select = (index) => {
		for (const panel of panels)
			panel.hidden = Number(panel.dataset.columnPanel) !== index;
		for (const tab of tabButtons)
			tab.setAttribute("aria-pressed", String(Number(tab.dataset.columnTab) === index));
		for (const [position, cell] of activeCells.entries())
			cell.toggleAttribute?.("data-selected", position === index);
	};
	if (tabs && panels.length > 1) {
		tabs.hidden = false;
		select(0);
		for (const tab of tabButtons)
			listen(tab, "click", () => select(Number(tab.dataset.columnTab)));
		if (activeStrip)
			listen(activeStrip, "click", (event) => {
				// The active row is already being edited; its cells pick a column instead.
				event.preventDefault();
				const cell = event.target.closest?.("[data-share]");
				const index = activeCells.indexOf(cell);
				if (index >= 0) select(index);
			});
		listen(
			root,
			"invalid",
			(event) => {
				const panel = event.target.closest?.("[data-column-panel]");
				if (panel) select(Number(panel.dataset.columnPanel));
			},
			true,
		);
	}
	listen(form, "submit", (event) => {
		if (event.submitter !== button) return;
		event.preventDefault();
		void request();
	});
	listen(form, "input", (event) => {
		if (!event.target.name || event.target.type === "hidden") return;
		window.clearTimeout(debounce);
		debounce = window.setTimeout(request, 180);
	});
	listen(form, "record-saved", () => {
		if (!workspace || workspace.open) void request();
	});
	listen(frame, "load", () => {
		const id = Number(
			frame.contentDocument?.querySelector('meta[name="composition-sequence"]')
				?.content,
		);
		if (!id || id !== state.active) return;
		window.clearTimeout(deadline);
		const failed = !!frame.contentDocument.querySelector("[role=alert]");
		snapshots.delete(id);
		dispatch({ type: "settled", requestId: id, failed });
		scale();
		// Web fonts can change the page height after load; measure again once they are ready.
		frame.contentDocument?.fonts?.ready?.then(() => {
			if (!disposed) scale();
		});
	});
	listen(window, "resize", scale);
	const observer = new window.ResizeObserver(scale);
	observer.observe(stage);
	if (root.hasAttribute("autofocus")) root.focus();
	// The preview loads only while the workspace is open.
	let initial;
	if (!workspace || workspace.open) initial = window.setTimeout(request, 0);
	if (workspace)
		listen(workspace, "toggle", () => {
			if (workspace.open) void request();
		});
	return () => {
		if (disposed) return;
		const loading = state.active !== null;
		dispatch({ type: "disposed" });
		disposed = true;
		for (const remove of removers) remove();
		if (loading) frame.src = "about:blank";
		observer.disconnect();
		window.clearTimeout(initial);
		window.clearTimeout(debounce);
		window.clearTimeout(deadline);
		snapshots.clear();
		status.textContent = initialStatus;
		frame.hidden = false;
		frame.removeAttribute("style");
		box?.removeAttribute("style");
		if (label) label.hidden = true;
		if (tabs) tabs.hidden = true;
		for (const panel of panels) panel.hidden = false;
		for (const cell of activeCells) cell.toggleAttribute?.("data-selected", false);
		for (const cell of cells) cell.style.removeProperty("flex-grow");
		if (initialWidth === null) frame.removeAttribute("width");
		else frame.setAttribute("width", initialWidth);
	};
}
