<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\ArchitectureGate;

use JsonException;
use RuntimeException;

final readonly class SourceBudget
{
    public function __construct(
        public int $warning,
        public int $maximum,
    ) {
        if ($warning < 1 || $maximum < $warning) {
            throw new RuntimeException('Source budgets require 0 < warning <= maximum');
        }
    }
}

final readonly class LayerPolicy
{
    /**
     * @param list<string> $paths
     * @param list<string> $mayDependOn
     * @param list<string> $namespacePrefixes
     */
    public function __construct(
        public string $name,
        public array $paths,
        public array $mayDependOn,
        public array $namespacePrefixes,
    ) {}
}

final readonly class ClassificationPolicy
{
    /**
     * @param list<string> $testPathPrefixes
     * @param list<string> $facadeFiles
     * @param list<string> $facadeDirectories
     */
    public function __construct(
        public array $testPathPrefixes,
        public array $facadeFiles,
        public array $facadeDirectories,
    ) {}
}

final readonly class ArchitecturePolicy
{
    /**
     * @param list<string> $sourceRoots
     * @param list<string> $sourceExtensions
     * @param list<string> $ignoredDirectories
     * @param list<LayerPolicy> $layers
     * @param array{production: SourceBudget, facade: SourceBudget, test: SourceBudget} $sourceBudgets
     * @param list<string> $forbiddenModuleNames
     * @param list<string> $requiredDocuments
     */
    public function __construct(
        public int $schema,
        public array $sourceRoots,
        public array $sourceExtensions,
        public array $ignoredDirectories,
        public array $layers,
        public array $sourceBudgets,
        public ClassificationPolicy $classification,
        public array $forbiddenModuleNames,
        public array $requiredDocuments,
    ) {}
}

final readonly class PolicyException
{
    public function __construct(
        public string $path,
        public string $rule,
        public int $ceiling,
        public string $owner,
        public string $reason,
        public string $created,
        public string $expires,
        public string $removalTask,
    ) {}
}

final readonly class ExceptionRegistry
{
    /** @param list<PolicyException> $exceptions */
    public function __construct(
        public int $schema,
        public array $exceptions,
    ) {}
}

final readonly class ArchitectureConfiguration
{
    public function __construct(
        public ArchitecturePolicy $policy,
        public ExceptionRegistry $exceptions,
    ) {}
}

