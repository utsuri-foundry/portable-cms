<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Http\RequestBodyTooLarge;
use Utsuri\PortableCms\Conventional\Http\Response;
use Utsuri\PortableCms\Conventional\Http\SecurityHeaders;
use Utsuri\PortableCms\Conventional\Installation\InstallationGate;
use Utsuri\PortableCms\Conventional\Upgrade\UpgradeBundle;
use Utsuri\PortableCms\Conventional\Upgrade\UpgradeFailure;
use Utsuri\PortableCms\Conventional\Upgrade\UpgradeFiles;
use Utsuri\PortableCms\Conventional\Upgrade\UpgradeService;

require_once dirname(__DIR__) . '/engine/src/cms/conventional/bootstrap.php';
$correlationId = bin2hex(random_bytes(12));
/** @param array<string, mixed> $body */
function upgradeResponse(int $status, array $body, string $reference): never
{
    SecurityHeaders::apply(new Response($status, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"), $reference)->withHeader('content-type', 'application/json; charset=utf-8')->emit();
}
try {
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    if (($https === '' || $https === 'off' || $https === '0') && ($_SERVER['SERVER_PORT'] ?? '') !== '443') {
        upgradeResponse(404, ['error' => 'not_found'], $correlationId);
    }
    $request = Request::fromGlobals(512);
    $gatePath = getenv('CMS_UPGRADE_CONFIG');
    if ($request->method !== 'POST' || !is_string($gatePath) || $gatePath === '') {
        upgradeResponse(404, ['error' => 'not_found'], $correlationId);
    }
    try {
        $gate = InstallationGate::load($gatePath, is_string($_SERVER['DOCUMENT_ROOT'] ?? null) ? $_SERVER['DOCUMENT_ROOT'] : null);
    } catch (Throwable) {
        upgradeResponse(404, ['error' => 'not_found'], $correlationId);
    }
    if (!$gate->authorizes($request->header('x-cms-upgrade-token') ?? '')) {
        upgradeResponse(404, ['error' => 'not_found'], $correlationId);
    }
    $intentPath = $gate->runtimeConfigurationPath();
    $intentRoot = realpath(dirname($intentPath));
    $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $permissions = \Utsuri\PortableCms\Conventional\HostIo::attempt(fn() => fileperms($intentPath))->value;
    if (!is_string($intentRoot) || !is_string($documentRoot) || UpgradeFiles::within($intentRoot, $documentRoot) || !is_int($permissions) || ($permissions & 0o077) !== 0) {
        throw new UpgradeFailure('upgrade_intent_must_be_owner_only_and_private');
    }
    $intent = UpgradeFiles::json($intentPath);
    $service = new UpgradeService($intent['config'], $intent['cmsRoot'], new DateTimeImmutable('now', new DateTimeZone('UTC')), launcherSource: (string) file_get_contents(__DIR__ . '/conventional-release-launcher.php'));
    $bundle = in_array($intent['action'], ['dry-run', 'apply'], true) ? UpgradeBundle::load($intent['bundle']) : null;
    if ($bundle !== null && (realpath(dirname(__DIR__)) !== realpath($bundle->root . '/private/runtime') || $bundle->engineVersion !== \Utsuri\PortableCms\Conventional\Content\ProfileManifest::ENGINE_VERSION)) {
        throw new UpgradeFailure('run_the_new_bundles_upgrade_endpoint');
    }
    $result = match ($intent['action']) {
        'dry-run' => $service->dryRun($bundle ?? throw new UpgradeFailure('private_bundle_required')),
        'backup' => $service->backup($intent['backup']),
        'apply' => $service->apply($bundle ?? throw new UpgradeFailure('private_bundle_required'), $intent['backup'], $intent['confirmBundle'], $intent['standbyConfig'] ?? null),
        'recover' => $service->recover(),
        'rollback' => $service->rollback(),
        'cleanup-table-set' => $service->cleanupTableSet(is_string($intent['tablePrefix'] ?? null) ? $intent['tablePrefix'] : '', is_string($intent['confirmTablePrefix'] ?? null) ? $intent['confirmTablePrefix'] : ''),
        default => throw new UpgradeFailure('upgrade_action_invalid'),
    };
    $gate->close(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    upgradeResponse(($result['blocked'] ?? false) === true ? 422 : 200, $result, $correlationId);
} catch (RequestBodyTooLarge) {
    upgradeResponse(413, ['error' => 'request_too_large'], $correlationId);
} catch (\Utsuri\PortableCms\Conventional\Content\ProfileEngineMismatch $error) {
    upgradeResponse(422, $error->document(), $correlationId);
} catch (Throwable $error) {
    upgradeResponse(500, ['error' => $error instanceof UpgradeFailure ? $error->codeName : 'upgrade_failed_recover_or_rollback', 'reference' => $correlationId], $correlationId);
}
