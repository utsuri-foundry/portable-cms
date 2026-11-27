import { mountMediaDescriptions } from "./media-descriptions.js";
import { messages } from "./messages.js";

const STORAGE_PREFIX = "utsuri-portable-cms:media-upload:";
const JOB_POLL_ATTEMPTS = 90;

export function uploadFileMaximum(file, limits) {
	const video =
		file.type.startsWith("video/") || (!file.type && /\.(mp4|webm)$/i.test(file.name));
	return Number(video ? limits.videoMaxBytes : limits.imageMaxBytes);
}

function storageKey(recordId, file) {
	return `${STORAGE_PREFIX}${recordId}:${file.name}:${file.size}:${file.lastModified}`;
}

async function json(response, fallback) {
	const value = await response.json();
	if (!response.ok) {
		throw new Error(value.message || fallback);
	}
	return value;
}

function delay(milliseconds) {
	return new Promise((resolve) => setTimeout(resolve, milliseconds));
}

async function waitForJob(jobId, onStatus, attention) {
	for (let attempt = 0; attempt < JOB_POLL_ATTEMPTS; attempt += 1) {
		let response;
		try {
			response = await fetch(`/api/jobs/${encodeURIComponent(jobId)}`, {
				headers: { Accept: "application/json" },
			});
		} catch {
			return false;
		}
		if (!response.ok) return false;
		const value = await response.json();
		const job = value.job;
		onStatus(job.status);
		if (job.status === "completed" && job.mediaStatus === "ready") return true;
		if (job.status === "needs_attention") {
			throw new Error(attention);
		}
		await delay(1000);
	}
	return false;
}

async function uploadFile(panel, file, onProgress) {
	const recordType = panel.dataset.recordType;
	const recordId = panel.dataset.recordId;
	const revision = Number(panel.dataset.recordRevision);
	const csrf = panel.dataset.csrfToken;
	const key = storageKey(recordId, file);
	const clientUploadId = sessionStorage.getItem(key) || crypto.randomUUID();
	sessionStorage.setItem(key, clientUploadId);
	const commonHeaders = { "X-CSRF-Token": csrf };
	const incomplete = messages(panel)("upload.incomplete");
	const read = (response) => json(response, incomplete);
	const created = await read(
		await fetch(
			`/api/records/${encodeURIComponent(recordType)}/${encodeURIComponent(recordId)}/media-uploads`,
			{
				method: "POST",
				headers: { ...commonHeaders, "Content-Type": "application/json" },
				body: JSON.stringify({
					formatVersion: 1,
					expectedRevision: revision,
					filename: file.name,
					declaredType:
						file.type ||
						(/\.mp4$/i.test(file.name)
							? "video/mp4"
							: /\.webm$/i.test(file.name)
								? "video/webm"
								: ""),
					byteSize: file.size,
					clientUploadId,
				}),
			},
		),
	);
	let upload = created.upload;
	sessionStorage.setItem(`${key}:accepted`, upload.id);
	const completed = new Set(upload.completedParts.map((part) => part.partNumber));
	let uploadedBytes = upload.completedParts.reduce(
		(total, part) => total + part.byteSize,
		0,
	);
	onProgress(uploadedBytes, file.size);
	for (let partNumber = 1; partNumber <= upload.partCount; partNumber += 1) {
		if (completed.has(partNumber)) continue;
		const start = (partNumber - 1) * upload.partSize;
		const part = file.slice(
			start,
			Math.min(file.size, start + upload.partSize),
			file.type,
		);
		const value = await read(
			await fetch(
				`/api/media-uploads/${encodeURIComponent(upload.id)}/parts/${partNumber}`,
				{
					method: "PUT",
					headers: commonHeaders,
					body: part,
				},
			),
		);
		upload = value.upload;
		uploadedBytes += part.size;
		onProgress(uploadedBytes, file.size);
	}
	const completedValue = await read(
		await fetch(`/api/media-uploads/${encodeURIComponent(upload.id)}/complete`, {
			method: "POST",
			headers: commonHeaders,
		}),
	);
	// A replay after a partial field batch must name the same completed image.
	if (!panel.dataset.uploadField) {
		sessionStorage.removeItem(key);
		sessionStorage.removeItem(`${key}:accepted`);
	}
	return completedValue;
}

