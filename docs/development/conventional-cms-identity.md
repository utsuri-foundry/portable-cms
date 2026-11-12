# Install the CMS and manage accounts

A CMS installation has one owner, administrators and editors, each with individual
credentials. Install it on its own HTTPS origin and keep its configuration,
sessions and working files outside every public document root.

For a new host, first run [host qualification](conventional-host-probe.md). Then
prepare private configuration, apply migrations and bootstrap the first owner.
Use operator-assisted recovery if that owner loses access.

## Private configuration

Copy [`config/conventional-cms.example.json`](../../config/conventional-cms.example.json) to an owner-only file outside the document root. Replace every placeholder and set mode `0600`. Generate the throttle secret locally:

```sh
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
```

The configuration loader refuses a group-readable or world-readable file and, in web execution, refuses a configuration below the document root. Error logs contain only UTC time, correlation ID, and exception class. SQL messages, submitted values, paths, and credentials are not logged by the application.

## Migrations and first owner

Apply the forward migration ledger from the private runtime bundle:

```sh
php tools/conventional-migrate.php --config=/absolute/private/cms.json
```

Bootstrap closes transactionally after the first user. The password is either collected twice from a hidden terminal or read from an owner-only file beside the private configuration. It is never accepted as a command argument.

```sh
php tools/conventional-bootstrap-admin.php \
  --config=/absolute/private/cms.json \
  --login=admin@example.test \
  --display-name='Named Administrator' \
  --password-file=/absolute/private/bootstrap-password
```

The historical command name remains `conventional-bootstrap-admin.php`, but it
creates an owner. Remove the temporary password file after the command succeeds.
A concurrent or repeated bootstrap observes the locked, closed singleton and fails.

### Hosts without command execution

Some shared-host accounts expose SFTP and cron management but no interactive or non-interactive SSH command execution. The bundle therefore contains a narrowly scoped `install.php` entry point. It accepts only HTTPS `POST`, requires a 256-bit random operator token in the `X-CMS-Install-Token` header, bounds the request body, and returns no host or database diagnostics. The private gate expires after one hour. A successful bootstrap atomically closes it and removes the stored token digest.

Generate the runtime configuration, one-use gate, and separate token file locally from an owner-only database-password file:

```sh
php tools/conventional-create-install-config.php \
  --runtime-output=.local/install/cms.json \
  --runtime-config-path=/absolute/remote/private-cms/cms.json \
  --gate-output=.local/install/install.json \
  --token-output=.local/install/install-token \
  --origin=https://cms.example.test \
  --dsn='mysql:host=db.example;dbname=cms;charset=utf8mb4' \
  --username=cms \
  --database-password-file=.local/install/database-password \
  --error-log=/absolute/remote/private-cms/cms-errors.log \
  --upgrade-mode=in_place \
  --database-maximum-bytes=1073741824 \
  --profile-root=/absolute/remote/private-cms/profile \
  --public-base-url=https://www.example.test \
  --publication-root=/absolute/remote/public-website \
  --time-zone=Europe/Andorra
```

The runtime configuration names the installation's tables with `database.tablePrefix`, which defaults to `cms_`. Pass `--table-prefix` (one lowercase word and a final underscore, at most 16 characters) when the database also holds another table set, such as a separate installation or an upgrade's staged copy. Every table, constraint and index of the installation carries the prefix; engine SQL and migrations keep their canonical `cms_` names and checksums.

Choose `--upgrade-mode` during host qualification: `table_set` stages a second
table set in the same database; `in_place` closes the CMS while changing its
tables and restores the verified backup on failure; `separate_database` uses an
empty database provisioned through the control panel. Pass
`--database-maximum-bytes` with the host's qualified database quota as a positive
whole byte count; the number above is an illustrative 1 GiB limit. Table-set
upgrades need quota for both copies. These options write `database.upgradeMode`
and `database.maximumBytes` using the runtime's validators. Invalid values leave
no configuration, gate or token. When omitted, both keys stay absent, the
effective mode remains `table_set`, and no quota is recorded. The tool reports
the selected mode. See [installed upgrades](conventional-cms-upgrades.md) for
backup, maintenance, capacity and rollback requirements. Changing an existing
installation's mode requires an explicit operator decision and new host
qualification.

For a packaged profile that publishes a website, the profile and publication options write
`profileRoot`, `publicUrlContext` (`formatVersion: 1` and `baseUrl`) and
`publicationRoot`. The creator checks absolute remote paths without resolving
them on your workstation, and validates the public HTTPS address with the
runtime's rules. It refuses credentials, query strings, fragments and a public
address on the CMS origin, including a different path on that same origin.
Invalid settings leave no configuration, gate or token.

