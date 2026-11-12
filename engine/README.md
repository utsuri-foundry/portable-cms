# Engine

The reusable CMS runtime: content editing, media, previews, static compilation,
publication and portable content transfer. It also owns the administration and
its native JavaScript enhancements.

Site-specific content, design and renderers belong in profiles. The engine may
depend on versioned schemas and reviewed runtime libraries, but never on a
particular profile, client, provider identity or credential.

## Find the code

Most PHP code lives in [`src/cms/conventional`](src/cms/conventional/):

| Area | Responsibility |
| --- | --- |
| `content`, `profile` | Records, field validation and profile contracts |
| `identity`, `http`, `view`, `i18n` | Accounts, requests, administration and translations |
| `client` | Administration JavaScript islands |
| `media`, `design`, `ai` | Media processing, design settings and optional assistance |
| `compiler`, `preview`, `publication` | Frozen builds, private previews and approved static releases |
| `export`, `restore`, `import`, `transfer` | Portable content and resumable transfers |
| `database`, `installation`, `upgrade` | Persistence, setup and installed upgrades |

[`src/browser`](src/browser/) contains engine-owned browser modules used by
public sites. `bootstrap.php` loads the PHP runtime; application factories wire
services to their adapters.

Domain validation lives once in PHP services. HTTP, database, filesystem and
provider adapters supply effects through explicit boundaries. Browser islands
enhance native forms; they do not become a second content authority. See the
[architecture](../docs/architecture/README.md) and
[PHP standard](../docs/development/php-standard.md).

## Check a change

Run from the repository root, inside the pinned development environment:

```sh
nix develop -c just test
nix develop -c just test-bundle
```

For database changes, use `nix develop -c just test-mariadb`. Before review, run
the fast gate and affected lanes described in the [testing guide](../docs/development/testing.md).

Use the [documentation index](../docs/README.md) for feature contracts and
operating guides. [Profile authoring](../docs/development/profile-authoring.md)
describes the engine APIs available to site code; the
[release policy](../docs/development/engine-releases.md) governs compatibility.
