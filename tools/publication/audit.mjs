import {
	lstatSync,
	readFileSync,
	readlinkSync,
	realpathSync,
	writeFileSync,
} from "node:fs";
import { dirname, resolve } from "node:path";
import { digest, git, objects, safePath } from "./source.mjs";

// Private input: [{id: "client-1", pattern: "...", flags: "i"}]. Never commit it.
// Reports contain evidence positions and hashes, not matched values.
try {
	if (process.argv.length !== 5)
		throw new Error(
			"Usage: node tools/publication/audit.mjs REPOSITORY PRIVATE_RULES_JSON NEW_PRIVATE_REPORT_JSON",
		);
	const [directory, rulesFile, reportFile] = process.argv.slice(2);
	const root = realpathSync(directory);
	for (const privatePath of [
		realpathSync(rulesFile),
		resolve(realpathSync(dirname(resolve(reportFile))), "audit-report"),
	]) {
		if (privatePath === root || privatePath.startsWith(`${root}/`))
			throw new Error("Keep audit inputs and reports outside the checkout");
	}
	const rulesBytes = readFileSync(rulesFile);
	const input = JSON.parse(rulesBytes.toString("utf8"));
	if (
		!Array.isArray(input) ||
		input.length === 0 ||
		input.some(
			(rule) =>
				!/^[a-z0-9-]+$/.test(rule.id) ||
				typeof rule.pattern !== "string" ||
				!rule.pattern ||
				!/^[imu]*$/.test(rule.flags ?? ""),
		)
	)
		throw new Error("Invalid private rules");
	const rules = input.map((rule) => ({
		id: rule.id,
		regex: new RegExp(rule.pattern, `${rule.flags ?? ""}g`),
	}));
	const findings = [];
	function inspect(kind, reference, bytes) {
		const text = bytes.toString("utf8");
		for (const { id, regex } of rules) {
			regex.lastIndex = 0;
			for (const hit of text.matchAll(regex))
				findings.push({
					kind,
					reference,
					rule: id,
					offset: hit.index,
					matchSha256: digest(hit[0]),
				});
		}
	}
	const refs = git(root, ["for-each-ref", "--format=%(refname) %(objectname)"])
		.toString("utf8")
		.trim()
		.split("\n")
		.filter(Boolean);
	const all = objects(root);
	inspect("refs", "all", Buffer.from(refs.join("\n")));
	for (const { oid, type, bytes } of all) inspect(type, oid, bytes);
	const tree = git(root, ["ls-files", "-z"])
		.toString("utf8")
		.split("\0")
		.filter(Boolean);
	for (const path of tree) {
		safePath(path);
		inspect("tracked-path", digest(path), Buffer.from(path));
		const target = resolve(root, path);
		inspect(
			"tracked-content",
			digest(path),
			lstatSync(target).isSymbolicLink()
				? Buffer.from(readlinkSync(target))
				: readFileSync(target),
		);
	}
	const counts = Object.fromEntries(
		["commit", "tree", "blob", "tag"].map((type) => [
			type,
			all.filter((object) => object.type === type).length,
		]),
	);
	const report = {
		schema: 1,
		ruleSetSha256: digest(rulesBytes),
		trackedFiles: tree.length,
		objects: counts,
		refs: refs.map((ref) => ({
			ref,
			disposition: "retain-private-archive; never copy to public snapshot",
		})),
		findings,
	};
	writeFileSync(reportFile, `${JSON.stringify(report, null, 2)}\n`, {
		mode: 0o600,
		flag: "wx",
	});
	console.log(
		JSON.stringify({
			...counts,
			trackedFiles: tree.length,
			refs: refs.length,
			findings: findings.length,
			ruleSetSha256: report.ruleSetSha256,
		}),
	);
} catch (error) {
	console.error(error.message);
	process.exitCode = 1;
}
