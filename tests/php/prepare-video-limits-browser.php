<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/bootstrap.php';
require_once __DIR__ . '/trailer-fixture.php';
require_once __DIR__ . '/video-limits-fixture.php';

$state = $argv[1] ?? throw new RuntimeException('An isolated browser state is required');
$package = videoLimitsPackage($state . '/video-profile', ['maximumBytes' => 1048576, 'maximumDurationSeconds' => 15, 'maximumWidth' => 1920, 'maximumHeight' => 1080, 'sound' => 'none']);
$profilePath = $package->root . '/profile.json';
$document = json_decode((string) file_get_contents($profilePath), flags: JSON_THROW_ON_ERROR);
foreach ($document->recordTypes as &$type) {
    if ($type->id === 'project') {
        $type->fields[] = (object) ['id' => 'libraryFilm', 'label' => 'Other films', 'type' => 'video_reference', 'localized' => false, 'requiredOnDraft' => false, 'requiredOnPublish' => false, 'settings' => (object) ['posterRequired' => true, 'maximumBytes' => 41943040]];
    }
}
unset($type);
file_put_contents($profilePath, json_encode($document, JSON_THROW_ON_ERROR));
$configPath = $state . '/private/cms.json';
$config = json_decode((string) file_get_contents($configPath), true, flags: JSON_THROW_ON_ERROR);
$config['profileRoot'] = $package->root;
file_put_contents($configPath, json_encode($config, JSON_THROW_ON_ERROR));
mkdir($state . '/video-probes', 0700);
foreach (['fitting', 'duration', 'width', 'height', 'sound', 'bytes'] as $variant) {
    $bytes = videoLimitsClip($variant === 'bytes' ? 'fitting' : $variant);
    if ($variant === 'bytes') {
        $bytes .= pack('N', 1048576) . 'free' . str_repeat("\0", 1048568);
    }
    file_put_contents($state . '/video-probes/limits-' . $variant . '.mp4', $bytes);
}
