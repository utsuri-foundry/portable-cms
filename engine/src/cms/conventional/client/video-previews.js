const ID = /^[a-f0-9]{32}$/;
const HASH = /^[a-f0-9]{64}$/;
const MAX_EDGE = 320;
const MAX_BYTES = 65536;

export function frameDimensions(width, height) {
	const scale = Math.min(1, MAX_EDGE / Math.max(1, width, height));
	return [
		Math.max(1, Math.round(width * scale)),
		Math.max(1, Math.round(height * scale)),
	];
}

/** Bounded image capture. Neither this function nor the thumbnail loader plays video. */
export function frameImage(video, document = video.ownerDocument) {
	if (video.readyState < 2 || video.videoWidth < 1 || video.videoHeight < 1)
		return null;
	const canvas = document.createElement("canvas");
	[canvas.width, canvas.height] = frameDimensions(video.videoWidth, video.videoHeight);
	const context = canvas.getContext("2d", { alpha: false });
	if (!context) return null;
	context.drawImage(video, 0, 0, canvas.width, canvas.height);
	const url = canvas.toDataURL("image/webp", 0.65);
	const prefix = "data:image/webp;base64,";
	if (!url.startsWith(prefix)) return null;
	const encoded = url.slice(prefix.length);
	return encoded.length <= Math.ceil(MAX_BYTES / 3) * 4 ? encoded : null;
}

/** A video field is a bounded island over its native form buttons and hidden identities. */
export function mountVideoField(field) {
	const document = field.ownerDocument;
	const choose = (event) => {
		const button = event.target.closest("[data-video-choose],[data-video-clear]");
		if (!button || !field.contains(button) || button.disabled) return;
		const kind = button.dataset.videoChoose ?? button.dataset.videoClear;
		if (!["video", "poster"].includes(kind)) return;
		const id = button.dataset.videoClear ? "" : button.dataset.mediaId;
		if (id !== "" && !ID.test(id)) return;
		event.preventDefault();
		const input = field.querySelector(`[data-video-value="${kind}"]`);
		input.value = id;
		const clear = field.querySelector(`[data-video-clear="${kind}"]`);
		if (clear) clear.hidden = !id;
		const name = field.querySelector(`[data-video-name="${kind}"]`);
		name.textContent = id ? button.dataset.mediaName : field.dataset.videoNone;
		const link = field.querySelector(`[data-video-link="${kind}"]`);
		if (link) {
			link.hidden = !id;
			if (id) link.href = `/media/${id}`;
		}
		const player = field.querySelector("[data-video-player]");
		const image = field.querySelector("[data-video-poster-preview]");
		if (kind === "video") {
			player.pause();
			player.hidden = !id;
			if (id) {
				// Assigning src respects preload=none; an explicit load() can fetch immediately.
				player.src = `/media/${id}/video`;
				player.setAttribute("aria-label", button.dataset.mediaName);
				player.width = Number(button.dataset.mediaWidth);
				player.height = Number(button.dataset.mediaHeight);
				player.dataset.mediaId = id;
				player.dataset.videoChecksum = button.dataset.videoChecksum;
				player.muted = true;
			} else {
				player.removeAttribute("src");
				delete player.dataset.mediaId;
				player.load();
			}
		} else {
			image.hidden = !id;
			if (id) {
				image.src = `/media/${id}/thumbnail`;
				player.poster = image.src;
			} else {
				image.removeAttribute("src");
				player.removeAttribute("poster");
			}
		}
		for (const error of field.querySelectorAll("[data-video-limit-error]"))
			error.hidden = true;
		input.dispatchEvent(new document.defaultView.Event("input", { bubbles: true }));
	};
	field.addEventListener("click", choose);
	return () => field.removeEventListener("click", choose);
}

