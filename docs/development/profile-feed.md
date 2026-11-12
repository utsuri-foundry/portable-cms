# Select content for a public feed

A content feed makes selected published content available as static JSON. Use it
when another website or integration needs to read that content without querying
the CMS. Exposure is opt-in: undeclared types and fields stay out.

## Public content feed

A profile can opt types and individual leaf fields into a static machine-readable
feed. Absence means no feed. For example:

```json
"contentFeed": {
  "formatVersion": 1,
  "pageSize": 100,
  "recordTypes": [
    {"typeId": "project", "fields": ["title", "body", "gallery"]},
    {"typeId": "article", "fields": ["title", "excerpt"]}
  ]
}
```

Only Published records and the frozen artifact's languages enter it, including
when a review-only preview also draws drafts or languages in preparation.
Every field must be named: adding a field to a type never adds it to the feed.
The same PHP content service validates frozen values before projection.
Each language collection carries stable record IDs, revision numbers and that
language's declared values. References retain their canonical identities and do
not expand target records. Optional values remain null or their canonical empty
value. Revision numbers can change even when only an unexposed field was edited.

V1 supports text, rich text, integers, flags, dates, enums, record/taxonomy
references, web addresses and image references. Groups, blocks, composition and
video are unsupported: they need a separate explicit child or media-selection contract.
Rich text and addresses keep their validated portable
documents. Images become checksum-addressed resized WebP URLs under
`feed/v1/media/`; originals, object keys and administration metadata are excluded.
These copies enter the verified artifact even when HTML does not select them.

### Limits

| Declaration | Bound |
| --- | --- |
| `pageSize` | 1–100; default 100 |
| Record types | At most 64 |
| Fields per type | At most 32 |
| Each feed JSON document | At most 8 MiB |

Empty collections have one empty page. Snapshot and artifact limits still apply.

### Snapshot identity

The digest covers page checksums, record fingerprints and media facts, excluding
build time, release identity and unrelated presentation. Hash the manifest's
compact UTF-8 bytes with its first `snapshotId` property omitted, including the
trailing newline. Do not reserialize it: different key order or escaping changes
the bytes.

The manifest is at `feed/v1/snapshots/<sha256>/manifest.json`; its page paths are
relative to that snapshot directory. Exact previews contain the bytes they would
publish. A feed-declaration change requires a newly inspected artifact, without
rewriting authored records.

Publication adds separate activation history and retention; see
[consuming and operating the feed](content-feed.md#public-content-feed).
