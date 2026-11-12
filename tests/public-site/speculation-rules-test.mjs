import assert from "node:assert/strict";
import { launchBrowser } from "../../tools/quality/public-site/browser.mjs";
import { startStaticHost } from "../../tools/quality/public-site/static-host.mjs";
import { waitForPrerenderActivation } from "./prerender-activation-poll.mjs";

const [artifact, noRulesArtifact] = process.argv.slice(2);
assert.ok(artifact && noRulesArtifact);
const host = await startStaticHost(artifact);
const plain = await startStaticHost(noRulesArtifact);
let browser;
const observations = [];
const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
async function until(check, label) {
	for (let attempt = 0; attempt < 150; attempt++) {
		const value = await check();
		if (value) return value;
		await delay(100);
	}
	throw new Error(`${label}: ${JSON.stringify(observations)}`);
}
try {
	const page = await fetch(`${host.origin}/en/`);
	assert.equal(page.status, 200);
	assert.equal(page.headers.get("speculation-rules"), '"/navigation-rules.json"');
	assert.ok(page.headers.get("content-security-policy").includes("script-src 'self';"));
	const rules = await fetch(`${host.origin}/navigation-rules.json`);
	assert.equal(rules.headers.get("content-type"), "application/speculationrules+json");
	assert.equal(rules.headers.get("speculation-rules"), null);
	const document = await rules.json();
	assert.equal(document.prerender[0].eagerness, "moderate");
	assert.equal((await fetch(plain.origin)).headers.get("speculation-rules"), null);
	browser = await launchBrowser({ tabTarget: true });
	browser.page.onEvent((event) => {
		if (event.method.startsWith("Preload.")) observations.push(event);
		if (event.method === "Target.attachedToTarget") observations.push(event);
	});
	await browser.page.command("Preload.enable");
	await browser.page.command("Page.navigate", { url: `${host.origin}/en/` });
	await until(
		() =>
			browser.evaluate(
				"document.readyState==='complete' && !!document.querySelector('a[data-preview-trigger]')",
			),
		"home is ready",
	);
	const link = await browser.evaluate(
		"(()=>{const a=document.querySelector('a[data-preview-trigger]');a.scrollIntoView({behavior:'instant',block:'center'});const r=a.getBoundingClientRect();const x=r.x+r.width/2,y=r.y+r.height/2;return {url:a.href,x,y,hit:document.elementFromPoint(x,y)?.closest('a')===a}})()",
	);
	assert.equal(link.hit, true, "pointer coordinates hit the declared anchor");
	observations.push({ link });
	await browser.page.command("Input.dispatchMouseEvent", {
		type: "mouseMoved",
		x: link.x,
		y: link.y,
	});
	const target = await until(async () => {
		return browser.page.targets().find((item) => item.subtype === "prerender");
	}, "declared link prerenders");
	await until(
		() =>
			observations.some(
				(event) =>
					event.method === "Preload.prerenderStatusUpdated" &&
					event.params.key.url === link.url &&
					event.params.status === "Ready",
			),
		"prerender becomes ready",
	);
	const { sessionId } = await browser.page.command("Target.attachToTarget", {
		targetId: target.targetId,
		flatten: true,
	});
	assert.ok(sessionId, "the prerender target is attached through the tab");
	const before = await until(async () => {
		const result = await browser.page.command(
			"Runtime.evaluate",
			{
				expression: `document.readyState==='complete' && location.href===${JSON.stringify(link.url)} ? {prerendering:document.prerendering, path:location.pathname} : null`,
				returnByValue: true,
			},
			sessionId,
		);
		return result.result.value;
	}, "prerendered page finishes loading");
	assert.equal(before.prerendering, true, "page really renders before activation");
	await browser.page.command(
		"Runtime.evaluate",
		{
			expression:
				"window.__prerenderObserved=document.prerendering;document.addEventListener('prerenderingchange',()=>{window.__prerenderActivated=true},{once:true})",
		},
		sessionId,
	);
	for (const type of ["mousePressed", "mouseReleased"]) {
		await browser.page.command("Input.dispatchMouseEvent", {
			type,
			x: link.x,
			y: link.y,
			button: "left",
			clickCount: 1,
		});
	}
	await waitForPrerenderActivation(
		() =>
			browser.evaluate(
				`location.href===${JSON.stringify(link.url)} && window.__prerenderActivated===true`,
			),
		observations,
	);
	assert.equal(
		await browser.evaluate("window.__prerenderObserved && !document.prerendering"),
		true,
	);
	assert.ok(
		await browser.evaluate(
			"performance.getEntriesByType('navigation')[0].activationStart>0",
		),
		"navigation is an activation rather than a new load",
	);
	process.stdout.write(
		"PASS speculation rules: strict Apache headers/MIME, absent-feature page, real Chromium prerendering and prerenderingchange activation.\n",
	);
} catch (error) {
	process.stderr.write(
		`Speculation observations: ${JSON.stringify(observations.slice(-20))}\n`,
	);
	throw error;
} finally {
	await browser?.close();
	await plain.close();
	await host.close();
}
