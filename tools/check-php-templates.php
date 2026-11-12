<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$violations = [];
$roots = [
    dirname(__DIR__) . '/engine/src/cms/conventional/view',
    dirname(__DIR__) . '/profiles',
];
foreach ($roots as $root) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $source = file_get_contents($file->getPathname());
        if (!is_string($source)) {
            throw new RuntimeException('Could not read a PHP view file');
        }
        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                continue;
            }
            if (in_array($token[0], [T_ECHO, T_PRINT, T_OPEN_TAG_WITH_ECHO], true)) {
                $relative = substr($file->getPathname(), strlen(dirname(__DIR__)) + 1);
                $violations[] = $relative . ':' . $token[2];
            }
        }
    }
}

if ($violations !== []) {
    fwrite(STDERR, "PHP views must return escaped markup and cannot emit it directly:\n");
    foreach ($violations as $violation) {
        fwrite(STDERR, $violation . "\n");
    }
    exit(1);
}

fwrite(STDOUT, "PHP template output check passed.\n");
