<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\AdministrationLabels;
use Utsuri\PortableCms\Conventional\Content\AdministrationMenuLabels;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\WebsiteIdentity;
use Utsuri\PortableCms\Conventional\Preview\PreviewSnapshot;
use Utsuri\PortableCms\Conventional\Publication\IdentityUpgradeNote;
use Utsuri\PortableCms\Conventional\Publication\WebsitePublication;
use Utsuri\PortableCms\Conventional\Publication\WebsitePublicationService;
use Utsuri\PortableCms\Conventional\View\AdminChrome;
use Utsuri\PortableCms\Conventional\View\WebsitePublicationPage;

$tests['profile loading rejects engine and cross-type menu names with type language and entry diagnostics'] = static function (): void {
    $root = staticCompilerTempRoot();
    try {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json');
        foreach ([['label', 'Media', 'shell.nav.media'], ['singularLabel', 'Website settings', 'holding.settings'], ['label', 'oVeRvIeW', 'shell.nav.overview'], ['singularLabel', 'Article', 'article']] as [$field, $name, $entry]) {
            $document = json_decode($source, flags: JSON_THROW_ON_ERROR);
            $document->recordTypes[0]->{$field} = $name;
            file_put_contents($root . '/profile.json', json_encode($document, JSON_THROW_ON_ERROR));
            try {
                ProfileManifest::load($root . '/profile.json');
                throw new RuntimeException('Ambiguous menu label passed');
            } catch (InvalidArgumentException $error) {
                foreach (['project', $entry === 'article' ? 'ca' : 'en', $entry] as $part) {
                    assertTrueValue(str_contains($error->getMessage(), $part), 'diagnostic names ' . $part . ': ' . $error->getMessage());
                }
            }
        }
    } finally {
        staticCompilerRemoveTree($root);
    }
};

$tests['translated menu collisions include localized singular labels and effective fallback names'] = static function (): void {
    $profile = referenceStudioPackage()->manifest;
    foreach ([['project', 'Configuració del web', 'Configuració del web', 'holding.settings'], ['project', 'Nom propi', 'Article', 'article']] as [$type, $plural, $singular, $entry]) {
        $labels = AdministrationLabels::fromDocument(['formatVersion' => 1, 'profileId' => $profile->id, 'languages' => ['ca' => ['types' => [$type => ['label' => $plural, 'singularLabel' => $singular]]]]], $profile);
        try {
            AdministrationMenuLabels::assert($profile, $labels);
            throw new RuntimeException('Translated collision passed');
        } catch (InvalidArgumentException $error) {
            foreach ([$type, 'ca', $entry] as $part) {
                assertTrueValue(str_contains($error->getMessage(), $part), 'translated diagnostic names ' . $part);
            }
        }
    }
};

$tests['profile menu checks use supplied language names rather than an overridden fallback'] = static function (): void {
    $root = staticCompilerTempRoot();
    try {
        $document = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json'), flags: JSON_THROW_ON_ERROR);
        $document->recordTypes[0]->label = 'Media';
        file_put_contents($root . '/profile.json', json_encode($document, JSON_THROW_ON_ERROR));
        file_put_contents($root . '/administration-labels.json', json_encode(['formatVersion' => 1, 'profileId' => 'reference-studio', 'languages' => ['en' => ['types' => ['project' => ['label' => 'Images', 'singularLabel' => 'Image']]]]], JSON_THROW_ON_ERROR));
        $profile = ProfileManifest::load($root . '/profile.json');
        assertSameValue('Media', $profile->type('project')?->label, 'validation does not mutate profile identity or declarations');
        AdministrationMenuLabels::assert($profile, AdministrationLabels::load($root, $profile));
    } finally {
        staticCompilerRemoveTree($root);
    }
};

$tests['canonical website identity is frozen into previews and works without profile discovery defaults'] = static function (): void {
    $fixture = profilePreviewFixture();
    try {
        $base = $fixture['snapshot'];
        $records = array_values(array_filter($base->records, static fn($record): bool => $record->typeId !== 'site-settings'));
        $identity = new WebsiteIdentity('One studio <name>');
        $snapshot = new PreviewSnapshot($base->profileId, $base->profileVersion, $base->locales, $records, $base->createdAt, $base->designRegistries, $base->profileChecksum, websiteIdentity: $identity);
        $fixture['compiler']->preflight($snapshot);
        $frozen = $fixture['compiler']->freeze($snapshot);
        assertSameValue($identity->document(), $frozen->websiteIdentity?->document(), 'the canonical identity is part of immutable input');
        assertSameValue($identity, $snapshot->with($records, true)->websiteIdentity, 'live preview record overlays preserve the same identity');
        $build = $fixture['package']->siteBuilder()->build($frozen);
        foreach ($build->routes as $route) {
            assertTrueValue(str_contains($route->html, 'One studio &lt;name&gt;'), 'all website heads and visible identity use one escaped name');
        }
    } finally {
        staticCompilerRemoveTree($fixture['root']);
    }
};

