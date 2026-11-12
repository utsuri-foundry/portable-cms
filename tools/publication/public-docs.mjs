import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { publicDocument } from "./documentation.mjs";
import { git } from "./source.mjs";

const publication = `# Source release verification

Portable CMS is distributed under Apache License 2.0. See
[provenance](../../PROVENANCE.md), [third-party material](../../THIRD_PARTY.md)
and [the licensing decision](../architecture/decisions/0010-apache-2-publication.md).

## Review source and rights

Review every new dependency, media asset and font for its source, licence,
modifications and required notices. The locked dependency graph and
\`tools/publication/assets.json\` are checked by the repository gate. Keep client
content, originals, credentials, installation configuration and local state out
of source commits. Secret scanners support review; they cannot prove absence.

## Qualify the exact commit

Use the public pinned toolchain and locked dependencies:

\`\`\`sh
nix develop -c composer install --no-interaction --prefer-dist
nix develop -c tools/check-fast.sh
nix develop -c tools/check-repository.sh
nix develop -c gitleaks git --log-opts='--all --full-history' --redact=100 --ignore-gitleaks-allow --no-banner .
\`\`\`

PRs require fast and affected checks. Release and source-publication review
requires complete qualification for the exact commit, including database,
public-site and browser lanes. Record the commit and successful hosted run;
neither a local run nor a short PR gate supplies that external full proof.
See [testing policy](testing.md) and [contribution guidance](../../CONTRIBUTING.md).

## Provenance and activation

\`PUBLICATION.json\` describes bytes and modes in the initial source import,
excluding itself. It is historical provenance; later contributions do not
regenerate it. Current qualification belongs to the commit under review.

Source verification does not deploy a CMS or change repository visibility.
Repository owners approve release publication and verify branch protection,
security reporting and anonymous access. Installed-site upgrades and content
publication use their separate documented operator workflows. Report security
issues through [SECURITY.md](../../SECURITY.md).
`;

const audit = `# Source review scope

The source import uses fresh history, as recorded in
[ADR 0010](../architecture/decisions/0010-apache-2-publication.md).
Original development records and sensitive audit evidence are not distributed.
This document describes review boundaries, not a current CI result or a
certificate for later commits.

Review covers tracked text and paths, reachable Git objects and metadata,
credentials, client and account identities, private operational material,
licences and binary provenance. Public standards and upstream attribution,
reserved fixture addresses and fictional Reference Studio content are retained.
No client publication permission is assumed. Identity rules, matched private
values and source-to-import mappings stay outside the distributed source.

Gitleaks scans source history without a fixture baseline or inline suppression.
Identity and rights review remains necessary alongside scanner results.
The repository gate checks the exact locked runtime graph and every admitted
media asset against its reviewed licence and provenance inventory. Imported
fonts retain OFL terms; upstream runtime licences and notices stay in bundles.
See [THIRD_PARTY.md](../../THIRD_PARTY.md).

Public documentation must describe the public contributor workflow. The gate
rejects internal coordination references and links to undistributed records.
Public architecture and quality exception registries stay empty; contributors
resolve policy failures rather than depend on unavailable removal records.

\`PUBLICATION.json\` identifies the initial imported bytes and modes. Subsequent
changes need their own review and exact-commit CI evidence. See
[source verification](publication.md), [testing](testing.md) and
[security reporting](../../SECURITY.md). Detailed operational inventories and
cutover status are not maintained as public documentation.
`;

