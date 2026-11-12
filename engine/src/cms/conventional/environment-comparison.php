<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional;

final class EnvironmentComparison
{
    public const FORMAT_VERSION = 1;

    /**
     * @param array<string, mixed> $web
     * @param array<string, mixed> $cli
     * @return array<string, mixed>
     */
    public static function compare(array $web, array $cli, ?int $now = null): array
    {
        $blockers = [];
        $warnings = [];
        $differences = [];

        if (($web['formatVersion'] ?? null) !== EnvironmentReport::FORMAT_VERSION) {
            $blockers[] = 'web_report_version';
        }
        if (($cli['formatVersion'] ?? null) !== EnvironmentReport::FORMAT_VERSION) {
            $blockers[] = 'cli_report_version';
        }
        if (($web['runtime']['sapi'] ?? null) === 'cli') {
            $blockers[] = 'web_sapi_not_web';
        }
        if (($cli['runtime']['sapi'] ?? null) !== 'cli') {
            $blockers[] = 'cli_sapi_not_cli';
        }
        if (($web['status'] ?? null) !== 'compatible') {
            $blockers[] = 'web_report_blocked';
        }
        if (($cli['status'] ?? null) !== 'compatible') {
            $blockers[] = 'cli_report_blocked';
        }

        self::comparePath(
            $web,
            $cli,
            'runtime.phpVersion',
            self::majorMinor($web['runtime']['phpVersion'] ?? null)
                === self::majorMinor($cli['runtime']['phpVersion'] ?? null)
                ? 'warning'
                : 'blocker',
            $differences,
            $blockers,
            $warnings,
        );
        foreach (['curl', 'fileinfo', 'intl', 'mbstring', 'openssl', 'pdo', 'pdo_mysql'] as $extension) {
            self::comparePath(
                $web,
                $cli,
                "extensions.{$extension}.loaded",
                'blocker',
                $differences,
                $blockers,
                $warnings,
            );
        }
        foreach ([
            'storage.privateStorage.protection.outsideDocumentRoot',
            'storage.privateStorage.protection.denyRuleVerified',
            'transport.outboundHttps.verified',
        ] as $path) {
            self::comparePath(
                $web,
                $cli,
                $path,
                'blocker',
                $differences,
                $blockers,
                $warnings,
            );
        }
        foreach ([
            'runtime.integerBytes',
            'configuration.disabledFunctions',
            'configuration.openBasedirConfigured',
            'configuration.session.saveHandler',
            'limits.memoryLimitBytes',
            'limits.maxExecutionSeconds',
            'limits.maxInputVars',
            'storage.privateStorage.operations',
            'storage.publication.operations',
            'storage.scratch.operations',
            'media.selectedEngine',
            'media.fidelity',
            'media.engines',
            'media.preencodedVideo.maxBytes',
            'media.preencodedVideo.reasons',
            'media.preencodedVideo.nativeMaxBytes',
            'limits.uploadMaxBytes',
            'limits.postMaxBytes',
            'limits.maxInputSeconds',
            'transport.outboundHttps.statusCode',
            'transport.outboundHttps.errorCode',
            'database.engine',
            'database.serverVersion',
            'database.sqlModes',
            'database.serverCharacterSet',
            'database.serverCollation',
            'database.databaseCharacterSet',
            'database.databaseCollation',
            'database.connectionCharacterSet',
            'database.connectionCollation',
            'database.transactionIsolation',
            'database.transactionProbe',
        ] as $path) {
            self::comparePath(
                $web,
                $cli,
                $path,
                'warning',
                $differences,
                $blockers,
                $warnings,
            );
        }

        $reportClasses = [
            $web['installationClass'] ?? 'unsupported',
            $cli['installationClass'] ?? 'unsupported',
        ];
        $classificationBlockers = array_values(array_diff(
            array_unique($blockers),
            ['web_report_blocked', 'cli_report_blocked'],
        ));
        $installationClass = $blockers === []
            ? 'standalone'
            : (
                !in_array('unsupported', $reportClasses, true)
                && in_array('managed_service_required', $reportClasses, true)
                && $classificationBlockers === []
                    ? 'managed_service_required'
                    : 'unsupported'
            );

        return [
            'formatVersion' => self::FORMAT_VERSION,
            'generatedAt' => gmdate('Y-m-d\TH:i:s\Z', $now ?? time()),
            'status' => $blockers === [] ? 'compatible' : 'blocked',
            'installationClass' => $installationClass,
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
            'differences' => $differences,
            'webReport' => $web,
            'cliReport' => $cli,
        ];
    }

    /**
     * @param array<string, mixed> $web
     * @param array<string, mixed> $cli
     * @param list<array{path: string, severity: string, web: mixed, cli: mixed}> $differences
     * @param list<string> $blockers
     * @param list<string> $warnings
     */
    private static function comparePath(
        array $web,
        array $cli,
        string $path,
        string $severity,
        array &$differences,
        array &$blockers,
        array &$warnings,
    ): void {
        $webValue = self::atPath($web, $path);
        $cliValue = self::atPath($cli, $path);
        if ($webValue === $cliValue) {
            return;
        }
        $differences[] = [
            'path' => $path,
            'severity' => $severity,
            'web' => $webValue,
            'cli' => $cliValue,
        ];
        $code = str_replace(['.', '_'], '-', $path) . '-differs';
        if ($severity === 'blocker') {
            $blockers[] = $code;
        } else {
            $warnings[] = $code;
        }
    }

    /** @param array<string, mixed> $value */
    private static function atPath(array $value, string $path): mixed
    {
        $current = $value;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }
        return $current;
    }

    private static function majorMinor(mixed $version): ?string
    {
        if (!is_string($version) || !preg_match('/^(\d+\.\d+)/', $version, $matches)) {
            return null;
        }
        return $matches[1];
    }
}
