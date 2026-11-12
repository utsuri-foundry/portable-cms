# Reference Studio

A fictional, deterministic profile for local development and product acceptance.
It exercises multilingual editing, relationships, media, layouts, typed blocks,
previews and static publication. It is a test fixture, not a promoted showcase
or a client template.

Profile code owns presentation and depends only on public engine contracts.
It renders frozen data; it must not query live repositories, read private
configuration or redefine validation and publication rules.

## Find the source

| Path | Purpose |
| --- | --- |
| [`profile.json`](profile.json) | Content model, routes, assets and compatibility |
| [`administration-labels.json`](administration-labels.json) | Translated editing labels |
| [`src`](src/) | Reviewed renderers, compiler factory and holding page |
| [`assets`](assets/) | Prebuilt styles, scripts, fonts and images |
| [`fixtures`](fixtures/) | Fictional content, media and expected artifacts |
| [`build.php`](build.php) | Deterministic static build and fixture refresh |

The fixture includes Catalan, Spanish and English content. Additional languages
must fit the declared font and language envelope; see
[profile authoring](../../docs/development/profile-languages.md#language-envelope).
Asset origins and licences live in [PROVENANCE.md](PROVENANCE.md);
[the design benchmark](design-benchmark.md) records its acceptance criteria.

## Try it

From the repository root:

```sh
nix develop -c just build-reference
nix develop -c just serve-reference
```

The build requires a new output directory. For the editing interface, follow
[the local CMS guide](../../docs/development/local-reference-cms.md).

## Change the fixture

Profile assets and source are checksum-verified. After reviewing an intentional
change, regenerate fixture pins and expected output:

```sh
nix develop -c php profiles/reference-studio/build.php --refresh-fixtures --output=build/reference-fixture-refresh
```

Use a new output path. Add `--refresh-assets` only for reviewed asset changes;
never refresh checksums to hide unexplained drift. Original media and font
licences must remain documented in the provenance file.

Run `nix develop -c php tests/php/run.php` for compiler and fixture checks.
Before review, run the fast gate and affected lanes, including the public-site
lane for rendered changes; see the [testing policy](../../docs/development/testing.md).
