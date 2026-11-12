<?php

declare(strict_types=1);

return [
    'publication.feed_pending' => 'The content feed is still being updated. It will resume automatically. Contact the site operator if this message remains.',
    'publication.status.needs_attention' => 'Needs operator attention',
    'publication.title' => 'Website releases',
    'publication.help' => 'Inspect an exact preview, then approve its frozen bytes for the public website. Record status changes alone never update the live site.',
    'publication.unavailable' => 'Publication is unavailable until an operator configures a dedicated static document root and public URL context.',
    'publication.error' => 'The release action could not be completed. Review the code below and refresh before trying again.',
    'publication.current' => 'Current release: {id}',
    'publication.none' => 'No release yet',
    'publication.pending' => 'Publication or recovery is in progress. The previous verified release remains recoverable. Cron continues the bounded work.',
    'publication.refresh_work' => 'Continue background work',
    'publication.approve' => 'Approve and publish',
    'publication.inspect' => 'Inspect preview',
    'publication.preview' => 'Exact preview',
    'publication.confirm' => 'I have reviewed this action and its effect on the public website.',
    'publication.history' => 'Release history',
    'publication.rollback' => 'Restore this release',
    'publication.retention' => 'Retention',
    'publication.forget_help' => 'Forgetting removes this rollback option. Its preview can then be deleted if no other release retains it. The active release cannot be forgotten.',
    'publication.forget' => 'Forget retained release',
    'publication.status.queued' => 'Queued',
    'publication.status.applying' => 'Applying',
    'publication.status.recovering' => 'Recovering previous release',
    'publication.status.active' => 'Active',
    'publication.status.retained' => 'Retained for rollback',
    'publication.status.failed' => 'Failed; previous release restored',
];
