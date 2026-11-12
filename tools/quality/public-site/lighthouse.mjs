import { execFile } from "node:child_process";
import { promisify } from "node:util";

const run = promisify(execFile);
const CATEGORIES = ["performance", "accessibility", "best-practices", "seo"];

async function lighthouse(url, port, bar, categories) {
	const { stdout } = await run(
		"lighthouse",
		[
			url,
			`--port=${port}`,
			"--quiet",
			"--output=json",
			`--form-factor=${bar.formFactor}`,
			`--only-categories=${categories.join(",")}`,
		],
		{ maxBuffer: 256 * 1024 * 1024, timeout: 120_000 },
	);
	return JSON.parse(stdout);
}

const median = (values) =>
	[...values].sort((a, b) => a - b)[Math.floor(values.length / 2)];

/**
 * Audits one URL: all categories once, then extra performance runs for a stable median.
 * A page that deliberately asks not to be indexed is exempt from the crawlability audit
 * only; every other search audit still applies.
 */
export async function auditWithLighthouse(url, port, bar, { noindex = false } = {}) {
	const first = await lighthouse(url, port, bar, CATEGORIES);
	const performance = [first];
	for (let index = 1; index < bar.runs; index++) {
		performance.push(await lighthouse(url, port, bar, ["performance"]));
	}
	const findings = [];
	const scores = {};
	for (const category of CATEGORIES) {
		const runs = category === "performance" ? performance : [first];
		const score = median(runs.map((report) => report.categories[category]?.score ?? 0));
		scores[category] = score;
		const exempt = category === "seo" && noindex ? ["is-crawlable"] : [];
		const failing = runs[0].categories[category].auditRefs
			.filter((ref) => ref.weight > 0 && !exempt.includes(ref.id))
			.map((ref) => runs[0].audits[ref.id])
			.filter((audit) => audit && audit.score !== null && audit.score < 1)
			.map(
				(audit) => `${audit.id}${audit.displayValue ? ` (${audit.displayValue})` : ""}`,
			);
		if (
			score >= bar.categories[category] ||
			(exempt.length > 0 && failing.length === 0)
		)
			continue;
		findings.push(
			`Lighthouse ${category} ${Math.round(score * 100)} is below ${Math.round(bar.categories[category] * 100)}${failing.length ? `: ${failing.join(", ")}` : ""}`,
		);
	}
	const metrics = {};
	for (const [id, limit] of Object.entries(bar.metrics)) {
		const value = median(
			performance.map((report) => report.audits[id]?.numericValue ?? Infinity),
		);
		metrics[id] = value;
		if (value > limit)
			findings.push(`${id} ${Math.round(value * 1000) / 1000} exceeds ${limit}`);
	}
	return { scores, metrics, findings };
}
