# Reference Studio asset provenance

Reference Studio is fictional. Its names, copy, data, layout implementation,
CSS, JavaScript, and renderer source were created for Portable CMS and do not
copy the identity, content, assets, or source of any benchmark site.

`navigation-rules.json` is original profile configuration for the existing
collection-card link group. The engine produces the mounted speculation-rules
JSON from this checked source; it contains no external URLs or third-party code.

## Fonts

| Asset / derivative family | Upstream release | Transformation | Licence / RFN decision |
| --- | --- | --- | --- |
| `studio-sans-latin.woff2` / Studio Sans | [Adobe Source Sans 3, 3.052R](https://github.com/adobe-fonts/source-sans/tree/3.052R) | Latin subset of `SourceSans3VF-Upright.otf`; renamed family, full/unique/PostScript and variable-instance names; upright weight axis 200–900 retained | OFL 1.1; RFN "Source" requires renaming; no permission to retain it is claimed. Original text: `licenses/source-sans-3-OFL-1.1.txt`; complete text embedded in name ID 13 |
| `studio-serif-latin.woff2` / Studio Serif | [Adobe Source Serif 4, 4.005R](https://github.com/adobe-fonts/source-serif/tree/4.005R) | Latin subset of `SourceSerif4-Regular.otf`; renamed family, full/unique/PostScript and CFF names; regular weight retained | OFL 1.1; RFN "Source" requires renaming; no permission to retain it is claimed. Original text: `licenses/source-serif-4-OFL-1.1.txt`; complete text embedded in name ID 13 |
| `studio-serif-italic-latin.woff2` / Studio Serif Italic | Same pinned Adobe Source Serif 4, 4.005R | Latin subset of `SourceSerif4-It.otf`; genuine italic outlines, angle and weight 400 retained; renamed family, full/unique/PostScript and CFF names | Same OFL 1.1 and RFN decision; complete retained licence embedded in name ID 13 |

### Licence review, 2026-10-05

The retained upstream notices both reserve "Source". Removing glyphs makes these
Modified Versions under OFL 1.1; CSS aliases alone did not rename their internal
names. The reviewed derivatives now use Studio Sans and Studio Serif in their
binaries, filenames and editor-facing registry labels. Upstream copyright and
trademark notices remain verbatim as attribution, including the CFF notices;
the reservation itself is not erased from the licence. Full licence metadata
also accompanies each standalone public font download. Neither font is sold
alone or claimed to be endorsed by Adobe.

The repository font inventory (`git ls-files '*.woff' '*.woff2' '*.ttf' '*.otf'
'*.ttc' '*.eot'`) found only these three files. Engine administration uses system
fonts; ingestion tests reuse these assets and package no additional font.
Historical schema examples describe registry data, not other font binaries.
No other packaged font needs an RFN decision in this repository.

The review follows OFL condition 3 and the authoritative
[OFL FAQ 2.6 and 3.1](https://openfontlicense.org/ofl-faq/). Future profiles follow
[the development font rules](../../docs/development/dependencies.md#packaged-open-fonts).

### Preparing and verifying the renamed assets

The original subsets were produced with FontTools 4.63.0 from Adobe's public
3.052R and 4.005R releases pinned by the existing Nix lock. The language subset is
`U+0000-00FF,U+013F-0140,U+2010-2027,U+20AC`, retaining upstream layout features.
The two upright subsets subsequently received metadata-only naming/licence
corrections. Their exact reviewed output hashes below are authoritative.
The original intermediate subsets remain in the private archive and are not
publication inputs: they predate the RFN correction. No recipe in the public
project requires an object from that archive.

For a future upright regeneration, obtain the original OTFs from the public
upstream releases above (or the Nix-pinned `source-sans` and `source-serif`
sources), subset the same codepoints and layout features, then apply the same
identity and licence rules demonstrated by the complete italic recipe below.
For Sans, retain the 200–900 weight axis and rename variable-instance names too;
for Serif regular, use Studio Serif / StudioSerif-Regular. Preserve copyright
and trademark notices and embed the complete retained OFL in name ID 13.
A fresh subsetting pass is a new asset revision: compare names, glyphs, metrics,
shaping and transfer size and refresh all profile/artifact hashes through the
normal gate. It is not claimed to reproduce the earlier upright bytes exactly.

The reviewed outputs are:

| Asset | Bytes | SHA-256 |
| --- | ---: | --- |
| `studio-sans-latin.woff2` | 46352 | `99e2eaadce0bb7b13fe490f7f9c5d9e6fc3dbd57c392f8550e06b7fb2cfcee7f` |
| `studio-serif-latin.woff2` | 45972 | `45e2c6d281a2834b67d97349c6c7325f23a2bb59ac769d0ab421467d39c7e618` |

Together they transfer 92,324 bytes, below the benchmark's 160 KiB limit.
Font-table and glyph-drawing comparisons confirm unchanged character coverage,
metrics, outlines, layout features (including `smcp`/`c2sc`) and variable weights.
The profile build refreshes asset, package, design and expected artifact pins;
public site acceptance is measured by the repository's existing quality gate.

### Italic extension, 2026-10-21

The new italic face follows the same licence decision and language subset. It
does not alter either earlier asset. The upstream input SHA-256 is
`f8062257f4693e438a8e317c9fe2b4393f0d4eed84d897edcbfd733c30a1c1a1`.
The output is 36,076 bytes, SHA-256
`b72cc296b5f6eb2c36e1dc875e10f9254548c957a4684bedba524628c0602f9a`.
All three faces total 128,400 bytes, below the unchanged 160 KiB benchmark.

Reproduce it using the same Nix-locked FontTools preparation environment:

```sh
CMS_FONT_SOURCE=$(nix build --impure --expr '
  let pkgs = (builtins.getFlake (toString ./.)).inputs.nixpkgs.legacyPackages.${builtins.currentSystem};
  in pkgs.source-serif.src
' --no-link --print-out-paths)
nix shell --impure --expr '
  let pkgs = (builtins.getFlake (toString ./.)).inputs.nixpkgs.legacyPackages.${builtins.currentSystem};
  in pkgs.python3.withPackages (p: [p.fonttools p.brotli])
' -c python - "$CMS_FONT_SOURCE/OTF/SourceSerif4-It.otf" <<'PY'
from pathlib import Path
from hashlib import sha256
import sys
from fontTools import subset
from fontTools.ttLib import TTFont

source = Path(sys.argv[1])
assert sha256(source.read_bytes()).hexdigest() == 'f8062257f4693e438a8e317c9fe2b4393f0d4eed84d897edcbfd733c30a1c1a1'
font = TTFont(source, recalcTimestamp=False)
options = subset.Options()
options.layout_features = ['*']
options.name_IDs = ['*']
options.name_legacy = True
options.name_languages = ['*']
subsetter = subset.Subsetter(options=options)
subsetter.populate(unicodes=subset.parse_unicodes('U+0000-00FF,U+013F-0140,U+2010-2027,U+20AC'))
subsetter.subset(font)
family = 'Studio Serif'
for record in font['name'].names:
    value = record.toUnicode()
    if record.nameID in (1,16,21): value = family
    elif record.nameID in (4,18): value = family + ' Italic'
    elif record.nameID == 3: value = '4.005;PortableCMS;StudioSerif-Italic-Latin'
    elif record.nameID == 6: value = 'StudioSerif-Italic'
    elif record.nameID not in (0,7,13,14): value = value.replace('SourceSerif4','StudioSerif').replace('Source Serif 4',family)
    record.string = value.encode(record.getEncoding())
licence = Path('profiles/reference-studio/licenses/source-serif-4-OFL-1.1.txt').read_text()
font['name'].setName(licence,13,3,1,0x409)
font['name'].setName('https://openfontlicense.org/',14,3,1,0x409)
cff = font['CFF '].cff
cff.fontNames = ['StudioSerif-Italic']
for top in cff.topDictIndex:
    top.FamilyName = family
    top.FullName = family + ' Italic'
font.flavor = 'woff2'
output = Path('profiles/reference-studio/assets/studio-serif-italic-latin.woff2')
font.save(output)
assert sha256(output.read_bytes()).hexdigest() == 'b72cc296b5f6eb2c36e1dc875e10f9254548c957a4684bedba524628c0602f9a'
PY
```

Verification reads the emitted font's weight 400, italic OS/2 flag and nonzero
italic angle; checks renamed identity/CFF names and the complete embedded OFL;
and observes `StudioSerif-Italic` glyphs in Chromium rather than synthetic
slanting. Public font selection and file hashes travel in the v2 family registry.

## Icon

`icon.svg` is an original geometric mark drawn by hand for Reference Studio in the
profile's ink and paper colours. It contains only rectangles, with no text, scripts
or external references.

## Fixture media

The three WebP fixtures are original abstract gradients generated with the
pinned ImageMagick 7.1.2-13 build. Each source is created directly at its
declared dimensions with `gradient`, rotated where noted by the generator
command, stripped of metadata, and encoded as lossless WebP. Their committed
bytes and SHA-256 checksums are frozen in `fixtures/snapshot.json`; compiler
tests never regenerate them. No benchmark-site image or video is included.

Sharing-image verification on 2026-10-04 found that the first two snapshot
dimension pairs were transposed after their generator rotations. Those
declarations now match the committed WebP headers; source bytes are unchanged.
The build additionally produces stripped, contained JPEG sharing cards from
selected frozen WebP bytes. Their hashes and encoder recipes are part of the
artifact manifest, not new source assets copied from benchmark sites.

The reviewed site stylesheet now consumes revisioned spacing tokens. The
engine generates `assets/design.css` from the frozen registry; its fonts refer
to the same licensed packaged assets or verified sanitized uploaded bytes.
Registry defaults are original profile data in `profile.json`.
