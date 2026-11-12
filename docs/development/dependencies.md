# Dependency policy

The default is no new runtime dependency. A dependency requires a concrete boundary, compatible licence, active maintenance evidence, locked complete graph, advisory-monitoring path, resource cost, update policy, and removal plan.

The product distinguishes:

- installed PHP runtime dependencies, vendored into a checked release bundle;
- engine-owned browser code, shipped as native modules;
- profile developer tools, whose prebuilt output may ship but whose runtime does not;
- repository and CI tools, which never enter the customer bundle or public artifact;
- replaceable hosted providers, which require a provider-free baseline or declared managed capability.

Composer never runs on a customer host. Node.js is not an installed-host requirement. A dependency used by tools does not enter the product merely because it already exists in the monorepo.

The Gate 0 baseline includes the accepted `opis/json-schema` family behind one engine-owned adapter. Its locked graph, licence, remote-reference prohibition, limits, update policy, and removal plan must be imported with the dependency decision.

Development quality uses the Nix-locked Biome, PHPStan, and PHP CS Fixer graph accepted in [ADR 0004](../architecture/decisions/0004-development-quality-toolchain.md). These tools never enter Composer's installed production graph, a customer bundle, or a public artifact. Their update and removal lifecycle is still subject to the same review standard as a runtime dependency.

Optional native webfont processing is accepted in
[ADR 0005](../architecture/decisions/0005-optional-verified-webfont-processing.md).
The locked Nix graph supplies OTS and WOFF2 for development/security tests;
qualified hosts may provide separately pinned executables behind the explicit
sanitation boundary. Neither executable enters the installed bundle.

Chromium is a repository-only dependency for the required native-form and
rich-text browser journey. Its Linux package and complete graph are pinned
through the existing `flake.lock` nixpkgs revision. Chromium's BSD licence and
bundled third-party notices apply to the development tool; the browser and its
libraries never enter customer bundles. The Chrome DevTools Protocol is used
directly through Node's native WebSocket, with no additional JavaScript graph.
The first shell realization downloads the browser graph; each bounded test
run creates and removes its own browser profile and services. Monitor upstream
[Chrome release advisories](https://chromereleases.googleblog.com/) and Nixpkgs
Chromium updates. Updates require a reviewed lock-file change and the complete
repository gate. Removal requires replacing the CDP journey and its required
gate lane with an accepted real-browser adapter, then removing the Nix input.

The public site audit uses the Nix-locked Google Lighthouse command-line tool
(`google-lighthouse`, not the unrelated `lighthouse` attribute) and the Nu HTML
Checker, accepted in
[ADR 0006](../architecture/decisions/0006-public-site-quality-audit.md). Both are
repository-only tools; Lighthouse attaches to the pinned Chromium and neither enters
a customer bundle or public artifact.

## Packaged open fonts

Profile authors must review the licence of the exact font release before
packaging it. For SIL Open Font License 1.1 fonts, subsetting removes font
components and creates a Modified Version. A CSS `font-family` alias does not
rename the binary. Subset only fonts that declare no Reserved Font Name (RFN),
or rename the subset's internal family, full, unique and PostScript names,
including CFF names and variable-font instance names, to avoid every RFN.
Recorded written permission from the copyright holder is the alternative to
renaming; do not infer permission from a font's availability online.

Keep the upstream copyright, attribution, trademark notices and complete licence
text with the profile package. Standalone public font downloads must also carry
the copyright and licence, either in readable font metadata or accompanying
public files; private package notices alone do not accompany that download.
Modified OFL fonts remain under the OFL. Use the new family name in editor-facing
registry labels, CSS and asset filenames; retain upstream names only for
attribution and provenance, without implying endorsement.

Record the upstream version, immutable input and checksums, transformations,
RFN decision, derivative names and licence locations in the profile's provenance.
Verify emitted metadata, language coverage, layout features, weight range and
transfer budget, then run the public site quality gate after any font change.
Reference Studio's [font review and reproduction recipe](../../profiles/reference-studio/PROVENANCE.md#fonts)
show the renamed-subset path. FontTools 4.63.0 (MIT) and its Python/Brotli graph
are used only for that asset preparation through the existing Nix lock; they do
not enter the installed runtime or public artifact. Monitor
[FontTools releases](https://github.com/fonttools/fonttools/releases) and the
[GitHub advisory database](https://github.com/advisories); updates require a
reviewed lock change, metadata/rendering verification and the repository gate.
Replacing the font preparation tool removes this optional development invocation.

The authoritative [OFL FAQ](https://openfontlicense.org/ofl-faq/) covers webfont
subsetting (2.6), internal renaming (3.1) and copyright/licence retention (1.10,
3.1 and 3.7). Apply each other font licence's own modification and distribution
conditions rather than assuming the OFL applies.

## Publication-only tooling

Gitleaks 8.30.1 (MIT) and FFmpeg 9.0.1 (LGPL/GPL depending on enabled features)
resolve through `flake.lock`. Gitleaks scans historical diffs, every reachable
object and the public candidate; no blanket fixture baseline is used. Follow
upstream releases/security reports and update the lock with reviewed full-gate
evidence. Replacement of Gitleaks requires repeating the complete audit with a
reviewed scanner. FFmpeg inspects/authors original synthetic fixtures only;
remove it when this preparation boundary is no longer needed. Neither executable
or source enters the installed runtime or public artifact. They use the existing
locked Nix transitive graph, not a new host dependency.

CI uses SHA-pinned `actions/checkout` (MIT) and `cachix/install-nix-action`
(Apache-2.0), solely to obtain source and the locked development shell on a hosted
runner. No action source is vendored in the product. Pins are reviewed against
their public upstream repositories and security advisories; updates require the
same complete check. Removal means replacing checkout or Nix bootstrap with an
equivalent reviewed runner step. CI has read-only contents permission, no saved
checkout credentials and no private cache or secret requirement.
