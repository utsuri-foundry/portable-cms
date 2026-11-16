<?php

declare(strict_types=1);

return [
    'schedule.when' => 'When',
    'schedule.now' => 'Now',
    'schedule.later' => 'On a date and time',
    'schedule.date' => 'Date and time ({zone})',
    'schedule.for' => 'Scheduled for {time}',
    'schedule.help' => 'Publishing takes a few minutes. A scheduled release starts within a few minutes of its time.',
    'schedule.move' => 'Move',
    'schedule.cancel' => 'Cancel',
    'schedule.stale' => 'Another release went live since this was approved. Approve it again to publish it.',
    'schedule.included' => 'Scheduled release: {time}',
    'schedule.frozen' => 'This release keeps the version approved for it. Later edits do not change it.',
    'schedule.error.invalid' => 'Enter a valid date and time in the named time zone. Times skipped by a clock change are unavailable.',
    'schedule.error.ambiguous' => 'This time occurs twice during a clock change. Choose an unambiguous time.',
    'schedule.error.range' => 'Choose a future time no more than one year ahead.',
    'publication.status.scheduled' => 'Scheduled',
    'publication.status.needs_approval' => 'Needs a new approval',
    'publication.status.cancelled' => 'Cancelled',
];
