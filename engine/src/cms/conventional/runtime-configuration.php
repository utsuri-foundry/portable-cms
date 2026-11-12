<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional;

use PDO;

final readonly class RuntimeConfiguration
{
    public function __construct(
        public string $origin,
        public string $databaseDsn,
        public string $databaseUsername,
        public string $databasePassword,
        public string $throttleSecret,
        public ?string $errorLog,
        public string $mediaRoot,
        public string $mediaWakeStrategy,
        public bool $allowInsecureLoopback = false,
        public bool $showTechnicalData = false,
        public ?string $fontSanitizer = null,
        public ?string $fontCompressor = null,
        public ?string $profileRoot = null,
        public ?Ai\AssistancePolicy $ai = null,
        public ?Compiler\PublicUrlContext $publicUrlContext = null,
        public ?string $publicationRoot = null,
        public ?Media\VideoUploadQualification $videoUploads = null,
        public Database\TablePrefix $tablePrefix = new Database\TablePrefix(Database\TablePrefix::CANONICAL),
        public string $databaseUpgradeMode = self::UPGRADE_TABLE_SET,
        public ?int $databaseMaximumBytes = null,
        public string $timeZone = 'UTC',
    ) {}

    /** Schema and profile upgrades build a second table set in the same database, then switch. */
    public const UPGRADE_TABLE_SET = 'table_set';
    /** Schema and profile upgrades pause the CMS and change the serving tables, restoring the backup on failure. */
    public const UPGRADE_IN_PLACE = 'in_place';
    /** Schema and profile upgrades use an empty, separately provisioned database. */
    public const UPGRADE_SEPARATE_DATABASE = 'separate_database';

    public static function load(string $path, ?string $documentRoot = null): self
    {
        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
            throw new \RuntimeException('CMS configuration is unavailable');
        }
        $resolvedDocumentRoot = $documentRoot !== null ? realpath($documentRoot) : false;
        if ($resolvedDocumentRoot !== false) {
            if (self::within($resolved, $resolvedDocumentRoot)) {
                throw new \RuntimeException('CMS configuration must be outside the document root');
            }
        }
        $permissions = HostIo::attempt(fn() => fileperms($resolved))->value;
        if (!is_int($permissions) || ($permissions & 0o077) !== 0) {
            throw new \RuntimeException('CMS configuration permissions must be 0600 or stricter');
        }

        try {
            $decoded = json_decode((string) HostIo::attempt(fn() => file_get_contents($resolved))->value, true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            throw new \RuntimeException('CMS configuration is invalid', previous: $error);
        }
        if (!is_array($decoded)) {
            throw new \RuntimeException('CMS configuration must contain an object');
        }
        $database = is_array($decoded['database'] ?? null) ? $decoded['database'] : [];
        $storage = is_array($decoded['storage'] ?? null) ? $decoded['storage'] : [];
        $development = is_array($decoded['development'] ?? null) ? $decoded['development'] : [];
        $allowInsecureLoopback = ($development['allowInsecureLoopback'] ?? false) === true;
        $origin = self::requiredString($decoded, 'origin');
        $parts = parse_url($origin);
        if (
            !is_array($parts)
            || !isset($parts['host'])
            || !self::allowedOrigin($parts, $allowInsecureLoopback)
            || (isset($parts['path']) && $parts['path'] !== '')
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new \RuntimeException('CMS origin must be HTTPS');
        }
        $secret = base64_decode(self::requiredString($decoded, 'throttleSecretBase64'), true);
        if (!is_string($secret) || strlen($secret) < 32) {
            throw new \RuntimeException('CMS throttle secret is invalid');
        }
        $errorLog = $decoded['errorLog'] ?? null;
        if ($errorLog !== null && (!is_string($errorLog) || $errorLog === '')) {
            throw new \RuntimeException('CMS error log path is invalid');
        }
        if ($errorLog !== null) {
            if (!str_starts_with($errorLog, DIRECTORY_SEPARATOR)) {
                throw new \RuntimeException('CMS error log path must be absolute');
            }
            $logDirectory = realpath(dirname($errorLog));
            if (
                $logDirectory === false
                || ($resolvedDocumentRoot !== false && self::within($logDirectory, $resolvedDocumentRoot))
            ) {
                throw new \RuntimeException('CMS error log directory must exist outside the document root');
            }
        }
        $databaseDsn = self::requiredString($database, 'dsn');
        if (!str_starts_with($databaseDsn, 'mysql:') || !str_contains(strtolower($databaseDsn), 'charset=utf8mb4')) {
            throw new \RuntimeException('CMS database DSN must select MariaDB/MySQL with utf8mb4');
        }
        $mediaRoot = $storage['mediaRoot'] ?? dirname($resolved) . DIRECTORY_SEPARATOR . 'media';
        if (!is_string($mediaRoot) || !str_starts_with($mediaRoot, DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('CMS media root must be absolute');
        }
        $mediaParent = realpath(dirname($mediaRoot));
        if (
            $mediaParent === false
            || ($resolvedDocumentRoot !== false && self::within(
                $mediaParent . DIRECTORY_SEPARATOR . basename($mediaRoot),
                $resolvedDocumentRoot,
            ))
        ) {
            throw new \RuntimeException('CMS media root must be in private storage');
        }
        $mediaWakeStrategy = $decoded['mediaWakeStrategy'] ?? 'post_response';
        if (!is_string($mediaWakeStrategy) || !in_array($mediaWakeStrategy, ['post_response', 'cron_only'], true)) {
            throw new \RuntimeException('CMS media wake strategy is invalid');
        }
        $profileRoot = self::profileRoot($decoded['profileRoot'] ?? null);
        if ($profileRoot !== null) {
            $profileResolved = realpath($profileRoot);
            if ($profileResolved === false || !is_dir($profileResolved) || is_link($profileRoot)
                || ($resolvedDocumentRoot !== false && self::within($profileResolved, $resolvedDocumentRoot))) {
                throw new \RuntimeException('Installed profile must be in private storage');
            }
            $profileRoot = $profileResolved;
        }

        $publicationRoot = self::publicationRoot($decoded['publicationRoot'] ?? null);
        if ($publicationRoot !== null) {
            $resolvedPublic = realpath($publicationRoot);
            if ($resolvedPublic === false || $resolvedPublic === '/' || $resolvedPublic !== rtrim($publicationRoot, '/') || !is_dir($resolvedPublic)
                || !is_writable($resolvedPublic) || self::within($resolved, $resolvedPublic)
                || self::within($mediaRoot, $resolvedPublic) || self::within($resolvedPublic, $mediaRoot)
                || ($profileRoot !== null && self::within($profileRoot, $resolvedPublic))
                || ($errorLog !== null && self::within($logDirectory, $resolvedPublic))
                || ($resolvedDocumentRoot !== false && (self::within($resolvedPublic, $resolvedDocumentRoot) || self::within($resolvedDocumentRoot, $resolvedPublic)))) {
                throw new \RuntimeException('Publication needs a dedicated static root separate from private storage and the CMS');
            }
            $publicationRoot = $resolvedPublic;
        }

        return new self(
            origin: rtrim($origin, '/'),
            databaseDsn: $databaseDsn,
            databaseUsername: self::requiredString($database, 'username'),
            databasePassword: self::requiredString($database, 'password'),
            throttleSecret: $secret,
            errorLog: $errorLog,
            mediaRoot: rtrim($mediaRoot, DIRECTORY_SEPARATOR),
            mediaWakeStrategy: $mediaWakeStrategy,
            allowInsecureLoopback: $allowInsecureLoopback,
            showTechnicalData: ($development['showTechnicalData'] ?? false) === true,
            fontSanitizer: isset($decoded['fontProcessing']) ? self::requiredString(self::fontConfig($decoded['fontProcessing']), 'sanitizer') : null,
            fontCompressor: isset($decoded['fontProcessing']) ? self::requiredString(self::fontConfig($decoded['fontProcessing']), 'compressor') : null,
            profileRoot: $profileRoot,
            ai: self::aiPolicy($decoded['aiAssistance'] ?? null),
            publicUrlContext: self::publicUrlContext($decoded['publicUrlContext'] ?? null, $origin),
            publicationRoot: $publicationRoot,
            videoUploads: Media\VideoUploadQualification::from($decoded['videoUploads'] ?? null),
            tablePrefix: self::tablePrefix($database['tablePrefix'] ?? Database\TablePrefix::CANONICAL),
            databaseUpgradeMode: self::upgradeMode($database['upgradeMode'] ?? self::UPGRADE_TABLE_SET),
            databaseMaximumBytes: self::maximumBytes($database['maximumBytes'] ?? null),
            timeZone: self::timeZone($decoded['timeZone'] ?? 'UTC'),
        );
    }

    /** Validates a remote profile path without resolving it on this host. */
    public static function profileRoot(mixed $value): ?string
    {
        return self::installationPath($value);
    }

    public static function timeZone(mixed $value): string
    {
        try {
            return Publication\ReleaseTime::zone($value)->getName();
        } catch (\InvalidArgumentException $error) {
            throw new \RuntimeException('Installation time zone must be an IANA identifier', previous: $error);
        }
    }

    /** Validates a remote publication path without filesystem effects. */
    public static function publicationRoot(mixed $value): ?string
    {
        $path = self::installationPath($value);
        if ($path !== null && rtrim($path, '/') === '') {
            throw new \RuntimeException('Publication needs a dedicated static root');
        }
        return $path;
    }

    private static function installationPath(mixed $value): ?string
    {
        if ($value !== null && (!is_string($value) || !str_starts_with($value, DIRECTORY_SEPARATOR) || str_contains($value, "\0"))) {
            throw new \RuntimeException('Installation paths must be absolute');
        }
        return $value;
    }

    /** Validates public identity independently of the installation's host layout. */
    public static function publicUrlContext(mixed $value, string $cmsOrigin): ?Compiler\PublicUrlContext
    {
        if ($value === null) {
            return null;
        }
        $context = Compiler\PublicUrlContext::from($value);
        $public = parse_url($context->baseUrl);
        $cms = parse_url($cmsOrigin);
        if (is_array($public) && is_array($cms)
            && ($public['scheme'] ?? null) === ($cms['scheme'] ?? null)
            && strtolower(rtrim($public['host'] ?? '', '.')) === strtolower(rtrim($cms['host'] ?? '', '.'))
            && ($public['port'] ?? 443) === ($cms['port'] ?? 443)) {
            throw new \RuntimeException('Public website origin must differ from the CMS origin');
        }
        return $context;
    }

    private static function aiPolicy(mixed $config): ?Ai\AssistancePolicy
    {
        try {
            return function_exists('curl_init') ? Ai\AssistancePolicy::from($config) : null;
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private static function tablePrefix(mixed $value): Database\TablePrefix
    {
        try {
            return new Database\TablePrefix(is_string($value) ? $value : '');
        } catch (\InvalidArgumentException) {
            throw new \RuntimeException('CMS database table prefix must be one lowercase word followed by an underscore');
        }
    }

    /** Validates installation policy without filesystem or database effects. */
    public static function upgradeMode(mixed $value): string
    {
        if (!in_array($value, [self::UPGRADE_TABLE_SET, self::UPGRADE_IN_PLACE, self::UPGRADE_SEPARATE_DATABASE], true)) {
            throw new \RuntimeException('CMS database upgrade mode must be table_set, in_place or separate_database');
        }
        return $value;
    }

    /** Validates an optional database quota already decoded as a whole byte count. */
    public static function maximumBytes(mixed $value): ?int
    {
        if ($value !== null && (!is_int($value) || $value < 1)) {
            throw new \RuntimeException('CMS database maximum bytes must be a positive whole number');
        }
        return $value;
    }

    /** @return array<string, mixed> */
    private static function fontConfig(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \RuntimeException('Private font processing configuration is invalid');
        }
        return $value;
    }

    /** Connects to this installation's table set, or to another table set in the same database. */
    public function connect(?Database\TablePrefix $tablePrefix = null): PDO
    {
        return new Database\DatabaseConnection(
            $this->databaseDsn,
            $this->databaseUsername,
            $this->databasePassword,
            $tablePrefix ?? $this->tablePrefix,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        );
    }

    /** @param array<array-key, mixed> $source */
    private static function requiredString(array $source, string $key): string
    {
        $value = $source[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \RuntimeException('CMS configuration is missing a required value');
        }
        return $value;
    }

    private static function within(string $path, string $directory): bool
    {
        $directory = rtrim($directory, DIRECTORY_SEPARATOR);
        return $path === $directory || str_starts_with($path, $directory . DIRECTORY_SEPARATOR);
    }

    /** @param array<string, mixed> $parts */
    private static function allowedOrigin(array $parts, bool $allowInsecureLoopback): bool
    {
        $scheme = $parts['scheme'] ?? null;
        if ($scheme === 'https') {
            return true;
        }
        if (!$allowInsecureLoopback || $scheme !== 'http') {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }
}
