<?php

declare(strict_types=1);

return [
    'branding.title' => 'Administration logo',
    'branding.logo' => 'Standard logo',
    'branding.dark_logo' => 'Dark-theme variant (optional)',
    'branding.help' => 'Choose ready PNG, WebP or JPEG images. Square, tall and wide logos fit: they are centred and scaled down proportionally without cropping, stretching or enlargement. No padding is needed. Prepared images must be at most 960 × 960 pixels and 1 MiB each. Only the chosen prepared images are visible before sign-in, never originals. Without a logo, the website icon is used, then the initials. These settings do not change the public website. Without a dark variant, the logo keeps a light backing.',
    'branding.unavailable' => 'The media library is unavailable. Your saved selection is preserved.',
    'branding.error.administration_logo_invalid' => 'Choose valid images from the media library.',
    'branding.error.administration_logo_required' => 'Choose a standard logo before adding a dark-theme variant.',
    'branding.error.administration_logo_not_ready' => 'A selected logo is unavailable or exceeds the prepared image limits. Choose a ready image with a WebP derivative of at most 960 × 960 pixels and 1 MiB.',
];
