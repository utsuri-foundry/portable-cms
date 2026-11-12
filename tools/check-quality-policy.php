<?php

declare(strict_types=1);

use Utsuri\PortableCms\QualityGate\QualityPolicy;
use Utsuri\PortableCms\QualityGate\QualityScanner;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/quality-gate/scanner.php';
require_once __DIR__ . '/quality-gate/policy.php';

$root = dirname(__DIR__);
$scanner = new QualityScanner();
$policy = new QualityPolicy();
$findings = $policy->apply(
    $scanner->scan($root),
    $policy->load($root . '/quality-exceptions.json'),
    $root,
    new DateTimeImmutable('today', new DateTimeZone('UTC')),
);

if ($findings !== []) {
    foreach ($findings as $finding) {
        fwrite(STDERR, sprintf(
            "%s:%d [%s] %s fingerprint=%s\n",
            $finding->path,
            $finding->line,
            $finding->rule,
            $finding->message,
            $finding->fingerprint(),
        ));
    }
    exit(1);
}

fwrite(STDOUT, "Implementation quality policy passed.\n");
