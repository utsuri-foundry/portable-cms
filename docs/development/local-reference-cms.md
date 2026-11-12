# Local Reference Studio CMS

## Purpose

The local sandbox is the persistent product-review environment for Portable
CMS administration. It runs the real PHP application and MariaDB repositories,
loads the verified Reference Studio profile, and starts with representative
multilingual records. It is not a static mock and is not a deployment model.

## Development setup

Follow [CONTRIBUTING](../../CONTRIBUTING.md) to install Git and Nix with flakes
enabled on Linux, then install the locked development dependencies from the
repository root:

```sh
nix develop -c composer install --no-interaction --prefer-dist
```

Reference Studio is fictional test content for development and verification.
Its design is not a promoted showcase or a client template.

## Run the administration

Start it from the repository root:

```text
nix develop -c just serve-reference-cms
```

The command starts an isolated MariaDB process without network access, applies
all migrations, creates the development administrator on first use, seeds an
empty database, adds verified fictional images to the normal derivative queue,
runs the bounded media worker, and serves the CMS at
`http://localhost:4181/`. Stop the complete process group with `Ctrl-C`.

Sign in with the local development account:

```text
Login:    admin@portable.test
Password: portable-cms-local
```

State is retained at `.local/reference-cms/`. Startup is idempotent and never
replaces edited records. The command refuses a second concurrent start. Use the
following explicit destructive command only while the sandbox is stopped:

```text
nix develop -c just reset-reference-cms
```

The reset target is fixed to the ignored sandbox directory. It refuses a
symbolic-link target and refuses to run while the sandbox database process is
alive. The next start creates a fresh seed.

## Build and view the static site

To build the fictional profile's committed content and view its static output:

```sh
nix develop -c just build-reference
nix develop -c just serve-reference
```

Open `http://127.0.0.1:4173/`. This serves the built files independently of the
administration and database; it does not include edits from the administration
sandbox. The builder refuses an existing output directory. See the
[profile README](../../profiles/reference-studio/README.md) for build options.

For repository checks and the complete qualification command, follow the
[testing policy](testing.md).

## Security boundary

Production runtime configuration accepts HTTPS origins only. The sandbox sets
an explicit development flag that permits HTTP only for `localhost`,
`127.0.0.1`, or `::1`. Origin and fetch-metadata validation remain active.
External HTTP hosts are rejected even when the flag is present. HSTS and the
CSP upgrade directive are omitted only for that loopback response; the rest of
the administration security policy and secure host-only cookies remain intact.

The printed account and database credentials are fixed development values.
They must never be copied to a conventional installation. The database socket,
configuration, media, logs, and browser-check artifacts remain in ignored
local storage.

## Current product boundary

Record lists, creation, revisioned edits, validation, conflict handling, media
upload and processing, the media library, native relationship/group controls,
and normalized export use the real product services. See
[editorial controls](./editorial-controls.md) for limits and review journeys.
Reference Studio exact-preview compilation uses frozen database snapshots and
the ordinary static compiler. The Previews area builds and opens a private copy
of saved content currently included in the website; new drafts stay excluded.
Status, deep navigation, loaded fonts/media and guarded deletion use the real
workflow. Releases queues verified promotion and retained rollback to the isolated
`.local/reference-cms/public/` root. The existing worker resumes bounded
publication work. That directory contains static files, not the CMS or private
media; localhost:4181 remains the administration server. Restart a stopped
sandbox to apply new migrations and runtime configuration. Qualified-host
acceptance is a separate installation check. See [exact website preview](exact-website-preview.md)
and [publication and recovery](publication-workflow.md).

## Other profile packages

`tools/serve-profile-cms.sh` runs the same sandbox for any verified profile
package, with its own state directory, database socket and port, and no seed
content. Set `PROFILE_ROOT`, `STATE` and `PORT`, or use
`nix develop -c just serve-profile-cms <profile-root> [port]`. See
[profile authoring](profile-authoring.md) for package and renderer contracts.
