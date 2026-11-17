<?php

declare(strict_types=1);

return [
    'workflow.delete' => 'Delete archived entry',
    'workflow.delete_help' => 'Deletion removes this entry and its editorial revision history. Existing exact previews and releases remain unchanged. Entries with incoming references, owned media, uploads or AI jobs cannot be deleted; keep them archived.',
    'workflow.delete_confirm' => 'I confirm permanent deletion of this entry and its revision history.',
    'workflow.delete_blocked' => 'Only archived collection entries without references, owned media, uploads or AI jobs can be deleted.',
    'workflow.save' => 'Save changes',
    'workflow.change' => 'Change status',
    'workflow.help' => 'Releases take the ready version of each record. Save edits for review, then mark them as ready. Nothing reaches the public website until a release is approved.',
    'workflow.state' => 'New status',
    'workflow.confirm' => 'I confirm this status change for the saved record.',
    'workflow.submit' => 'Confirm status change',
    'workflow.failed' => 'Status not changed',
    'workflow.conflict' => 'The record changed. Review the current saved revision before confirming again.',
    'workflow.invalid' => 'Check the saved content before marking it as ready.',
    'workflow.current' => 'Current status: {state}. Saved revision: {revision}.',
    'workflow.back' => 'Back to record',
    'workflow.required' => '{field} is required in every website language before the record is ready.',
    'workflow.relationship' => 'Mark referenced records as ready first, or remove the reference.',
    'workflow.media' => 'Selected media must have finished processing. Unsupported media cannot be included.',
    'preview.mode.drafts' => 'Includes saved drafts. Review only; cannot be released.',
    'preview.include_preparation' => 'Include languages in preparation: {languages} (review only)',
    'preview.mode.preparation' => 'Includes languages in preparation. Review only; cannot be released.',
    'preview.mode.published' => 'Ready content only; this is what a release publishes.',
];
