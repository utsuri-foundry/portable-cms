<?php

declare(strict_types=1);

return [
    'branding.title' => 'Administration appearance',
    'branding.help' => 'The logo on the sign-in page and in the sidebar. Without one, the website icon is used, then the website’s initials. This does not change the public website.',
    'branding.logo' => 'Logo',
    'branding.logo_help' => 'A PNG, WebP or JPEG of at most 960 × 960 pixels. Square, tall and wide logos fit: the image is centred and scaled down, never cropped or stretched.',
    'branding.dark_logo' => 'Logo for dark mode',
    'branding.dark_help' => 'Shown instead of the logo when the administration is in dark mode. Without it, the logo keeps a light backing.',
    'branding.none' => 'None',
    'branding.choose' => 'Choose another image',
    'branding.empty' => 'No prepared image of at most 960 × 960 pixels is in the library yet.',
    'branding.unavailable' => 'The media library is unavailable. Your saved selection is kept.',
    'branding.error.administration_logo_invalid' => 'Choose logos from the media library.',
    'branding.error.administration_logo_required' => 'Choose a logo before adding one for dark mode.',
    'branding.error.administration_logo_not_ready' => 'A chosen logo is unavailable or larger than 960 × 960 pixels. Choose a prepared image within that size.',
];
