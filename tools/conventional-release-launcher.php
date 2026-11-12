<?php

declare(strict_types=1);

// Installed beside the pointer, outside release bundles, so engine classes are never mixed.
try {
    $root = getenv('CMS_UPGRADE_ROOT');
    if (!is_string($root) || $root === '' || is_link($root) || !is_file($root . '/active.json') || is_link($root . '/active.json')) {
        throw new RuntimeException('release_dispatcher_unavailable');
    }
    require_once $root . '/runtime-lease.php';
    $runtimeLease = \Utsuri\PortableCms\Conventional\Upgrade\RuntimeLease::acquire($root);
    $active = \Utsuri\PortableCms\Conventional\Upgrade\RuntimeLease::pointer($root);
    foreach (['runtime', 'config', 'assets'] as $key) {
        $path = $active[$key] ?? null;
        if (!is_string($path) || realpath($path) !== $path || is_link($path)) {
            throw new RuntimeException('release_pointer_invalid');
        }
    }
    putenv('CMS_CONFIG=' . $active['config']);
    putenv('CMS_ACTIVE_CONFIG=' . $active['config']);
    if (PHP_SAPI === 'cli') {
        $options = getopt('', ['tool:', 'config:', 'max-jobs:', 'max-publication-batches:']);
        $tool = $options['tool'] ?? '';
        $filename = match ($tool) {
            'media' => 'conventional-media-worker.php',
            'ai' => 'conventional-ai-worker.php',
            default => throw new RuntimeException('worker_tool_invalid'),
        };
    } else {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if (is_string($path) && str_starts_with($path, '/assets/')) {
            $name = substr($path, strlen('/assets/'));
            if (preg_match('/\A[a-z0-9-]+\.(?:js|css)\z/', $name) !== 1 || is_link($active['assets'] . '/' . $name) || !is_file($active['assets'] . '/' . $name)) {
                http_response_code(404);
                exit;
            }
            header('Content-Type: ' . (str_ends_with($name, '.css') ? 'text/css' : 'text/javascript') . '; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            readfile($active['assets'] . '/' . $name);
            exit;
        }
        $filename = 'conventional-cms-web.php';
    }
    require $active['runtime'] . '/tools/' . $filename;
} catch (Throwable) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "The installed runtime is unavailable or busy; retry or recover the upgrade.\n");
        exit(1);
    }
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('Retry-After: 10');
    header('X-Robots-Tag: noindex');
    exit('The CMS is temporarily busy. Please retry.');
}
