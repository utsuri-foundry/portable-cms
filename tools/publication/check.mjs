import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { checkPublicDocumentation } from "./public-docs.mjs";
import { digest, git } from "./source.mjs";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "../..");
const bytes = (path) => readFileSync(resolve(root, path));
const json = (path) => JSON.parse(bytes(path).toString("utf8"));
checkPublicDocumentation(root);
assert.equal(
	digest(bytes("LICENSE")),
	"cfc7749b96f63bd31c3c42b5c471bf756814053e847c10f3eb003417bc523d30",
	"Retain complete official Apache 2.0 text",
);
assert.equal(json("composer.json").license, "Apache-2.0");
assert.match(bytes("NOTICE").toString(), /Copyright 2026 Utsuri Foundry, SL/);
assert.equal(
	digest(bytes("vendor/composer/LICENSE")),
	"7855ac293067aebe7e51afdd23b9dea54b8be24187dbecc9b9142581c37f596c",
	"Retain Composer autoload support's complete MIT licence",
);
const expected = {
	"opis/json-schema": "2.6.0",
	"opis/string": "2.1.0",
	"opis/uri": "1.1.0",
};
const packages = json("composer.lock").packages;
assert.deepEqual(
	Object.fromEntries(packages.map((pkg) => [pkg.name, pkg.version])),
	expected,
	"Review inventory when locked runtime graph changes",
);
for (const pkg of packages) {
	assert.deepEqual(pkg.license, ["Apache-2.0"]);
	assert.match(bytes(`vendor/${pkg.name}/LICENSE`).toString(), /Apache License/);
	assert.ok(bytes("THIRD_PARTY.md").includes(pkg.version));
}
for (const name of ["json-schema", "string"])
	assert.ok(
		bytes("NOTICE").includes(bytes(`vendor/opis/${name}/NOTICE`)),
		"Preserve complete upstream attribution",
	);
const assets = json("tools/publication/assets.json");
const mediaPaths = [
	...new Set(
		git(root, ["ls-files", "-z", "--cached", "--others", "--exclude-standard"])
			.toString("utf8")
			.split("\0")
			.filter((path) =>
				/\.(?:woff2?|ttf|otf|ttc|eot|webp|png|jpe?g|gif|avif|mp4|webm|svg)$/i.test(
					path,
				),
			),
	),
].sort();
assert.deepEqual(
	assets.map((asset) => asset.path).sort(),
	mediaPaths,
	"Every committed binary/media asset needs a reviewed licence and provenance",
);
for (const { path, sha256, license, provenance } of assets) {
	assert.equal(
		digest(bytes(path)),
		sha256,
		`Re-review asset licence and provenance: ${path}`,
	);
	assert.ok(["Apache-2.0", "OFL-1.1"].includes(license));
	assert.ok(bytes(provenance).length > 0);
}
assert.doesNotMatch(
	bytes("tools/check-repository.sh").toString(),
	/\btsuzuri\b/,
	"Public gate must not need private coordination",
);
console.log("Publication licence, dependency and asset inventory passed.");
