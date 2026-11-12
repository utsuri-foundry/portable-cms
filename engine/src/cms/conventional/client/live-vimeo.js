import { watchVimeo } from "./vimeo-player.js";

/** The player is a sibling of the opaque page, so Vimeo receives a real origin referrer. */
export function mountLiveVimeo(root, activeFrame, { shell = false } = {}) {
	const document = root.ownerDocument;
	const window = document.defaultView;
	let player = null;
	let cancel = null;
	let notify = null;
	let request = null;
	const clear = () => {
		cancel?.();
		cancel = null;
		notify?.("failed");
		notify = null;
		request = null;
		player?.remove();
		player = null;
	};
	const message = (event) => {
		const frame = activeFrame();
		const data = event.data;
		if (
			event.source !== frame?.contentWindow ||
			event.origin !== "null" ||
			data?.type !== "cms-live"
		)
			return;
		if (
			data.event === "scroll" ||
			(data.event === "vimeo-cancel" && data.request === request)
		)
			clear();
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
		if (
			!rect ||
			!["x", "y", "width", "height"].every((key) => Number.isFinite(rect[key])) ||
			rect.width <= 0 ||
			rect.height <= 0 ||
			rect.width > frame.offsetWidth + 1 ||
			rect.height > 16384 ||
			rect.x >= frame.offsetWidth ||
			rect.y >= frame.offsetHeight ||
			rect.x + rect.width <= 0 ||
			rect.y + rect.height <= 0
		)
			return;
		clear();
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
			clear();
			return;
		}
		const scale = box.width / frame.offsetWidth;
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
		player.src = `https://player.vimeo.com/video/${data.id}?${query}`;
		player.style.position = "absolute";
		player.style.left = `${rect.x * scale}px`;
		player.style.top = `${rect.y * scale}px`;
		player.style.width = `${rect.width * scale}px`;
		player.style.height = `${rect.height * scale}px`;
		player.style.border = "0";
		player.style.zIndex = "10";
		if (shell) {
			player.dataset.previewVimeo = "";
			player.referrerPolicy = "origin";
			player.style.position = "fixed";
			player.style.left = `${box.left + rect.x * scale}px`;
			player.style.top = `${box.top + rect.y * scale}px`;
			player.style.clipPath = `inset(${Math.max(0, -rect.y * scale)}px ${Math.max(0, (rect.x + rect.width) * scale - box.width)}px ${Math.max(0, (rect.y + rect.height) * scale - box.height)}px ${Math.max(0, -rect.x * scale)}px)`;
		}
		cancel = watchVimeo(player, window, () => notify?.("ready"), clear);
		frame.parentElement.append(player);
	};
	window.addEventListener("message", message);
	window.addEventListener("resize", clear);
	window.addEventListener("scroll", clear, true);
	return {
		clear,
		destroy() {
			clear();
			window.removeEventListener("message", message);
			window.removeEventListener("resize", clear);
			window.removeEventListener("scroll", clear, true);
		},
	};
}
