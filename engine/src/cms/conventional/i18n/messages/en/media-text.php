<?php

declare(strict_types=1);

return [
    'media.text.title' => 'Description',
    'media.text.help' => 'Say what the image shows, in each website language, for people who cannot see it and for search engines. A language left empty uses the {locale} text. Up to 500 characters each. Changes apply wherever the image is used.',
    'media.text.decorative' => 'Decorative image, needs no description',
    'media.text.decorative_help' => 'For images that add nothing to the text, such as a background or a divider. Screen readers skip them.',
    'media.text.alternative' => 'Description',
    'media.text.more' => 'Caption and credit',
    'media.text.caption' => 'Caption',
    'media.text.credit' => 'Credit',
    'media.text.edit' => 'Edit image details',
    'media.text.invalid' => 'Use plain text of at most 500 characters for each description, caption and credit.',
    'media.upload.title' => 'Upload',
    'media.upload.rule' => 'Images up to {limit} MB.',
    'media.upload.rule_video' => 'Images and short videos up to {limit} MB.',
    'media.upload.owner' => 'Belongs to',
    'media.upload.owner_help' => 'Where the files come from. Every page can use them either way.',
    'media.upload.website' => 'The website',
    'media.upload.files' => 'Files',
    'media.upload.submit' => 'Upload files',
    'preflight.issue.media_text' => 'Page {route} shows image {id} without its authored text alternative or decorative flag in {locale}.',
    'workflow.media_text' => '{field} needs an authored image alternative or the decorative flag in {locale}.',
];
