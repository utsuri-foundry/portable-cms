# Provenance

Portable CMS began as an in-house conventional-hosting prototype. The reusable
PHP/MariaDB foundation was extracted into an independent product in October
2026. That extraction retained the runtime, forward migrations, bounded schemas,
operator tools and synthetic tests, while excluding client content, branding,
private media, credentials, local state and provider deployment configuration.
The [extraction mapping](docs/extraction/gate0-import-v1.json) records the technical
path transformations and exclusions without identifying private projects.

Publication uses a reviewed source snapshot with a fresh Git history. The
original development history, permanent internal task records and detailed
extraction evidence remain in a private archive; none of those Git objects or
refs is imported. The public root commit records the reviewed public tree,
not an ancestry link to that archive. See [the publication decision](docs/architecture/decisions/0010-apache-2-publication.md)
and [publication procedure](docs/development/publication.md).

Original software, documentation and fictional acceptance material are copyright
2026 Utsuri Foundry, SL, licensed under Apache-2.0. Third-party libraries and
fonts retain their licences and notices in [the inventory](THIRD_PARTY.md).
Reference Studio is fictional original acceptance material. Its
[asset provenance](profiles/reference-studio/PROVENANCE.md) records the fonts,
icon and geometric images; [test video provenance](tests/fixtures/video/README.md)
records the synthetic clips. No client permission to publish is assumed.
