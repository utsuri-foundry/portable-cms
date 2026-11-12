# Tests

Shared fixtures, unit tests, adapter integration and browser journeys prove the
product's contracts. Tests may use the engine, profiles and operator tools;
production code must never depend on test helpers.

## Choose the right layer

| Directory | What it proves |
| --- | --- |
| `php` | Domain rules, schemas, adapters, MariaDB, bundles and recovery |
| `unit` | JavaScript modules, browser contracts and developer tooling |
| `browser` | Real administration workflows, native forms and enhancements |
| `public-site` | Generated HTML, accessibility, performance and audit rejection |
| `fixtures` | Shared deterministic inputs and licensed test assets |

Use the smallest layer that proves the change. Keep tests deterministic and
bounded; inject time, IDs, providers and failures. Database and browser harnesses
use disposable local installations. Never point them at a client installation.

## Run checks

From the repository root:

```sh
nix develop -c just test
nix develop -c just test-bundle
nix develop -c just test-mariadb
```

For one JavaScript test, use
`nix develop -c node --test tests/unit/NAME.test.js`.
Browser and integration checks have named lanes:
`nix develop -c sh tools/ci/run-check.sh LANE`.
See [CI tooling](../tools/ci/README.md) for lane selection.

Before review, run `nix develop -c tools/check-fast.sh` and the affected lanes.
Full qualification is `nix develop -c tools/check-repository.sh`.
The [testing policy](../docs/development/testing.md) defines when each is required
and how to diagnose hosted runs.

## Maintain fixtures

Schema cases live in [`contract-cases-v1.json`](../schemas/fixtures/contract-cases-v1.json).
They distinguish accepted, structurally rejected and semantically rejected
inputs. Browser decoders share fixtures with PHP where a format crosses both.

The [Reference Studio fixture](../profiles/reference-studio/README.md) pins
compiled artifacts and search data. Regenerate expected output only after
reviewing the intended contract change; do not refresh it merely to pass a test.
Keep asset provenance with the fixture, including the
[generated video recipes and checksums](fixtures/video/README.md).