export async function uploadThenProcessBatch({
	files,
	upload,
	wait,
	onUploading = () => {},
	onQueued = () => {},
	onProcessing = () => {},
}) {
	const queued = [];
	for (let index = 0; index < files.length; index += 1) {
		const file = files[index];
		onUploading({ file, index, total: files.length });
		const completed = await upload(file, index);
		queued.push({ file, jobId: completed.job.id });
		onQueued({
			file,
			index,
			total: files.length,
			queuedCount: queued.length,
			completed,
		});
	}

	let readyCount = 0;
	const readiness = await Promise.all(
		queued.map(async ({ file, jobId }, index) => {
			const ready = await wait(jobId, (jobStatus) => {
				onProcessing({
					file,
					index,
					total: files.length,
					readyCount,
					jobStatus,
				});
			});
			if (ready) readyCount += 1;
			onProcessing({
				file,
				index,
				total: files.length,
				readyCount,
				jobStatus: ready ? "completed" : "queued",
			});
			return ready;
		}),
	);

	return {
		queuedCount: queued.length,
		readyCount,
		everyDerivativeReady: readiness.every(Boolean),
	};
}

/** Reuse the native form round trip so PHP applies field limits to the latest unsaved values. */
export function submitUploadSelection(panel, sessions, failed = false) {
	const form = panel.closest("[data-editorial-form]");
	if (!panel.dataset.uploadField || !form || sessions.length === 0) return false;
	const document = panel.ownerDocument;
	for (const id of sessions) {
		const input = document.createElement("input");
		input.type = "hidden";
		input.name = "_completed_uploads[]";
		input.value = id;
		form.append(input);
	}
	if (failed) {
		const input = document.createElement("input");
		input.type = "hidden";
		input.name = "_upload_failed";
		input.value = "1";
		form.append(input);
	}
	const submitter = document.createElement("button");
	submitter.type = "submit";
	submitter.name = "_editor_action";
	submitter.value = `uploaded-media:${panel.dataset.uploadField}`;
	submitter.formNoValidate = true;
	submitter.hidden = true;
	form.append(submitter);
	form.requestSubmit(submitter);
	return true;
}

/** One row per chosen file in the field's list, with its own progress, until the field takes the files. */
function uploadRows(panel, files) {
	const list = panel
		.closest("[data-reference-control]")
		?.querySelector(".admin-selections");
	if (!list) return null;
	const document = panel.ownerDocument;
	for (const row of list.querySelectorAll("[data-upload-row]")) row.remove();
	return files.map((file) => {
		const row = document.createElement("li");
		row.className = "admin-selection";
		row.dataset.uploadRow = "";
		const thumbnail = document.createElement("span");
		thumbnail.className = "admin-thumbnail admin-thumbnail--pending";
		thumbnail.setAttribute("aria-hidden", "true");
		thumbnail.textContent = "…";
		const text = document.createElement("span");
		text.textContent = file.name;
		const progress = document.createElement("progress");
		progress.className = "admin-progress";
		progress.max = 1;
		progress.value = 0;
		progress.setAttribute("aria-label", file.name);
		text.append(progress);
		row.append(thumbnail, text);
		list.append(row);
		return { row, text, progress, thumbnail };
	});
}

function failUploadRow(entry, message) {
	entry.progress.remove();
	entry.thumbnail.textContent = "!";
	const note = entry.row.ownerDocument.createElement("small");
	note.className = "admin-field__error";
	note.textContent = message;
	entry.text.append(note);
}