final class PolicyLoader
{
    public static function load(string $policyPath, string $exceptionsPath): ArchitectureConfiguration
    {
        $policy = self::decode($policyPath);
        self::exactKeys($policy, [
            'schema',
            'sourceRoots',
            'sourceExtensions',
            'ignoredDirectories',
            'layers',
            'sourceBudgets',
            'classification',
            'forbiddenModuleNames',
            'requiredDocuments',
        ], 'architecture policy');
        $schema = self::integer($policy['schema'], 'architecture policy schema');
        if ($schema !== 1) {
            throw new RuntimeException("Unsupported architecture policy schema {$schema}");
        }

        $sourceRoots = self::paths($policy['sourceRoots'], 'sourceRoots');
        self::nonemptyList($sourceRoots, 'sourceRoots');
        $sourceExtensions = self::strings($policy['sourceExtensions'], 'sourceExtensions');
        self::nonemptyList($sourceExtensions, 'sourceExtensions');
        foreach ($sourceExtensions as $extension) {
            if (!preg_match('/^[a-z][a-z0-9]*$/', $extension)) {
                throw new RuntimeException("Invalid source extension {$extension}");
            }
        }
        $ignoredDirectories = self::strings($policy['ignoredDirectories'], 'ignoredDirectories');
        foreach ($ignoredDirectories as $directory) {
            if ($directory === '' || str_contains($directory, '/') || str_contains($directory, '\\')) {
                throw new RuntimeException('Ignored directories must be single path components');
            }
        }

        $layers = [];
        foreach (self::list($policy['layers'], 'layers') as $index => $value) {
            $layer = self::object($value, "layers[{$index}]");
            self::exactKeys(
                $layer,
                ['name', 'paths', 'mayDependOn', 'namespacePrefixes'],
                "layers[{$index}]",
            );
            $name = self::string($layer['name'], "layers[{$index}].name");
            if (!preg_match('/^[a-z][a-z0-9-]*$/', $name)) {
                throw new RuntimeException("Invalid architecture layer name {$name}");
            }
            $prefixes = self::strings($layer['namespacePrefixes'], "layers[{$index}].namespacePrefixes");
            foreach ($prefixes as $prefix) {
                if ($prefix === '' || !str_ends_with($prefix, '\\')) {
                    throw new RuntimeException("Namespace prefix {$prefix} must end with a namespace separator");
                }
            }
            $paths = self::paths($layer['paths'], "layers[{$index}].paths");
            self::nonemptyList($paths, "layers[{$index}].paths");
            $layers[] = new LayerPolicy(
                $name,
                $paths,
                self::strings($layer['mayDependOn'], "layers[{$index}].mayDependOn"),
                $prefixes,
            );
        }
        self::nonemptyList($layers, 'layers');
        self::unique(array_map(static fn(LayerPolicy $layer): string => $layer->name, $layers), 'layer names');
        $knownLayers = array_fill_keys(array_map(static fn(LayerPolicy $layer): string => $layer->name, $layers), true);
        $ownedPaths = [];
        $namespaceOwners = [];
        foreach ($layers as $layer) {
            foreach ($layer->paths as $path) {
                if (isset($ownedPaths[$path])) {
                    throw new RuntimeException("Architecture path {$path} is owned by several layers");
                }
                $ownedPaths[$path] = $layer->name;
            }
            foreach ($layer->namespacePrefixes as $prefix) {
                if (isset($namespaceOwners[$prefix])) {
                    throw new RuntimeException("Namespace prefix {$prefix} is owned by several layers");
                }
                $namespaceOwners[$prefix] = $layer->name;
            }
            foreach ($layer->mayDependOn as $dependency) {
                if (!isset($knownLayers[$dependency])) {
                    throw new RuntimeException("Layer {$layer->name} allows unknown layer {$dependency}");
                }
                if ($dependency === $layer->name) {
                    throw new RuntimeException("Layer {$layer->name} must not declare its implicit self-dependency");
                }
            }
        }

        $budgetValues = self::object($policy['sourceBudgets'], 'sourceBudgets');
        self::exactKeys($budgetValues, ['production', 'facade', 'test'], 'sourceBudgets');
        $budgets = [];
        foreach (['production', 'facade', 'test'] as $class) {
            $value = self::object($budgetValues[$class], "sourceBudgets.{$class}");
            self::exactKeys($value, ['warning', 'maximum'], "sourceBudgets.{$class}");
            $budgets[$class] = new SourceBudget(
                self::integer($value['warning'], "sourceBudgets.{$class}.warning"),
                self::integer($value['maximum'], "sourceBudgets.{$class}.maximum"),
            );
        }

        $classificationValue = self::object($policy['classification'], 'classification');
        self::exactKeys(
            $classificationValue,
            ['testPathPrefixes', 'facadeFiles', 'facadeDirectories'],
            'classification',
        );
        $classification = new ClassificationPolicy(
            self::paths($classificationValue['testPathPrefixes'], 'classification.testPathPrefixes'),
            self::paths($classificationValue['facadeFiles'], 'classification.facadeFiles'),
            self::paths($classificationValue['facadeDirectories'], 'classification.facadeDirectories'),
        );

        $forbidden = self::strings($policy['forbiddenModuleNames'], 'forbiddenModuleNames');
        foreach ($forbidden as $name) {
            if (!preg_match('/^[a-z][a-z0-9-]*$/', $name)) {
                throw new RuntimeException("Invalid forbidden module name {$name}");
            }
        }
        self::unique($forbidden, 'forbidden module names');

        $requiredDocuments = self::paths($policy['requiredDocuments'], 'requiredDocuments');
        self::nonemptyList($requiredDocuments, 'requiredDocuments');

        $registry = self::decode($exceptionsPath);
        self::exactKeys($registry, ['schema', 'exceptions'], 'architecture exception registry');
        $exceptionSchema = self::integer($registry['schema'], 'architecture exception schema');
        if ($exceptionSchema !== 1) {
            throw new RuntimeException("Unsupported architecture exception schema {$exceptionSchema}");
        }
        $exceptions = [];
        foreach (self::list($registry['exceptions'], 'exceptions') as $index => $value) {
            $exception = self::object($value, "exceptions[{$index}]");
            self::exactKeys($exception, [
                'path',
                'rule',
                'ceiling',
                'owner',
                'reason',
                'created',
                'expires',
                'removalTask',
            ], "exceptions[{$index}]");
            $rule = self::string($exception['rule'], "exceptions[{$index}].rule");
            if ($rule !== 'source-size') {
                throw new RuntimeException("Unsupported architecture exception rule {$rule}");
            }
            $exceptions[] = new PolicyException(
                self::path($exception['path'], "exceptions[{$index}].path"),
                $rule,
                self::integer($exception['ceiling'], "exceptions[{$index}].ceiling"),
                self::nonemptyString($exception['owner'], "exceptions[{$index}].owner"),
                self::nonemptyString($exception['reason'], "exceptions[{$index}].reason"),
                self::nonemptyString($exception['created'], "exceptions[{$index}].created"),
                self::nonemptyString($exception['expires'], "exceptions[{$index}].expires"),
                self::nonemptyString($exception['removalTask'], "exceptions[{$index}].removalTask"),
            );
        }
        self::unique(
            array_map(static fn(PolicyException $exception): string => $exception->path . ':' . $exception->rule, $exceptions),
            'architecture exception path/rule pairs',
        );

        return new ArchitectureConfiguration(
            new ArchitecturePolicy(
                $schema,
                $sourceRoots,
                $sourceExtensions,
                $ignoredDirectories,
                $layers,
                $budgets,
                $classification,
                $forbidden,
                $requiredDocuments,
            ),
            new ExceptionRegistry($exceptionSchema, $exceptions),
        );
    }

