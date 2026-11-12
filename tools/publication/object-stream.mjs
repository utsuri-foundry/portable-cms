import { git, objects } from "./source.mjs";

// Feed every reachable object, including merge-only blobs and commit identities,
// into Gitleaks stdin. Keep all output in a private audit directory.
const root = process.argv[2];
if (!root) throw new Error("Repository argument required");
process.stdout.write(git(root, ["for-each-ref", "--format=%(refname) %(objectname)"]));
for (const { oid, type, bytes } of objects(root)) {
	process.stdout.write(`\nOBJECT ${oid} ${type}\n`);
	process.stdout.write(bytes);
}
