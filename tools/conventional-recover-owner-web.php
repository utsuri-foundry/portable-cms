<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Http\Response;
use Utsuri\PortableCms\Conventional\Http\SecurityHeaders;
use Utsuri\PortableCms\Conventional\Identity\OwnerRecovery;
use Utsuri\PortableCms\Conventional\Identity\SystemClock;
use Utsuri\PortableCms\Conventional\Identity\SystemRandomSource;
use Utsuri\PortableCms\Conventional\Installation\InstallationGate;

require_once dirname(__DIR__) . '/engine/src/cms/conventional/bootstrap.php';
$correlation = bin2hex(random_bytes(12));
try {
    if (($_SERVER['HTTPS'] ?? '') !== 'on' && ($_SERVER['SERVER_PORT'] ?? '') !== '443') {
        throw new RuntimeException('HTTPS required');
    }
    $request = Request::fromGlobals(1_024);
    $path = getenv('CMS_OWNER_RECOVERY_CONFIG');
    $root = is_string($_SERVER['DOCUMENT_ROOT'] ?? null) ? $_SERVER['DOCUMENT_ROOT'] : null;
    if ($request->method !== 'POST' || !is_string($path) || $path === '') {
        throw new RuntimeException('Recovery unavailable');
    }
    $gate = InstallationGate::load($path, $root);
    $token = $request->header('x-cms-owner-recovery-token') ?? '';
    if (!$gate->authorizes($token)) {
        throw new RuntimeException('Recovery unavailable');
    }
    $lease = \Utsuri\PortableCms\Conventional\Upgrade\RuntimeLease::guard($gate->runtimeConfigurationPath(), dirname(__DIR__));
    OwnerRecovery::execute($path, $token, $request->formValue('password'), $root, new SystemClock(), new SystemRandomSource());
    $response = Response::json(['status' => 'recovered']);
} catch (Throwable) {
    $response = Response::json(['error' => 'recovery_unavailable'], 404);
}
SecurityHeaders::apply($response, $correlation)->emit();
