<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\ApplicationFactory;
use Utsuri\PortableCms\Conventional\Identity\SessionContext;
use Utsuri\PortableCms\Conventional\Publication\PublicationFailure;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;
use Utsuri\PortableCms\Conventional\Upgrade\RuntimeLease;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/engine/src/cms/conventional/bootstrap.php';
$options = getopt('', ['config:', 'snapshot:', 'confirm:', 'owner:', 'maximum-files:', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php tools/conventional-purge-feed.php --config=/private/cms.json --snapshot=SHA256 --confirm=SHA256 --owner=owner@example.test [--maximum-files=8]\nRemoves a public feed snapshot before retention expires. Repeat an incomplete command to resume. Exit 2 means cleanup is still pending. The purge is audited and prevents that snapshot being published again.\n");
    exit(0);
}
try {
    $configPath = is_string($options['config'] ?? null) ? $options['config'] : '';
    $runtimeLease = RuntimeLease::guard($configPath, dirname(__DIR__));
    $configuration = RuntimeConfiguration::load($configPath);
    $snapshot = is_string($options['snapshot'] ?? null) ? $options['snapshot'] : '';
    if (preg_match('/\A[a-f0-9]{64}\z/', $snapshot) !== 1 || ($options['confirm'] ?? null) !== $snapshot) {
        throw new PublicationFailure('feed_purge_confirmation_required');
    }
    $maximum = filter_var($options['maximum-files'] ?? '8', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
    if (!is_int($maximum)) {
        throw new PublicationFailure('feed_purge_batch_invalid');
    }
    $query = $configuration->connect()->prepare("SELECT id, login_name, display_name FROM cms_users WHERE role = 'owner' AND status = 'active' AND must_change_password = 0 AND login_name = :login");
    $query->execute(['login' => is_string($options['owner'] ?? null) ? $options['owner'] : '']);
    $owner = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($owner)) {
        throw new PublicationFailure('owner_required');
    }
    $publication = ApplicationFactory::publicationRunner($configuration) ?? throw new PublicationFailure('publication_unavailable');
    $actor = new SessionContext('operator', 'authenticated', $owner['id'], $owner['login_name'], $owner['display_name'], 'owner', '', '', new DateTimeImmutable('+1 hour'), false);
    $complete = $publication->purgeFeed($snapshot, $actor, $maximum);
    fwrite(STDOUT, json_encode(['status' => $complete ? 'purged' : 'purging', 'snapshotId' => $snapshot], JSON_THROW_ON_ERROR) . "\n");
    exit($complete ? 0 : 2);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['status' => 'rejected', 'reason' => $error instanceof PublicationFailure ? $error->codeName : 'feed_purge_failed_safely'], JSON_THROW_ON_ERROR) . "\n");
    exit(1);
}
