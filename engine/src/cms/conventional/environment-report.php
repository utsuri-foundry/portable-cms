<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional;

require_once __DIR__ . '/media/video-support.php';

final class EnvironmentReport
{
    public const FORMAT_VERSION = 3;
    public const MINIMUM_PHP_VERSION = '8.2.0';

    /** @var list<string> */
    private const REQUIRED_EXTENSIONS = [
        'curl',
        'fileinfo',
        'hash',
        'intl',
        'json',
        'mbstring',
        'openssl',
        'pdo',
        'pdo_mysql',
    ];

    /**
     * Collect a redacted report. Paths are inspected but never returned.
     *
     * @param array{
     *   privateStorage?: string|null,
     *   publication?: string|null,
     *   scratch?: string|null,
     *   cronHeartbeat?: string|null,
     *   documentRoot?: string|null
     * } $paths
     * @param array<string, mixed>|null $server
     * @param array<string, mixed>|null $database
     * @return array<string, mixed>
     */
    public static function collect(
        array $paths,
        ?int $now = null,
        ?array $server = null,
        ?array $database = null,
        ?string $outboundHttpsUrl = null,
        ?\Utsuri\PortableCms\Conventional\Media\VideoUploadQualification $videoQualification = null,
    ): array {
        $now ??= time();
        $server ??= $_SERVER;

        $extensions = [];
        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            $extensions[$extension] = [
                'loaded' => extension_loaded($extension),
                'version' => self::extensionVersion($extension),
            ];
        }

        $storage = [];
        foreach (['privateStorage', 'publication', 'scratch'] as $name) {
            $storage[$name] = self::inspectDirectory($paths[$name] ?? null);
        }
        $storage['privateStorage']['protection'] = self::inspectPrivateStorageProtection(
            $paths['privateStorage'] ?? null,
            $paths['documentRoot'] ?? ($server['DOCUMENT_ROOT'] ?? null),
        );

        $snapshot = [
            'generatedAt' => gmdate('Y-m-d\TH:i:s\Z', $now),
            'runtime' => [
                'phpVersion' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'integerBytes' => PHP_INT_SIZE,
                'iniFileLoaded' => php_ini_loaded_file() !== false,
                'iniScanFileCount' => self::iniScanFileCount(),
            ],
            'extensions' => $extensions,
            'limits' => self::runtimeLimits(),
            'configuration' => self::inspectConfiguration(),
            'storage' => $storage,
            'scheduler' => [
                'heartbeat' => self::inspectHeartbeat($paths['cronHeartbeat'] ?? null, $now),
                'postResponseFunction' => function_exists('fastcgi_finish_request'),
            ],
            'media' => self::inspectMedia(),
            'transport' => [
                'https' => self::httpsState($server),
                'curlCaBundleConfigured' => self::hasCurlCaBundle(),
                'outboundHttps' => self::inspectOutboundHttps($outboundHttpsUrl),
            ],
            'database' => $database ?? [
                'configured' => false,
                'connected' => false,
                'errorCode' => 'not_configured',
            ],
        ];

