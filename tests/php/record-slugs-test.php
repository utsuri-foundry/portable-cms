<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\RecordSlugs;
use Utsuri\PortableCms\Conventional\Content\RecordSnapshot;
use Utsuri\PortableCms\Conventional\Content\ValidationFailure;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\View\Pages;

/** @return array{service: ContentService, repository: MemoryRecordRepository, profile: ProfileManifest} */
function recordSlugsService(): array
{
    $profile = ProfileManifest::load(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json');
    $repository = new MemoryRecordRepository();
    $service = new ContentService($repository, $profile, new IdentityTestClock(new DateTimeImmutable('2026-11-20T10:00:00Z')), new IdentityTestRandom());
    return ['service' => $service, 'repository' => $repository, 'profile' => $profile];
}

/**
 * @param array<string, string> $slug
 * @return array{title: array<string, string>, slug: array<string, string>}
 */
function recordSlugsArticle(string $title, array $slug): array
{
    return ['title' => ['ca' => $title, 'es' => $title, 'en' => $title], 'slug' => $slug];
}

$tests['record slugs are found by convention and only lowercase hyphenated segments are addresses'] = static function (): void {
    $profile = ProfileManifest::load(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json');
    assertSameValue('slug', RecordSlugs::field($profile->type('project'))?->id, 'a collection takes its address from its slug field');
    assertSameValue('name', RecordSlugs::titleField($profile->type('person'))?->id, 'a record named by another field proposes its slug from it');
    assertSameValue(null, RecordSlugs::field($profile->type('site-settings')), 'a singleton has no record address');
    assertSameValue(null, RecordSlugs::field(ProfileManifest::gateZero()->type('article')), 'a type without a slug field has no rule');
    foreach (['casa', 'casa-nova', 'a1-b2', '2026'] as $valid) {
        assertTrueValue(RecordSlugs::valid($valid), 'valid: ' . $valid);
    }
    foreach (['', 'Casa', 'casa nova', 'casa--nova', '-casa', 'casa-', 'casa_nova', 'casa/nova', 'càsa', 'casa.html'] as $invalid) {
        assertTrueValue(!RecordSlugs::valid($invalid), 'invalid: ' . $invalid);
    }
};

$tests['saving refuses a slug that is not an address and proposes the one it would make'] = static function (): void {
    ['service' => $service] = recordSlugsService();
    $failure = $service->create('article', recordSlugsArticle('Casa Nova', ['ca' => 'Casa Nova', 'es' => '', 'en' => '']), str_repeat('1', 32));
    assertTrueValue($failure instanceof ValidationFailure && isset($failure->errors['slug']), 'a slug with capitals and spaces is refused');
    assertSameValue('Slug (Català): Use only lowercase letters, digits and single hyphens, such as “casa-nova”.', Translator::english()->message($failure->errors['slug']), 'the sentence names the language and the address to use');
    assertSameValue('Slug (Català): Fes servir només lletres minúscules, xifres i guions simples, com ara «casa-nova».', Translator::load('ca')->message($failure->errors['slug']), 'the sentence is translated');
    $symbols = $service->create('article', recordSlugsArticle('Pati Interior', ['ca' => '', 'es' => '¿?', 'en' => '']), str_repeat('1', 32));
    assertTrueValue($symbols instanceof ValidationFailure, 'a slug of symbols is refused');
    assertSameValue('Slug (Español): Use only lowercase letters, digits and single hyphens, such as “pati-interior”.', Translator::english()->message($symbols->errors['slug']), 'with nothing to keep, the title proposes the address');
    $saved = $service->create('article', recordSlugsArticle('Casa Nova', ['ca' => 'casa-nova', 'es' => '', 'en' => '']), str_repeat('1', 32));
    assertTrueValue($saved instanceof RecordSnapshot, 'a valid slug saves and an empty one waits for publishing');
    $live = $service->unsaved('article', recordSlugsArticle('Casa Nova', ['ca' => 'casa nova', 'es' => '', 'en' => '']), $saved);
    assertTrueValue($live instanceof ValidationFailure && isset($live->errors['slug']), 'the live preview validates the slug as Save does');
};

$tests['saving refuses a slug another record of the type uses in that language and names it'] = static function (): void {
    ['service' => $service, 'repository' => $repository] = recordSlugsService();
    $first = $service->create('article', recordSlugsArticle('El pati', ['ca' => 'pati', 'es' => 'patio', 'en' => 'courtyard']), str_repeat('1', 32));
    assertTrueValue($first instanceof RecordSnapshot, 'the first article saves');
    $taken = $service->create('article', recordSlugsArticle('Un altre pati', ['ca' => 'pati', 'es' => '', 'en' => '']), str_repeat('1', 32));
    assertTrueValue($taken instanceof ValidationFailure, 'a second article cannot take the same address');
    assertSameValue('Slug (Català): “El pati” already uses this address.', Translator::english()->message($taken->errors['slug']), 'the sentence names the record that uses it');
    assertSameValue('Slug (Català): «El pati» ja fa servir aquesta adreça.', Translator::load('ca')->message($taken->errors['slug']), 'and is translated');
    $other = $service->create('article', recordSlugsArticle('Patio', ['ca' => 'patio', 'es' => 'pati', 'en' => '']), str_repeat('1', 32));
    assertTrueValue($other instanceof RecordSnapshot, 'the same text in another language is another address');
    $project = $service->create('project', recordSlugsArticle('El pati', ['ca' => 'pati', 'es' => '', 'en' => '']), str_repeat('1', 32));
    assertTrueValue($project instanceof RecordSnapshot, 'another record type has its own addresses');
    $again = $service->update('article', $first->id, 1, recordSlugsArticle('El pati gran', ['ca' => 'pati', 'es' => 'patio', 'en' => 'courtyard']), str_repeat('1', 32));
    assertTrueValue($again instanceof RecordSnapshot, 'a record keeps its own address when saved again');
    $moved = $service->update('article', $other->id, 1, recordSlugsArticle('Patio', ['ca' => 'patio', 'es' => 'patio', 'en' => '']), str_repeat('1', 32));
    assertTrueValue($moved instanceof ValidationFailure && str_contains(Translator::english()->message($moved->errors['slug']), '(Español): “El pati gran”'), 'a changed slug is checked in its own language');
    // A record saved before this rule keeps a slug the website accepts until an editor changes it.
    $legacyId = str_repeat('7', 32);
    $repository->records[$legacyId] = new RecordSnapshot($legacyId, 'article', 'draft', str_repeat('8', 32), 1, recordSlugsArticle('Antic', ['ca' => 'Antic_1', 'es' => '', 'en' => '']), new DateTimeImmutable('2026-11-01T10:00:00Z'));
    $kept = $service->update('article', $legacyId, 1, recordSlugsArticle('Antic renovat', ['ca' => 'Antic_1', 'es' => '', 'en' => '']), str_repeat('1', 32));
    assertTrueValue($kept instanceof RecordSnapshot, 'an unchanged saved slug does not block other edits');
    $changed = $service->update('article', $legacyId, 2, recordSlugsArticle('Antic renovat', ['ca' => 'Antic_2', 'es' => '', 'en' => '']), str_repeat('1', 32));
    assertTrueValue($changed instanceof ValidationFailure && str_contains(Translator::english()->message($changed->errors['slug']), '“antic-2”'), 'a changed slug meets the rule');
};

$tests['a record cannot be marked ready with a slug the website cannot publish'] = static function (): void {
    ['service' => $service, 'repository' => $repository] = recordSlugsService();
    $locales = ['ca', 'es', 'en'];
    $body = static fn(string $title, array $slug): array => [
        ...recordSlugsArticle($title, $slug),
        'excerpt' => array_fill_keys($locales, 'Summary'),
        'body' => array_fill_keys($locales, ['formatVersion' => 1, 'nodes' => [['type' => 'paragraph', 'children' => [['text' => 'Body', 'marks' => []]]]]]),
    ];
    $published = $service->create('article', $body('Primer', ['ca' => 'primer', 'es' => 'primero', 'en' => 'first']), str_repeat('1', 32));
    assertTrueValue($published instanceof RecordSnapshot, 'a complete article saves');
    $ready = $service->workflow()->transition('article', $published->id, 1, 'published', workflowTestActor());
    assertTrueValue($ready instanceof RecordSnapshot, 'a unique valid slug can be marked ready');
    foreach ([['Two words', 'the sentence asks for a valid address'], ['primer', 'the sentence names the record that already uses it']] as [$slug, $why]) {
        $id = str_repeat($slug === 'primer' ? '6' : '5', 32);
        $repository->records[$id] = new RecordSnapshot($id, 'article', 'draft', str_repeat('9', 32), 1, $body('Segon', ['ca' => $slug, 'es' => 'segundo', 'en' => 'second']), new DateTimeImmutable('2026-11-01T10:00:00Z'));
        $failed = $service->workflow()->transition('article', $id, 1, 'published', workflowTestActor());
        assertTrueValue($failed instanceof ValidationFailure && isset($failed->errors['slug']), $why);
    }
};

$tests['slug controls edit inside their address and follow the title only while the record is a draft'] = static function (): void {
    $profile = ProfileManifest::load(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json');
    $type = $profile->type('person');
    $new = Pages::recordForm(str_repeat('b', 32), $profile, $type, [], null, chrome: adminTestChrome());
    assertTrueValue(str_contains($new, '<span class="admin-input-unit" aria-hidden="true">…/people/</span><input id="field-slug-ca" name="slug__ca"'), 'the slug sits inside the address it makes');
    assertTrueValue(str_contains($new, 'data-slug spellcheck="false" autocapitalize="off" autocomplete="off" data-slug-from="field-name"'), 'a new record proposes its slug in every language from its untranslated name');
    $published = new RecordSnapshot(str_repeat('1', 32), 'person', 'published', str_repeat('2', 32), 3, ['name' => 'Anna', 'slug' => ['ca' => 'anna']], new DateTimeImmutable('2026-10-02T08:00:00Z'));
    $ready = Pages::recordForm(str_repeat('b', 32), $profile, $type, $published->body, $published, chrome: adminTestChrome());
    assertTrueValue(str_contains($ready, 'name="slug__ca"') && str_contains($ready, 'data-slug spellcheck="false"'), 'a published slug still normalizes as it is typed');
    assertTrueValue(!str_contains($ready, 'data-slug-from'), 'a published address never follows the title on its own');
    assertTrueValue(!str_contains($ready, 'name="name" type="text" maxlength="160" value="Anna" data-slug'), 'the name control is not a slug control');
};
