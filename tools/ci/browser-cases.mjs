import { spawnSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { pathToFileURL } from "node:url";

export const browserCases = JSON.parse(
	readFileSync(new URL("./browser-cases.json", import.meta.url)),
);

export function browserCaseForPath(path) {
	return Object.entries(browserCases).find(
		([, item]) => item.files.length === 1 && path === `tests/browser/${item.files[0]}`,
	)?.[0];
}

function run(argv, env = process.env) {
	const result = spawnSync(argv[0], argv.slice(1), {
		stdio: "inherit",
		env,
	});
	if (result.error) throw result.error;
	if (result.status !== 0) process.exit(result.status || 1);
}

if (
	process.argv[1] &&
	import.meta.url === pathToFileURL(resolve(process.argv[1])).href
) {
	const [, , mode, name] = process.argv;
	if (!["launch", "execute"].includes(mode) || !Object.hasOwn(browserCases, name))
		throw new Error("Unknown bounded browser case");
	const item = browserCases[name];
	if (mode === "launch") {
		run(["sh", item.harness ?? "tests/browser/run-editorial-journey.sh"], {
			...Object.fromEntries(
				Object.entries(process.env).filter(([key]) => !key.startsWith("CMS_TEST_")),
			),
			...item.env,
			...(item.harness ? {} : { CMS_TEST_CI_BROWSER_CASE: name }),
		});
	} else {
		if (item.harness) throw new Error("Case requires its own harness");
		if (name === "ai-assistance")
			run([
				"php",
				"tests/php/ai-mariadb-integration.php",
				process.env.CMS_BROWSER_WORKER_CONFIG,
			]);
		for (const file of item.files)
			run([process.execPath, `tests/browser/${file}`], {
				...process.env,
				...(name === "ai-assistance"
					? { CMS_BROWSER_AI_CONFIG: process.env.CMS_BROWSER_WORKER_CONFIG }
					: {}),
			});
	}
}