/** A waiting row takes the state the server reports: still preparing, ready or failed. */
export function showPreparation(row, item) {
	const state = row.querySelector("[data-media-state]");
	if (item.state === "preparing") {
		if (state && item.text) state.textContent = item.text;
		return;
	}
	delete row.dataset.mediaPending;
	const thumbnail = row.querySelector(".admin-thumbnail");
	if (item.state === "failed") {
		if (state) {
			state.textContent = item.text;
			state.className = "admin-field__error";
		}
		if (thumbnail) thumbnail.textContent = "!";
		// A file that could not be prepared needs no description.
		for (const node of row.parentElement
			?.closest("[data-reference-control]")
			?.querySelectorAll(
				`[data-media-description="${item.id}"], [data-description-warning="${item.id}"]`,
			) ?? [])
			node.hidden = true;
		return;
	}
	state?.remove();
	if (thumbnail && item.thumbnail) {
		const image = row.ownerDocument.createElement("img");
		image.className = "admin-thumbnail";
		image.src = item.thumbnail;
		image.width = 80;
		image.height = 64;
		image.alt = "";
		thumbnail.replaceWith(image);
	}
	// The ready image can now be kept with the record.
	const view = row.ownerDocument.defaultView ?? globalThis;
	row
		.querySelector("input[type=hidden]")
		?.dispatchEvent(new view.Event("change", { bubbles: true }));
}

/** Rows of files still being prepared ask where they stand until each is ready or failed. */
export function watchPreparation(
	root,
	{
		request = (url) => fetch(url, { headers: { Accept: "application/json" } }),
		schedule = setTimeout,
	} = {},
) {
	const waiting = () => [...root.querySelectorAll("[data-media-pending]")];
	let poll = 3;
	const check = async () => {
		const rows = waiting();
		if (rows.length === 0) return;
		const ids = [...new Set(rows.map((row) => row.dataset.mediaPending))];
		// One request asks about twelve files at most.
		for (let start = 0; start < ids.length; start += 12) {
			try {
				const response = await request(
					`/media/status?ids=${ids.slice(start, start + 12).join(",")}`,
				);
				if (!response.ok) continue;
				const value = await response.json();
				if (Number.isInteger(value.poll) && value.poll > 0) poll = value.poll;
				for (const item of value.items ?? [])
					for (const row of rows)
						if (row.dataset.mediaPending === item.id) showPreparation(row, item);
			} catch {
				/* The next check asks again. */
			}
		}
		if (waiting().length > 0) schedule(check, poll * 1000);
	};
	if (waiting().length > 0) schedule(check, poll * 1000);
	return check;
}

if (globalThis.document) watchPreparation(globalThis.document);

