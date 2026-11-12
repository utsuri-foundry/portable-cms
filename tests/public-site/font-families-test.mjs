import assert from "node:assert/strict";
import { launchBrowser } from "../../tools/quality/public-site/browser.mjs";
import { startStaticHost } from "../../tools/quality/public-site/static-host.mjs";

const [reference, fixture] = process.argv.slice(2);
assert.ok(reference && fixture);
const browser = await launchBrowser();
const hosts = [];
try {
	await browser.page.command("DOM.enable");
	await browser.page.command("CSS.enable");
	for (const [artifact, path, roman, italic, family] of [
		[reference, "/en/articles/measuring-shade/", "h1", ".studio-copy em", "cms-serif"],
		[fixture, "/", "#roman", "#italic", "cms-reading"],
	]) {
		const host = await startStaticHost(artifact);
		hosts.push(host);
		const loaded = new Promise((resolve, reject) => {
			const timer = setTimeout(
				() => reject(new Error("Font fixture load timed out")),
				15000,
			);
			const stop = browser.page.onEvent((event) => {
				if (event.method !== "Page.loadEventFired") return;
				clearTimeout(timer);
				stop();
				resolve();
			});
		});
		await browser.page.command("Page.navigate", { url: host.origin + path });
		await loaded;
		await browser.evaluate("document.fonts.ready.then(()=>true)");
		for (const [selector, style, postScript] of [
			[roman, "normal", "StudioSerif-Regular"],
			[italic, "italic", "StudioSerif-Italic"],
		]) {
			const facts = await browser.evaluate(
				`(()=>{const e=document.querySelector(${JSON.stringify(selector)});const s=getComputedStyle(e);return {family:s.fontFamily,style:s.fontStyle,synthesis:s.fontSynthesis,faces:[...document.fonts].filter(f=>f.family.includes(${JSON.stringify(family)})&&f.style===${JSON.stringify(style)}).map(f=>f.status)}})()`,
			);
			assert.ok(facts.family.includes(family));
			assert.equal(facts.style, style);
			assert.equal(facts.synthesis, "none");
			assert.deepEqual(facts.faces, ["loaded"]);
			const { root } = await browser.page.command("DOM.getDocument");
			const { nodeId } = await browser.page.command("DOM.querySelector", {
				nodeId: root.nodeId,
				selector,
			});
			const { fonts } = await browser.page.command("CSS.getPlatformFontsForNode", {
				nodeId,
			});
			assert.ok(
				fonts.some(
					(font) =>
						font.isCustomFont &&
						font.glyphCount > 0 &&
						font.postScriptName === postScript,
				),
				`real glyphs use ${postScript}, not a fallback or synthetic face: ${JSON.stringify(fonts)}`,
			);
		}
	}
	console.log(
		"PASS real roman/italic glyphs in Reference Studio and Reading Room, with synthesis disabled and generated family CSS.",
	);
} finally {
	await browser.close();
	for (const host of hosts) await host.close();
}
