<?php

declare(strict_types=1);

return [
    'preflight.issue.record' => '{type} "{title}": {reason}',
    'preflight.issue.route' => 'Page {route}: {reason}',
    'preflight.issue.setting' => '{setting}: {reason}',
    'preflight.issue.website' => 'Website: {reason}',
    'preflight.issue.field' => '{field}: {reason}',
    'preflight.issue.field_locale' => '{field} in {locale}: {reason}',
    'preflight.reason.missing_setting' => 'Include exactly one record of this type in the preview.',
    'preflight.reason.required_value' => 'Add the required value.',
    'preflight.reason.missing_translation' => 'Add the missing translation.',
    'preflight.reason.relationship' => 'Select an available record of an allowed type and include it in this preview.',
    'preflight.reason.sharing_image' => 'Select a supported sharing image (JPEG, PNG or WebP).',
    'preflight.reason.duplicate_route' => 'Choose a unique page address.',
    'preflight.reason.invalid_route' => 'Correct the page address.',
    'preflight.reason.invalid_content' => 'Correct the saved content.',
    'preflight.reason.profile_error' => 'The profile could not build this page. Ask the profile developer to check the diagnostic reference.',
    'preflight.reference' => 'Diagnostic reference: {reference}',
];
