<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional;

use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\JsonPointer;

final class SchemaValidationFailure extends \InvalidArgumentException
{
    /** @param list<array{path: string, keyword: string, message: string}> $issues */
    public function __construct(
        string $message,
        public readonly array $issues = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}

final class JsonSchemaValidator
{
    private const MAX_DOCUMENT_BYTES = 1_048_576;
    private const MAX_SCHEMA_BYTES = 262_144;
    private const MAX_DEPTH = 64;
    private const MAX_NODES = 50_000;
    private const MAX_ERRORS = 8;
    private const DRAFT = 'https://json-schema.org/draft/2020-12/schema';

    /** @var list<string> */
    private const OPIS_EXTENSION_KEYWORDS = [
        '$data',
        '$error',
        '$filters',
        '$globals',
        '$map',
        '$pragma',
        '$slots',
    ];

    public static function validateFile(string $documentPath, string $schemaPath): void
    {
        self::validateJson(
            self::readBoundedFile($documentPath, self::MAX_DOCUMENT_BYTES, 'document'),
            self::readBoundedFile($schemaPath, self::MAX_SCHEMA_BYTES, 'schema'),
        );
    }

    public static function validateJson(string $documentJson, string $schemaJson): void
    {
        self::validateBounded($documentJson, $schemaJson, self::MAX_DOCUMENT_BYTES, self::MAX_NODES);
    }

    public static function validateJsonWithSchemaFile(string $documentJson, string $schemaPath): void
    {
        self::validateJson($documentJson, self::readBoundedFile($schemaPath, self::MAX_SCHEMA_BYTES, 'schema'));
    }

    /** Export is the only larger envelope. Ordinary form/profile limits stay unchanged. */
    public static function validateExportJson(string $documentJson, string $schemaJson): void
    {
        self::validateBounded($documentJson, $schemaJson, 33_554_432, 500_000);
    }

    /** Current projections and their compiler output, never full retained history. */
    public static function validateSnapshotJson(string $documentJson, string $schemaJson): void
    {
        self::validateBounded($documentJson, $schemaJson, 4_194_304, 250_000);
    }

    public static function validateArtifactJson(string $documentJson, string $schemaJson): void
    {
        self::validateBounded($documentJson, $schemaJson, 8_388_608, 250_000);
    }

    public static function validateSearchJson(string $documentJson, string $schemaJson): void
    {
        self::validateBounded($documentJson, $schemaJson, 8_388_608, 250_000);
    }

    private static function validateBounded(string $documentJson, string $schemaJson, int $maximumBytes, int $maximumNodes): void
    {
        if (strlen($documentJson) > $maximumBytes || strlen($schemaJson) > self::MAX_SCHEMA_BYTES) {
            throw new SchemaValidationFailure('Schema validation input exceeds its byte limit');
        }

        try {
            $document = json_decode($documentJson, false, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
            $schema = json_decode($schemaJson, false, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new SchemaValidationFailure('Schema validation input is not valid bounded JSON', previous: $error);
        }
        if (!is_object($schema) || ($schema->{'$schema'} ?? null) !== self::DRAFT) {
            throw new SchemaValidationFailure('Schema must declare JSON Schema Draft 2020-12');
        }

        $nodes = 0;
        self::inspectValue($schema, 0, $nodes, true);
        $nodes = 0;
        self::inspectValue($document, 0, $nodes, false, $maximumNodes);

        try {
            $result = (new CompliantValidator(null, self::MAX_ERRORS, false))->validate($document, $schema);
        } catch (\Throwable $error) {
            throw new SchemaValidationFailure('Schema execution failed safely', previous: $error);
        }
        if ($result->isValid()) {
            return;
        }

        $error = $result->error();
        $issues = $error === null ? [] : self::projectIssues($error);
        throw new SchemaValidationFailure('Document does not satisfy its schema', $issues);
    }

    private static function readBoundedFile(string $path, int $maximumBytes, string $kind): string
    {
        $size = HostIo::attempt(fn() => filesize($path))->value;
        if (!is_int($size) || $size < 2 || $size > $maximumBytes) {
            throw new SchemaValidationFailure("Schema validation {$kind} size is invalid");
        }
        $contents = HostIo::attempt(fn() => file_get_contents($path))->value;
        if (!is_string($contents) || strlen($contents) !== $size) {
            throw new SchemaValidationFailure("Schema validation {$kind} could not be read completely");
        }
        return $contents;
    }

    private static function inspectValue(mixed $value, int $depth, int &$nodes, bool $schema, int $maximumNodes = self::MAX_NODES): void
    {
        $nodes++;
        if ($depth > self::MAX_DEPTH || $nodes > $maximumNodes) {
            throw new SchemaValidationFailure('Schema validation input exceeds structural limits');
        }
        if (is_array($value)) {
            foreach ($value as $child) {
                self::inspectValue($child, $depth + 1, $nodes, $schema, $maximumNodes);
            }
            return;
        }
        if (!is_object($value)) {
            return;
        }
        foreach (get_object_vars($value) as $key => $child) {
            if ($schema && in_array($key, self::OPIS_EXTENSION_KEYWORDS, true)) {
                throw new SchemaValidationFailure('Schema contains a disabled extension keyword');
            }
            if ($schema && in_array($key, ['$ref', '$dynamicRef'], true)) {
                if (!is_string($child) || !str_starts_with($child, '#')) {
                    throw new SchemaValidationFailure('Schema references must remain inside the local document');
                }
            }
            self::inspectValue($child, $depth + 1, $nodes, $schema, $maximumNodes);
        }
    }

    /** @return list<array{path: string, keyword: string, message: string}> */
    private static function projectIssues(ValidationError $root): array
    {
        $issues = [];
        $pending = [$root];
        while ($pending !== [] && count($issues) < self::MAX_ERRORS) {
            /** @var ValidationError $error */
            $error = array_shift($pending);
            $children = $error->subErrors();
            if ($children !== []) {
                array_push($pending, ...$children);
                continue;
            }
            $path = JsonPointer::pathToString($error->data()->fullPath());
            $issues[] = [
                'path' => mb_substr($path, 0, 240),
                'keyword' => mb_substr($error->keyword(), 0, 80),
                'message' => self::safeMessage($error->keyword()),
            ];
        }
        return $issues;
    }

    private static function safeMessage(string $keyword): string
    {
        return match ($keyword) {
            'required' => 'A required property is missing.',
            'additionalProperties' => 'An unknown property is present.',
            'type' => 'A value has the wrong JSON type.',
            'pattern' => 'A string has an invalid format.',
            'minLength', 'maxLength' => 'A string has an invalid length.',
            'minItems', 'maxItems', 'uniqueItems' => 'An array violates its item constraints.',
            'minimum', 'maximum' => 'A number is outside its allowed range.',
            'const', 'enum' => 'A value is not an allowed variant.',
            default => 'A schema constraint was not satisfied.',
        };
    }
}
