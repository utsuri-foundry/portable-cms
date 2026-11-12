<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/bootstrap.php';
require_once __DIR__ . '/single-line-rich-text-fixture.php';

$state = $argv[1] ?? throw new RuntimeException('An isolated browser state is required');
$package = singleLineRichTextPackage($state . '/single-line-profile');
$path = $state . '/private/cms.json';
$config = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
$config['profileRoot'] = $package->root;
file_put_contents($path, json_encode($config, JSON_THROW_ON_ERROR));
