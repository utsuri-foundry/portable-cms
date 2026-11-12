<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot;
use Utsuri\PortableCms\Conventional\Compiler\WebAddressLink;
use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\EditorialControls;
use Utsuri\PortableCms\Conventional\Content\EditorialForm;
use Utsuri\PortableCms\Conventional\Content\FieldDefinition;
use Utsuri\PortableCms\Conventional\Content\LocaleCoverage;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Content\RecordConflict;
use Utsuri\PortableCms\Conventional\Content\RecordSnapshot;
use Utsuri\PortableCms\Conventional\Content\ValidationFailure;
use Utsuri\PortableCms\Conventional\Content\WebAddress;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\I18n\InvalidInput;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\View\WebAddressControl;

/** @return array{formatVersion: int, url: string, linkText: string} */
function addressDocument(string $url = 'https://example.test/studio?a=1&b=2', string $text = ''): array
{
    return ['formatVersion' => 1, 'url' => $url, 'linkText' => $text];
}

$tests['web addresses bound absolute credential-free HTTP links and single-line labels'] = static function (): void {
    foreach (['https://example.test/', 'http://example.test:8080/path#section', 'https://example.test/%E2%82%AC'] as $url) {
        assertSameValue(addressDocument($url), WebAddress::from(addressDocument($url))->document(), 'valid URL bytes are preserved');
    }
    foreach (['javascript:alert(1)', 'data:text/html,<script>', 'file:///private', 'ftp://example.test/', 'mailto:admin@example.test', 'tel:+12345', '/relative/', '//example.test/', 'https://user:password@example.test/', 'https://example.test/"onclick=', "https://example.test/\npath", 'https://example.test\\path', 'https://', '', 'https://example.test/' . str_repeat('x', 2048)] as $url) {
        try {
            WebAddress::from(addressDocument($url));
            throw new RuntimeException('Hostile address accepted: ' . $url);
        } catch (InvalidInput $error) {
            assertTrueValue(str_contains($error->getMessage(), 'address'), 'invalid URL has a stable safe diagnostic');
        }
    }
    foreach ([['formatVersion' => 1, 'url' => 'https://example.test/'], [...addressDocument(), 'target' => '_blank'], addressDocument(text: str_repeat('é', 201)), addressDocument(text: "line\nbreak"), addressDocument(text: "bad\xff")] as $value) {
        try {
            WebAddress::from($value);
            throw new RuntimeException('Malformed address document accepted');
        } catch (InvalidInput) {
        }
    }
    assertSameValue(str_repeat('é', 200), WebAddress::from(addressDocument(text: str_repeat('é', 200)))->linkText, 'labels count Unicode characters');
    $url = 'https://example.test/' . str_repeat('x', 2048 - strlen('https://example.test/'));
    assertSameValue($url, WebAddress::from(addressDocument($url))->url, 'exact byte bound is inclusive');
    try {
        WebAddress::from(addressDocument(), 10);
        throw new RuntimeException('Profile-specific limit ignored');
    } catch (InvalidInput) {
    }
};

$tests['web address links use engine policy and escape labels and URL attributes without broadening other URL contexts'] = static function (): void {
    $html = WebAddressLink::render(addressDocument(text: '<script> & "label"'))->value;
    assertTrueValue(str_contains($html, 'a=1&amp;b=2') && str_contains($html, '&lt;script&gt;'), 'both output contexts are escaped');
    assertTrueValue(!str_contains($html, '<script>') && !str_contains($html, 'target='), 'labels cannot introduce markup or browsing targets');
    assertTrueValue(str_contains(WebAddressLink::render(addressDocument('http://example.test/'))->value, '>http://example.test/</a>'), 'blank label falls back to the address');
    assertSameValue('', WebAddressLink::render(null)->value, 'optional empty values emit nothing');
    try {
        WebAddressLink::render(addressDocument('javascript:alert(1)'));
        throw new RuntimeException('Renderer accepted an unsafe address');
    } catch (InvalidInput) {
    }
    try {
        Html::url('http://example.test/');
        throw new RuntimeException('Unrelated HTTPS-only URL policy broadened');
    } catch (InvalidArgumentException) {
    }
};

