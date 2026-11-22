import { watchVimeo } from "./vimeo-player.js";

const VIMEO_ORIGIN = "https://player.vimeo.com";

/** The player is a sibling of the opaque page, so Vimeo receives a real origin referrer. */
export function mountLiveVimeo(root, activeFrame, { shell = false } = {}) {
	const document = root.ownerDocument;
	const window = document.defaultView;
	let player = null;
	let cancel = null;
	let notify = null;
	let request = null;
	let paused = false;
	// A player leaves with its state: "closed" when the viewer hid, left or moved on, so
	// the page brings its Play button back; "failed" when the provider did not answer.
	const clear = (state = "closed") => {
		cancel?.();
		cancel = null;
		notify?.(state);
		notify = null;
		request = null;
		player?.remove();
		player = null;
		paused = false;
	};
	const close = () => clear("closed");
	const validRect = (rect) =>
		rect !== null &&
		typeof rect === "object" &&
		["x", "y", "width", "height"].every((key) => Number.isFinite(rect[key])) &&
		rect.width > 0 &&
		rect.height > 0 &&
		rect.height <= 16384;
	const withinFrame = (rect, frame) =>
		rect.width <= frame.offsetWidth + 1 &&
		rect.x < frame.offsetWidth &&
		rect.y < frame.offsetHeight &&
		rect.x + rect.width > 0 &&
		rect.y + rect.height > 0;
	// Where the player sits for a poster rectangle given in the page's own pixels. In a
	// shell it floats over the frame and is clipped to the frame's box, so a poster scrolled
	// partly or wholly out of view takes its player with it.
	const place = (frame, rect) => {
		const box = frame.getBoundingClientRect();
		const scale = box.width / frame.offsetWidth;
		player.style.width = `${rect.width * scale}px`;
		player.style.height = `${rect.height * scale}px`;
		if (!shell) {
			player.style.left = `${rect.x * scale}px`;
			player.style.top = `${rect.y * scale}px`;
			return;
		}
		player.style.left = `${box.left + rect.x * scale}px`;
		player.style.top = `${box.top + rect.y * scale}px`;
		player.style.clipPath = `inset(${Math.max(0, -rect.y * scale)}px ${Math.max(0, (rect.x + rect.width) * scale - box.width)}px ${Math.max(0, (rect.y + rect.height) * scale - box.height)}px ${Math.max(0, -rect.x * scale)}px)`;
	};
	const tell = (method) => player?.contentWindow?.postMessage({ method }, VIMEO_ORIGIN);
	const message = (event) => {
		const frame = activeFrame();
		const data = event.data;
		if (
			event.source !== frame?.contentWindow ||
			event.origin !== "null" ||
			data?.type !== "cms-live"
		)
			return;
		if (data.event === "scroll") close();
		if (data.event === "vimeo-cancel" && data.request === request) clear("failed");
		// The page reports where its poster went; the player follows while it plays.
		if (data.event === "vimeo-move") {
			if (player && data.request === request && validRect(data.rect))
				place(frame, data.rect);
			return;
		}
		if (
			data.event !== "vimeo-play" ||
			!window.navigator?.userActivation?.isActive ||
			root.hidden ||
			(shell &&
				(document.hidden ||
					frame.hidden ||
					frame.offsetWidth <= 0 ||
					frame.offsetHeight <= 0))
		)
			return;
		if (
			!/^[1-9][0-9]{0,9}$/.test(data.request ?? "") ||
			!/^[1-9][0-9]{0,19}$/.test(data.id ?? "") ||
			(data.hash !== "" && !/^[A-Za-z0-9]{10}$/.test(data.hash ?? "")) ||
			typeof data.title !== "string" ||
			data.title.length > 400 ||
			!data.title.trim() ||
			[...data.title].length > 200 ||
			[...data.title].some(
				(character) => character.charCodeAt(0) < 32 || character.charCodeAt(0) === 127,
			)
		)
			return;
		const rect = data.rect;
		if (!validRect(rect) || !withinFrame(rect, frame)) return;
		close();
		request = data.request;
		notify = (state) =>
			frame.contentWindow.postMessage(
				{
					type: "cms-live",
					event: "vimeo-state",
					request: data.request,
					state,
				},
				"*",
			); // The addressed preview window has an opaque origin.
		const box = frame.getBoundingClientRect();
		if (shell && (box.width <= 0 || box.height <= 0)) {
			close();
			return;
		}
		const query = new URLSearchParams();
		if (data.hash) query.set("h", data.hash);
		query.set("dnt", "1");
		query.set("autoplay", "1");
		player = document.createElement("iframe");
		player.dataset.liveVimeo = "";
		player.dataset.liveActive = "";
		player.title = data.title;
		player.allow = "autoplay; fullscreen; picture-in-picture";
		player.allowFullscreen = true;
		player.src = `${VIMEO_ORIGIN}/video/${data.id}?${query}`;
		player.style.position = shell ? "fixed" : "absolute";
		player.style.border = "0";
		player.style.zIndex = "10";
		if (shell) {
			player.dataset.previewVimeo = "";
			player.referrerPolicy = "origin";
		}
		place(frame, rect);
		cancel = watchVimeo(
			player,
			window,
			() => notify?.("ready"),
			() => clear("failed"),
		);
		frame.parentElement.append(player);
	};
	window.addEventListener("message", message);
	// In a shell the page reports its own scrolls and resizes, and the player follows;
	// elsewhere any movement of the workspace ends the player.
	if (!shell) {
		window.addEventListener("resize", close);
		window.addEventListener("scroll", close, true);
	}
	return {
		clear,
		// A hidden viewer pauses the player and a returning one resumes it, in place.
		pause() {
			if (player === null || paused) return;
			paused = true;
			tell("pause");
		},
		resume() {
			if (player === null || !paused) return;
			paused = false;
			tell("play");
		},
		destroy() {
			close();
			window.removeEventListener("message", message);
			if (!shell) {
				window.removeEventListener("resize", close);
				window.removeEventListener("scroll", close, true);
			}
		},
	};
}
