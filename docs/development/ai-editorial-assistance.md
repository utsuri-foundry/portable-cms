# Optional AI editorial assistance

The PHP administration offers optional image description, caption, contextual
alternative text, and taxonomy label drafts. Every operation has a native form
path. An AI request, status check, cancellation, or suggestion application
preserves the submitted record revision and unsaved form values. Reopening a
record resumes the latest private request for that administrator. Applied values
enter the ordinary record-save service: automatically with scripts, or through
**Save draft** without scripts. Publishing remains a separate human action. Taxonomy drafts populate
the existing localized term form; **Create and select** remains a separate,
authorized human action. A taxonomy draft never creates or activates a term.

## Installation and qualification

AI is disabled by default: `"aiAssistance": {"enabled": false}`. Missing cURL,
missing configuration, invalid configuration, absent worker qualification, or
missing provider privacy qualification visibly disables assistance and leaves
editing, media, export, preview and publication independent of AI.

A qualified installation may add this owner-only configuration outside the
document root. These example values are fictional and must be replaced during
operator qualification:

```json
{
  "aiAssistance": {
    "enabled": true,
    "workerAvailable": true,
    "providerNoTraining": true,
    "providerZeroRetention": true,
    "endpoint": "https://gateway.example.invalid/editorial",
    "credential": "replace-with-private-gateway-token",
    "publicAddress": "93.184.216.34",
    "provider": "reviewed-gateway",
    "model": "reviewed-image-model-version",
    "residency": "EU",
    "retentionHours": 24,
    "dailyRequests": 20,
    "dailyBudgetMicros": 1000000,
    "attemptCostMicros": 1000,
    "excludedTypes": [],
    "excludedMedia": []
  }
}
```

The installation must qualify its HTTPS gateway, pinned public IPv4 address,
TLS certificates, processing residency, no-training and zero-retention agreement,
hard per-attempt USD cost ceiling, idempotency, and cron before enabling AI.
These declarations are operator assertions, not a claim that the engine can
independently verify a provider's legal or operational commitments. Endpoint
redirects, private addresses, arbitrary provider URLs, and browser credentials
are unsupported. Only a reviewed gateway implementing the internal request
contract is supported; a vendor-specific API needs an adapter at that boundary.
Changing provider/model, endpoint/address, residency, exclusions, retention or
cost policy invalidates outstanding suggestions. Credential rotation does not
change canonical content or require migration.

Run the bundled worker every five minutes with the installed profile package:

```text
php /private/runtime/tools/conventional-ai-worker.php \
  --config=/private/cms.json --profile-root=/private/profile --max-jobs=1
```

The worker uses the installation’s private `profileRoot` unless explicitly
overridden by `--profile-root`. The legacy fixture profile does not enable AI. The fictional Reference Studio
profile declares `ai_drafts` optional. Cron and post-response media processing
remain independent; an AI worker failure cannot block either. The CLI performs
at most three jobs per invocation, one provider call per job, a 45-second transfer
limit and a two-minute lease. An installation permits one active AI lease.
Missing or disabled AI settles pending requests as unavailable when reconciled
by the administration or worker. An absent worker cannot enqueue new jobs when
its qualification is disabled. Operators must disable AI before removing cron.

## Request and result contracts

The server chooses a saved, ready image already selected in a top-level media
field of the record. Operator exclusions by record type and media ID are checked
both at request time and before dispatch/application. The editor explicitly
checks permission to send that image and its contextual note on each request.
The note is at most 1,000 Unicode characters. No other record content is sent.
Only the verified, metadata-stripped `960-webp` derivative is transmitted,
never an original, filesystem path, session, or complete record. The derivative
must be at most 4 MiB. Up to ten installed locales are supported by this recipe.

The private job freezes original and derivative checksums, source revision,
the revision digest of all installed taxonomy terms (bounded to 1,000), locales,
profile identity/version, recipe, provider/model and policy fingerprint.
Source freshness is checked before dispatch, after response and before human
application. A newer source or taxonomy version requires a new request.

The gateway receives one JSON object: `formatVersion: 1`,
`recipe: "image-editorial-v1"`, model, locales, context, `maximumCostMicros`,
residency and `image: {mime, sha256, dataBase64}`. The authorization header carries
the private gateway token. `Idempotency-Key` is the opaque job ID and stays the
same across recovery/retry. The gateway must enforce the maximum cost and
return only `portable-cms-ai-candidate-v1.schema.json`, with exactly the frozen
locale set and separate description, caption, alt and taxonomy strings.
Provider types and identifiers never enter saved content, exports or artifacts.

Candidate output is plain text: no markup, control characters, tools, field
IDs, record IDs, or provider metadata. Bounds are 2,000 characters for description,
500 for caption, 300 for alt, and five taxonomy labels of 100 characters per
locale; the full response is at most 64 KiB. The UI escapes every string and
requires a compatible target field before replacing one unsaved value. A target's
own length limit still applies. Native taxonomy label forms expose every language
for review; unequal or missing proposed translations must be completed manually.
Rich-text targets receive one validated paragraph of plain text. Composition and
nested field rewriting are outside this recipe.

## Recovery, cost, retention and diagnostics

A server-generated native form request token prevents duplicate delivery from
creating another job or charging quota twice. MariaDB serializes daily request
and cost reservations. Both attempts' maximum cost is reserved before enqueue;
cancellation, failure and expiration do not refund it. Limits are installation
wide, not per tab or browser. The maximum is 100 requests and 100 USD reserved
per UTC day. Cost is a conservative reservation, not an actual billing meter.

Only explicit gateway 429/503 responses request a second attempt after five
minutes. Ambiguous timeouts and transport failures are terminal because dispatch
may already have incurred cost. An interrupted lease may be recovered once,
using the same idempotency key; the qualified gateway must deduplicate it.
A second exhausted lease becomes a terminal failure. Cancellation clears the
lease and discards any later result; a dispatched remote call cannot be recalled.

Request context, frozen provenance and candidates stay in private MariaDB for
at most the configured 1–168 hours. Every worker run and authorized status read
purges expired private payloads. Minimal tombstones last another 30 days;
quota counters last 90 days, so purging cannot restore a consumed daily quota.
Backups must use the installation's independently qualified private retention.
No provider body, prompt, credential or exception message enters error logs.
Only stable allowlisted error codes are retained. Results belong to the requesting
administrator and record. Authentication, same-origin and CSRF checks surround
all request, application and cancellation actions.

## Verification

`nix develop -c tools/check-repository.sh` includes the candidate fixture/schema
and adapter tests, deterministic fake provider, real MariaDB idempotency,
lease/recovery/cancellation/quota/retention/staleness/security tests, and the
Chromium no-script request/review/application journey. The fake provider exists
only under `tests/`; production has no fixture switch or test endpoint. Tests
never contact a provider or incur cost.
