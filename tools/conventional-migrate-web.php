<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Database\MigrationRunner;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Http\RequestBodyTooLarge;
use Utsuri\PortableCms\Conventional\Http\Response;
use Utsuri\PortableCms\Conventional\Http\SecurityHeaders;
use Utsuri\PortableCms\Conventional\Installation\InstallationGate;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;

require_once dirname(__DIR__) . '/engine/src/cms/conventional/bootstrap.php';

/** @param array<string, scalar> $body */
function migrationResponse(int $status, array $body, string $correlationId): never
{
    SecurityHeaders::apply(
        new Response($status, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"),
        $correlationId,
    )->withHeader('content-type', 'application/json; charset=utf-8')->emit();
}

$correlationId = bin2hex(random_bytes(12));
try {
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    if (($https === '' || $https === 'off' || $https === '0') && ($_SERVER['SERVER_PORT'] ?? '') !== '443') {
        migrationResponse(404, ['error' => 'not_found'], $correlationId);
    }
    $request = Request::fromGlobals(512);
    if ($request->method !== 'POST') {
        migrationResponse(404, ['error' => 'not_found'], $correlationId);
    }
    $gatePath = getenv('CMS_MIGRATION_CONFIG');
    if (!is_string($gatePath) || $gatePath === '') {
        migrationResponse(404, ['error' => 'not_found'], $correlationId);
    }
    $documentRoot = is_string($_SERVER['DOCUMENT_ROOT'] ?? null) ? $_SERVER['DOCUMENT_ROOT'] : null;
    try {
        $gate = InstallationGate::load($gatePath, $documentRoot);
    } catch (Throwable) {
        migrationResponse(404, ['error' => 'not_found'], $correlationId);
    }
    $token = $request->header('x-cms-migration-token') ?? '';
    if (!$gate->authorizes($token)) {
        migrationResponse(404, ['error' => 'not_found'], $correlationId);
    }
    $runtimeLease = \Utsuri\PortableCms\Conventional\Upgrade\RuntimeLease::guard($gate->runtimeConfigurationPath(), dirname(__DIR__));
    $configuration = RuntimeConfiguration::load($gate->runtimeConfigurationPath(), $documentRoot);
    $migrations = glob(dirname(__DIR__) . '/engine/migrations/*.sql');
    if ($migrations === false) {
        throw new RuntimeException('Migration directory could not be read');
    }
    sort($migrations, SORT_STRING);
    $result = (new MigrationRunner($configuration->connect()))->apply($migrations);
    $gate->close(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    migrationResponse(200, [
        'status' => 'migrated',
        'migrationsApplied' => $result->applied,
        'migrationsSkipped' => $result->skipped,
    ], $correlationId);
} catch (RequestBodyTooLarge) {
    migrationResponse(413, ['error' => 'request_too_large'], $correlationId);
} catch (Throwable $error) {
    error_log(sprintf(
        '%s correlation=%s error=%s',
        gmdate('Y-m-d\TH:i:s\Z'),
        $correlationId,
        $error::class,
    ));
    migrationResponse(500, ['error' => 'migration_failed', 'reference' => $correlationId], $correlationId);
}
