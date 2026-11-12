<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/engine/src/cms/conventional/bootstrap.php';

$config = \Utsuri\PortableCms\Conventional\RuntimeConfiguration::load($argv[1] ?? '');
if (!$config->allowInsecureLoopback) {
    throw new RuntimeException('Session-expiry proof requires the isolated loopback fixture');
}
$config->connect()->exec("UPDATE cms_sessions SET expires_at = '2000-01-01 00:00:00' WHERE kind = 'authenticated'");
