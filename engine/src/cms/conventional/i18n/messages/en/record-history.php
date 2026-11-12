<?php

declare(strict_types=1);

return [
    'history.title' => 'Revision history',
    'history.help' => 'Keep a bounded history for each record. Current edits and the published version are always kept, in addition to this allowance.',
    'history.limit' => 'Historical revisions per record',
    'history.policy' => 'Default: 50. Choose 10 to 500. The oldest historical revisions are removed after a save or a confirmed reduction. Frozen previews and releases keep their own copies.',
    'history.save' => 'Review limit',
    'history.saved' => 'Revision limit saved.',
    'history.confirm' => 'Keeping {limit} historical revisions per record would remove {count} revisions across this website. Current and published versions are excluded.',
    'history.backup' => 'Removed history cannot be recovered from a later content export. Keep a verified installation backup before continuing.',
    'history.accept' => 'I confirm this limit and the removal of older revisions.',
    'history.apply' => 'Apply limit',
    'history.cancel' => 'Cancel',
    'history.pending' => 'History cleanup is pending. Each request handles one record and removes at most 100 revisions. You can leave this page and resume later; completed batches are kept.',
    'history.continue' => 'Continue cleanup',
    'history.capacity' => '{count, number, integer} retained revisions out of the measured export capacity of {capacity, number, integer}. This total includes current and published versions.',
    'history.warning' => 'Revision history is near or above the measured export capacity. Review the history limit and arrange a verified full export. Other export count and byte limits also apply.',
    'history.invalid' => 'Choose a whole-number history limit from 10 to 500.',
    'history.changed' => 'The history settings or removal count changed. Review the limit and confirm the current count again.',
    'history.forbidden' => 'Only an administrator can change revision history.',
    'history.preview_missing' => '{count, number, integer} source revisions are no longer kept. This frozen preview and releases made from it retain their exact content.',
];
