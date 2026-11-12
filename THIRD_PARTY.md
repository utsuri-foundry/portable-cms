# Third-party inventory

The root Apache 2.0 licence covers original Portable CMS material. Aggregation
of the following components does not change their licences. Installed runtime
bundles include `LICENSE`, `NOTICE`, this inventory and the locked vendored
packages with their original licence and notice files. Profile packages retain
their own licences. No third-party runtime code is copied into first-party files.

| Component | Exact version / source | Licence | Distribution / notices |
| --- | --- | --- | --- |
| Opis JSON Schema | 2.6.0, [upstream](https://github.com/opis/json-schema/tree/2.6.0) | Apache-2.0 | `vendor/opis/json-schema/LICENSE`, `NOTICE`; Zindex Software and original authors, retained in root NOTICE |
| Opis String | 2.1.0, [upstream](https://github.com/opis/string/tree/2.1.0) | Apache-2.0 | `vendor/opis/string/LICENSE`, `NOTICE`; Zindex Software and original authors, retained in root NOTICE |
| Opis URI | 1.1.0, [upstream](https://github.com/opis/uri/tree/1.1.0) | Apache-2.0 | `vendor/opis/uri/LICENSE`; authors Marius Sarca and Sorin Sarca |
| Composer-generated autoload support | Composer 2.10.3, pinned by `flake.lock` | MIT | `vendor/composer/LICENSE`; copyright Nils Adermann and Jordi Boggiano; retained with generated ClassLoader/InstalledVersions support in bundles |
| Studio Sans Latin | Adobe Source Sans 3, 3.052R | OFL-1.1 | Subset and renamed modified font; `profiles/reference-studio/licenses/source-sans-3-OFL-1.1.txt` and embedded complete licence |
| Studio Serif Latin, regular and italic | Adobe Source Serif 4, 4.005R | OFL-1.1 | Subset and renamed modified fonts; `profiles/reference-studio/licenses/source-serif-4-OFL-1.1.txt` and embedded complete licence |

`composer.lock` pins the complete three-package runtime graph and immutable
source revisions. `composer install` retains upstream notices; `composer audit
--locked` checks advisories. Changes require the dependency review and full gate
in [dependency policy](docs/development/dependencies.md). Removal means replacing
the JSON Schema adapter and its locked transitive graph.

The fonts are aggregated with Apache-licensed software, remain OFL-licensed and
are never sold by themselves. Reserved Font Name `Source` was removed from the
modified identities. Adobe attribution remains. Exact asset hashes,
transformations and provenance are in the
[profile inventory](profiles/reference-studio/PROVENANCE.md).

## Original acceptance material

Reference Studio's fictional copy, icon, renderers, styles and geometric WebP
colour studies are original work of Utsuri Foundry, SL, under Apache-2.0. Their
source and frozen hashes are recorded with the profile. Benchmark sites supply
no code, media or branding. The two prepared video fixtures are original silent
one-second colour clips, with no imported media, music or people; see
[video provenance](tests/fixtures/video/README.md). Other fixtures are authored
synthetic data or generated within tests. No unknown-origin media is admitted.

## Developer tools

Public `flake.lock` pins the development graph; none of it is copied into the
installed runtime or static site. Git, ripgrep, PHP, MariaDB, Apache, Node.js, Chromium,
Lighthouse, Nu, Nix, Composer, Biome, PHPStan, PHP CS Fixer, ImageMagick, WOFF2,
OpenType Sanitizer and optional font preparation tools keep their upstream
licences. See [dependency policy](docs/development/dependencies.md).
Gitleaks (MIT) is the publication secrets scanner; FFmpeg (LGPL/GPL according to
its enabled build) is only a fixture authoring/inspection tool. Executing these
tools does not incorporate their code into authored media or the product.
Updates are reviewed through the Nix lock and the full repository gate;
Gitleaks advisories/releases are monitored upstream. Removal requires a reviewed
replacement scanner and repeat audit; FFmpeg can be removed once no fixture
authoring or inspection is needed. No tool executable is redistributed here.
