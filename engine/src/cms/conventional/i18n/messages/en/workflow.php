<?php

declare(strict_types=1);

return [
    'workflow.delete' => 'Delete archived entry',
    'workflow.delete_help' => 'Deletion removes this entry and its editorial revision history. Existing exact previews and releases remain unchanged. Entries with incoming references, owned media, uploads or AI jobs cannot be deleted; keep them archived.',
    'workflow.delete_confirm' => 'I confirm permanent deletion of this entry and its revision history.',
    'workflow.delete_blocked' => 'Only archived collection entries without references, owned media, uploads or AI jobs can be deleted.',
    'workflow.save' => 'Save changes',
    'workflow.change' => 'Change status',
    'workflow.help' => 'Website releases take the published version. Save edits for review, then publish changes when they are ready. Publishing does not immediately change the live website.',
    'workflow.state' => 'New status',
    'workflow.confirm' => 'I confirm this status change for the saved record.',
    'workflow.submit' => 'Confirm status change',
    'workflow.failed' => 'Status not changed',
    'workflow.conflict' => 'The record changed. Review the current saved revision before confirming again.',
    'workflow.invalid' => 'Check the saved content before publishing.',
    'workflow.current' => 'Current status: {state}. Saved revision: {revision}.',
    'workflow.back' => 'Back to record',
    'workflow.required' => '{field} is required in every website language before publishing.',
    'workflow.relationship' => 'Publish referenced records first, or remove the reference.',
    'workflow.media' => 'Selected media must be ready. Unsupported media cannot be published.',
    'preview.include_drafts' => 'Include saved drafts and unpublished changes (review only)',
    'preview.mode.drafts' => 'Includes saved drafts. Review only; cannot be released.',
    'preview.include_preparation' => 'Include languages in preparation: {languages} (review only)',
    'preview.mode.preparation' => 'Includes languages in preparation. Review only; cannot be released.',
    'preview.mode.published' => 'Published content only; eligible for release checks.',
];
