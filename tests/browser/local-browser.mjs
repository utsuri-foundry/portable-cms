import assert from "node:assert/strict";

/** A dedicated Chromium target on an explicitly local fictional sandbox. */
export async function connectLocalBrowser(origin, debug, onEvent = null) {
	for (const value of [origin, debug]) {
		const url = new URL(value);
		assert.ok(["localhost", "127.0.0.1"].includes(url.hostname));
		assert.equal(url.protocol, "http:");
	}
	const target = await fetch(`${debug}/json/new?about:blank`, { method: "PUT" }).then(
		(response) => response.json(),
	);
	const socket = new WebSocket(target.webSocketDebuggerUrl);
	await new Promise((resolve, reject) => {
		socket.addEventListener("open", resolve, { once: true });
		socket.addEventListener("error", reject, { once: true });
	});
	const pending = new Map();
	const browserErrors = [];
	let sequence = 0;
	/** A session ID addresses an attached target, such as an out-of-process frame. */
	function command(method, params = {}, sessionId = undefined) {
		return new Promise((resolve, reject) => {
			const id = ++sequence;
			// Navigation can queue behind fixture assets.
			const timeoutMs = method === "Page.navigate" ? 30000 : 10000;
			const timedOut = new Error(
				`Timed out: ${method}${params.url ? ` ${params.url}` : ""}`,
			);
			const timeout = setTimeout(() => {
				pending.delete(id);
				reject(timedOut);
			}, timeoutMs);
			pending.set(id, { resolve, reject, timeout });
			socket.send(
				JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }),
			);
		});
	}
	socket.addEventListener("message", ({ data }) => {
		const response = JSON.parse(data);
		if (response.method)
			onEvent?.(response.method, response.params, response.sessionId);
		if (response.method === "Runtime.exceptionThrown")
			browserErrors.push(response.params.exceptionDetails.text);
		if (
			response.method === "Log.entryAdded" &&
			response.params.entry.level === "error" &&
			!response.params.entry.url?.endsWith("/favicon.ico") &&
			!/status of (422|409)/.test(response.params.entry.text)
		)
			browserErrors.push(`${response.params.entry.text}: ${response.params.entry.url}`);
		if (response.method === "Page.javascriptDialogOpening")
			void command("Page.handleJavaScriptDialog", { accept: true });
		if (!response.id) return;
		const call = pending.get(response.id);
		if (!call) return;
		pending.delete(response.id);
		clearTimeout(call.timeout);
		if (response.error) call.reject(new Error(JSON.stringify(response.error)));
		else call.resolve(response.result);
	});
	async function evaluate(expression) {
		const result = await command("Runtime.evaluate", {
			expression,
			returnByValue: true,
			awaitPromise: true,
			userGesture: true,
		});
		if (result.exceptionDetails)
			throw new Error(
				result.exceptionDetails.exception?.description ?? "Browser evaluation failed",
			);
		return result.result.value;
	}
	async function readyDuringLoad(expression, timeoutMs = 30000) {
		const deadline = Date.now() + timeoutMs;
		while (Date.now() < deadline) {
			try {
				if (await evaluate(expression)) return;
			} catch {
				/* Navigation replaced the context. */
			}
			await new Promise((resolve) => setTimeout(resolve, 100));
		}
		let state;
		try {
			state = await evaluate(
				"({path:location.pathname,ready:document.readyState,revision:document.querySelector('[name=_revision]')?.value,heading:document.querySelector('h1')?.textContent,alert:document.querySelector('[role=alert]')?.textContent,invalid:[...document.querySelectorAll(':invalid')].map(e=>e.name)})",
			);
		} catch {
			state = "Navigation context unavailable";
		}
		throw new Error(
			`Page did not become ready: ${expression}; state=${JSON.stringify(state)}`,
		);
	}
	async function ready(expression, timeoutMs = 30000) {
		// An early field or summary can precede the native form's final guard.
		// Functional checks need the whole DOM; loading probes opt in explicitly.
		await readyDuringLoad(
			`document.readyState !== 'loading' && (${expression})`,
			timeoutMs,
		);
	}
	async function navigate(path, selector) {
		await evaluate(
			"document.documentElement.setAttribute('data-browser-reloading', '')",
		);
		await command("Page.navigate", { url: origin + path });
		await ready(
			`!document.documentElement.hasAttribute('data-browser-reloading') && location.pathname === ${JSON.stringify(path)} && document.querySelector(${JSON.stringify(selector)}) && document.readyState === 'complete'`,
		);
	}
	async function readyPage(expression) {
		await ready(`document.readyState === 'complete' && (${expression})`);
	}
	async function setFiles(selector, files) {
		const { result, exceptionDetails } = await command("Runtime.evaluate", {
			expression: `document.querySelector(${JSON.stringify(selector)})`,
		});
		assert.ok(!exceptionDetails && result.objectId, "File input must exist");
		try {
			// Use a runtime handle instead of the inspector's cached node IDs.
			await command("DOM.setFileInputFiles", { objectId: result.objectId, files });
		} finally {
			await command("Runtime.releaseObject", { objectId: result.objectId });
		}
	}
	async function reload() {
		// Reload acknowledges before navigation finishes. A complete old document
		// can already match the caller's readiness predicate, especially after disposal.
		await evaluate(
			"document.documentElement.setAttribute('data-browser-reloading', '')",
		);
		await command("Page.reload");
		await ready(
			"!document.documentElement.hasAttribute('data-browser-reloading') && document.readyState === 'complete'",
		);
	}
	async function close() {
		await command("Page.close");
		for (const call of pending.values()) clearTimeout(call.timeout);
		socket.close();
	}
	return {
		command,
		evaluate,
		ready,
		readyDuringLoad,
		setFiles,
		readyPage,
		navigate,
		reload,
		close,
		browserErrors,
	};
}
