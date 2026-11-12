# Portable CMS

Portable CMS is a self-hosted CMS for custom-designed websites. It combines
control over the site's code and presentation with visual, multilingual content
editing.

Write the site's templates, styles and browser code to suit the design, without
a required frontend framework or proprietary platform. The CMS is designed for
affordable PHP and MariaDB/MySQL hosting, including compatible shared plans.

Each site can have its own branded editing experience, shaped around its content
and built on the same reusable engine.

Publishing produces static files that run independently of the CMS and its
database.

## What you can do

- **Tailor the CMS to the site.** Use the site's name and logo, with content
  types, fields and labels shaped around the project.
- **Edit in multiple languages.** Manage structured content, translations and
  page blocks tailored to your site through a browser-based administration interface.
- **Edit visually.** See changes in a live page preview, select page elements
  to reach their fields, and edit supported text directly in place.
- **Prepare images and video.** Add descriptions, captions and focal points,
  with media controls defined for each site's content.
- **Review before publishing.** Preview the generated website and share a
  private preview for review.
- **Publish deliberately.** Keep revision history, prepare changes without
  altering live pages, schedule releases and return to a retained release.

## What makes it portable

The website can outlive the CMS used to create it. Portability means being able
to keep your work and choose where it runs:

- **The published website travels.** Ordinary HTML, CSS, JavaScript and media
  files can move between static hosts. They keep serving your website even
  when the CMS and its database are switched off.
- **The editable content travels.** Export content, retained revisions and
  media in a documented format, then restore them into a compatible installation.
  You retain the material needed to keep editing, as well as the published site.
- **The software stays under your control.** The CMS is open source and runs on
  conventional hosting. External services are optional integrations; the core
  authoring and publishing workflow needs no proprietary vendor platform.

Moving the authoring system requires installation, configuration and a proper
export and restore. It is not an arbitrary folder copy, and moving content to
a different CMS still requires a migration. The
[handover guide](docs/development/portable-handover.md) explains the process and
requirements; see [export capacity](docs/development/export-capacity.md#supported-envelope)
for supported limits.

## Technology and principles

- **Build once, serve static files.** PHP and the database handle authoring.
  Publishing produces HTML, CSS, JavaScript and media ahead of time, so visitor
  requests never enter the CMS. Public traffic and editing stay independent.
- **Designed for affordable hosting.** The authoring application runs on
  conventional PHP and MariaDB/MySQL hosting, including qualified shared hosts.
  The installed bundle needs no Node.js, Composer, Docker or
  continuously running application process; scheduled jobs can use cron.
  See the [hosting requirements](docs/development/conventional-host-probe.md#hosting-requirements).
- **Keep maintenance manageable.** The runtime uses plain PHP and a small
  [reviewed dependency set](THIRD_PARTY.md), version-locked and shipped with the
  installation. There is no application framework to install. The authoring
  system still needs security updates and backups.
- **Work with the browser.** The administration uses server-rendered HTML and
  native forms, enhanced with plain JavaScript for focused interactions.
  Validation and content rules live in PHP, keeping one authoritative set of
  rules behind the editing interface.
- **Keep site design separate from the engine.** Profiles define content types,
  fields, page rendering and assets. Developers control the site's markup and
  presentation while the shared engine handles editing, media and publication.
  Each project gets its own design and content model while the engine can be
  maintained and improved across installations. See the
  [administration branding and customisation boundary](docs/architecture/administration-experience.md#ownership-boundary).

The [architecture guide](docs/architecture/README.md) explains these choices
and boundaries in more detail.

## Get started

The [documentation index](docs/README.md) groups guides for building a website,
operating it and contributing to the engine.

- **Explore locally:** follow the [local development guide](docs/development/local-reference-cms.md)
  to try the administration and build a static site using the fictional Reference
  Studio test profile.
- **Build a site:** read the [profile authoring guide](docs/development/profile-authoring.md)
  to define its content and presentation.
- **Operate a site:** see [publishing and recovery](docs/development/publication-workflow.md)
  and [export and restore](docs/development/conventional-cms-export.md).
- **Contribute:** start with [CONTRIBUTING](CONTRIBUTING.md) for development setup
  and checks. The [development handbook](docs/development/README.md) and
  [architecture guide](docs/architecture/README.md) cover the technical details.

Portable CMS is pre-1.0. Read the [security and support policy](SECURITY.md)
before adopting it for a project.

## Licence

[Apache License 2.0](LICENSE) · Copyright 2026 Utsuri Foundry, SL.
See [NOTICE](NOTICE) and [third-party licences](THIRD_PARTY.md) for attribution.