for (const panel of globalThis.document?.querySelectorAll("[data-media-upload]") ??
	[]) {
	const input = panel.querySelector("[data-media-upload-files]");
	const button = panel.querySelector("[data-media-upload-start]");
	const progress = panel.querySelector("[data-media-upload-progress]");
	const status = panel.querySelector("[data-media-upload-status]");
	const nativeButton = panel.querySelector("[data-native-upload-start]");
	const owner = panel.closest("[data-editorial-form]") ?? panel;
	const t = messages(panel);
	const descriptions = mountMediaDescriptions(panel);
	for (const card of panel.querySelectorAll("[data-description-job]")) {
		void waitForJob(
			card.dataset.descriptionJob,
			() => {},
			t("upload.derivative_attention"),
		)
			.then((ready) => {
				if (ready) descriptions.ready(card.dataset.mediaDescription);
			})
			.catch(() => {});
	}
	if (nativeButton) nativeButton.hidden = true;
	const choose = panel.querySelector("[data-media-upload-choose]");
	const disclosure = panel.closest(".admin-picker__upload");
	if (choose && disclosure) {
		// One step: the field's button opens the file chooser and choosing starts the upload.
		// Each file shows as a row of the field with its progress; failures say what to do.
		disclosure.parentElement.after(panel);
		disclosure.replaceWith(choose);
		panel.classList.add("admin-upload--direct");
		choose.hidden = false;
		choose.addEventListener("click", () => {
			if (!owner.hasAttribute("data-upload-busy")) input.click();
		});
		input.addEventListener("change", () => {
			if (input.files.length > 0) button.click();
		});
	} else if (button) button.hidden = false;
	const refuse = (message) => {
		status.classList.remove("admin-visually-hidden");
		status.textContent = message;
		(choose ?? input).focus();
		if (choose) input.value = "";
	};
	button.addEventListener("click", async () => {
		if (owner.hasAttribute("data-upload-busy")) return;
		const files = [...input.files];
		if (files.length > 8) return refuse(t("upload.limit"));
		if (files.length === 0) return refuse(t("upload.select"));
		for (const file of files) {
			const maximum = uploadFileMaximum(file, panel.dataset);
			const accepted = sessionStorage.getItem(
				`${storageKey(panel.dataset.recordId, file)}:accepted`,
			);
			if (!accepted && file.size > maximum)
				return refuse(
					t("upload.too_large", {
						name: file.name,
						limit: Math.round((maximum / 1048576) * 100) / 100,
					}),
				);
		}
		owner.setAttribute("data-upload-busy", "");
		const starts = [
			...owner.querySelectorAll(
				"[data-media-upload-start], [data-media-upload-choose]",
			),
		];
		for (const start of starts) start.disabled = true;
		input.disabled = true;
		const rows = choose ? uploadRows(panel, files) : null;
		// The rows show progress; the status line still narrates it to assistive technology.
		status.classList.toggle("admin-visually-hidden", rows !== null);
		if (!rows) {
			progress.hidden = false;
			progress.value = 0;
		}
		const sessions = [];
		try {
			const mediaByJob = new Map();
			const result = await uploadThenProcessBatch({
				files,
				upload: async (file, index) => {
					const completed = await uploadFile(panel, file, (uploaded, total) => {
						const value = total > 0 ? uploaded / total : 0;
						if (rows) rows[index].progress.value = value;
						else progress.value = value;
					});
					if (!panel.dataset.uploadField) await descriptions.add(completed.media);
					mediaByJob.set(completed.job.id, completed.media.id);
					return completed;
				},
				wait: async (jobId, onStatus) => {
					if (panel.dataset.uploadField) return false;
					const ready = await waitForJob(
						jobId,
						onStatus,
						t("upload.derivative_attention"),
					);
					if (ready) descriptions.ready(mediaByJob.get(jobId));
					return ready;
				},
				onUploading: ({ file, index, total }) => {
					status.textContent = t("upload.uploading", {
						position: index + 1,
						total,
						name: file.name,
					});
				},
				onQueued: ({ queuedCount, total, completed }) => {
					if (panel.dataset.uploadField) sessions.push(completed.upload.id);
					status.textContent = t("upload.queued", { count: queuedCount, total });
				},
				onProcessing: ({ total, readyCount, jobStatus }) => {
					status.textContent =
						jobStatus === "retry"
							? t("upload.processing_retry", { ready: readyCount, total })
							: t("upload.processing", { ready: readyCount, total });
				},
			});
			progress.value = 1;
			status.textContent = result.everyDerivativeReady
				? t.plural("upload.prepared", files.length)
				: t.plural("upload.pending", files.length);
			input.value = "";
			submitUploadSelection(panel, sessions);
		} catch (error) {
			// A dropped connection is a TypeError in the browser's own words; the server explains the rest.
			const reason =
				error instanceof Error && !(error instanceof TypeError)
					? error.message
					: t("upload.failed");
			status.textContent = rows ? `${reason} ${t("upload.again")}` : reason;
			// Files that did not finish say so in their own row; finished ones still join the field.
			for (const entry of rows?.slice(sessions.length) ?? [])
				failUploadRow(entry, `${reason} ${t("upload.again")}`);
			submitUploadSelection(panel, sessions, true);
		} finally {
			owner.removeAttribute("data-upload-busy");
			for (const start of starts) start.disabled = false;
			input.disabled = false;
			if (choose) {
				input.value = "";
				// Without a round trip, the way to try again keeps the focus.
				if (sessions.length === 0) choose.focus();
			}
		}
	});
}