$tests['identity upgrade notes survive saving and show retained values as escaped text'] = static function (): void {
    $profile = referenceStudioPackage()->manifest;
    $note = new IdentityUpgradeNote('website-settings', [['typeId' => 'site-settings', 'recordId' => str_repeat('a', 32), 'name' => 'Previous <studio>', 'iconMediaId' => str_repeat('b', 32)]]);
    $settings = WebsitePublication::from([...WebsitePublication::defaults($profile->id, $profile->locales)->document(), 'revision' => 2, 'websiteName' => 'Canonical studio', 'identityUpgrade' => $note->document()]);
    $repository = new MemoryWebsitePublication($settings);
    $service = new WebsitePublicationService($repository, $profile, new IdentityTestClock(new DateTimeImmutable('2026-10-19T12:00:00Z')), new IdentityTestRandom());
    $input = $settings->document();
    unset($input['identityUpgrade']);
    $next = $service->save([...$input, 'revision' => 3, 'websiteName' => 'Later intentional edit'], 2, holdingTestActor(), false);
    assertSameValue($note->document(), $next->identityUpgrade?->document(), 'ordinary native saves cannot drop the preservation note');
    assertSameValue($note->document(), $next->only(['en'])->identityUpgrade?->document(), 'holding language selection preserves the note');
    $expanded = $next->within([...$profile->locales, 'it']);
    assertSameValue($note->document(), $expanded->identityUpgrade?->document(), 'adding an installed language preserves the former identity values');
    assertSameValue($next->identity()->document(), $expanded->identity()->document(), 'language expansion does not change the canonical identity');
    assertSameValue('Coming soon.', $expanded->document()['translations'][3]['message'], 'a newly installed language receives its default holding message');
    $page = WebsitePublicationPage::render($profile, new AdminChrome('csrf', 'Administrator'), $next);
    assertTrueValue(str_contains($page, 'kept the name and icon from Website settings') && str_contains($page, 'Previous &lt;studio&gt;') && !str_contains($page, 'Previous <studio>'), 'the editor sees which source was kept and the escaped former values');
};

$tests['typed blocks cannot introduce a second editable website icon'] = static function (): void {
    $root = staticCompilerTempRoot();
    try {
        $document = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json'), flags: JSON_THROW_ON_ERROR);
        foreach ($document->singletons as $type) {
            foreach ($type->fields as $field) {
                if (($field->type ?? null) === 'blocks') {
                    $field->settings->blockTypes[0]->fields[] = (object) ['id' => 'duplicate-icon', 'label' => 'Duplicate icon', 'type' => 'media_references', 'localized' => false, 'requiredOnDraft' => false, 'requiredOnPublish' => false, 'settings' => (object) ['maximumItems' => 1, 'kinds' => ['image'], 'purpose' => 'website-icon']];
                }
            }
        }
        file_put_contents($root . '/profile.json', json_encode($document, JSON_THROW_ON_ERROR));
        try {
            ProfileManifest::load($root . '/profile.json');
            throw new RuntimeException('A block introduced a second website icon');
        } catch (InvalidArgumentException $error) {
            assertTrueValue(str_contains($error->getMessage(), 'duplicate-icon') && str_contains($error->getMessage(), 'Website settings'), 'block identity declarations use the same single-source diagnostic');
        }
    } finally {
        staticCompilerRemoveTree($root);
    }
};

$tests['website identity and preservation notes reject malformed values without truncating valid names'] = static function (): void {
    $name = str_repeat('é', 180);
    $identity = WebsiteIdentity::from(['name' => $name, 'iconMediaId' => null]);
    assertSameValue($name, $identity->name, 'the full Unicode name is retained');
    assertTrueValue(str_contains(\Utsuri\PortableCms\Conventional\Content\SearchSharing::from(null)->resolve('Page', '', $name)->searchTitle, $name), 'search titles accept the same name bound as Website settings');
    foreach ([['name' => '', 'iconMediaId' => null], ['name' => "Hidden\nname", 'iconMediaId' => null], ['name' => $name . 'x', 'iconMediaId' => null], ['name' => 'Studio', 'iconMediaId' => '../private'], ['name' => 'Studio', 'iconMediaId' => null, 'extra' => true]] as $document) {
        try {
            WebsiteIdentity::from($document);
            throw new RuntimeException('Invalid website identity accepted');
        } catch (InvalidArgumentException) {
        }
    }
    $previous = ['typeId' => 'site-settings', 'recordId' => str_repeat('a', 32), 'name' => 'Former name', 'iconMediaId' => null];
    foreach ([['kept' => 'other', 'previous' => [$previous]], ['kept' => 'website-settings', 'previous' => [$previous, $previous]], ['kept' => 'website-settings', 'previous' => ['key' => $previous]], ['kept' => 'website-settings', 'previous' => [[...$previous, 'iconMediaId' => 'https://example.test/icon.png']]]] as $document) {
        try {
            IdentityUpgradeNote::from($document);
            throw new RuntimeException('Invalid preservation note accepted');
        } catch (\Utsuri\PortableCms\Conventional\Publication\PublicationFailure) {
        }
    }
};

