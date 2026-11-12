import { spawnSync } from "node:child_process";
import { createHash } from "node:crypto";

export const digest = (bytes) => createHash("sha256").update(bytes).digest("hex");
export function run(command, args, cwd, input, extraEnv = {}) {
	const env = Object.fromEntries(
		Object.entries(process.env).filter(([key]) => !key.startsWith("GIT_")),
	);
	const result = spawnSync(command, args, {
		cwd,
		input,
		env: {
			...env,
			GIT_CONFIG_NOSYSTEM: "1",
			GIT_CONFIG_GLOBAL: "/dev/null",
			...extraEnv,
		},
		maxBuffer: 256 * 1024 * 1024,
	});
	if (result.error || result.status !== 0) {
		throw new Error(
			`${command} failed (exit ${result.status}); inspect private command diagnostics`,
		);
	}
	return result.stdout;
}
export const git = (root, args, input, env) => run("git", args, root, input, env);
export function safePath(path) {
	if (
		!path ||
		path.startsWith("/") ||
		path.includes("\\") ||
		Array.from(path).some(
			(character) => character.codePointAt(0) < 32 || character.codePointAt(0) === 127,
		) ||
		path
			.split("/")
			.some(
				(part) =>
					!part || part === "." || part === ".." || part.toLowerCase() === ".git",
			)
	) {
		throw new Error("Unsafe publication path");
	}
	return path;
}
export function entries(root, revision) {
	return git(root, ["ls-tree", "-rz", "--full-tree", revision])
		.toString("utf8")
		.split("\0")
		.filter(Boolean)
		.map((record) => {
			const tab = record.indexOf("\t");
			const [mode, type, oid] = record.slice(0, tab).split(" ");
			const path = safePath(record.slice(tab + 1));
			if (type !== "blob" || !["100644", "100755"].includes(mode))
				throw new Error("Links and submodules require a separate publication review");
			return { path, mode, oid };
		});
}
export function objects(root) {
	const ids = git(root, [
		"rev-list",
		"--objects",
		"--no-object-names",
		"--all",
	]).toString("utf8");
	const data = git(root, ["cat-file", "--batch"], ids);
	const result = [];
	let offset = 0;
	while (offset < data.length) {
		const end = data.indexOf(10, offset);
		if (end < offset) throw new Error("Invalid Git object framing");
		const [oid, type, rawSize] = data
			.subarray(offset, end)
			.toString("ascii")
			.split(" ");
		const size = Number(rawSize);
		if (
			!/^[a-f0-9]{40,64}$/.test(oid) ||
			!["commit", "tree", "blob", "tag"].includes(type) ||
			!Number.isSafeInteger(size) ||
			size < 0 ||
			end + size + 1 >= data.length
		)
			throw new Error("Invalid Git object header");
		result.push({ oid, type, bytes: data.subarray(end + 1, end + 1 + size) });
		offset = end + 1 + size + 1;
	}
	return result;
}