const hostAcceptance = `# Installation acceptance checks

Use this procedure to verify a specific installation before handing it over or
relying on it for publication. Passing on one account does not qualify every
plan from that provider. Keep host identities, credentials, configuration,
backups and detailed evidence in private operator storage.

## Prepare the installation

1. Follow the [host qualification guide](conventional-host-probe.md) using the
   actual web PHP and scheduled CLI environments. Check private storage, required
   extensions, transactional database behaviour, HTTPS and the worker schedule.
   Qualify optional capabilities separately.
2. Install the independently verified engine and profile package using the
   [installation and identity guide](conventional-cms-identity.md). Verify the
   deployed files against the bundle manifest, apply forward migrations and
   create the owner account. Remove temporary installer and probe entrypoints.
3. Preserve and test the installation's recovery path before changing an
   existing site. A complete backup includes the database, private storage,
   configuration and installed engine/profile packages. A content export alone
   does not replace that backup.

## Check editing and publication

- Use fictional acceptance content on an isolated test installation. Exercise
  sign-in, content validation, translations, media processing and the actual
  scheduled worker. Verify disabled capabilities do not leave work stuck.
- Build an [exact preview](exact-website-preview.md). Check representative pages,
  languages, media and deep navigation; verify anonymous requests cannot read
  private previews, originals or configuration.
- On an authorised test target, approve a release through the ordinary
  [publication workflow](publication-workflow.md). Verify served files against
  its artifact manifest and check discovery metadata, redirects and delivery
  headers over HTTPS. Database activation alone is not proof of correct delivery.
- Exercise retained-release rollback and interrupted-publication recovery on
  the isolated target. Standard delivery may expose mixed approved bytes during
  recovery; only claim Atomic delivery when that capability has been qualified.

## Check export and restore

Follow the [handover procedure](portable-handover.md) and its
[capacity limits](export-capacity.md#supported-envelope). Use a distinct empty
destination with independently configured accounts, secrets, URLs and a
compatible engine/profile package. Do not reuse or reset an occupied target.

Run the read-only restore plan, test bounded interruption and resume, and check
that repeating completion is idempotent. Compare restored content, retained
revisions, relationships and media against the captured export, then build a
fresh destination preview. Compare against that frozen export, not later edits
made to the source. Source accounts and publication authority are not imported.

## Record and maintain the result

Record the date, engine/profile versions, tested capabilities and limits, plus
private manifests and recovery evidence. Remove temporary test entrypoints and
restore the normal worker configuration. Resolve failed checks without rewriting
content history, bypassing checksums or substituting assets to manufacture a pass.

Recheck relevant capabilities after hosting, runtime, profile or deployment
changes. A successful acceptance run is evidence for that configuration, not a
promise of unlimited capacity, recovery time or provider-wide compatibility.
`;

export function publicProse(path, bytes) {
	if (!path.endsWith(".md")) return bytes;
	let text = bytes.toString("utf8").replaceAll(" (internal planning record)", "");
	if (
		path === "docs/development/publication.md" &&
		text.includes("# Preparing a public repository")
	)
		text = publication;
	if (
		path === "docs/development/publication-audit.md" &&
		text.includes("# Publication preparation evidence")
	)
		text = audit;
	if (path === "docs/architecture/README.md")
		text = text.replace(
			/^- Tsuzuri owns.*$/m,
			"- Contributor work uses issues and pull requests; checks do not authorize merging, publication or deployment.",
		);
	if (path.startsWith("docs/architecture/decisions/"))
		text = text.replace(/^Accepted by:.*$/m, "Decision owner: Utsuri Foundry, SL.");
	if (
		path === "docs/architecture/decisions/0008-integration-surface.md" &&
		text.includes("## Evidence and follow-up gates")
	) {
		text = text.replace(
			"## Context",
			"The context below records the situation at the decision date. Consult current\noperating guides for available features.\n\n## Context",
		);
		text = text.replace(
			/## Evidence and follow-up gates[\s\S]*$/,
			"## Implementation status\n\nThis decision defines constraints, not a complete integration API. The current\n[profile authoring guide](../../development/profile-feed.md#public-content-feed)\nand [publication workflow](../../development/content-feed.md#public-content-feed)\ndescribe the public content feed. Additional integration capabilities require\ntheir own implementation, documented protocol and qualification; this decision\nalone does not make them available.\n",
		);
	}
	if (path === "docs/architecture/decisions/0001-product-first-reference-profile.md")
		text = text
			.replace("independent private product", "independent product")
			.replace(
				/Gate 0 proved[^\n]+Tsuzuri tasks[^\n]+/,
				"Gate 0 proved the PHP/MariaDB/shared-host foundation. Subsequent work establishes product contracts, a reference design benchmark and the fictional profile foundation.",
			);
	if (path === "docs/architecture/decisions/0003-repository-architecture-gate.md") {
		text = text.replace(
			/Warnings above a review threshold[^\n]+/,
			"Warnings above a review threshold do not fail by themselves. Files above a maximum fail. Public contributions keep architecture-exceptions.json empty and resolve policy failures without a private exception workflow.",
		);
		text = text.replace(
			/These budgets are deliberately tighter than [^.]+\./,
			"These budgets reflect the product's small PHP/JavaScript modules and their limited generated or platform-specific code.",
		);
	}
	if (path === "docs/architecture/decisions/0004-development-quality-toolchain.md")
		text = text.replace(
			/The quality policy permits only exact[^\n]+/,
			"Public contributions keep quality-exceptions.json empty. Resolve implementation-quality failures in code; no suppression or private removal-record dependency is admitted by the public gate.",
		);
	if (
		path === "docs/development/reference-host-acceptance.md" &&
		text.startsWith("# Reference host publication and restore acceptance")
	)
		text = hostAcceptance;
	if (path === "docs/development/implementation-quality-policy.md")
		text = text.replace(
			/## Exceptions[\s\S]*?(?=## Commands)/,
			"## Exceptions\n\nPublic contributions keep `quality-exceptions.json` empty. Resolve quality failures in code. No analyzer suppression, unsafe browser escape hatch or production PHP error-control operator is admitted. There is no private removal-record prerequisite for contributing. Expected host warnings cross the typed `HostIo` boundary documented in [the PHP standard](php-standard.md#resources-and-cleanup).\n\n",
		);
	if (path === "docs/development/repository-architecture-policy.md")
		text = text.replace(
			/## Temporary exceptions[\s\S]*?(?=## Adding an area)/,
			"## Exceptions\n\nPublic contributions keep `architecture-exceptions.json` empty. Resolve policy violations within the existing budgets and dependency boundaries. The gate rejects active exceptions; no private planning record is needed to contribute.\n\n",
		);
	if (path === "docs/development/review-checklist.md")
		text = text.replace(
			/^- \[ \] Any exception.*$/m,
			"- [ ] Architecture and quality exception registries remain empty; policy failures are resolved in code.",
		);
	if (path === "AGENTS.md") {
		text = text.replace(
			/`architecture\.json` is the executable[^\n]+/,
			"`architecture.json` enforces ownership, dependencies, documentation and source budgets. Public contributions keep `architecture-exceptions.json` empty. A line count prompts cohesion review; never split a coherent module solely to satisfy a number.",
		);
		text = text.replace(
			/`quality-exceptions\.json` is the separate[^\n]+/,
			"Public contributions keep `quality-exceptions.json` empty. Resolve quality failures in code; analyzer suppressions, unsafe browser escape hatches and production PHP error-control operators are not admitted.",
		);
		text = text.replace(
			"internal planning and coordination; public snapshots include only the explanatory README.",
			"contributor guidance; work is tracked through issues and pull requests.",
		);
	}
	if (path === "docs/development/repository-layout.md")
		text = text
			.replace(
				"internal coordination in the private archive; contributor guidance in public snapshots",
				"contributor guidance; issues and pull requests track work",
			)
			.replace(
				"Private archive worktrees and ownership\nrecords are maintained separately and are not required in a public checkout.\n",
				"No special checkout layout or private coordination tool is required.\n",
			);
	if (path === "tasks/README.md")
		text =
			"# Work coordination\n\nUse GitHub issues and pull requests. Architecture and quality exception registries\nstay empty; resolve policy failures without introducing suppression records.\n";
	if (path === "docs/development/content-import.md")
		text = text.replace("their private repository", "a separate profile package");
	return Buffer.from(text);
}