$tests['native automatic metadata uses the engine identity even before discovery defaults exist'] = static function (): void {
    $profile = referenceStudioPackage()->manifest;
    $service = new \Utsuri\PortableCms\Conventional\Content\ContentService(new MemoryRecordRepository(), $profile, new IdentityTestClock(new DateTimeImmutable('2026-10-19T12:00:00Z')), new IdentityTestRandom(), identity: static fn(): WebsiteIdentity => new WebsiteIdentity('Canonical studio'));
    $type = $profile->type('project');
    if ($type === null) {
        throw new RuntimeException('Reference project is not installed');
    }
    $field = \Utsuri\PortableCms\Conventional\View\SearchSharingFeedback::field($type);
    if ($field === null) {
        throw new RuntimeException('Reference search/sharing mapping is unavailable');
    }
    $values = ['title' => array_fill_keys($profile->locales, 'Project')];
    $resolved = \Utsuri\PortableCms\Conventional\View\SearchSharingFeedback::resolve($type, $field, $values, $profile, $service);
    assertSameValue('Canonical studio', $resolved['en']['siteName'], 'the illustrative metadata reads the engine value');
    assertSameValue('Project | Canonical studio', $resolved['en']['searchTitle'], 'automatic titles do not need another profile name or settings record');
};

$tests['legacy declared identity fields are discovered without hardcoded client names and require a profile update'] = static function (): void {
    $root = staticCompilerTempRoot();
    try {
        $document = json_decode(ProfileManifest::load(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json')->definitionJson ?? throw new RuntimeException('Missing expanded profile'), flags: JSON_THROW_ON_ERROR);
        foreach ($document->singletons as $type) {
            if ($type->id === 'site-settings') {
                $type->fields[] = (object) ['id' => 'formerSiteName', 'label' => 'Former site name', 'type' => 'short_text', 'localized' => false, 'requiredOnDraft' => false, 'requiredOnPublish' => false, 'settings' => (object) ['maximumLength' => 180]];
                $type->fields[] = (object) ['id' => 'formerIcon', 'label' => 'Former site icon', 'type' => 'media_references', 'localized' => false, 'requiredOnDraft' => false, 'requiredOnPublish' => false, 'settings' => (object) ['maximumItems' => 1, 'kinds' => ['image'], 'purpose' => 'website-icon']];
            }
        }
        foreach ($document->recordTypes[0]->fields as $field) {
            if (isset($field->settings->searchSharing)) {
                unset($field->settings->searchSharing->siteNameSource);
                $field->settings->searchSharing->siteNameField = 'formerSiteName';
            }
        }
        file_put_contents($root . '/profile.json', json_encode($document, JSON_THROW_ON_ERROR));
        try {
            ProfileManifest::load($root . '/profile.json');
            throw new RuntimeException('Fresh profile installation accepted a duplicate identity');
        } catch (InvalidArgumentException $error) {
            assertTrueValue(str_contains($error->getMessage(), 'Website settings'), 'fresh installs name the authoritative identity source');
        }
        $legacy = ProfileManifest::load($root . '/profile.json', allowLegacyProfile: true);
        assertSameValue([['typeId' => 'site-settings', 'nameField' => 'formerSiteName', 'iconField' => 'formerIcon']], \Utsuri\PortableCms\Conventional\Upgrade\WebsiteIdentityUpgrade::sources($legacy), 'upgrade capture follows declarations rather than reference-profile field IDs');
        try {
            \Utsuri\PortableCms\Conventional\Upgrade\WebsiteIdentityUpgrade::assertIncoming($legacy);
            throw new RuntimeException('Incoming duplicate identity source accepted');
        } catch (\Utsuri\PortableCms\Conventional\Upgrade\UpgradeFailure $error) {
            assertSameValue('website_identity_profile_update_required', $error->codeName, 'old builders must adopt the single source before activation');
        }
    } finally {
        staticCompilerRemoveTree($root);
    }
};

$tests['native content renames refuse engine menu collisions without saving any changes'] = static function (): void {
    $package = referenceStudioPackage();
    $repository = new MemoryTerminologyRepository();
    $service = new \Utsuri\PortableCms\Conventional\Content\TerminologyService($repository, $package->manifest, new IdentityTestClock(new DateTimeImmutable('2026-10-19T12:00:00Z')), new IdentityTestRandom(), $package->administrationLabels);
    $values = [];
    foreach ($package->manifest->types as $type) {
        $values[$type->id . '__singular'] = $type->singularLabel;
        $values[$type->id . '__plural'] = $type->label;
    }
    foreach ([['project__plural' => 'Media'], ['project__plural__ca' => 'Configuració del web', 'project__singular__ca' => 'Configuració del web']] as $collision) {
        $result = $service->save(0, [...$values, ...$collision], str_repeat('a', 32));
        assertTrueValue($result instanceof \Utsuri\PortableCms\Conventional\Content\ValidationFailure, 'the native editor receives a recoverable validation failure');
        assertSameValue(0, $repository->load($package->manifest->id)->revision, 'a rejected name never reaches the repository');
    }
};
