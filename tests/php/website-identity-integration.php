<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Database\MigrationRunner;
use Utsuri\PortableCms\Conventional\Identity\AdministratorBootstrap;
use Utsuri\PortableCms\Conventional\Identity\SystemClock;
use Utsuri\PortableCms\Conventional\Identity\SystemRandomSource;
use Utsuri\PortableCms\Conventional\Publication\MariaDbWebsitePublication;
use Utsuri\PortableCms\Conventional\Publication\WebsitePublication;
use Utsuri\PortableCms\Conventional\Upgrade\WebsiteIdentityUpgrade;

require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/bootstrap.php';
require_once __DIR__ . '/assertions.php';

$dsn = getenv('CMS_TEST_IDENTITY_DSN');
if (!is_string($dsn) || $dsn === '') {
    throw new RuntimeException('An isolated identity migration database is required');
}
$pdo = new PDO($dsn, 'portable', 'integration-secret', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
(new MigrationRunner($pdo))->apply(glob(dirname(__DIR__, 2) . '/engine/migrations/*.sql') ?: []);
$clock = new SystemClock();
$actor = (new AdministratorBootstrap($pdo, $clock, new SystemRandomSource()))->create('identity@example.test', 'Identity migration', 'isolated identity migration password');
$profile = ProfileManifest::load(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json');
$id = str_repeat('1', 32);
$revision = str_repeat('2', 32);
$time = $clock->now()->format('Y-m-d H:i:s.u');
$legacyBody = ['siteName' => 'Former <studio>', 'websiteIcon' => [str_repeat('a', 32)]];
// Historical store projection: this test covers reconciliation, not image preparation.
$pdo->prepare('INSERT INTO cms_records (id, type_id, workflow_state, published_revision_id, current_revision_id, current_revision_number, created_by, created_at, updated_by, updated_at) VALUES (:id, :type, :state, :published, :revision, 1, :actor, :time, :editor, :updated)')->execute(['id' => $id, 'type' => 'site-settings', 'state' => 'published', 'published' => $revision, 'revision' => $revision, 'actor' => $actor, 'time' => $time, 'editor' => $actor, 'updated' => $time]);
$pdo->prepare('INSERT INTO cms_record_revisions (id, record_id, revision_number, profile_id, profile_version, body_json, created_by, created_at) VALUES (:id, :record, 1, :profile, :version, :body, :actor, :time)')->execute(['id' => $revision, 'record' => $id, 'profile' => $profile->id, 'version' => '1.0.0', 'body' => json_encode($legacyBody, JSON_THROW_ON_ERROR), 'actor' => $actor, 'time' => $time]);
$sources = [['typeId' => 'site-settings', 'nameField' => 'siteName', 'iconField' => 'websiteIcon']];
$repository = new MariaDbWebsitePublication($pdo);

foreach ([false, true] as $explicit) {
    $pdo->exec('DELETE FROM cms_website_publication');
    if ($explicit) {
        $saved = [...WebsitePublication::defaults($profile->id, $profile->locales)->document(), 'revision' => 3, 'websiteName' => 'Engine studio', 'iconMediaId' => str_repeat('b', 32)];
        $pdo->prepare('INSERT INTO cms_website_publication (profile_id, revision, document_json) VALUES (:profile, 3, :document)')->execute(['profile' => $profile->id, 'document' => json_encode($saved, JSON_THROW_ON_ERROR)]);
    }
    assertTrueValue(WebsiteIdentityUpgrade::needed($pdo, $profile, $sources), 'read-only qualification detects reconciliation');
    $before = $repository->load($profile->id, $profile->locales)->document();
    $pdo->beginTransaction();
    WebsiteIdentityUpgrade::migrate($pdo, $profile, $sources);
    $pdo->rollBack();
    assertSameValue($before, $repository->load($profile->id, $profile->locales)->document(), 'an interrupted upgrade leaves the former identity exact');
    $pdo->beginTransaction();
    WebsiteIdentityUpgrade::migrate($pdo, $profile, $sources);
    $pdo->commit();
    $after = $repository->load($profile->id, $profile->locales);
    assertSameValue($explicit ? ['Engine studio', str_repeat('b', 32)] : ['Former <studio>', str_repeat('a', 32)], [$after->identity()->name, $after->identity()->iconMediaId], 'an authored engine identity wins; otherwise the profile identity is adopted');
    assertSameValue([['typeId' => 'site-settings', 'recordId' => $id, 'name' => 'Former <studio>', 'iconMediaId' => str_repeat('a', 32)]], $after->identityUpgrade?->previous, 'the former different name and icon are retained together');
    $pdo->beginTransaction();
    WebsiteIdentityUpgrade::migrate($pdo, $profile, $sources);
    $pdo->commit();
    assertSameValue($after->document(), $repository->load($profile->id, $profile->locales)->document(), 'recovery is idempotent and never changes an already reconciled identity');
    assertTrueValue(!WebsiteIdentityUpgrade::needed($pdo, $profile, $sources), 'subsequent upgrade reports no duplicate work');
    assertSameValue(json_encode($legacyBody, JSON_THROW_ON_ERROR), $pdo->query('SELECT body_json FROM cms_record_revisions')->fetchColumn(), 'capture happens without rewriting original content');
}

$pdo->beginTransaction();
try {
    $wrongScope = [...$after->document(), 'profileId' => 'other-studio'];
    $pdo->prepare('UPDATE cms_website_publication SET document_json = :document WHERE profile_id = :profile')->execute(['profile' => $profile->id, 'document' => json_encode($wrongScope, JSON_THROW_ON_ERROR)]);
    try {
        (new \Utsuri\PortableCms\Conventional\Preview\MariaDbPreviewRepository($pdo))->freezeSnapshot($profile->id, $profile->version, $profile->locales, $clock->now(), false);
        throw new RuntimeException('Mismatched identity scope accepted');
    } catch (\Utsuri\PortableCms\Conventional\Publication\PublicationFailure $error) {
        assertSameValue('website_publication_scope_changed', $error->codeName, 'a preview cannot adopt settings from another profile');
    }
} finally {
    $pdo->rollBack();
}

fwrite(STDOUT, "PASS website identity migration: differing names/icons, explicit/default precedence, preserved values, transaction rollback, replay and scope validation.\n");
