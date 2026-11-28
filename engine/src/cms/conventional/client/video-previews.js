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

/** A video as the browser reads it: duration and size, and its first frame as a full-size JPEG. */
export function inspectVideo(source, document, timeout = 10000) {
	const window = document.defaultView;
	return new Promise((resolve) => {
		const video = document.createElement("video");
		video.muted = true;
		video.playsInline = true;
		video.preload = "auto";
		let finished = false;
		const finish = (value) => {
			if (finished) return;
			finished = true;
			window.clearTimeout(timer);
			video.removeAttribute("src");
			video.load();
			resolve(value);
		};
		const timer = window.setTimeout(() => finish(null), timeout);
		video.addEventListener("error", () => finish(null), { once: true });
		video.addEventListener(
			"loadeddata",
			() => {
				const facts = {
					duration: Number.isFinite(video.duration) ? video.duration : null,
					width: video.videoWidth,
					height: video.videoHeight,
					frame: null,
				};
				const canvas = document.createElement("canvas");
				canvas.width = video.videoWidth;
				canvas.height = video.videoHeight;
				const context =
					canvas.width > 0 && canvas.height > 0
						? canvas.getContext("2d", { alpha: false })
						: null;
				if (!context) return finish(facts);
				try {
					context.drawImage(video, 0, 0, canvas.width, canvas.height);
					canvas.toBlob(
						(blob) => finish({ ...facts, frame: blob }),
						"image/jpeg",
						0.92,
					);
				} catch {
					finish(facts);
				}
			},
			{ once: true },
		);
		video.src = source;
	});
}

/** The first field limit a measured video exceeds, with the numbers to say it, or null. */
export function videoLimitProblem(facts, limits) {
	const measured = {
		// Containers round their duration; a twentieth of a second is not a longer video.
		duration: facts.duration === null ? null : facts.duration - 0.05,
		width: facts.width,
		height: facts.height,
	};
	for (const key of ["duration", "width", "height"]) {
		const limit = limits[key];
		const actual = measured[key];
		if (!Number.isInteger(limit) || !Number.isFinite(actual) || actual <= limit)
			continue;
		return {
			key,
			limit,
			actual: key === "duration" ? Math.round(facts.duration * 10) / 10 : actual,
		};
	}
	return null;
}

/** A video field is a bounded island over its native form buttons and hidden identities. */
export function mountVideoField(field) {
	const document = field.ownerDocument;
	const value = (kind) =>
		field.querySelector(`[data-video-value="${kind}"]`)?.value ?? "";
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
		// The row shows the chosen file with the thumbnail its library entry already carries.
		const row = field.querySelector(`[data-video-row="${kind}"]`);
		if (row) {
			row.hidden = !id;
			delete row.dataset.mediaPending;
			for (const state of row.querySelectorAll("[data-media-state]")) state.remove();
		}
		const thumb = field.querySelector(`[data-video-thumb="${kind}"]`);
		if (thumb) {
			const source = id ? button.querySelector(".admin-thumbnail") : null;
			thumb.replaceChildren(...(source ? [source.cloneNode(true)] : []));
		}
		// The choice closes; what is still missing is offered, and a full field offers nothing.
		button.closest("details")?.removeAttribute("open");
		const video = field.querySelector('[data-video-choosers="video"]');
		if (video) video.hidden = value("video") !== "";
		const poster = field.querySelector('[data-video-choosers="poster"]');
		if (poster) poster.hidden = value("video") === "" || value("poster") !== "";
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