export function assertPublicDocumentation(path, bytes) {
	if (!path.endsWith(".md") && !path.startsWith("docs/")) return;
	const text = bytes.toString("utf8");
	assert.doesNotMatch(
		text,
		/\btsuzuri\b|tasks\/projects\/|internal planning record|canonical checkout|worktree_root/i,
		`Internal workflow in public documentation: ${path}`,
	);
	assert.doesNotMatch(
		text,
		/\/(?:home|Users)\/[^/\s]+\/(?:dev|projects|workspaces)\//,
		`Personal workspace path in public documentation: ${path}`,
	);
}

export function checkPublicDocumentation(root) {
	// Root provenance survives file deletion; removing the marker cannot disable the gate.
	const roots = git(root, ["rev-list", "--max-parents=0", "HEAD"])
		.toString()
		.trim()
		.split("\n");
	if (
		!roots.some(
			(revision) => git(root, ["ls-tree", revision, "--", "PUBLICATION.json"]).length,
		)
	)
		return;
	const paths = git(root, ["ls-files", "-z"]).toString().split("\0").filter(Boolean);
	assert.ok(paths.includes("PUBLICATION.json"), "Retain initial source provenance");
	for (const path of paths) {
		if (path.startsWith("docs/"))
			assert.ok(
				publicDocument(path),
				`Internal working document in public source: ${path}`,
			);
		assert.ok(
			!/(?:^|\/)\.tsuzuri(?:\.|\/|$)/i.test(path) &&
				!path.startsWith("tasks/projects/"),
			"Internal coordination files cannot enter public source",
		);
		if (path.endsWith(".md") || path.startsWith("docs/"))
			assertPublicDocumentation(path, readFileSync(resolve(root, path)));
	}
	for (const registry of ["architecture-exceptions.json", "quality-exceptions.json"]) {
		assert.deepEqual(
			JSON.parse(readFileSync(resolve(root, registry))).exceptions,
			[],
			"Public contributions resolve findings without private exceptions",
		);
	}
}