        return self::fromSnapshot($snapshot, $videoQualification);
    }

    /** @return array<string, mixed> */
    public static function runtimeLimits(): array
    {
        return [
            'fileUploads' => filter_var(ini_get('file_uploads'), FILTER_VALIDATE_BOOL),
            'uploadMaxBytes' => self::parseIniBytes(ini_get('upload_max_filesize')),
            'postMaxBytes' => self::parseIniBytes(ini_get('post_max_size')),
            'memoryLimitBytes' => self::parseIniBytes(ini_get('memory_limit')),
            'maxExecutionSeconds' => self::parseIniInteger(ini_get('max_execution_time')),
            'maxInputSeconds' => self::parseIniInteger(ini_get('max_input_time')),
            'maxFileUploads' => self::parseIniInteger(ini_get('max_file_uploads')),
            'maxInputVars' => self::parseIniInteger(ini_get('max_input_vars')),
        ];
    }

    /**
     * Convert an already-redacted snapshot into the stable report contract.
     * This seam keeps status derivation deterministic and independently testable.
     *
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    public static function fromSnapshot(array $snapshot, ?\Utsuri\PortableCms\Conventional\Media\VideoUploadQualification $videoQualification = null): array
    {
        $free = $snapshot['storage']['privateStorage']['freeBytes'] ?? null;
        $policy = \Utsuri\PortableCms\Conventional\Media\VideoUploadPolicy::qualify($videoQualification, $snapshot['limits'] ?? [], is_int($free) ? $free : null, ($snapshot['media']['preencodedVideo']['enabled'] ?? false) === true);
        $snapshot['media']['preencodedVideo'] = [...($snapshot['media']['preencodedVideo'] ?? []), ...$policy->report()];
        $blockers = [];
        $warnings = [];
        $phpVersion = (string) ($snapshot['runtime']['phpVersion'] ?? '0.0.0');
        if (!version_compare($phpVersion, self::MINIMUM_PHP_VERSION, '>=')) {
            $blockers[] = 'php_version';
        }

        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            if (($snapshot['extensions'][$extension]['loaded'] ?? false) !== true) {
                $blockers[] = "extension_{$extension}";
            }
        }

        if (($snapshot['limits']['fileUploads'] ?? false) !== true) {
            $blockers[] = 'file_uploads_disabled';
        }

        foreach (['privateStorage', 'publication', 'scratch'] as $directory) {
            $status = $snapshot['storage'][$directory] ?? [];
            if (($status['configured'] ?? false) !== true) {
                $blockers[] = "{$directory}_not_configured";
            } elseif (($status['directory'] ?? false) !== true) {
                $blockers[] = "{$directory}_not_directory";
            } elseif (($status['writable'] ?? false) !== true) {
                $blockers[] = "{$directory}_not_writable";
            } elseif (($status['operations']['attempted'] ?? false) === true) {
                if (($status['operations']['fileWrite'] ?? false) !== true) {
                    $blockers[] = "{$directory}_file_write_unavailable";
                }
                if ($directory === 'publication') {
                    if (($status['operations']['fileRename'] ?? false) !== true) {
                        $blockers[] = 'publication_file_rename_unavailable';
                    }
                    if (($status['operations']['fileReplace'] ?? false) !== true) {
                        $blockers[] = 'publication_file_replace_unavailable';
                    }
                }
                if (($status['operations']['directoryRename'] ?? false) !== true) {
                    $warnings[] = "{$directory}_directory_rename_unavailable";
                }
                if (($status['operations']['symlink'] ?? false) !== true) {
                    $warnings[] = "{$directory}_symlink_unavailable";
                }
                if (($status['operations']['cleanupComplete'] ?? false) !== true) {
                    $warnings[] = "{$directory}_probe_cleanup_incomplete";
                }
            }
        }
        $protection = $snapshot['storage']['privateStorage']['protection'] ?? [];
        if (($protection['outsideDocumentRoot'] ?? false) !== true
            && ($protection['denyRuleVerified'] ?? false) !== true) {
            $blockers[] = 'private_storage_exposure_unverified';
        }

        $imagick = $snapshot['media']['engines']['imagick'] ?? [];
        if (($imagick['loaded'] ?? false) !== true) {
            $blockers[] = 'imagick_unavailable';
        } else {
            if (array_key_exists('operations', $imagick)) {
                if (($imagick['operations']['decodePng'] ?? false) !== true) {
                    $blockers[] = 'imagick_decode_png';
                }
                foreach (['Jpeg', 'Png', 'Webp'] as $format) {
                    if (($imagick['operations']["encode{$format}"] ?? false) !== true) {
                        $blockers[] = 'imagick_encode_' . strtolower($format);
                    }
                }
                if (($imagick['operations']['encodeAvif'] ?? false) !== true) {
                    $warnings[] = 'imagick_format_avif_unavailable';
                }
            } else {
                foreach (['jpeg', 'png', 'webp'] as $format) {
                    if (($imagick['formats'][$format] ?? false) !== true) {
                        $blockers[] = "imagick_format_{$format}";
                    }
                }
                if (($imagick['formats']['avif'] ?? false) !== true) {
                    $warnings[] = 'imagick_format_avif_unavailable';
                }
            }
        }
        if (($snapshot['media']['engines']['gd']['loaded'] ?? false) === true
            && ($snapshot['media']['selectedEngine'] ?? null) === null) {
            $warnings[] = 'gd_reduced_fidelity_candidate';
        }

        $database = $snapshot['database'] ?? [];
        if (($database['configured'] ?? false) !== true) {
            $blockers[] = 'database_not_configured';
        } elseif (($database['connected'] ?? false) !== true) {
            $blockers[] = 'database_unavailable';
        } else {
            $engine = $database['engine'] ?? null;
            $version = (string) ($database['serverVersion'] ?? '0');
            if (!in_array($engine, ['mariadb', 'mysql'], true)) {
                $blockers[] = 'database_engine_unsupported';
            } elseif (!self::databaseVersionSupported($engine, $version)) {
                $blockers[] = 'database_version';
            }
            if (($database['databaseCharacterSet'] ?? null) !== 'utf8mb4') {
                $blockers[] = 'database_character_set';
            }
            if (($database['connectionCharacterSet'] ?? null) !== 'utf8mb4') {
                $blockers[] = 'database_connection_character_set';
            }
            foreach ([
                'temporaryTable' => 'temporary_table',
                'rollback' => 'rollback',
                'compareAndSet' => 'compare_and_set',
            ] as $capability => $code) {
                if (($database['transactionProbe'][$capability] ?? false) !== true) {
                    $blockers[] = "database_{$code}_unavailable";
                }
            }
            if (($database['transactionProbe']['cleanupComplete'] ?? false) !== true) {
                $warnings[] = 'database_probe_cleanup_incomplete';
            }
            if (!str_starts_with((string) ($database['databaseCollation'] ?? ''), 'utf8mb4_')) {
                $warnings[] = 'database_collation_not_utf8mb4';
            }
            if (!str_starts_with((string) ($database['connectionCollation'] ?? ''), 'utf8mb4_')) {
                $warnings[] = 'database_connection_collation_not_utf8mb4';
            }
        }

        if (($snapshot['configuration']['openBasedirConfigured'] ?? false) === true) {
            $warnings[] = 'open_basedir_configured';
        }
        if (($snapshot['configuration']['disabledFunctions'] ?? []) !== []) {
            $warnings[] = 'php_functions_disabled';
        }
        if (($snapshot['scheduler']['heartbeat']['configured'] ?? false) !== true) {
            $warnings[] = 'cron_heartbeat_not_configured';
        } elseif (($snapshot['scheduler']['heartbeat']['fresh'] ?? false) !== true) {
            $warnings[] = 'cron_heartbeat_stale';
        }
        if (($snapshot['transport']['https'] ?? 'unknown') !== 'on') {
            $warnings[] = 'https_not_observed';
        }
        if (($snapshot['transport']['outboundHttps']['verified'] ?? false) !== true) {
            $blockers[] = 'outbound_https_unverified';
        }

        $blockers = array_values(array_unique($blockers));
        $warnings = array_values(array_unique($warnings));
        $managedMediaBlockers = [
            'imagick_unavailable',
            'imagick_decode_png',
            'imagick_encode_jpeg',
            'imagick_encode_png',
            'imagick_encode_webp',
            'imagick_format_jpeg',
            'imagick_format_png',
            'imagick_format_webp',
        ];
        $nonMediaBlockers = array_values(array_diff($blockers, $managedMediaBlockers));
        $installationClass = $blockers === []
            ? 'standalone'
            : ($nonMediaBlockers === [] ? 'managed_service_required' : 'unsupported');

        return [
            'formatVersion' => self::FORMAT_VERSION,
            'generatedAt' => $snapshot['generatedAt'] ?? null,
            'status' => $blockers === [] ? 'compatible' : 'blocked',
            'installationClass' => $installationClass,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'runtime' => $snapshot['runtime'] ?? [],
            'extensions' => $snapshot['extensions'] ?? [],
            'limits' => $snapshot['limits'] ?? [],
            'configuration' => $snapshot['configuration'] ?? [],
            'storage' => $snapshot['storage'] ?? [],
            'scheduler' => $snapshot['scheduler'] ?? [],
            'media' => $snapshot['media'] ?? [],
            'transport' => $snapshot['transport'] ?? [],
            'database' => $database,
        ];
    }

    private static function databaseVersionSupported(mixed $engine, string $version): bool
    {
        if (!preg_match('/\d+(?:\.\d+){1,2}/', $version, $matches)) {
            return false;
        }
        $minimum = $engine === 'mariadb' ? '10.6.0' : '8.0.0';
        return version_compare($matches[0], $minimum, '>=');
    }

    public static function parseIniBytes(string|false $value): ?int
    {
        if ($value === false) {
            return null;
        }
        $normalized = trim($value);
        if ($normalized === '' || $normalized === '-1') {
            return null;
        }
        if (!preg_match('/^(\d+)\s*([kmgt])?b?$/i', $normalized, $matches)) {
            return null;
        }
        $multipliers = ['' => 1, 'k' => 1024, 'm' => 1024 ** 2, 'g' => 1024 ** 3, 't' => 1024 ** 4];
        $unit = strtolower($matches[2] ?? '');
        $digits = ltrim($matches[1], '0');
        $digits = $digits === '' ? '0' : $digits;
        if (strlen($digits) > strlen((string) PHP_INT_MAX)) {
            return null;
        }
        $quantity = (int) $digits;
        $multiplier = $multipliers[$unit];
        if ($quantity > intdiv(PHP_INT_MAX, $multiplier)) {
            return null;
        }
        return $quantity * $multiplier;
    }

    private static function parseIniInteger(string|false $value): ?int
    {
        if ($value === false || !preg_match('/^-?\d+$/', trim($value))) {
            return null;
        }
        return (int) $value;
    }

    /** @return array<string, mixed> */
    private static function inspectDirectory(?string $path): array
    {
        if ($path === null || trim($path) === '') {
            return [
                'configured' => false,
                'directory' => false,
                'writable' => false,
                'freeBytes' => null,
                'operations' => self::emptyFilesystemOperations(),
            ];
        }
        $directory = HostIo::attempt(fn() => is_dir($path))->value;
        $freeBytes = $directory ? HostIo::attempt(fn() => disk_free_space($path))->value : false;
        return [
            'configured' => true,
            'directory' => $directory,
            'writable' => $directory && HostIo::attempt(fn() => is_writable($path))->value,
            'freeBytes' => is_float($freeBytes) ? (int) $freeBytes : null,
            'operations' => $directory && HostIo::attempt(fn() => is_writable($path))->value
                ? self::inspectFilesystemOperations($path)
                : self::emptyFilesystemOperations(),
        ];
    }

    /** @return array{documentRootKnown: bool, outsideDocumentRoot: bool|null, denyRuleVerified: bool} */
    private static function inspectPrivateStorageProtection(?string $privateStorage, mixed $documentRoot): array
    {
        $privatePath = is_string($privateStorage) ? HostIo::attempt(fn() => realpath($privateStorage))->value : false;
        $documentPath = is_string($documentRoot) ? HostIo::attempt(fn() => realpath($documentRoot))->value : false;
        if ($privatePath === false || $documentPath === false) {
            return [
                'documentRootKnown' => $documentPath !== false,
                'outsideDocumentRoot' => null,
                'denyRuleVerified' => false,
            ];
        }
        $root = rtrim($documentPath, DIRECTORY_SEPARATOR);
        $inside = $privatePath === $documentPath
            || str_starts_with($privatePath, $root . DIRECTORY_SEPARATOR);
        return [
            'documentRootKnown' => true,
            'outsideDocumentRoot' => !$inside,
            'denyRuleVerified' => false,
        ];
    }

    /** @return array{attempted: bool, fileWrite: bool, fileRename: bool, fileReplace: bool, directoryRename: bool, symlink: bool, cleanupComplete: bool} */
    private static function emptyFilesystemOperations(): array
    {
        return [
            'attempted' => false,
            'fileWrite' => false,
            'fileRename' => false,
            'fileReplace' => false,
            'directoryRename' => false,
            'symlink' => false,
            'cleanupComplete' => true,
        ];
    }

    /** @return array{attempted: bool, fileWrite: bool, fileRename: bool, fileReplace: bool, directoryRename: bool, symlink: bool, cleanupComplete: bool} */
    private static function inspectFilesystemOperations(string $directory): array
    {
        $result = self::emptyFilesystemOperations();
        $result['attempted'] = true;
        try {
            $probe = $directory . DIRECTORY_SEPARATOR . '.portable-cms-probe-' . bin2hex(random_bytes(8));
        } catch (\Throwable) {
            $result['cleanupComplete'] = false;
            return $result;
        }

        $first = $probe . DIRECTORY_SEPARATOR . 'first.tmp';
        $current = $probe . DIRECTORY_SEPARATOR . 'current.dat';
        $replacement = $probe . DIRECTORY_SEPARATOR . 'replacement.tmp';
        $directoryBefore = $probe . DIRECTORY_SEPARATOR . 'directory-before';
        $directoryAfter = $probe . DIRECTORY_SEPARATOR . 'directory-after';
        $link = $probe . DIRECTORY_SEPARATOR . 'current.link';

        if (!HostIo::attempt(fn() => mkdir($probe, 0700))->value) {
            $result['cleanupComplete'] = !HostIo::attempt(fn() => file_exists($probe))->value;
            return $result;
        }

        try {
            $result['fileWrite'] = HostIo::attempt(fn() => file_put_contents($first, "probe\n", LOCK_EX))->value === 6;
            $result['fileRename'] = $result['fileWrite'] && HostIo::attempt(fn() => rename($first, $current))->value;
            if (HostIo::attempt(fn() => file_put_contents($replacement, "replacement\n", LOCK_EX))->value === 12) {
                $result['fileReplace'] = HostIo::attempt(fn() => rename($replacement, $current))->value
                    && HostIo::attempt(fn() => file_get_contents($current))->value === "replacement\n";
            }
            if (HostIo::attempt(fn() => mkdir($directoryBefore, 0700))->value) {
                $result['directoryRename'] = HostIo::attempt(fn() => rename($directoryBefore, $directoryAfter))->value;
            }
            if (function_exists('symlink') && HostIo::attempt(fn() => is_file($current))->value) {
                $result['symlink'] = HostIo::attempt(fn() => symlink('current.dat', $link))->value && is_link($link);
            }
        } finally {
            if (HostIo::attempt(fn() => is_link($link))->value) {
                HostIo::cleanup(fn() => unlink($link));
            }
            foreach ([$first, $replacement, $current] as $file) {
                if (HostIo::attempt(fn() => is_file($file))->value) {
                    HostIo::cleanup(fn() => unlink($file));
                }
            }
            foreach ([$directoryBefore, $directoryAfter] as $childDirectory) {
                if (HostIo::attempt(fn() => is_dir($childDirectory))->value) {
                    HostIo::cleanup(fn() => rmdir($childDirectory));
                }
            }
            if (HostIo::attempt(fn() => is_dir($probe))->value) {
                HostIo::cleanup(fn() => rmdir($probe));
            }
            $result['cleanupComplete'] = !HostIo::attempt(fn() => file_exists($probe))->value && !HostIo::attempt(fn() => is_link($probe))->value;
        }

        return $result;
    }

    /** @return array{configured: bool, observed: bool, ageSeconds: int|null, fresh: bool} */
    private static function inspectHeartbeat(?string $path, int $now): array
    {
        if ($path === null || trim($path) === '') {
            return ['configured' => false, 'observed' => false, 'ageSeconds' => null, 'fresh' => false];
        }
        $modifiedAt = is_file($path) ? HostIo::attempt(fn() => filemtime($path))->value : false;
        if (!is_int($modifiedAt)) {
            return ['configured' => true, 'observed' => false, 'ageSeconds' => null, 'fresh' => false];
        }
        $ageSeconds = max(0, $now - $modifiedAt);
        return [
            'configured' => true,
            'observed' => true,
            'ageSeconds' => $ageSeconds,
            'fresh' => $ageSeconds <= 20 * 60,
        ];
    }

    /** @return array<string, mixed> */
    private static function inspectMedia(): array
    {
        $imagick = self::inspectImagick();
        $qualified = ($imagick['loaded'] ?? false) === true
            && ($imagick['operations']['decodePng'] ?? false) === true
            && ($imagick['operations']['encodeJpeg'] ?? false) === true
            && ($imagick['operations']['encodePng'] ?? false) === true
            && ($imagick['operations']['encodeWebp'] ?? false) === true;
        return [
            'preencodedVideo' => ['enabled' => \Utsuri\PortableCms\Conventional\Media\VideoSupport::available(), 'maxBytes' => \Utsuri\PortableCms\Conventional\Media\VideoSupport::MAX_BYTES, 'transcoding' => false],
            'selectedEngine' => $qualified ? 'imagick' : null,
            'fidelity' => $qualified ? 'full' : 'unavailable',
            'engines' => [
                'imagick' => $imagick,
                'gd' => self::inspectGd(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function inspectImagick(): array
    {
        if (!extension_loaded('imagick') || !class_exists('Imagick')) {
            return self::emptyImagickReport(false);
        }
        try {
            $version = \Imagick::getVersion();
            $available = array_map('strtoupper', \Imagick::queryFormats());
        } catch (\Throwable) {
            return self::emptyImagickReport(true);
        }
        $formats = array_fill_keys($available, true);
        return [
            'loaded' => true,
            'version' => is_array($version) ? ($version['versionString'] ?? null) : null,
            'formats' => [
                'avif' => isset($formats['AVIF']),
                'jpeg' => isset($formats['JPEG']) || isset($formats['JPG']),
                'png' => isset($formats['PNG']),
                'webp' => isset($formats['WEBP']),
            ],
            'operations' => [
                'decodePng' => self::imagickCanDecodePng(),
                'encodeAvif' => self::imagickCanEncode('AVIF'),
                'encodeJpeg' => self::imagickCanEncode('JPEG'),
                'encodePng' => self::imagickCanEncode('PNG'),
                'encodeWebp' => self::imagickCanEncode('WEBP'),
            ],
            'resourceLimits' => self::imagickResourceLimits(),
        ];
    }

    /** @return array<string, mixed> */
    private static function emptyImagickReport(bool $loaded): array
    {
        return [
            'loaded' => $loaded,
            'version' => null,
            'formats' => ['avif' => false, 'jpeg' => false, 'png' => false, 'webp' => false],
            'operations' => [
                'decodePng' => false,
                'encodeAvif' => false,
                'encodeJpeg' => false,
                'encodePng' => false,
                'encodeWebp' => false,
            ],
            'resourceLimits' => [],
        ];
    }

    /** @return array<string, mixed> */
    private static function inspectGd(): array
    {
        if (!extension_loaded('gd') || !function_exists('gd_info')) {
            return [
                'loaded' => false,
                'version' => null,
                'adapterStatus' => 'not_implemented',
                'reportedFormats' => ['avif' => false, 'jpeg' => false, 'png' => false, 'webp' => false],
            ];
        }
        $info = gd_info();
        return [
            'loaded' => true,
            'version' => is_string($info['GD Version'] ?? null) ? $info['GD Version'] : null,
            'adapterStatus' => 'not_implemented',
            'reportedFormats' => [
                'avif' => ($info['AVIF Support'] ?? false) === true,
                'jpeg' => ($info['JPEG Support'] ?? false) === true,
                'png' => ($info['PNG Support'] ?? false) === true,
                'webp' => ($info['WebP Support'] ?? false) === true,
            ],
        ];
    }

    private static function imagickCanDecodePng(): bool
    {
        try {
            $image = new \Imagick();
            $image->readImageBlob(base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
                true,
            ));
            $valid = $image->getImageWidth() === 1 && $image->getImageHeight() === 1;
            $image->clear();
            $image->destroy();
            return $valid;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function imagickCanEncode(string $format): bool
    {
        try {
            $image = new \Imagick();
            $image->newImage(2, 2, new \ImagickPixel('white'));
            $image->setImageFormat($format);
            $encoded = $image->getImagesBlob();
            $image->clear();
            $image->destroy();
            return $encoded !== '';
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, int|null> */
    private static function imagickResourceLimits(): array
    {
        $limits = [];
        foreach (['AREA', 'DISK', 'FILE', 'MAP', 'MEMORY', 'THREAD', 'TIME'] as $name) {
            $constant = "Imagick::RESOURCETYPE_{$name}";
            try {
                $limits[strtolower($name)] = defined($constant)
                    ? \Imagick::getResourceLimit(constant($constant))
                    : null;
            } catch (\Throwable) {
                $limits[strtolower($name)] = null;
            }
        }
        return $limits;
    }

    /** @return array<string, mixed> */
    private static function inspectConfiguration(): array
    {
        $openBasedir = trim((string) ini_get('open_basedir'));
        return [
            'disabledFunctions' => self::iniList(ini_get('disable_functions'), ','),
            'openBasedirConfigured' => $openBasedir !== '',
            'openBasedirEntryCount' => $openBasedir === '' ? 0 : count(self::iniList($openBasedir, PATH_SEPARATOR)),
            'session' => [
                'saveHandler' => (string) ini_get('session.save_handler'),
                'savePathConfigured' => trim((string) ini_get('session.save_path')) !== '',
                'useStrictMode' => filter_var(ini_get('session.use_strict_mode'), FILTER_VALIDATE_BOOL),
                'useCookies' => filter_var(ini_get('session.use_cookies'), FILTER_VALIDATE_BOOL),
                'cookieSecure' => filter_var(ini_get('session.cookie_secure'), FILTER_VALIDATE_BOOL),
                'cookieHttpOnly' => filter_var(ini_get('session.cookie_httponly'), FILTER_VALIDATE_BOOL),
                'cookieSameSite' => (string) ini_get('session.cookie_samesite'),
            ],
        ];
    }

    /** @return list<string> */
    private static function iniList(string|false $value, string $separator): array
    {
        if ($value === false || trim($value) === '') {
            return [];
        }
        $items = array_values(array_filter(array_map('trim', explode($separator, $value))));
        $items = array_values(array_unique($items));
        sort($items, SORT_STRING);
        return $items;
    }

    private static function iniScanFileCount(): int
    {
        $files = php_ini_scanned_files();
        if ($files === false || trim($files) === '') {
            return 0;
        }
        return count(array_filter(array_map('trim', explode(',', $files))));
    }

    private static function extensionVersion(string $extension): ?string
    {
        if (!extension_loaded($extension)) {
            return null;
        }
        $version = phpversion($extension);
        return is_string($version) ? $version : null;
    }

    /** @param array<string, mixed> $server */
    private static function httpsState(array $server): string
    {
        $https = strtolower((string) ($server['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off' && $https !== '0') {
            return 'on';
        }
        $port = (string) ($server['SERVER_PORT'] ?? '');
        if ($port === '443') {
            return 'on';
        }
        return PHP_SAPI === 'cli' ? 'unknown' : 'off';
    }

    private static function hasCurlCaBundle(): bool
    {
        $curl = trim((string) ini_get('curl.cainfo'));
        $openssl = trim((string) ini_get('openssl.cafile'));
        return $curl !== '' || $openssl !== '';
    }

    /** @return array{configured: bool, verified: bool, statusCode: int|null, errorCode: string|null} */
    private static function inspectOutboundHttps(?string $url): array
    {
        if ($url === null || trim($url) === '') {
            return ['configured' => false, 'verified' => false, 'statusCode' => null, 'errorCode' => 'not_configured'];
        }
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])) {
            return ['configured' => true, 'verified' => false, 'statusCode' => null, 'errorCode' => 'invalid_url'];
        }
        if (!extension_loaded('curl')) {
            return ['configured' => true, 'verified' => false, 'statusCode' => null, 'errorCode' => 'curl_unavailable'];
        }

        $handle = curl_init($url);
        if ($handle === false) {
            return ['configured' => true, 'verified' => false, 'statusCode' => null, 'errorCode' => 'request_failed'];
        }
        curl_setopt_array($handle, [
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_NOBODY => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => 'Utsuri-Portable-CMS-Host-Probe/1',
        ]);
        $response = curl_exec($handle);
        $errorNumber = curl_errno($handle);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($response !== false && $errorNumber === CURLE_OK && $statusCode >= 200 && $statusCode < 400) {
            return ['configured' => true, 'verified' => true, 'statusCode' => $statusCode, 'errorCode' => null];
        }
        $verificationErrors = array_filter([
            defined('CURLE_PEER_FAILED_VERIFICATION') ? CURLE_PEER_FAILED_VERIFICATION : null,
            defined('CURLE_SSL_CACERT') ? CURLE_SSL_CACERT : null,
        ], static fn(mixed $value): bool => is_int($value));
        $errorCode = match (true) {
            in_array($errorNumber, $verificationErrors, true) => 'tls_verification_failed',
            $errorNumber === CURLE_COULDNT_RESOLVE_HOST => 'dns_failed',
            $errorNumber === CURLE_COULDNT_CONNECT => 'connection_failed',
            $errorNumber === CURLE_OPERATION_TIMEDOUT => 'timeout',
            default => 'request_failed',
        };
        return [
            'configured' => true,
            'verified' => false,
            'statusCode' => $statusCode > 0 ? $statusCode : null,
            'errorCode' => $errorCode,
        ];
    }
}
