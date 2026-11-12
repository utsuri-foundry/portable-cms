# Security and privacy

The CMS, public artifact, operator tooling, external providers, and hosting control panel are separate trust boundaries.

- Install only on a qualified isolated account or enforceable equivalent sandbox.
- Keep secrets, private originals, sessions, previews, backups, work directories, and diagnostic logs outside the document root.
- Authorize before database, filesystem, image, AI, or deployment work.
- Require secure cookies, session rotation, same-origin mutation checks, session-bound CSRF, abuse controls, least privilege, audit, and explicit administrator lifecycle.
- Treat uploaded files, profile packages, exports, backups, rich text, URLs, paths, image metadata, and provider responses as hostile input.
- Bound request bytes, decoded image geometry, collection sizes, nesting, job attempts, leases, concurrency, runtime, output files, diagnostics, and retention.
- Redact configuration values, credentials, cookies, authorization headers, private paths, AI payloads, provider bodies, and personal data from browser errors and diagnostics exports.
- Preserve a provider-free baseline. Optional AI, email, object storage, CDN, access proxy, or bot protection cannot silently become mandatory.
- Public tracking, analytics, forms, and personal-data processing are profile decisions with explicit privacy, retention, processor, CSP, and failure-mode review.

Security lifecycle policy, vulnerability targets, supported versions, update recovery, end-of-support notice, and disclosure procedures must be accepted before general availability.

