const token = window.location.hash.slice(1);
if (window.location.hash) {
	window.history.replaceState(null, "", window.location.pathname);
}
const form = document.querySelector("[data-share-open]");
if (form instanceof HTMLFormElement && /^[a-f0-9]{64}$/.test(token)) {
	const input = form.elements.namedItem("token");
	if (input instanceof HTMLInputElement) {
		input.value = token;
		form.requestSubmit();
	}
}
const dismiss = document.querySelector("[data-share-dismiss]");
dismiss?.addEventListener("click", () => {
	const notice = document.querySelector("[data-share-notice]");
	if (notice instanceof HTMLElement) notice.hidden = true;
});

const frame = document.querySelector("iframe[data-share-renew]");
if (frame instanceof HTMLIFrameElement) {
	const match = new URL(frame.src).pathname.match(
		/^\/(?:shared|newest)-preview-files\/[a-f0-9]{32}\/([a-f0-9]{64})\/$/,
	);
	const endpoint = frame.dataset.shareRenew;
	if (match && endpoint) {
		let pending = null;
		let stopped = false;
		// Keep arbitrary popups denied; only a current click and a fixed Vimeo
		// destination can ask this shell to open a new tab with no opener.
		const linkSupport = () =>
			frame.contentWindow.postMessage(
				{ type: "cms-vimeo", event: "link-support" },
				"*",
			);
		const linkMessage = (event) => {
			const data = event.data;
			if (
				stopped ||
				document.hidden ||
				event.source !== frame.contentWindow ||
				event.origin !== "null" ||
				data?.type !== "cms-vimeo"
			)
				return;
			if (data.event === "link-support") return linkSupport();
			if (
				data.event !== "open" ||
				!window.navigator?.userActivation?.isActive ||
				!/^[1-9][0-9]{0,19}$/.test(data.id ?? "") ||
				(data.hash !== "" && !/^[A-Za-z0-9]{10}$/.test(data.hash ?? ""))
			)
				return;
			window.open(
				`https://vimeo.com/${data.id}${data.hash ? `/${data.hash}` : ""}`,
				"_blank",
				"noopener,noreferrer",
			);
		};
		window.addEventListener("message", linkMessage);
		frame.addEventListener("load", linkSupport);
		linkSupport();
		async function renew() {
			if (stopped || document.hidden || pending) return;
			pending = new AbortController();
			const timeout = setTimeout(() => pending?.abort(), 15000);
			try {
				const response = await fetch(endpoint, {
					method: "POST",
					mode: "same-origin",
					credentials: "same-origin",
					body: new URLSearchParams({ token: match[1] }),
					signal: pending.signal,
				});
				if (response.status === 404) stop();
			} catch {
				// A temporary connection failure keeps the last bounded lease.
			} finally {
				clearTimeout(timeout);
				pending = null;
			}
		}
		const timer = setInterval(renew, 15 * 60 * 1000);
		function suspend(event) {
			if (event.persisted) pending?.abort();
			else stop();
		}
		function stop() {
			if (stopped) return;
			stopped = true;
			clearInterval(timer);
			pending?.abort();
			window.removeEventListener("message", linkMessage);
			frame.removeEventListener("load", linkSupport);
			document.removeEventListener("visibilitychange", renew);
			window.removeEventListener("pagehide", suspend);
			window.removeEventListener("pageshow", renew);
		}
		document.addEventListener("visibilitychange", renew);
		window.addEventListener("pagehide", suspend);
		window.addEventListener("pageshow", renew);
	}
}
