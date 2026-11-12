<?php

declare(strict_types=1);

return [
    'export.capacity.details' => 'Export and restore limits',
    'export.capacity.legacy' => 'Browser download (v1): up to {records, number, integer} records, {revisions, number, integer} retained versions, {relationships, number, integer} current relationships and {media, number, integer} media records, within {mib} MiB of JSON.',
    'export.capacity.chunked' => 'Private chunked package (v2), prepared by your installation administrator: up to {records, number, integer} records, {revisions, number, integer} retained versions, {relationships, number, integer} current relationships and {media, number, integer} media records, within {mib} MiB of content chunks.',
    'export.capacity.scope' => 'These totals cover the whole installation, including drafts, archives, terms and settings. Each saved version contains its full body. Both paths allow up to {derivatives, number, integer} derivatives. All count and byte limits apply together; an oversized export is rejected, never truncated or pruned.',
    'export.capacity.restore' => 'Restore has separate file, byte, runtime and host checks. V1 needs free PHP memory of at least 64 MiB or sixteen times the JSON file size, whichever is larger. The measured v2 tier needs a qualified 256 MiB PHP CLI process; a record count alone does not qualify a host. Incomplete preparation must be resumed, not treated as a completed restore.',
    'export.capacity.bytes' => 'JSON excludes private originals, derivatives and uploaded fonts: an operator must package those bytes too. A content package excludes accounts, secrets, sessions, old audit history, job recovery and release activation. Keep a separately verified installation backup.',
    'export.capacity.exceeded' => 'This website exceeds at least one supported count or byte limit. Nothing has been deleted. Ask your installation administrator to review the capacity report and use a qualified export workflow before continuing to grow the website.',
    'preflight.issue.capacity' => 'This website exceeds a current preview size or record-count limit. Nothing has been deleted. Ask your installation administrator to review preview and full-export capacity before building.',
    'export.capacity.counts' => 'This website has {records} records and {revisions} saved versions, including drafts and archived content.',
    'export.capacity.warning' => 'This website is approaching a supported content, history or current-page size limit. Arrange a verified full export and review capacity before adding more content. Record history follows the configured retention limit.',
    'export.capacity.package' => 'Use the private chunked-package export workflow for a complete export of this website. Ask your installation administrator to run the bundled export packaging tool. Every retained version is included; no history is discarded.',
];
