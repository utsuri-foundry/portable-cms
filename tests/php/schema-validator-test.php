<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\JsonSchemaValidator;
use Utsuri\PortableCms\Conventional\SchemaValidationFailure;

$schemaPath = dirname(__DIR__, 2) . '/schemas/portable-cms-profile-v1.schema.json';
$profilePath = dirname(__DIR__, 2) . '/engine/src/cms/conventional/profile/gate-zero.json';

$tests['executes the Draft 2020-12 profile schema'] = static function () use ($schemaPath, $profilePath): void {
    JsonSchemaValidator::validateFile($profilePath, $schemaPath);
};

$tests['rejects structurally invalid profiles with safe bounded issues'] = static function () use ($schemaPath, $profilePath): void {
    $profile = json_decode((string) file_get_contents($profilePath), true, 16, JSON_THROW_ON_ERROR);
    $secret = 'do-not-reflect-this-value';
    $profile['id'] = $secret;
    $profile['unknown'] = $secret;
    try {
        JsonSchemaValidator::validateJson(
            json_encode($profile, JSON_THROW_ON_ERROR),
            (string) file_get_contents($schemaPath),
        );
        throw new RuntimeException('invalid profile unexpectedly passed schema validation');
    } catch (SchemaValidationFailure $error) {
        assertTrueValue($error->issues !== [], 'schema failure should expose bounded safe issues');
        assertTrueValue(count($error->issues) <= 8, 'schema failure should cap projected issues');
        assertTrueValue(
            !str_contains(json_encode($error->issues, JSON_THROW_ON_ERROR), $secret),
            'schema issues must not reflect submitted values',
        );
    }
};

$tests['prohibits remote and filesystem schema references'] = static function () use ($profilePath): void {
    $document = (string) file_get_contents($profilePath);
    foreach (['https://example.invalid/schema.json', 'file:///etc/passwd', '../schema.json'] as $reference) {
        $schema = json_encode([
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$ref' => $reference,
        ], JSON_THROW_ON_ERROR);
        try {
            JsonSchemaValidator::validateJson($document, $schema);
            throw new RuntimeException('non-local schema reference unexpectedly passed');
        } catch (SchemaValidationFailure $error) {
            assertSameValue(
                'Schema references must remain inside the local document',
                $error->getMessage(),
                'non-local references should fail before schema execution',
            );
        }
    }
};

$tests['disables Opis extension keywords and requires Draft 2020-12'] = static function (): void {
    foreach ([
        '{"$schema":"https://json-schema.org/draft/2020-12/schema","$filters":{}}'
            => 'Schema contains a disabled extension keyword',
        '{"$schema":"http://json-schema.org/draft-07/schema#","type":"object"}'
            => 'Schema must declare JSON Schema Draft 2020-12',
    ] as $schema => $expected) {
        try {
            JsonSchemaValidator::validateJson('{}', $schema);
            throw new RuntimeException('unsafe schema unexpectedly passed');
        } catch (SchemaValidationFailure $error) {
            assertSameValue($expected, $error->getMessage(), 'unsafe schema should fail with a stable reason');
        }
    }
};

$tests['bounds schema document size and JSON depth'] = static function (): void {
    $schema = '{"$schema":"https://json-schema.org/draft/2020-12/schema"}';
    try {
        JsonSchemaValidator::validateJson(str_repeat(' ', 1_048_577), $schema);
        throw new RuntimeException('oversized document unexpectedly passed');
    } catch (SchemaValidationFailure $error) {
        assertSameValue('Schema validation input exceeds its byte limit', $error->getMessage(), 'byte limit should fail first');
    }

    $deep = str_repeat('[', 65) . 'null' . str_repeat(']', 65);
    try {
        JsonSchemaValidator::validateJson($deep, $schema);
        throw new RuntimeException('deep document unexpectedly passed');
    } catch (SchemaValidationFailure $error) {
        assertSameValue(
            'Schema validation input is not valid bounded JSON',
            $error->getMessage(),
            'JSON depth should be bounded',
        );
    }
};
