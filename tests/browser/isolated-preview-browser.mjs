import assert from "node:assert/strict";

/** Inspector access proves behavior inside a frame that the CMS document cannot read. */
export async function isolatedPreviewBrowser(browser, source) {
	let target;
	for (let attempt = 0; attempt < 100 && !target; attempt++) {
		const { targetInfos } = await browser.command("Target.getTargets");
		target = targetInfos.find((info) => info.type === "iframe" && info.url === source);
		if (!target) await new Promise((resolve) => setTimeout(resolve, 100));
	}
	assert.ok(target, "the preview runs as an isolated frame target");
	const { sessionId } = await browser.command("Target.attachToTarget", {
		targetId: target.targetId,
		flatten: true,
	});
	const command = (method, params = {}) => browser.command(method, params, sessionId);
	async function evaluate(expression) {
		const result = await command("Runtime.evaluate", {
			expression,
			returnByValue: true,
			awaitPromise: true,
			userGesture: true,
		});
		if (result.exceptionDetails)
			throw new Error(JSON.stringify(result.exceptionDetails));
		return result.result.value;
	}
	async function ready(expression) {
		for (let attempt = 0; attempt < 300; attempt++) {
			if (await evaluate(expression)) return;
			await new Promise((resolve) => setTimeout(resolve, 100));
		}
		const state = await evaluate(
			"({title:document.title,heading:document.querySelector('h1')?.textContent,ready:document.readyState})",
		);
		assert.fail(
			`the isolated preview did not become ready: ${expression}; ${JSON.stringify(state)}`,
		);
	}
	return {
		command,
		evaluate,
		ready,
		close: () => browser.command("Target.detachFromTarget", { sessionId }),
	};
}
