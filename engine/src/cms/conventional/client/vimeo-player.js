const VIMEO_ORIGIN = "https://player.vimeo.com";
const LOAD_WAIT = 5000;
let workspaceRequest = 0;

/** Negotiate trusted preview capabilities; unanswered pages keep local playback. */
function previewVimeoSupport(root, fallback, id, hash) {
	const window = root.ownerDocument.defaultView;
	const support = { player: false };
	if (!fallback || window.origin !== "null" || window.parent === window) return support;
	const origin = new URL(root.ownerDocument.URL).origin;
	let enabled = false;
	window.addEventListener("message", (event) => {
		if (
			event.source !== window.parent ||
			event.origin !== origin ||
			event.data?.type !== "cms-vimeo"
		)
			return;
		if (event.data.event === "link-support") enabled = true;
		if (event.data.event === "player-support") {
			support.player = true;
			root.dataset.vimeoShell = "true";
		}
	});
	fallback.addEventListener("click", (event) => {
		if (!enabled) return;
		event.preventDefault();
		window.parent.postMessage({ type: "cms-vimeo", event: "open", id, hash }, origin);
	});
	window.parent.postMessage({ type: "cms-vimeo", event: "link-support" }, origin);
	window.parent.postMessage({ type: "cms-vimeo", event: "player-support" }, origin);
	return support;
}

/** A load event alone also fires for blocked/error documents. Require Vimeo's handshake. */
export function watchVimeo(frame, window, ready, failed) {
	let active = true;
	let loaded = false;
	// Descendants inherit the preview sandbox's opaque origin. Source identity
	// still pins messages to the exact, engine-created Vimeo frame.
	const origin = window.origin === "null" ? "null" : VIMEO_ORIGIN;
	frame.style.visibility = "hidden";
	const cancel = () => {
		active = false;
		window.clearTimeout(timeout);
		window.removeEventListener("message", message);
		frame.removeEventListener("load", ping);
		frame.removeEventListener("error", fail);
	};
	const fail = () => {
		if (!active) return;
		cancel();
		frame.remove();
		failed();
	};
	const ping = () =>
		frame.contentWindow?.postMessage(
			{ method: "ping" },
			origin === "null" ? "*" : origin,
		);
	const message = (event) => {
		if (!active || event.source !== frame.contentWindow || event.origin !== origin)
			return;
		let data = event.data;
		if (typeof data === "string") {
			if (data.length > 4096) return;
			try {
				data = JSON.parse(data);
			} catch {
				return;
			}
		}
		if (data?.event === "error") return fail();
		if (loaded) return;
		if (data?.event !== "ready" && data?.method !== "ping") return;
		loaded = true;
		window.clearTimeout(timeout);
		frame.removeEventListener("load", ping);
		frame.style.visibility = "visible";
		ready();
		frame.focus();
	};
	const timeout = window.setTimeout(fail, LOAD_WAIT);
	window.addEventListener("message", message);
	frame.addEventListener("load", ping);
	frame.addEventListener("error", fail);
	return cancel;
}

/**
 * Keep the child poster/link in sync with the trusted sibling player. In a shell the player
 * sits over this page and follows the poster as the page scrolls or resizes, so playback
 * goes on while the visitor reads around it; the parent says when it closed the player
 * (a hidden or left viewer), and the Play button comes back, or when it failed.
 */