/** One visible library tile at a time. Fields never enter the automatic capture queue. */
export function mountVideoPreviews(document) {
	const window = document.defaultView;
	for (const field of document.querySelectorAll("[data-video-reference]"))
		mountVideoField(field);
	const attempted = new Set();
	const queue = [];
	let active = false;
	let disposed = false;
	let temporary = null;
	const csrf = () => document.querySelector('input[name="_csrf"]')?.value;
	const save = async (video, id, checksum) => {
		const token = csrf();
		if (!token || !ID.test(id ?? "") || !HASH.test(checksum ?? "")) return;
		const key = `${id}:${checksum}`;
		if (attempted.has(key)) return;
		const image = frameImage(video, document);
		if (!image) return;
		attempted.add(key);
		const controller = new window.AbortController();
		const timer = window.setTimeout(() => controller.abort(), 10000);
		try {
			const response = await window.fetch(`/media/${id}/video-frame`, {
				method: "POST",
				credentials: "same-origin",
				headers: { "Content-Type": "application/x-www-form-urlencoded" },
				body: new window.URLSearchParams({
					_csrf: token,
					source: checksum,
					frame: image,
				}),
				signal: controller.signal,
			});
			if (!response.ok || disposed) return;
			for (const tile of document.querySelectorAll(
				`[data-video-thumbnail][data-media-id="${id}"]`,
			)) {
				const preview = tile.querySelector("[data-video-frame-image]");
				// The placeholder is hidden until load; lazy loading would wait for visibility.
				preview.loading = "eager";
				preview.addEventListener(
					"load",
					() => {
						preview.hidden = false;
						tile.querySelector("[data-video-frame-fallback]").hidden = true;
					},
					{ once: true },
				);
				preview.src = `/media/${id}/thumbnail`;
			}
		} finally {
			window.clearTimeout(timer);
		}
	};
	const capture = (source, id, checksum) =>
		new Promise((resolve) => {
			const video = document.createElement("video");
			temporary = video;
			video.muted = true;
			video.preload = "metadata";
			let finished = false;
			const finish = () => {
				if (finished) return;
				finished = true;
				window.clearTimeout(timer);
				video.pause();
				video.removeAttribute("src");
				video.load();
				temporary = null;
				resolve();
			};
			const take = async () => {
				if (finished || video.readyState < 2) return;
				try {
					await save(video, id, checksum);
				} catch {
					/* A missing hint keeps its neutral fallback. */
				}
				finish();
			};
			const timer = window.setTimeout(finish, 10000);
			video.addEventListener("error", finish, { once: true });
			video.addEventListener(
				"loadedmetadata",
				() => {
					if (Number.isFinite(video.duration) && video.duration > 0)
						video.currentTime = Math.min(0.1, video.duration / 2);
				},
				{ once: true },
			);
			video.addEventListener("loadeddata", take, { once: true });
			video.addEventListener("seeked", take, { once: true });
			video.src = source;
		});
	const drain = async () => {
		if (active || disposed) return;
		active = true;
		while (queue.length && !disposed) {
			const tile = queue.shift();
			const { mediaId: id, videoChecksum: checksum } = tile.dataset;
			if (
				!ID.test(id ?? "") ||
				!HASH.test(checksum ?? "") ||
				attempted.has(`${id}:${checksum}`)
			)
				continue;
			await capture(`/media/${id}/video`, id, checksum);
		}
		active = false;
	};
	const observer =
		typeof window.IntersectionObserver === "function"
			? new window.IntersectionObserver(
					(entries) => {
						for (const entry of entries) {
							if (!entry.isIntersecting) continue;
							observer.unobserve(entry.target);
							queue.push(entry.target);
						}
						drain().catch(() => {
							active = false;
							queue.length = 0;
						});
					},
					{ rootMargin: "100px" },
				)
			: null;
	for (const tile of document.querySelectorAll(
		'[data-video-thumbnail][data-video-capture="auto"]',
	)) {
		if (observer) observer.observe(tile);
		else queue.push(tile);
	}
	if (!observer)
		drain().catch(() => {
			active = false;
			queue.length = 0;
		});
	for (const player of document.querySelectorAll("[data-video-player]")) {
		const take = () => {
			save(player, player.dataset.mediaId, player.dataset.videoChecksum).catch(
				() => {},
			);
		};
		player.addEventListener("loadeddata", take);
		if (player.readyState >= 2) take();
	}
	window.addEventListener(
		"pagehide",
		() => {
			disposed = true;
			observer?.disconnect();
			queue.length = 0;
			if (temporary) {
				temporary.pause();
				temporary.removeAttribute("src");
				temporary.load();
			}
		},
		{ once: true },
	);
}
