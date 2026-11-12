import { spawnSync } from "node:child_process";
import { readFileSync } from "node:fs";

// One command inventory drives both local complete checks and isolated CI jobs.
const lanes = JSON.parse(
	readFileSync(new URL("./qualification.json", import.meta.url)),
);
const selected = process.argv[2];
if (selected === "list") {
	console.log(JSON.stringify(lanes.map((lane) => lane.id)));
} else {
	const work =
		selected === "all" ? lanes : lanes.filter((lane) => lane.id === selected);
	if (!work.length) throw new Error("Unknown qualification lane");
	for (const lane of work) {
		const started = Date.now();
		console.log(`Qualification ${lane.id}: starting`);
		for (const { argv, env } of lane.commands) {
			const result = spawnSync(argv[0], argv.slice(1), {
				stdio: "inherit",
				env: { ...process.env, ...env },
			});
			if (result.error || result.status !== 0) {
				console.error(
					`Qualification ${lane.id}: failed (${result.error?.message ?? result.signal ?? result.status})`,
				);
				process.exit(result.status || 1);
			}
		}
		console.log(
			`Qualification ${lane.id}: passed in ${Math.round((Date.now() - started) / 1000)}s`,
		);
	}
	if (selected === "all") console.log("Portable CMS repository check passed.");
}