function workspaceVimeo(root, window, id, hash, ready, closed, failed, shell = false) {
	const request = String(++workspaceRequest);
	const origin = new URL(root.ownerDocument.URL).origin;
	const send = (event, fields = {}) =>
		window.parent.postMessage({ type: "cms-live", event, request, ...fields }, origin);
	const box = () => {
		const rect = root.getBoundingClientRect();
		return { x: rect.x, y: rect.y, width: rect.width, height: rect.height };
	};
	// A page that swaps its content in place, as a profile's own navigation may, removes the
	// poster without leaving the document: the player outside the page must go with it.
	const gone = () => root.isConnected === false;
	let pending = false;
	const move = () => {
		if (gone()) return leave();
		if (pending) return;
		pending = true;
		const frame = () => {
			pending = false;
			if (gone()) return leave();
			send("vimeo-move", { rect: box() });
		};
		if (typeof window.requestAnimationFrame === "function")
			window.requestAnimationFrame(frame);
		else frame();
	};
	const MutationObserver = window.MutationObserver;
	const watch =
		typeof MutationObserver === "function"
			? new MutationObserver(() => {
					if (gone()) leave();
				})
			: null;
	const letGo = () => {
		window.clearTimeout(timeout);
		window.removeEventListener("message", message);
		watch?.disconnect();
		if (shell) {
			window.removeEventListener("scroll", move, { capture: true });
			window.removeEventListener("resize", move);
		}
	};
	const leave = () => {
		letGo();
		send("vimeo-close");
	};
	const fail = () => {
		letGo();
		send("vimeo-cancel");
		failed();
	};
	const message = (event) => {
		const data = event.data;
		if (
			event.source !== window.parent ||
			event.origin !== origin ||
			data?.type !== "cms-live" ||
			data.event !== "vimeo-state" ||
			data.request !== request
		)
			return;
		if (data.state === "failed") fail();
		if (data.state === "closed") {
			letGo();
			closed();
		}
		if (data.state === "ready") {
			window.clearTimeout(timeout);
			ready();
		}
	};
	const timeout = window.setTimeout(fail, LOAD_WAIT);
	window.addEventListener("message", message);
	if (shell) {
		window.addEventListener("scroll", move, { capture: true });
		window.addEventListener("resize", move);
	}
	const body = root.ownerDocument.body;
	if (watch && body) watch.observe(body, { childList: true, subtree: true });
	send("vimeo-play", { id, hash, title: root.dataset.vimeoTitle, rect: box() });
}

/** No provider SDK, storage, preconnect or iframe exists before the visitor's choice. */
export function mountVimeoPlayer(root) {
	const button = root.querySelector("[data-vimeo-play]");
	const poster = root.querySelector("img");
	const id = root.dataset.vimeoId;
	const hash = root.dataset.vimeoHash;
	if (
		!button ||
		!poster ||
		!/^[1-9][0-9]{0,19}$/.test(id ?? "") ||
		(hash !== "" && !/^[A-Za-z0-9]{10}$/.test(hash ?? ""))
	)
		return;
	button.hidden = false;
	const fallback = root.querySelector(".cms-vimeo__link");
	const support = previewVimeoSupport(root, fallback, id, hash);
	if (fallback) fallback.hidden = true;
	let started = false;
	button.addEventListener("click", () => {
		if (started) return;
		started = true;
		// The button hides while the player runs and returns when a sibling player closes.
		button.hidden = true;
		const ready = () => {
			root.dataset.vimeoPlaying = "true";
		};
		const closed = () => {
			delete root.dataset.vimeoPlaying;
			started = false;
			button.hidden = false;
		};
		const failed = () => {
			delete root.dataset.vimeoPlaying;
			if (fallback) {
				fallback.hidden = false;
				fallback.focus();
			}
		};
		const document = root.ownerDocument;
		const window = document.defaultView;
		if (
			support.player ||
			(document.querySelector?.("script[data-vimeo-workspace]") &&
				window.parent !== window)
		) {
			workspaceVimeo(root, window, id, hash, ready, closed, failed, support.player);
			return;
		}
		const frame = document.createElement("iframe");
		const query = new URLSearchParams();
		if (hash) query.set("h", hash);
		query.set("dnt", "1");
		query.set("autoplay", "1");
		frame.title = root.dataset.vimeoTitle;
		frame.width = poster.getAttribute("width");
		frame.height = poster.getAttribute("height");
		frame.allow = "autoplay; fullscreen; picture-in-picture";
		frame.allowFullscreen = true;
		frame.src = `${VIMEO_ORIGIN}/video/${id}?${query}`;
		watchVimeo(frame, window, ready, failed);
		root.append(frame);
	});
}
if (typeof document !== "undefined") {
	for (const root of document.querySelectorAll("[data-vimeo-video]"))
		mountVimeoPlayer(root);
}
