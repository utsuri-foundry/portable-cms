<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\KeptRecord;
use Utsuri\PortableCms\Conventional\Content\MariaDbRecordRepository;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Content\RecordEditIntent;
use Utsuri\PortableCms\Conventional\Content\RecordSnapshot;
use Utsuri\PortableCms\Conventional\Content\ValidationFailure;
use Utsuri\PortableCms\Conventional\Database\DatabaseConnection;
use Utsuri\PortableCms\Conventional\Database\MigrationRunner;
use Utsuri\PortableCms\Conventional\Database\TablePrefix;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Identity\AdministratorBootstrap;
use Utsuri\PortableCms\Conventional\Identity\SystemClock;
use Utsuri\PortableCms\Conventional\Identity\SystemRandomSource;

require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/bootstrap.php';
require_once __DIR__ . '/assertions.php';

$dsn = getenv('CMS_TEST_SITE_SEQUENCE_DSN');
if (!is_string($dsn) || $dsn === '') {
    throw new RuntimeException('An isolated record database is required');
}
$pdo = new DatabaseConnection($dsn, 'portable', 'integration-secret', new TablePrefix('slugs_'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$migrations = glob(dirname(__DIR__, 2) . '/engine/migrations/*.sql');
sort($migrations);
(new MigrationRunner($pdo))->apply($migrations);
$clock = new SystemClock();
$random = new SystemRandomSource();
$actor = (new AdministratorBootstrap($pdo, $clock, $random))->create('slugs@portable.test', 'Slug owner', 'portable slug test password');
$profile = ProfilePackage::load(dirname(__DIR__, 2) . '/profiles/reference-studio')->manifest;
$repository = new MariaDbRecordRepository($pdo);
$content = new ContentService($repository, $profile, $clock, $random);
$article = static fn(string $title, array $slug): array => ['title' => array_fill_keys($profile->locales, $title), 'slug' => $slug + array_fill_keys($profile->locales, '')];

$first = $content->create('article', $article('pati', ['ca' => 'pati', 'es' => 'patio']), $actor);
assertTrueValue($first instanceof RecordSnapshot, 'the first article saves its address');
$taken = $content->create('article', $article('Un altre pati', ['ca' => 'pati']), $actor);
assertTrueValue($taken instanceof ValidationFailure && isset($taken->errors['slug']), 'the database finds the record that uses the address');
assertSameValue('Slug (Català): “pati” already uses this address.', Translator::english()->message($taken->errors['slug']), 'the refusal names that record');
$elsewhere = $content->create('article', $article('Patio', ['es' => 'pati', 'en' => 'patio']), $actor);
assertTrueValue($elsewhere instanceof RecordSnapshot, 'the same text in another language or another field is not that address');
assertSameValue(null, $repository->slugOwner('article', 'ca', 'p%', null), 'wildcards in a slug are literal');
assertSameValue(null, $repository->slugOwner('article', 'ca', 'pati', $first->id), 'a record never conflicts with itself');
assertSameValue($first->id, $repository->slugOwner('article', 'ca', 'pati', null)?->id, 'the exact slug in its language is found');
assertSameValue(null, $repository->slugOwner('project', 'ca', 'pati', null), 'each record type has its own addresses');

// Keeping while typing stores every valid field and leaves the refused slug only in the editor.
$id = bin2hex($random->bytes(16));
$kept = $content->keep('article', $id, ['title' => array_fill_keys($profile->locales, 'Kept title'), 'slug' => ['ca' => 'pati'] + array_fill_keys($profile->locales, '')], $actor, new RecordEditIntent(0, hash('sha256', 'slug page'), bin2hex($random->bytes(16))));
assertTrueValue($kept instanceof KeptRecord && isset($kept->errors['slug']), 'keeping reports the taken address');
assertSameValue('Kept title', $kept->record->body['title']['ca'], 'the other fields are kept');
assertSameValue('', $kept->record->body['slug']['ca'], 'the taken address is not stored');
$invalid = $content->keep('article', $id, ['title' => array_fill_keys($profile->locales, 'Kept title'), 'slug' => ['ca' => 'Two Words'] + array_fill_keys($profile->locales, '')], $actor, new RecordEditIntent($kept->record->editSequence, hash('sha256', 'slug page'), bin2hex($random->bytes(16))));
assertTrueValue($invalid instanceof KeptRecord && str_contains(Translator::english()->message($invalid->errors['slug']), '“two-words”'), 'keeping proposes the address a slug would make');
assertSameValue('', $invalid->record->body['slug']['ca'], 'an invalid address is not stored');

fwrite(STDOUT, "PASS record slugs stay unique, valid web addresses in MariaDB\n");
