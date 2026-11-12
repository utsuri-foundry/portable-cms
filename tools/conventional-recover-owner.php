<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Identity\OwnerRecovery;
use Utsuri\PortableCms\Conventional\Identity\SystemClock;
use Utsuri\PortableCms\Conventional\Identity\SystemRandomSource;
use Utsuri\PortableCms\Conventional\Installation\InstallationGate;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/engine/src/cms/conventional/bootstrap.php';
$options = getopt('', ['gate:', 'token-file:', 'password-file:', 'document-root:']);
try {
    foreach (['gate', 'token-file', 'password-file', 'document-root'] as $key) {
        if (!is_string($options[$key] ?? null) || $options[$key] === '') {
            throw new InvalidArgumentException('Private recovery gate, credential files and document root are required');
        }
    }
    $lease = \Utsuri\PortableCms\Conventional\Upgrade\RuntimeLease::guard(InstallationGate::load($options['gate'], $options['document-root'])->runtimeConfigurationPath(), dirname(__DIR__));
    $gate = InstallationGate::load($options['gate'], $options['document-root']);
    $token = OwnerRecovery::credentialFile($options['token-file'], $gate->runtimeConfigurationPath(), 66);
    $password = OwnerRecovery::credentialFile($options['password-file'], $gate->runtimeConfigurationPath(), 74);
    OwnerRecovery::execute($options['gate'], $token, $password, $options['document-root'], new SystemClock(), new SystemRandomSource());
    fwrite(STDOUT, "Owner recovery completed. A password replacement is required at next sign-in.\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'Owner recovery failed (' . $error::class . ").\n");
    exit(1);
} finally {
    $password = '';
    $token = '';
}