    /** @return array<string, mixed> */
    private static function decode(string $path): array
    {
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException("Could not read {$path}");
        }
        try {
            return self::object(json_decode($bytes, true, 64, JSON_THROW_ON_ERROR), $path);
        } catch (JsonException $error) {
            throw new RuntimeException("Invalid JSON in {$path}: {$error->getMessage()}", 0, $error);
        }
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $expected
     */
    private static function exactKeys(array $value, array $expected, string $context): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new RuntimeException("{$context} has missing or unknown fields");
        }
    }

    /** @return array<string, mixed> */
    private static function object(mixed $value, string $context): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException("{$context} must be an object");
        }
        return $value;
    }

    /** @return list<mixed> */
    private static function list(mixed $value, string $context): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException("{$context} must be an array");
        }
        return $value;
    }

    /** @return list<string> */
    private static function strings(mixed $value, string $context): array
    {
        $result = [];
        foreach (self::list($value, $context) as $index => $item) {
            $result[] = self::nonemptyString($item, "{$context}[{$index}]");
        }
        self::unique($result, $context);
        return $result;
    }

    /** @return list<string> */
    private static function paths(mixed $value, string $context): array
    {
        $result = [];
        foreach (self::list($value, $context) as $index => $item) {
            $result[] = self::path($item, "{$context}[{$index}]");
        }
        self::unique($result, $context);
        return $result;
    }

    private static function path(mixed $value, string $context): string
    {
        $path = self::nonemptyString($value, $context);
        if (
            str_starts_with($path, '/')
            || str_contains($path, '\\')
            || str_ends_with($path, '/')
            || preg_match('~(?:^|/)\.\.?(/|$)~', $path)
        ) {
            throw new RuntimeException("{$context} must be a normalized repository-relative path");
        }
        return $path;
    }

    private static function string(mixed $value, string $context): string
    {
        if (!is_string($value)) {
            throw new RuntimeException("{$context} must be a string");
        }
        return $value;
    }

    private static function nonemptyString(mixed $value, string $context): string
    {
        $string = self::string($value, $context);
        if (trim($string) === '') {
            throw new RuntimeException("{$context} must not be empty");
        }
        return $string;
    }

    private static function integer(mixed $value, string $context): int
    {
        if (!is_int($value) || $value < 1) {
            throw new RuntimeException("{$context} must be a positive integer");
        }
        return $value;
    }

    /** @param list<string> $values */
    private static function unique(array $values, string $context): void
    {
        if (count($values) !== count(array_unique($values, SORT_STRING))) {
            throw new RuntimeException("{$context} must not contain duplicates");
        }
    }

    /** @param array<mixed> $values */
    private static function nonemptyList(array $values, string $context): void
    {
        if ($values === []) {
            throw new RuntimeException("{$context} must not be empty");
        }
    }
}
