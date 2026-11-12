<?php

declare(strict_types=1);

return [
    'schedule.when' => 'Go live',
    'schedule.now' => 'Now',
    'schedule.later' => 'At a date and time',
    'schedule.date' => 'Date and time ({zone})',
    'schedule.interval' => 'The worker starts publication on its first run at or after this time. The actual start depends on its configured interval.',
    'schedule.move' => 'Move scheduled release',
    'schedule.cancel' => 'Cancel scheduled release',
    'schedule.stale' => 'Another release went live. This schedule needs a new approval. Inspect the preview and approve it again against the current release.',
    'schedule.included' => 'Scheduled release {id}: {time}',
    'schedule.frozen' => 'This release keeps its approved preview. Later record edits do not change it. A scheduled release can also remove content.',
    'schedule.error.invalid' => 'Enter a valid date and time in the named time zone. Times skipped by a clock change are unavailable.',
    'schedule.error.ambiguous' => 'This time occurs twice during a clock change. Choose an unambiguous time.',
    'schedule.error.range' => 'Choose a future time no more than one year ahead.',
    'publication.status.scheduled' => 'Scheduled',
    'publication.status.needs_approval' => 'Needs a new approval',
    'publication.status.cancelled' => 'Cancelled',
];
