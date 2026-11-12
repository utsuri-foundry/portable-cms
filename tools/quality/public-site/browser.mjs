import { spawn } from "node:child_process";
import { mkdtemp, rm } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { freePort } from "./static-host.mjs";

const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** Connects a minimal Chrome DevTools Protocol client to one page target. */
async function connect(url) {
	const socket = new WebSocket(url);
	await new Promise((resolve, reject) => {
		socket.addEventListener("open", resolve, { once: true });
		socket.addEventListener("error", reject, { once: true });
	});
	let next = 0;
	const pending = new Map();
	const listeners = new Set();
	socket.addEventListener("message", (event) => {
		const message = JSON.parse(event.data);
		if (message.id === undefined) {
			for (const listener of listeners) listener(message);
			return;
		}
		const call = pending.get(message.id);
		if (!call) return;
		pending.delete(message.id);
		if (message.error)
			call.reject(new Error(`${call.method}: ${JSON.stringify(message.error)}`));
		else call.resolve(message.result);
	});
	return {
		command(method, params = {}, sessionId) {
			return new Promise((resolve, reject) => {
				const id = ++next;
				pending.set(id, { resolve, reject, method });
				socket.send(
					JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }),
				);
			});
		},
		onEvent(listener) {
			listeners.add(listener);
			return () => listeners.delete(listener);
		},
		close() {
			socket.close();
		},
	};
}

/**
 * Starts one private headless Chromium for the audit. Lighthouse attaches to the same
 * debugging port; behaviour checks drive a page through the returned client.
 */
export async function launchBrowser({ tabTarget = false } = {}) {
	const profile = await mkdtemp(join(tmpdir(), "portable-cms-audit-browser-"));
	const port = await freePort();
	const child = spawn(
		process.env.CHROME_PATH || "chromium",
		[
			"--headless=new",
			"--no-sandbox",
			"--disable-gpu",
			"--no-first-run",
			"--no-default-browser-check",
			`--remote-debugging-port=${port}`,
			`--user-data-dir=${profile}`,
			"about:blank",
		],
		{ stdio: "ignore" },
	);
	try {
		let target;
		for (let attempt = 0; attempt < 150 && !target; attempt++) {
			try {
				const list = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
				target = list.find((entry) => entry.type === "page");
			} catch {
				await delay(100);
			}
		}
		if (!target) throw new Error("Chromium did not start");
		let page = await connect(target.webSocketDebuggerUrl);
		if (tabTarget) {
			// Direct page attachment disables prerendering in Chromium. A tab session
			// follows the primary frame tree when a prerendered page is activated.
			page.close();
			const version = await (
				await fetch(`http://127.0.0.1:${port}/json/version`)
			).json();
			const protocol = await connect(version.webSocketDebuggerUrl);
			let primary;
			const sessions = new Map();
			const targets = new Map();
			protocol.onEvent((event) => {
				if (event.method === "Target.attachedToTarget") {
					const { sessionId, targetInfo } = event.params;
					sessions.set(targetInfo.targetId, sessionId);
					targets.set(targetInfo.targetId, targetInfo);
					if (targetInfo.type === "page") {
						if (!targetInfo.subtype) primary = sessionId;
					}
				}
				if (event.method === "Target.targetInfoChanged") {
					const info = event.params.targetInfo;
					if (sessions.has(info.targetId)) targets.set(info.targetId, info);
					if (info.type === "page" && !info.subtype && sessions.has(info.targetId)) {
						primary = sessions.get(info.targetId);
					}
				}
			});
			const { targetInfos } = await protocol.command("Target.getTargets", {
				filter: [{}],
			});
			const tab = targetInfos.find((entry) => entry.type === "tab");
			if (!tab) throw new Error("Chromium did not expose its tab target");
			const { sessionId } = await protocol.command("Target.attachToTarget", {
				targetId: tab.targetId,
				flatten: true,
			});
			await protocol.command(
				"Target.setAutoAttach",
				{ autoAttach: true, waitForDebuggerOnStart: false, flatten: true },
				sessionId,
			);
			for (let attempt = 0; !primary && attempt < 100; attempt++) await delay(50);
			if (!primary) throw new Error("Chromium did not attach its primary page");
			page = {
				command(method, params = {}, explicitSession) {
					return protocol.command(
						method,
						params,
						explicitSession ?? (method.startsWith("Target.") ? undefined : primary),
					);
				},
				onEvent: protocol.onEvent,
				sessionFor: (targetId) => sessions.get(targetId),
				targets: () => [...targets.values()],
				close: protocol.close,
			};
		}
		await page.command("Page.enable");
		await page.command("Runtime.enable");
		await page.command("Log.enable");
		await page.command("Network.enable");
		return {
			port,
			page,
			async evaluate(expression) {
				const result = await page.command("Runtime.evaluate", {
					expression,
					returnByValue: true,
					awaitPromise: true,
				});
				if (result.exceptionDetails) throw new Error(result.exceptionDetails.text);
				return result.result.value;
			},
			async close() {
				page.close();
				const exited = new Promise((resolve) => child.once("exit", resolve));
				child.kill();
				await Promise.race([exited, delay(5000)]);
				await rm(profile, {
					recursive: true,
					force: true,
					maxRetries: 5,
					retryDelay: 200,
				});
			},
		};
	} catch (error) {
		child.kill();
		await rm(profile, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 });
		throw error;
	}
}