The optional `--time-zone` writes the installation's IANA `timeZone`, shared by
web and CLI runtimes. It defaults to `UTC` when omitted. Scheduled releases use
this named zone for date input and display, and retain their UTC instant if an
operator later changes the zone. See [scheduling](publication-workflow.md#scheduled-releases).

The bundle builder's `--profile-root` names the verified **local** package;
the creator's `--profile-root` names its **remote** installed location. Upload
the bundle's `private/profile/` to the remote path shown above. Provision the
publication folder and its public hostname through the hosting control panel:
the tool does not create it or configure the web server. On the host, the
runtime verifies that the profile is private and the publication folder exists,
is writable, and is separate from private storage and the CMS document root.
Publication requires both the public address and the publication folder.

Omitting the three options keeps their keys absent and preserves the original
configuration. The tool then states that the installation will not use a
packaged profile or publish. Each option can also be supplied independently;
for example, a packaged profile can provide exact previews without enabling
publication. Generate these settings with the command rather than editing
the owner-only `cms.json` after creation.

After uploading the private files and bundle, call the installer with owner-only token and administrator-password files:

```sh
php tools/conventional-install-client.php \
  --endpoint=https://cms.example.test/install.php \
  --token-file=.local/install/install-token \
  --password-file=.local/install/administrator-password \
  --login=admin@example.test \
  --display-name='Named Administrator'
```

The client verifies HTTPS through PHP's CA configuration, refuses redirects, and puts credentials only in the request body/header. Delete the public installer and private gate after the successful response. The closed private gate remains the correctness boundary if cleanup is delayed or interrupted.

## Shared-host bundle

Build a no-Composer deployment bundle with the exact remote private root:

```sh
php tools/build-conventional-cms-bundle.php \
  --output=.local/conventional-cms-bundle \
  --private-root=/absolute/remote/private-cms \
  --profile-root=profiles/reference-studio
```

Upload `private/runtime/` and the packaged `private/profile/` outside the document root, upload the generated owner-only `cms.json` beside `runtime/`, and upload `public/` as the dedicated CMS hostname's document root. The bundle's `public/` directory serves the administration; the separate publication folder receives the approved static website. The generated public entry point contains only the absolute private include/configuration locations. The bundle manifest records SHA-256 and byte length for every generated file. The builder refuses to merge into or overwrite an existing output directory.

The included `.htaccess` uses Apache `FallbackResource` so server-rendered routes reach the one front controller. A real-host acceptance run must confirm this behavior rather than assuming every provider has the same Apache configuration.

## Roles and account management

The installation-created account is the owner. Migration `0015-account-roles`
promotes the earliest active administrator on an existing installation without
changing its password or authenticated sessions. New installations bootstrap
an owner directly. The database enforces at most one owner and an active owner;
account transactions serialize through the bootstrap singleton and require
exactly one active owner before commit. Ownership transfer demotes the old owner
and promotes an active administrator in that same transaction, never as two
independent requests. MySQL support requires its documented 8.0.19 or newer
`DROP CONSTRAINT` syntax ([MySQL manual](https://dev.mysql.com/doc/refman/8.0/en/alter-table.html));
the repository integration lane exercises MariaDB.

- Owners alone create or manage administrators and transfer ownership.
- Owners and administrators create and manage editors. They cannot manage
  themselves or accounts at or above their own authority through `/accounts`.
- Editors author records, translations, relationships and media and create
  exact previews. They can read the site's languages and release schedules but cannot publish or
  change website settings, languages, design, terminology, exports, releases,
  guest shares or other accounts. The central
  server route policy rejects forged requests, not only navigation links.
- Every person edits their own display name, login email and password at
  `/account`. Login or password changes require their current password and
  revoke all other sessions while retaining the changing session.

Accounts use unique normalized email logins. Creation and resets use an
operator-supplied temporary password, delivered securely outside the CMS; no
invitation email is sent. First sign-in can reach only the replacement form,
logout and personal interface preferences. Replacement requires the temporary
password and a different new password before editorial access is available.
Passwords contain 14 to 72 bytes without NUL, avoiding bcrypt truncation.
Names and emails may be changed without changing roles. Passwords never return
in rendered HTML, preserved error input, logs or audit payloads.

Native forms require exact origin, session-bound CSRF and current account
revision. Conflicts preserve only non-secret input and require passwords to be
entered again. Account changes append attributed audit events in the same
transaction. Deactivation, removal of access, resets and role changes revoke
all target sessions. Ownership transfer signs both people out. Removal is a
retained inactive identity, not deletion of authorship or audit history; the
UI explicitly labels this and permits later reactivation.

The account security version is stored on every session and compared against
the current account when opening it. A concurrent sign-in cannot restore
authority using a password read before a reset. A stale rehash cannot overwrite
a newer password. Managed changes recheck the acting session and role under
the transaction lock; revoked actors cannot perform a subsequent account change.

Some privacy-oriented browsers serialize a same-origin form submission with the literal opaque Origin value `null`. The compatibility path accepts that value only when the browser-controlled `Sec-Fetch-Site` header is explicitly `same-origin`; the session-bound hidden CSRF value and `SameSite=Strict` CSRF cookie must still match. Opaque Origin with absent or non-same-origin Fetch Metadata fails closed. Ordinary origins are parsed and compared by HTTPS scheme, normalized host, and effective port, so an explicit default port `443` is equivalent to an omitted port without weakening the origin boundary.

## Operator-assisted owner recovery

Recovery requires authorized write access to the private installation, not an
anonymous password-reset page. Generate a one-hour gate and separate token file
locally, specifying the installed absolute configuration path:

```sh
php tools/conventional-create-owner-recovery.php \
  --config-path=/absolute/remote/private-cms/cms.json \
  --gate-output=.local/recovery/recovery.json \
  --token-output=.local/recovery/recovery-token
```

Upload both outside the document root beside `cms.json`, with owner-only
permissions. Put a new temporary password in a separate owner-only file in
that same directory; do not put it in a command argument or cron field. Run
this command on the host, through PHP CLI or a single manual cron invocation:

```sh
php tools/conventional-recover-owner.php \
  --gate=/absolute/private-cms/recovery.json \
  --token-file=/absolute/private-cms/recovery-token \
  --password-file=/absolute/private-cms/recovery-password \
  --document-root=/absolute/cms-document-root
```

The command honors the installed upgrade lease, validates the expiring private
gate and token, resets only the current owner, revokes all its sessions, and
records an operator recovery audit event without an authenticated actor. It
prints only completion or a safe exception class. The owner must replace this
temporary password at next sign-in. A unique transactional recovery receipt
prevents replay even if replacing the gate file is interrupted. Remove the gate,
token and password files and any one-off cron after success.

The bundle also contains an optional operator-only `recover-owner.php` front
under `operator/`, not installed in `public/` by default. If CLI and cron cannot
be used, an operator may mount it temporarily at the CMS HTTPS origin. It uses
the private `recover-owner.json` beside `cms.json`, a token in the
`X-CMS-Owner-Recovery-Token` header, and a bounded URL-encoded `password` body.
Clients must verify TLS and refuse redirects. Never send secrets in URLs,
command arguments or access logs; remove this front immediately after recovery.

## Authentication and session security

- MariaDB stores users, anonymous pre-auth sessions, authenticated sessions, login throttles, bootstrap state, and attributed audit events.
- Passwords use PHP's current `PASSWORD_DEFAULT` policy and upgrade after a successful login when PHP changes that policy.
- Session and CSRF values are independent 256-bit random credentials. MariaDB stores only their SHA-256 digests.
- Authentication rotates both credentials and revokes the anonymous session. Logout revokes the server session and expires both cookies.
- The session cookie is host-only, `Secure`, `HttpOnly`, and `SameSite=Lax`. The readable CSRF cookie is host-only, `Secure`, and `SameSite=Strict`.
- Every mutation requires the session-bound CSRF value plus either an exact parsed HTTPS origin or the narrowly scoped opaque-origin compatibility signal described below. A contradictory `Sec-Fetch-Site` is rejected.
- Account and client login limits use keyed, non-reversible identifiers and durable MariaDB rows.
- Every administration response is `no-store`, `noindex`, protected by the administration CSP and related security headers, and carries a correlation ID.
- Dynamic text, attributes, and URLs pass through context-specific helpers. PHP view files cannot use `echo`, `print`, or short echo syntax; hostile-value tests cover the first login and overview pages.

The administration hostname is expected at its own HTTPS origin. Identity routes include `/login`, `/`, `/logout`, `/account` and `/accounts`; ordinary public-site requests do not reach this application.

## Verification

Qualify the installation with the [acceptance checklist](reference-host-acceptance.md).


Run the dependency-free PHP suite and the separate real-MariaDB integration test inside the pinned shell:

```sh
nix develop -c php tests/php/run.php
nix develop -c sh tests/php/run-mariadb-integration.sh
```

The integration test starts isolated MariaDB instances, applies and reapplies
migrations, closes bootstrap, authenticates through stored binary hashes, audits
outcomes, exercises durable throttling, and completes the one-use no-shell
installation path. The account lane proves legacy promotion, role hierarchy,
temporary passwords, concurrent-session revocation, forged editor HTTP requests,
ownership transfer, one-use recovery and exact full-backup restoration with the
generated owner constraint. Pure tests pin CSRF, forged identities, conflicts,
last-owner protection, bounded passwords and redacted input. The browser journey
uses native forms with page scripts disabled at 360px to create an editor,
replace its temporary password, author content, create a preview and update
its own credentials.

Focused checks:

```sh
nix develop -c env CMS_TEST_ACCOUNTS_ONLY=1 sh tests/php/run-mariadb-integration.sh
nix develop -c env CMS_TEST_ACCOUNTS_ONLY=1 sh tests/browser/run-record-workflow-journey.sh
```
