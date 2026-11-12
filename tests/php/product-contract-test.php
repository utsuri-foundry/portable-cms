<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\AdministrationLabels;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\JsonSchemaValidator;
use Utsuri\PortableCms\Conventional\SchemaValidationFailure;

$fixtureRoot = dirname(__DIR__, 2) . '/schemas/fixtures';
$schemaRoot = dirname(__DIR__, 2) . '/schemas';

$tests['validates the versioned product contract fixture catalog'] = static function () use (
    $fixtureRoot,
    $schemaRoot,
): void {
    $index = json_decode(
        (string) file_get_contents($fixtureRoot . '/contract-cases-v1.json'),
        true,
        32,
        JSON_THROW_ON_ERROR,
    );
    assertSameValue(1, $index['formatVersion'] ?? null, 'fixture catalog format should be explicit');
    $cases = $index['cases'] ?? null;
    assertTrueValue(is_array($cases) && array_is_list($cases), 'fixture catalog should contain a case list');

    $seen = [];
    foreach ($cases as $case) {
        assertTrueValue(is_array($case), 'each fixture case should be an object');
        assertSameValue(
            ['id', 'schema', 'document', 'expectation', 'domain'],
            array_keys($case),
            'fixture case keys should remain exact',
        );
        $id = $case['id'];
        assertTrueValue(is_string($id) && !isset($seen[$id]), 'fixture case IDs should be unique strings');
        $seen[$id] = true;
        $schema = realpath($schemaRoot . '/' . $case['schema']);
        $document = realpath($fixtureRoot . '/' . $case['document']);
        assertTrueValue(is_string($schema) && str_starts_with($schema, $schemaRoot . '/'), 'schema path should be local');
        assertTrueValue(is_string($document), 'fixture document should exist');

        $schemaAccepted = true;
        try {
            JsonSchemaValidator::validateFile($document, $schema);
        } catch (SchemaValidationFailure) {
            $schemaAccepted = false;
        }
        assertSameValue(
            $case['expectation'] !== 'schema_rejected',
            $schemaAccepted,
            "schema outcome should match fixture {$id}",
        );

        if (!in_array($case['domain'], ['profile', 'administration-labels', 'website-publication', 'web-address'], true) || !$schemaAccepted) {
            continue;
        }
        $domainAccepted = true;
        try {
            if ($case['domain'] === 'profile') {
                ProfileManifest::load($document);
            } elseif ($case['domain'] === 'web-address') {
                \Utsuri\PortableCms\Conventional\Content\WebAddress::from(json_decode((string) file_get_contents($document), true, 16, JSON_THROW_ON_ERROR));
            } elseif ($case['domain'] === 'website-publication') {
                \Utsuri\PortableCms\Conventional\Publication\WebsitePublication::from(json_decode((string) file_get_contents($document), true, 16, JSON_THROW_ON_ERROR));
            } else {
                AdministrationLabels::fromDocument(
                    json_decode((string) file_get_contents($document), true, 16, JSON_THROW_ON_ERROR),
                    ProfileManifest::load(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json'),
                );
            }
        } catch (InvalidArgumentException) {
            $domainAccepted = false;
        }
        assertSameValue(
            $case['expectation'] === 'accepted',
            $domainAccepted,
            "profile outcome should match fixture {$id}",
        );
    }
    assertTrueValue(count($seen) >= 12, 'the catalog should cover every initial product contract');
};

$tests['rejects incompatible profiles before domain objects are returned'] = static function (): void {
    $path = dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json';
    try {
        ProfileManifest::load($path, '9.0.0');
        throw new RuntimeException('incompatible engine unexpectedly loaded a profile');
    } catch (InvalidArgumentException $error) {
        assertSameValue(
            'Profile requires engine >= ' . ProfileManifest::load($path)->compatibility->minimumEngineVersion . ' and < 1.0.0; running engine is 9.0.0',
            $error->getMessage(),
            'compatibility should fail with a stable pre-mutation reason',
        );
    }
};
