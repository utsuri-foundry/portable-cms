const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** Page facts gathered in the browser after load. */
const FACTS = `(() => {
	const main = document.querySelector("main");
	const urls = new Set();
	for (const element of document.querySelectorAll("a[href], link[href], area[href]")) urls.add(element.href);
	for (const element of document.querySelectorAll("[src]")) urls.add(element.src);
	for (const element of document.querySelectorAll("[srcset]")) {
		for (const candidate of element.srcset.split(",")) {
			const url = candidate.trim().split(/\\s+/)[0];
			if (url) urls.add(new URL(url, document.baseURI).href);
		}
	}
	const fragments = [...document.querySelectorAll('a[href^="#"]')]
		.map((link) => decodeURIComponent(link.getAttribute("href").slice(1)))
		.filter((id) => id !== "" && !document.getElementById(id));
	const motion = document.getAnimations().filter((animation) => {
		const timing = animation.effect?.getComputedTiming?.();
		return animation.playState === "running" && (timing?.duration ?? 0) > 10;
	}).length;
	return {
		text: (main?.innerText ?? "").trim().length,
		heading: !!document.querySelector("h1"),
		urls: [...urls],
		fragments,
		motion,
		smoothScroll: getComputedStyle(document.documentElement).scrollBehavior === "smooth",
	};
})()`;

async function visit(browser, url, scripts) {
	const { page } = browser;
	const problems = [];
	const remove = page.onEvent((message) => {
		const params = message.params ?? {};
		if (message.method === "Runtime.exceptionThrown") {
			problems.push(
				`Script error: ${params.exceptionDetails?.exception?.description ?? params.exceptionDetails?.text}`,
			);
		} else if (
			message.method === "Runtime.consoleAPICalled" &&
			params.type === "error"
		) {
			problems.push(
				`Console error: ${params.args?.map((arg) => arg.value ?? arg.description).join(" ")}`,
			);
		} else if (message.method === "Log.entryAdded" && params.entry?.level === "error") {
			problems.push(
				`Console error: ${params.entry.text}${params.entry.url ? ` (${params.entry.url})` : ""}`,
			);
		} else if (
			message.method === "Network.responseReceived" &&
			params.response?.status >= 400
		) {
			problems.push(`HTTP ${params.response.status} for ${params.response.url}`);
		}
	});
	try {
		await page.command("Emulation.setScriptExecutionDisabled", { value: !scripts });
		const loaded = new Promise((resolve, reject) => {
			const timer = setTimeout(
				() => reject(new Error(`Timed out loading ${url}`)),
				30_000,
			);
			const stop = page.onEvent((message) => {
				if (message.method !== "Page.loadEventFired") return;
				clearTimeout(timer);
				stop();
				resolve();
			});
		});
		await page.command("Page.navigate", { url });
		await loaded;
		await browser.evaluate("document.fonts.ready.then(() => true)");
		// Let scripts finish deferred work, so content they add late is measured too.
		if (scripts) await delay(1000);
		return { facts: await browser.evaluate(FACTS), problems: [...new Set(problems)] };
	} finally {
		remove();
	}
}

/**
 * Loads each page with reduced motion requested, with and without scripts. Reports script
 * and console errors, failed requests, running motion, missing fragment targets and
 * content that only exists with JavaScript, and returns every referenced URL.
 */
export async function auditBehaviour(browser, origin, paths, bar) {
	await browser.page.command("Emulation.setEmulatedMedia", {
		features: [{ name: "prefers-reduced-motion", value: "reduce" }],
	});
	await browser.page.command("Emulation.setDeviceMetricsOverride", {
		width: 390,
		height: 844,
		deviceScaleFactor: 1,
		mobile: true,
	});
	const findings = new Map();
	const urls = new Set();
	for (const path of paths) {
		const url = origin + path;
		const list = [];
		const enhanced = await visit(browser, url, true);
		list.push(...enhanced.problems);
		if (enhanced.facts.motion > 0)
			list.push(
				`${enhanced.facts.motion} animation(s) run although reduced motion is requested`,
			);
		if (enhanced.facts.smoothScroll)
			list.push("Smooth scrolling ignores the reduced motion preference");
		for (const id of enhanced.facts.fragments)
			list.push(`Link to #${id} has no target`);
		for (const reference of enhanced.facts.urls) urls.add(reference);
		const plain = await visit(browser, url, false);
		if (!plain.facts.heading)
			list.push("Without JavaScript the page has no main heading");
		if (plain.facts.text < enhanced.facts.text * bar.noScript.minimumTextRatio) {
			list.push(
				`Without JavaScript the main content shrinks from ${enhanced.facts.text} to ${plain.facts.text} characters`,
			);
		}
		if (list.length) findings.set(path, list);
	}
	return { findings, urls };
}

/**
 * Requests every referenced URL that belongs to the site, including canonical and
 * alternate links written for the production base URL, and reports any that fail.
 */
export async function auditLinks(origin, baseUrl, urls) {
	const local = new Set();
	for (const reference of urls) {
		let url;
		try {
			url = new URL(reference);
		} catch {
			continue;
		}
		url.hash = "";
		const text = url.href;
		if (text.startsWith(`${origin}/`)) local.add(text);
		else if (baseUrl && text.startsWith(`${baseUrl}/`))
			local.add(origin + text.slice(baseUrl.length));
	}
	const broken = [];
	for (const url of [...local].sort()) {
		const response = await fetch(url, { redirect: "follow" });
		await response.arrayBuffer();
		if (!response.ok)
			broken.push(`${url.slice(origin.length)} answers ${response.status}`);
	}
	return broken;
}
