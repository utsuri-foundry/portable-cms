<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$root = dirname(__DIR__, 2);
$finder = Finder::create()
    ->in([
        $root . '/engine/src/cms/conventional',
        $root . '/profiles',
        $root . '/tests/php',
        $root . '/tools',
    ])
    ->exclude(['vendor'])
    ->name('*.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PER-CS2.0' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
    ])
    ->setFinder($finder)
    ->setCacheFile($root . '/build/php-cs-fixer.cache');
