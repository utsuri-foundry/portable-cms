# Portable CMS documentation

Start with the guide for the work you are doing. These documents describe the
supported product, its operating requirements and how to extend and maintain it.
Architecture decisions explain design choices; they are not a list of available
features or a delivery schedule.

## Build a website

- [Explore the local CMS and static site](development/local-reference-cms.md)
- [Build a website profile: model, templates, assets and preview](development/profile-authoring.md)
- [Understand the editing interface and site branding](architecture/administration-experience.md)
- [Work with page composition](development/composition-editor.md)
- [Configure typography, fonts and design settings](development/website-design-registries.md)
- [Configure search and sharing metadata](development/search-sharing-metadata.md)
- [Check the generated site's quality](development/public-site-quality.md)

The bundled Reference Studio profile is a fictional development fixture. Use
its examples to understand the contracts when building your own site profile.

## Operate a website

- [Check hosting requirements and qualify a host](development/conventional-host-probe.md)
- [Configure private storage, install and manage accounts](development/conventional-cms-identity.md)
- [Verify an installation before handover](development/reference-host-acceptance.md)
- [Understand content, languages and retained history](development/conventional-cms-content.md)
- [Prepare media and configure background processing](development/conventional-cms-media.md)
- [Configure upload processing and workers](development/media-processing.md)
- [Create exact and shared previews](development/exact-website-preview.md)
- [Share a private preview](development/preview-sharing.md)
- [Publish, schedule and recover releases](development/publication-workflow.md)
- [Read and operate the public content feed](development/content-feed.md)
- [Export and restore content](development/conventional-cms-export.md)
- [Check export capacity and supported limits](development/export-capacity.md)
- [Move content between compatible installations](development/portable-handover.md)
- [Import content into an existing installation](development/content-import.md)
- [Upgrade the engine and profiles](development/conventional-cms-upgrades.md)
- [Configure optional AI assistance](development/ai-editorial-assistance.md)

An export package is not a complete installation backup. Follow the relevant
operation's backup, compatibility and recovery requirements before changing a
live installation. Report vulnerabilities through [SECURITY.md](../SECURITY.md).

## Contribute to the engine

- [Set up development and submit a change](../CONTRIBUTING.md)
- [Understand the architecture](architecture/README.md) and [versioned contracts](architecture/product-contracts.md)
- [Read accepted architecture decisions](architecture/decisions/README.md)
- [Find implementation standards and detailed guides](development/README.md)
- [Run fast, focused and complete checks](development/testing.md)
- [Prepare an engine release](development/engine-releases.md)
- [Verify source releases](development/publication.md) and [review source provenance](development/publication-audit.md)

[Third-party licences](../THIRD_PARTY.md) and the [dependency policy](development/dependencies.md)
describe what ships with the product. Historical decisions retain their dates;
use current guides and checks for supported behaviour.
