import { mountLiveVimeo } from "./live-vimeo.js";

/** Host the provider outside the opaque page; never grant that page a CMS origin. */
export function mountPreviewVimeo(frame) {
	const document = frame.ownerDocument;
	const window = document.defaultView;
	const player = mountLiveVimeo(document.body, () => frame, { shell: true });
	const announce = () =>
		frame.contentWindow?.postMessage(
			{ type: "cms-vimeo", event: "player-support" },
			"*",
		);
	const message = (event) => {
		if (
			!document.hidden &&
			event.source === frame.contentWindow &&
			event.origin === "null" &&
			event.data?.type === "cms-vimeo" &&
			event.data.event === "player-support"
		)
			announce();
	};
	const load = () => {
		player.clear();
		announce();
	};
	const visibility = () => {
		if (document.hidden) player.clear();
	};
	const pagehide = () => player.clear();
	frame.addEventListener("load", load);
	window.addEventListener("message", message);
	window.addEventListener("pagehide", pagehide);
	document.addEventListener("visibilitychange", visibility);
	announce();
	frame.dataset.vimeoShellReady = "true";
	return {
		destroy() {
			player.destroy();
			frame.removeEventListener("load", load);
			window.removeEventListener("message", message);
			window.removeEventListener("pagehide", pagehide);
			document.removeEventListener("visibilitychange", visibility);
			delete frame.dataset.vimeoShellReady;
		},
	};
}

if (typeof document !== "undefined") {
	const frame = document.querySelector(
		"iframe[data-editor-exact-frame], iframe[data-share-renew]",
	);
	if (frame) mountPreviewVimeo(frame);
}