$tests['native localized URL controls preserve submitted input and save through authoritative services'] = static function (): void {
    $profile = ProfileManifest::load(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json');
    $type = $profile->type('person');
    $field = array_values(array_filter($type->fields, static fn(FieldDefinition $field): bool => $field->id === 'website'))[0];
    $form = ['name' => 'Fictional Editor', '_present_website' => '1', '_editorial_end' => '1', 'website__ca__url' => 'https://example.test/ca', 'website__ca__linkText' => 'Estudi', 'website__es__url' => '', 'website__en__url' => 'http://example.test/en', 'website__en__linkText' => 'Studio'];
    $input = EditorialForm::input(new Request('POST', '/', form: $form), $type, $profile, null);
    $expected = ['ca' => addressDocument('https://example.test/ca', 'Estudi'), 'es' => null, 'en' => addressDocument('http://example.test/en', 'Studio')];
    assertSameValue($expected, json_decode($input['website'], true, 8, JSON_THROW_ON_ERROR), 'native controls do not require JSON or JavaScript');
    $service = new ContentService(new MemoryRecordRepository(), $profile, new IdentityTestClock(new DateTimeImmutable('2026-10-06T12:00:00Z')), new IdentityTestRandom());
    $saved = $service->create('person', $input, str_repeat('a', 32));
    assertTrueValue($saved instanceof RecordSnapshot, 'addresses save with ordinary record fields');
    assertSameValue($expected, $saved->body['website'], 'localized values and intentionally empty locale persist');
    $preserved = EditorialForm::input(new Request('POST', '/', form: ['name' => 'New name']), $type, $profile, $saved);
    assertSameValue($expected, json_decode($preserved['website'], true, 8, JSON_THROW_ON_ERROR), 'omitted controls preserve saved addresses');
    $next = $service->update('person', $saved->id, 1, $input, str_repeat('a', 32));
    assertTrueValue($next instanceof RecordSnapshot && $next->revisionNumber === 2, 'valid addresses update normally');
    $conflict = $service->update('person', $saved->id, 1, $input, str_repeat('a', 32));
    assertTrueValue($conflict instanceof RecordConflict, 'ordinary revision conflict boundary remains intact');
    $bad = [...$form, 'website__ca__url' => 'javascript:alert(1)'];
    $badInput = EditorialForm::input(new Request('POST', '/', form: $bad), $type, $profile, $saved);
    $failure = $service->update('person', $saved->id, 2, $badInput, str_repeat('a', 32));
    assertTrueValue($failure instanceof ValidationFailure && isset($failure->errors['website']), 'URL validation happens before persistence');
    assertSameValue($bad['website__ca__url'], $failure->values['website']['ca']['url'], 'invalid user input is retained for correction');
    foreach (['en', 'ca', 'es', 'fr', 'de'] as $language) {
        $t = Translator::load($language);
        $html = WebAddressControl::field($field, $badInput['website'], $t->text('address.invalid', ['maximum' => 2048]), $profile, $t);
        assertSameValue(3, substr_count($html, 'type="url"'), 'one native URL input per declared content locale');
        assertTrueValue(str_contains($html, 'aria-invalid="true"') && str_contains($html, 'role="alert"'), 'errors are associated and visible');
        assertTrueValue(!str_contains($html, '<textarea') && str_contains($html, 'javascript:alert(1)'), 'unsafe input is shown as escaped input, not executed');
    }
    assertSameValue(['ca' => [], 'es' => ['Website'], 'en' => []], LocaleCoverage::missing($type, ['website' => $expected], $profile), 'language coverage includes URL fields');
    try {
        EditorialForm::input(new Request('POST', '/', form: ['_present_website' => '1']), $type, $profile, $saved);
        throw new RuntimeException('Truncated URL form accepted');
    } catch (InvalidArgumentException) {
    }
    $service->validateCanonical('person', $saved->body);
    foreach ([['ca' => addressDocument(), 'fr' => addressDocument()], ['ca' => addressDocument()], ['ca' => ['url' => 'https://example.test/'], 'es' => null, 'en' => null]] as $badLocales) {
        try {
            $service->validateCanonical('person', [...$saved->body, 'website' => $badLocales]);
            throw new RuntimeException('Invalid canonical localized address accepted');
        } catch (InvalidArgumentException) {
        }
    }
};

$tests['web addresses enforce required values and nested group validation and frozen snapshot safety'] = static function (): void {
    $field = new FieldDefinition('site', 'Site', 'web_address', false, true, true, ['maximumLength' => 100], maximumLength: 100);
    try {
        WebAddress::field(null, $field, ['en'], true);
        throw new RuntimeException('Required empty address accepted');
    } catch (InvalidInput) {
    }
    $group = new FieldDefinition('contact', 'Contact', 'group', false, false, false, [], [$field]);
    $repeat = new FieldDefinition('links', 'Links', 'repeatable_group', true, false, false, [], [$field], maximumItems: 4);
    WebAddress::assertFields([$group, $repeat], ['contact' => ['site' => addressDocument()], 'links' => ['en' => [['site' => addressDocument()]]]], ['en'], false);
    foreach ([['contact' => ['site' => addressDocument('data:text/plain,bad')]], ['links' => ['en' => [['site' => addressDocument('javascript:bad')]]]]] as $bad) {
        try {
            WebAddress::assertFields([$group, $repeat], $bad, ['en'], false);
            throw new RuntimeException('Unsafe nested address accepted');
        } catch (InvalidInput) {
        }
    }
    $package = ProfilePackage::load(dirname(__DIR__, 2) . '/profiles/reference-studio');
    $document = json_decode((string) file_get_contents($package->root . '/fixtures/snapshot.json'), true, 64, JSON_THROW_ON_ERROR);
    $snapshot = FrozenSnapshot::fromDocument($document, $package);
    assertSameValue('Web de la Nora', $snapshot->record('40000000000000000000000000000001')->body['website']['ca']['linkText'], 'snapshot preserves localized address');
    foreach ($document['records'] as &$record) {
        if ($record['typeId'] === 'person') {
            $record['body']['website']['en'] = addressDocument('javascript:bad');
        }
    }
    unset($record);
    try {
        FrozenSnapshot::fromDocument($document, $package);
        throw new RuntimeException('Forged snapshot bypassed URL validation');
    } catch (\Utsuri\PortableCms\Conventional\Compiler\ProfileBuildFailure $error) {
        assertTrueValue($error->getPrevious() instanceof InvalidInput, 'frozen validation retains the authoritative web address failure');
        assertSameValue('person', $error->typeId, 'the unsafe address record type is identified');
    }
};

$tests['native group address leaves preserve localized and repeatable controls'] = static function (): void {
    $profile = ProfileManifest::load(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json');
    $leaf = new FieldDefinition('site', 'Site', 'web_address', true, false, false, ['maximumLength' => 100], maximumLength: 100);
    $group = new FieldDefinition('contact', 'Contact', 'group', false, false, false, [], [$leaf]);
    $name = EditorialControls::childName('contact', 'site');
    $input = EditorialControls::groupInput(new Request('POST', '/', form: [$name . '__ca__url' => 'http://example.test/ca', $name . '__ca__linkText' => 'Estudi']), $group, $profile);
    assertSameValue(['site' => ['ca' => addressDocument('http://example.test/ca', 'Estudi'), 'es' => null, 'en' => null]], $input, 'localized address leaves use split successful controls');
    WebAddress::assertFields([$group], ['contact' => $input], $profile->locales, false);
    $repeat = new FieldDefinition('links', 'Links', 'repeatable_group', true, false, false, [], [$leaf], maximumItems: 4);
    $itemId = str_repeat('c', 32);
    $name = EditorialControls::childName('links_ca_i' . $itemId, 'site');
    $input = EditorialControls::groupInput(new Request('POST', '/', form: ['links_ca_items' => [$itemId], $name . '__url' => 'https://example.test/ca', $name . '__linkText' => 'Web']), $repeat, $profile);
    assertSameValue(['ca' => [['_itemId' => $itemId, 'site' => addressDocument('https://example.test/ca', 'Web')]], 'es' => [], 'en' => []], $input, 'a localized group does not add a second locale layer');
    WebAddress::assertFields([$repeat], ['links' => $input], $profile->locales, false);
};

$tests['web addresses survive normalized export reconstruction and reject poisoned restore input'] = static function (): void {
    $plan = restoreFixturePlan();
    $person = array_values(array_filter(restoreFixtureExport($plan)->document['revisions'], static fn(array $revision): bool => isset($revision['body']['website'])))[0];
    $serialized = json_decode(restoreFixtureExport($plan)->json(), true, 64, JSON_THROW_ON_ERROR);
    $reloaded = new \Utsuri\PortableCms\Conventional\Export\NormalizedExport($serialized);
    $restored = array_values(array_filter($reloaded->document['revisions'], static fn(array $revision): bool => isset($revision['body']['website'])))[0];
    assertSameValue($person['body']['website'], $restored['body']['website'], 'portable representation is lossless');
    try {
        restoreFixturePlan(static function (array &$document): void {
            foreach ($document['revisions'] as &$revision) {
                if (isset($revision['body']['website'])) {
                    $revision['body']['website']['en'] = addressDocument('data:text/html,bad');
                }
            }
            unset($revision);
        });
        throw new RuntimeException('Poisoned export accepted for restoration');
    } catch (\Utsuri\PortableCms\Conventional\Restore\RestoreRejected) {
    }
};
