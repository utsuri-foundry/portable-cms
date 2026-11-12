<?php

declare(strict_types=1);

return [
    'media.text.help' => 'Describe the image in each website language. Empty fields use the authored {locale} text. Alternatives, captions and credits accept plain text of up to 500 characters. An alternative or the decorative flag is required before publishing. Changes apply wherever this image is used.',
    'media.text.decorative' => 'Decorative image (empty text alternative)',
    'media.text.alternative' => 'Text alternative',
    'media.text.caption' => 'Caption (optional)',
    'media.text.credit' => 'Credit (optional)',
    'media.text.save' => 'Save media details',
    'media.text.edit' => 'Edit image details',
    'media.text.invalid' => 'Use plain text of at most 500 characters for each alternative, caption and credit.',
    'media.upload.title' => 'Upload images',
    'media.upload.help' => 'Choose the website or a saved record to keep the images. Uploaded images are available throughout the library; selecting them in content is a separate step.',
    'media.upload.owner' => 'Keep images with',
    'media.upload.files' => 'Images (up to eight)',
    'preflight.issue.media_text' => 'Page {route} shows image {id} without its authored text alternative or decorative flag in {locale}.',
    'workflow.media_text' => '{field} needs an authored image alternative or the decorative flag in {locale}.',
];
