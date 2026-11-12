# Reference Studio design benchmark

Status: accepted implementation brief  
Reviewed: 2026-10-03

## Decision

Reference Studio will use an original composite benchmark informed by three
public websites:

- [6a architects](https://www.6a.co.uk/projects) for restrained archival
  structure, sortable project metadata, and long-form project pages;
- [Heavy Traffic Magazine](https://www.heavytrafficmagazine.com/all) for a
  type-led index, compact editorial rhythm, and contextual previews; and
- [Pentagram](https://www.pentagram.com/work) for broad discovery by sector,
  discipline, location, year, and related people.

This is a behavioral benchmark, not a visual clone. Reference Studio must not
reproduce any reference's exact layout, proportions, typeface, animation,
identity, text, media, source code, or distinctive combination of those
elements. The result must remain recognizably original.

The composite is deliberately demanding. It tests a small portfolio, an
editorial publication, people, taxonomies, relationships, rich media, search,
and multilingual static output without expanding into commerce, live comments,
member accounts, or an unrestricted page builder.

## Study method and limits

The live pages, delivered HTML, stylesheets, scripts, media declarations, and
navigation were inspected on 2026-10-03. Point-in-time transfer sizes and
response timings were used only to understand implementation shape. They are
not laboratory measurements or performance rankings.

The Chrome performance-audit connector was unavailable during this study, so
Core Web Vitals, accessibility-tree inspection, and interaction traces for the
reference sites were not measured. Reference Studio has its own testable
budgets below. A browser-based audit remains an implementation acceptance
step.

## Comparison

| Concern | Heavy Traffic Magazine | 6a architects | Pentagram |
| --- | --- | --- | --- |
| Layout | Dense typographic index with fixed edge navigation and issue filters. | Restrained project table leading to long pages with varied image widths and captions. | Large visual work grid with featured entries and substantial filtering controls. |
| Typography | Editorial serif dominates and makes text itself the interface. | Quiet serif typography supports archival reading and metadata. | Large modern display typography supports a broad international practice. |
| Navigation | Small global vocabulary: Subscribe, Shop, Index, About. The index is the principal discovery surface. | Projects, Information, News, Contact, plus sortable project columns and previous/next movement. | Work, About, News, Contact, Search, Archive, with discovery across several taxonomies. |
| Motion and interaction | Compact filter changes and a strong precedent for contextual preview behavior. | Mostly restrained transitions, image viewing, and continuous previous/next browsing. | Filter panels, responsive media, and video within a high-volume visual archive. |
| Content model | Editorial entries, authors, issues, online-only state, and commerce links. | Projects, locations, types, years, people, awards, exhibitions, bibliography, clients, news, captions, and credits. | Projects, sectors, disciplines, locations, years, partners, associates, offices, news, and relationships between work and people. |
| Responsive behavior | Index rows and edge navigation recombine at narrower widths. | Multiple image sizes collapse through several breakpoints into a legible reading flow. | Explicit responsive image candidates and layouts serve a very broad device range. |
| Accessibility evidence | The typographic hierarchy and simple index are strong foundations, but the study did not complete an accessibility-tree audit. | Some delivered content images have empty alternative text and the stylesheet removes button focus outlines. These defects must not be copied. | Several controls have accessible labels, but many delivered images have empty alternative text. Semantic intent must be verified rather than inferred. |
| Performance and dependencies | Compact document and stylesheet shape, but delivered pages include application modules, commerce, and analytics scripts. | Server-delivered HTML, but also an external font service, an older jQuery runtime, and plugins. | Responsive media is disciplined, but the page carries a larger application bundle and consent or analytics integrations. |
| CMS pressure | Requires authors, issues, ordering, publication state, and concise previews. | Requires structured project metadata, long text, captions, credits, varied media placement, and related navigation. | Requires multiple taxonomies, people relationships, search, featured ordering, video, and responsive derivatives. |

None of these implementations is adopted wholesale. In particular, Reference
Studio will not inherit third-party analytics, commerce scripts, external font
services, old client libraries, absent focus indicators, or indiscriminate
empty alternative text.

## Original implementation requirements

### Information architecture

The compiled fixture must expose these crawlable static destinations in each
published locale:

1. Home, containing an editorial selection of projects, articles, and people.
2. Work, containing the complete project index and discovery controls.
3. A project route for every published project.
4. Journal, containing the article index.
5. An article route for every published article.
6. People, containing the people index and person routes.
7. About.
8. Search.

The header must expose Home, Work, Journal, People, About, and Search without
JavaScript. The current destination must be programmatically indicated. Every
record route must provide a direct way back to its index and contextual links
to related records where those relationships exist.

### Layout and typography

- The visual language must use an original neutral palette, spacing scale, and
  typographic hierarchy. It must not imitate a reference site's exact geometry.
- Wide layouts use a 12-column grid, medium layouts use 6 columns, and narrow
  layouts use one reading column. Profile renderers may span those tracks, but
  content order in the DOM must remain meaningful without CSS.
- Project pages must demonstrate full-width, half-width, and inset media,
  captions, long-form text, credits, taxonomy links, and related projects.
- Normal prose must remain between 45 and 80 characters per line at the tested
  viewports. Text must reflow at 200 percent browser zoom without horizontal
  page scrolling at a 320 CSS-pixel viewport, except for intrinsically
  two-dimensional content.
- The fixture must self-host pinned WOFF2 Latin subsets of Source Sans 3 and
  Source Serif 4, renamed Studio Sans and Studio Serif. No font request may
  leave the published origin.
- Font fallback metrics and explicit media dimensions must prevent visible
  layout shifts during font and image loading.

### Discovery and contextual previews

- The Work index must filter projects by free text, discipline, sector, year,
  and person. Journal must filter by free text and sector.
- Filters must be combinable, reflected in the URL, restorable by Back and
  Forward navigation, and removable individually or as a group.
- With JavaScript unavailable, the complete bounded index and links to taxonomy
  pages must remain usable. Client filtering is an enhancement over static
  content, not the only route to it.
- Search results must update no more than one animation frame after an input
  event once the local index is ready. Stale asynchronous results must never
  replace newer input.
- On devices with hover and a fine pointer, focusing or hovering an eligible
  index entry may reveal one compact preview panel containing synthetic media
  and a short label. The panel must not cover the active entry or pointer.
- Keyboard focus must reveal equivalent information at a stable anchored
  position. Touch devices must not depend on the preview. The preview must be
  disabled when reduced motion is requested and must never trap focus.

### Motion and media

- Interface transitions must last 250 milliseconds or less and use opacity or
  transforms unless another property is proven not to trigger layout work.
- `prefers-reduced-motion: reduce` must remove non-essential transitions and
  replace any moving preview with its static state.
- Video is optional fixture content. It must be pre-encoded, muted before any
  autoplay, supplied with a poster, controllable, keyboard operable, and never
  the only carrier of information. Reduced-motion mode must use the poster
  until the visitor explicitly starts playback.
- Images must use intrinsic dimensions, responsive candidates, modern formats
  where supported, and lazy loading below the first viewport. Alternative text
  is localized and descriptive when the image conveys content; only genuinely
  decorative images use empty alternative text.

### Responsive acceptance matrix

The profile must be reviewed at 360, 768, 1024, 1440, and 1920 CSS pixels. At
each width:

- navigation, filters, record titles, captions, and controls remain readable;
- no content is clipped or hidden by fixed navigation;
- filter state and reading order remain equivalent;
- a 120-character title, an absent optional image, portrait and panoramic
  media, a long article, and a project with 30 index peers remain usable; and
- pointer-only behavior has an operable keyboard and touch equivalent.

### Accessibility and privacy

- Published pages and administration surfaces target WCAG 2.2 AA.
- All functionality must be keyboard operable with visible focus. Profile CSS
  must not remove focus indicators without providing a visible replacement.
- Headings, landmarks, lists, tables, and controls must use their native HTML
  semantics before ARIA is considered.
- Pointer targets must satisfy WCAG 2.2's 24 by 24 CSS-pixel minimum or its
  spacing exception. Primary touch controls should be at least 44 by 44 CSS
  pixels.
- Dynamic result counts and validation summaries must be announced without
  moving focus unexpectedly.
- The baseline profile has no analytics, advertising, third-party scripts,
  public form submissions, or visitor cookies.

The accessibility requirements follow [WCAG
2.2](https://www.w3.org/TR/WCAG22/), including its
[Pause, Stop,
Hide](https://www.w3.org/WAI/WCAG22/Understanding/pause-stop-hide.html) and
[Target Size
Minimum](https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html)
guidance.

### Performance and delivery

The public site remains static HTML, CSS, JavaScript, fonts, and media. The CMS
runtime must not participate in visitor reads.

- Core content, navigation, and record links must render from HTML without
  waiting for JavaScript.
- Baseline pages must load no third-party JavaScript.
- Profile CSS loaded on first view must not exceed 30 KiB compressed.
- Profile JavaScript loaded on first view must not exceed 40 KiB compressed.
- Font files needed for the first view must not exceed 160 KiB compressed in
  total. Only faces used above the fold may be preloaded.
- The compiler must produce responsive media candidates and must not emit an
  original upload as the default index thumbnail.
- Search data may be loaded on intent or during idle time. It must be split or
  compressed if its transfer exceeds 150 KiB for the deterministic fixture.
- The implementation target at the 75th percentile is LCP at or below 2.5
  seconds, INP at or below 200 milliseconds, and CLS at or below 0.1. These are
  field targets, not claims about the reference sites.
- A reproducible browser audit on the representative mobile and desktop test
  profiles is required before the implementation task can be accepted.

The field targets follow the current [Core Web Vitals threshold
method](https://web.dev/articles/defining-core-web-vitals-thresholds).

### Localization and metadata

- The deterministic fixture publishes Catalan, Spanish, and English under
  explicit locale paths, with Catalan as its configured default.
- Every published translation has a canonical URL and reciprocal `hreflang`
  links. Missing translations must not silently masquerade as translated pages.
- Each record type supplies its own title, description, social image policy,
  and structured-data mapping through the trusted profile renderer.
- Search normalization is case-insensitive and Unicode-aware while preserving
  the displayed original text.

## Product ownership boundaries

### Reusable engine

The engine owns capabilities that remain valid across unrelated sites:

- profile validation, compatibility checks, and installation;
- localized records, singletons, drafts, revisions, slugs, publication state,
  ordering, and optimistic concurrency;
- bounded field types, taxonomy namespaces, typed relationships, and generic
  profile-generated administration forms;
- media metadata, responsive raster derivatives, pre-encoded video references,
  jobs, previews, releases, backup, restore, and updates;
- deterministic snapshots, route and asset manifests, search postings, and
  atomic publication; and
- security headers, escaping boundaries, accessibility primitives, and
  artifact validation that apply to every profile.

The engine must not contain Reference Studio colors, spacing, navigation labels,
route names, content assumptions, or visual component choices.

### Trusted profile renderer

The Reference Studio package owns:

- its public route map and localized navigation labels;
- the 12-column composition, typography, palette, spacing, and responsive
  presentation;
- project, article, person, index, Home, About, and Search renderers;
- the contextual hover or focus preview, filter presentation, related-record
  presentation, and optional video player enhancement; and
- profile CSS, small browser modules, templates, and bundled font assets.

Profile code is trusted executable code reviewed with the engine. Editors
choose content and bounded field values; they do not submit templates, CSS,
JavaScript, or executable markup.

### Fictional fixture

The fixture owns all editorial facts:

- fictional projects, articles, people, credits, dates, locations, disciplines,
  sectors, and relationships;
- complete Catalan, Spanish, and English examples plus intentional partial-draft
  edge cases;
- synthetic raster images, video, posters, captions, and alternative text; and
- stress cases such as long titles, empty optional fields, conflicting slugs,
  portrait and panoramic media, and maximum bounded relationship counts.

Fixture data must remain deterministic. Generated media must retain a
provenance record and stable source file or generation recipe.

## CMS implications

The accepted profile contract already defines the bounded breadth this brief
must exercise:

- project, article, and person record types;
- Home, About, and site-settings singletons;
- discipline and sector taxonomy namespaces;
- record-to-record, record-to-taxonomy, rich-text, media, video, repeatable
  group, and composition fields;
- Catalan, Spanish, and English locales; and
- profile-owned record, singleton, taxonomy, and search renderers.

Implementation must build from the canonical
[`profile.json`](./profile.json) rather than introducing an unbounded schema
system. If the benchmark reveals a
missing capability, the implementer must first show that it applies to more
than this visual design before proposing an engine contract change.

## Intellectual-property and provenance rules

- Do not copy reference HTML, CSS, JavaScript, data, screenshots, logos, names,
  text, imagery, videos, icons, or distinctive brand devices.
- Do not trace layouts pixel for pixel or reproduce a reference's complete
  visual sequence. Similar functional patterns must be expressed with original
  proportions, type, rhythm, and interaction details.
- Reference Studio must use a fictional name and original wordmark treatment.
  No reference trademark may appear in public fixture output.
- Reference fonts, including Baskerville 10, Starling, Neue Haas Grotesk, and
  Plain, are not fixture assets.
- [Source Sans 3](https://github.com/adobe-fonts/source-sans) and [Source Serif
  4](https://github.com/adobe-fonts/source-serif) are the selected replacements.
  Their Latin subsets are renamed Studio Sans and Studio Serif, including
  internal font names, to respect Adobe's Reserved Font Name "Source".
  Their pinned WOFF2 files and SIL Open Font License 1.1 texts must ship with
  the profile, and their provenance must be recorded.
- Synthetic media must be original or compatibly licensed for redistribution.
  Every asset must record its author or generator, source or recipe, license,
  and any required attribution.
- No source-code license from a reference site is inferred from public delivery.
  Only observed behavior is used as research input.

## Implementation acceptance

The following evidence is required from the subsequent profile implementation:

1. Deterministic snapshots for all three locales and expected route, asset, and
   search manifests.
2. Visual regression captures at all five specified viewport widths.
3. Keyboard-only, touch, fine-pointer, reduced-motion, 200-percent zoom, and
   no-JavaScript journeys.
4. Automated checks for headings, landmarks, names, focus visibility, contrast,
   alternative text policy, and invalid markup, plus a manual screen-reader
   smoke test.
5. Browser performance traces showing the declared byte budgets and Core Web
   Vitals targets on representative mobile and desktop profiles.
6. Content stress fixtures covering long localized strings, missing optional
   media, mixed aspect ratios, video poster fallback, dense relationships, and
   partial drafts.
7. A provenance inventory proving that every shipped font and media asset is
   original or redistributable.

Passing this benchmark proves the bounded product can support a sophisticated
multilingual editorial portfolio. It does not make Reference Studio a generic
theme, a client deliverable, or a license to add site-specific behavior to the
engine.
