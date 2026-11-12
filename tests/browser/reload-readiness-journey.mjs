import assert from "node:assert/strict";
import { createServer } from "node:http";
import { resolve } from "node:path";
import { connectLocalBrowser } from "./local-browser.mjs";

// The response tail is released by an observed browser state, never a timer.
const head = `<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Reload fixture</title></head><body>${"<p>Reload fixture</p>".repeat(64)}<div data-validation-summary>Check the year</div><form method="post"><input name="title" value="Native input"><button type="submit">Add block</button>`;
const tail =
	'<input name="year" value="1800"><input type="file" name="attachment"><input type="hidden" name="_editorial_end" value="1"></form></body></html>';
let requests = 0;
let release;
let submitted;
const server = createServer((request, response) => {
	if (request.url !== "/") {
		response.writeHead(204).end();
		return;
	}
	response.setHeader("Content-Type", "text/html; charset=utf-8");
	if (request.method === "POST") {
		let body = "";
		request.on("data", (chunk) => {
			body += chunk;
		});
		request.on("end", () => {
			submitted = new URLSearchParams(body).get("_editorial_end");
			response.writeHead(submitted === "1" ? 200 : 400);
			response.end(
				`<h1>${submitted === "1" ? "Complete form" : "Invalid request"}</h1>`,
			);
		});
		return;
	}
	requests++;
	if (requests > 1) {
		release = () => {
			if (!response.writableEnded) response.end(tail);
		};
		response.write(head);
		return;
	}
	response.end(head + tail);
});
await new Promise((resolve) => server.listen(0, "127.0.0.1", resolve));
const origin = `http://127.0.0.1:${server.address().port}`;
let browser;
try {
	browser = await connectLocalBrowser(origin, process.env.CMS_BROWSER_DEBUG_URL);
	const { command, evaluate, navigate, ready, readyDuringLoad, reload, setFiles } =
		browser;
	await command("Page.enable");
	await command("Runtime.enable");
	await navigate("/", "form");
	await command("Emulation.setScriptExecutionDisabled", { value: true });
	// The old page already satisfies the predicate that used to follow Page.reload.
	assert.equal(
		await evaluate(
			"document.readyState === 'complete' && !!document.querySelector('form')",
		),
		true,
	);
	for (const transition of [reload, () => navigate("/", "form")]) {
		let completed = false;
		const moving = transition().then(() => {
			completed = true;
		});
		await readyDuringLoad(
			"document.readyState === 'loading' && document.querySelector('[data-validation-summary]') && !document.querySelector('[name=year]')",
		);
		let pageCompleted = false;
		const page = ready("document.querySelector('[data-validation-summary]')").then(
			() => {
				pageCompleted = true;
			},
		);
		assert.equal(await evaluate("document.readyState"), "loading");
		assert.equal(
			completed,
			false,
			"navigation must await the replacement response tail",
		);
		assert.equal(
			pageCompleted,
			false,
			"an early validation summary is not a complete form",
		);
		assert.equal(typeof release, "function");
		release();
		await Promise.all([moving, page]);
		assert.equal(await evaluate("document.querySelector('[name=year]').value"), "1800");
	}
	assert.equal(
		await evaluate("document.querySelector('[name=_editorial_end]').value"),
		"1",
	);
	const file = resolve(
		"profiles/reference-studio/fixtures/media/d0000000000000000000000000000001-display_1600_webp.webp",
	);
	const { root } = await command("DOM.getDocument");
	const { nodeId } = await command("DOM.querySelector", {
		nodeId: root.nodeId,
		selector: "[name=attachment]",
	});
	await command("DOM.disable");
	await command("DOM.enable");
	await assert.rejects(
		command("DOM.setFileInputFiles", { nodeId, files: [file] }),
		/node/i,
	);
	await setFiles("[name=attachment]", [file]);
	assert.equal(
		await evaluate("document.querySelector('[name=attachment]').files.length"),
		1,
	);
	await evaluate("document.querySelector('button').click()");
	await ready(
		"document.readyState === 'complete' && document.querySelector('h1')?.textContent === 'Complete form'",
	);
	assert.equal(submitted, "1", "the native submission includes the complete form");
	console.log(
		"PASS browser reload waits for a replacement document and its streamed form tail with scripts disabled.",
	);
} finally {
	release?.();
	try {
		await browser?.close();
	} finally {
		server.closeAllConnections();
		await new Promise((resolve) => server.close(resolve));
	}
}
