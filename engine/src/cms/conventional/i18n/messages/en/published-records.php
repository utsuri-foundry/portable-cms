<?php

declare(strict_types=1);

return [
    'state.published_changes' => 'Ready, with changes',
    'workflow.publish' => 'Mark as ready',
    'workflow.publish_changes' => 'Mark changes as ready',
    'workflow.unpublish' => 'Back to draft',
    'workflow.discard' => 'Discard changes',
    'workflow.discard_help' => 'Restore the ready content as a new revision. The discarded revisions remain in history.',
    'workflow.discard_confirm' => 'I confirm discarding the changes made since this record was marked as ready.',
    'workflow.published_revision' => 'Ready revision: {revision}.',
    'workflow.pending' => 'These edits are saved for review. Mark them as ready to include them in the next release.',
    'workflow.changed_at' => 'Last edited:',
    'preview.pending_publication' => '{count, plural, one {# record has changes not yet marked as ready.} other {# records have changes not yet marked as ready.}}',
];
