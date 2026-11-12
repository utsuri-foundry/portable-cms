# Conventional-host qualification probe

## Hosting requirements

The authoring application requires PHP 8.2 or later within PHP 8.x and a
qualified MariaDB 10.6+ or MySQL 8.0.19+ host, HTTPS, private writable storage,
and the extensions and capabilities checked by this probe. Image processing
and other optional capabilities are qualified explicitly. SQLite is planned,
not currently a supported production backend.

Customer installations need no Node.js, Composer, Docker, Nix or persistent
process. Unattended jobs need scheduled cron. These requirements apply to
authoring and publishing; the public website is served as static files.
The pinned development gate uses PHP 8.3 and MariaDB.

## Purpose

The qualification probe compares the effective PHP web and cron CLI environments on a candidate shared host. It measures behavior needed by the CMS rather than treating a hosting feature list as proof.

The probe is temporary qualification tooling, not a permanent public endpoint. It reports no configured paths, database DSN, username, password, authentication token, raw exception, or policy-file contents.

## Evidence collected

- PHP version, SAPI, integer width, loaded INI state, required extensions, upload/body/time/input limits, `disable_functions`, and whether `open_basedir` is active;
- session handler and security-relevant session defaults without returning the session path;
- writable-space measurements and disposable file write, rename, replace, directory-rename, symlink, and cleanup probes in each configured root;
- proof that private storage is outside the document root;
- an engine-neutral media report with Imagick version, advertised formats, actual in-memory PNG decode and JPEG/PNG/WebP/AVIF encode behavior, effective resource limits, and detection-only GD facts;
- inbound HTTPS observation, an actual outbound HTTPS request with CA and hostname verification, cron-heartbeat freshness, and post-response function availability;
- MariaDB/MySQL engine and version, SQL modes, server, selected-database, and active-connection character sets/collations, isolation, autocommit, temporary-table support, rollback, and compare-and-set behavior;
- a field-level web/CLI comparison whose differences are stable codes and already-redacted values.

Prepared-video reports also show the effective upload ceiling, refusal reasons,
native multipart ceiling and finite completion budget. Existing hosts report
40 MiB with `qualification_missing`. To assess recorded trailer evidence, add
its `videoUploads` object to the private web probe configuration and provide the
same three-field object in an owner-only JSON file through CLI
`--video-qualification=/absolute/private/video-qualification.json`. The report
rechecks PHP limits and current disk space; it does not measure the web proxy's
timeout or manufacture completion evidence. Follow the
[larger-trailer qualification procedure](conventional-cms-media.md#qualifying-larger-trailers)
before enabling the private runtime configuration.

The MariaDB transaction test uses a connection-local temporary table and removes it before returning. Filesystem tests use random private child names and remove them in `finally` cleanup. A cleanup failure is visible in the report. GD never satisfies the full-fidelity image-processing requirement; its presence does not enable an alternative processing tier.

## Prepare the private web configuration

Copy [the example configuration](../../config/conventional-probe.example.json) to a path outside the repository and document root, replace every placeholder, and restrict the file to the account owner and web process. It contains database credentials and must never be committed, uploaded into `public_html`, included in diagnostics, or retained after qualification. The repository also ignores `config/conventional-probe.local.json` and `conventional-*-report.json` as a secondary local safeguard, but an ignored file inside the document root is still unsafe.

Generate a one-hour random token and password hash locally:

```sh
php tools/conventional-probe-credentials.php
```

Copy `tokenHash` and `expiresAt` into the private configuration. Set `outboundHttpsUrl` to an operator-controlled HTTPS health endpoint that accepts unauthenticated `HEAD` and returns a 2xx or 3xx response. The URL is used for qualification but is not returned in the report. Keep `token` only long enough to make the authenticated request. Do not put the token in a repository file, ticket, chat transcript, or shell history.

Configure the web SAPI environment variable `CMS_PROBE_CONFIG` with the absolute private configuration path. Deploy `tools/conventional-environment-web.php` at a temporary unguessable operator-controlled URL or behind the hosting control panel's access restriction. The script additionally requires:

- HTTPS;
- `POST`;
- the short-lived value in `X-Probe-Token`;
- an unexpired configuration whose expiry is no more than 24 hours away;
- a configuration file outside the document root and without group/world permissions.

An invalid request receives only `404 not_found`. An authenticated report returns `200` when compatible or `422` when it contains blockers. Save the JSON to private operator storage as the web report.

`CMS_PROBE_ALLOW_INSECURE_LOOPBACK=true` exists only for the repository integration test and works only from a loopback client. Never configure it on shared hosting.

## Run the cron CLI report and comparison

Provide database credentials to the process environment, not command arguments:

```text
CMS_PROBE_DB_DSN
CMS_PROBE_DB_USERNAME
CMS_PROBE_DB_PASSWORD
CMS_PROBE_OUTBOUND_HTTPS_URL
```

Then run the CLI probe with the same storage roots and the downloaded web report:

```sh
php tools/conventional-environment-check.php \
  --private-storage=/absolute/private/cms \
  --publication=/absolute/public_html \
  --scratch=/absolute/private/cms/scratch \
  --document-root=/absolute/public_html \
  --cron-heartbeat=/absolute/private/cms/cron-heartbeat \
  --compare-web=/absolute/private/web-report.json
```

The command writes JSON to standard output and exits `0` only when the combined report is standalone-compatible. Exit `2` means measured blockers. A report whose `installationClass` is `managed_service_required` is deliberately still blocked for standalone use; it means an otherwise capable host would require the declared external raster service. Exit `64` or `65` means the comparison input could not be read or decoded. Redirect output to a private report file when running through cron or SSH.

Run the CLI command through the exact PHP binary and account context that cron will use. A shell session using a different PHP selector is not cron evidence.

## Cleanup and acceptance

After collecting the report:

1. remove the temporary web endpoint;
2. remove the private probe configuration and web token;
3. unset probe-specific environment variables;
4. retain the redacted web, CLI, and comparison reports with the private qualification evidence;
5. verify that every filesystem and database cleanup flag is true;
6. record observed cron cadence separately through the heartbeat path.

A local or VPS report proves only that the probe works. Product support requires the same journey on the actual shared-host product and both of its PHP SAPIs.

## Optional publication qualification

Runtime qualification and publication-mode qualification are distinct. The
current environment report does not certify Atomic delivery; a successful
rename, symlink probe or writable `.htaccess` alone cannot establish it. The
existing Standard target still needs its documented HTTP rules, storage,
verification and recovery checks.

Under [0007: Portable publication capabilities](../architecture/decisions/0007-portable-publication-capabilities.md),
future Atomic qualification must test actual static routing, denial of direct
access to staged and retained pages, a same-filesystem selector replacement,
concurrent requests, interrupted activation, cache behavior and rollback. It
must also record storage and inode headroom for retained releases. Additional
requirements belong to that optional target, not the baseline PHP installation.
Missing Atomic support leaves an otherwise qualified Standard host supported.

Retain dated evidence per tested stack and PHP web/CLI configuration, identifying
the available publication mode and measured limits. A future installer must use
the same qualification implementation rather than duplicating these checks.
Do not advertise broad provider compatibility from one host report or mark a
planned delivery capability available before its full journey succeeds.
