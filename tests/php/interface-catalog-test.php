<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\I18n\InvalidInput;
use Utsuri\PortableCms\Conventional\I18n\Message;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Media\UploadFailure;

/** @return list<string> Engine source files of one extension, excluding catalogs. */
function interfaceCatalogSources(string $extension): array
{
    $root = dirname(__DIR__, 2) . '/engine/src/cms/conventional';
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === $extension && !str_contains($file->getPathname(), '/i18n/messages/')) {
            $files[] = $file->getPathname();
        }
    }
    sort($files, SORT_STRING);
    return $files;
}

$tests['interface catalog patterns compile and every literal engine key exists'] = static function (): void {
    $translator = Translator::english();
    $catalog = $translator->catalog();
    assertTrueValue(count($catalog) > 400, 'the English catalog should carry the whole administration interface');
    foreach ($catalog as $key => $pattern) {
        assertTrueValue(preg_match('/\A[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+\z/', $key) === 1, 'catalog key should be stable: ' . $key);
        assertTrueValue(MessageFormatter::create('en', $pattern) !== null, 'catalog pattern should compile: ' . $key);
        assertTrueValue(preg_match('/, number, [^a-z}]/', $pattern) !== 1, 'ICU keeps whitespace before custom number patterns: ' . $key);
        assertTrueValue(!str_contains($pattern, '—') && !str_contains($pattern, '–'), 'catalog text avoids long dashes: ' . $key);
        if (str_starts_with($key, 'client.')) {
            assertTrueValue(preg_match('/\{(?![a-z_]+\})/', $pattern) !== 1, 'browser patterns use simple named placeholders: ' . $key);
        }
    }
    $missing = [];
    foreach (interfaceCatalogSources('php') as $file) {
        $source = (string) file_get_contents($file);
        preg_match_all("/(?:->(?:text|html|markup)|new (?:Message|InvalidInput|FontRejected|FontUnavailable))\\('([a-z][a-z0-9_]*(?:\\.[a-z0-9_]+)+)'[,)]/", $source, $direct);
        preg_match_all("/new UploadFailure\\('[a-z_]+', [0-9]+, '([a-z][a-z0-9_.]+)'\\)/", $source, $uploads);
        foreach ([...$direct[1], ...$uploads[1]] as $key) {
            if (!$translator->has($key)) {
                $missing[] = basename($file) . ': ' . $key;
            }
        }
    }
    foreach (interfaceCatalogSources('js') as $file) {
        preg_match_all('/\bt(?:\.plural)?\("([a-z_]+\.[a-z_.]+)"|messages\([a-z]+\)\("([a-z_]+\.[a-z_.]+)"/', (string) file_get_contents($file), $client);
        foreach (array_filter([...$client[1], ...$client[2]]) as $key) {
            if (!$translator->has('client.' . $key) && !$translator->has('client.' . $key . '_other')) {
                $missing[] = basename($file) . ': client.' . $key;
            }
        }
    }
    assertSameValue([], array_values(array_unique($missing)), 'every referenced interface key should exist in the English catalog');
};

$tests['interface messages fail closed and keep English diagnostics'] = static function (): void {
    $translator = Translator::english();
    assertSameValue('Title is required.', $translator->message(new Message('validation.required', ['field' => 'Title'])), 'messages interpolate named parameters');
    assertSameValue('Body: Rich text is required.', $translator->message(new Message('validation.detail', ['field' => 'Body', 'detail' => new Message('validation.rich.required')])), 'nested messages are translated first');
    $invalid = new InvalidInput('validation.layout.row_required');
    assertSameValue('Add at least one layout row', $invalid->getMessage(), 'invalid input keeps an English log message');
    assertSameValue('Add at least one layout row', $translator->failure($invalid), 'invalid input renders its catalog text');
    $upload = new UploadFailure('upload_too_large', 413, 'upload.error.the_upload_exceeds_the_40_mb_limit');
    assertSameValue('The upload exceeds the 40 MB limit.', $translator->failure($upload), 'upload failures render catalog text');
    assertSameValue('Plain diagnostic', $translator->failure(new RuntimeException('Plain diagnostic')), 'non-catalog failures keep their diagnostic text');
    assertSameValue('1 issue to fix', $translator->text('preflight.issues', ['count' => 1]), 'plural patterns choose the singular form');
    assertSameValue('3 issues to fix', $translator->text('preflight.issues', ['count' => 3]), 'plural patterns choose the plural form');
    assertSameValue('3 Oct 2026, 14:05', $translator->date(new DateTimeImmutable('2026-10-03T12:05:00Z'), 'format.date_time', new DateTimeZone('+02:00')), 'dates accept ext/date offset zones');
    assertSameValue('3 Oct 2026, 12:05', $translator->date(new DateTimeImmutable('2026-10-03T12:05:00Z'), 'format.date_time', (new DateTimeImmutable('2026-10-03T12:05:00Z'))->getTimezone()), 'dates accept the Z zone');
    foreach ([
        static fn() => $translator->text('missing.key'),
        static fn() => $translator->text('validation.required'),
        static fn() => new Message('Not a key'),
        static fn() => Translator::load('../en'),
        static fn() => Translator::load('zz'),
        static fn() => $translator->date(new DateTimeImmutable('2026-10-03'), 'design.save', new DateTimeZone('UTC')),
    ] as $index => $hostile) {
        try {
            $hostile();
            throw new RuntimeException('hostile interface input ' . $index . ' should fail closed');
        } catch (LogicException) {
        }
    }
    $bundle = json_decode(html_entity_decode($translator->bundleAttribute('client'), ENT_QUOTES | ENT_HTML5), true, 4, JSON_THROW_ON_ERROR);
    assertTrueValue(is_array($bundle) && isset($bundle['editor.saving']) && !isset($bundle['client.editor.saving']), 'island bundles are relative to their prefix');
    assertSameValue('&lt;Note&gt; · edited <time>now</time>', $translator->markup('overview.edited', ['type' => '<Note>'], ['time' => '<time>now</time>']), 'markup escapes text and inserts only trusted engine markup');
};

/** @return list<string> Top-level and nested argument names a pattern requires. */
function interfaceCatalogPlaceholders(string $pattern): array
{
    preg_match_all('/\{([a-z_]+)[,}]/', $pattern, $matches);
    $names = array_values(array_unique($matches[1]));
    sort($names, SORT_STRING);
    return $names;
}

/** @return list<string>|null Literal array keys of the bracketed argument starting at $offset, or null when it is not a literal array. */
function interfaceCatalogArrayKeys(string $source, int $offset): ?array
{
    if (($source[$offset] ?? '') !== '[') {
        return null;
    }
    $depth = 0;
    $keys = [];
    for ($index = $offset, $length = strlen($source); $index < $length; $index++) {
        $character = $source[$index];
        if ($character === "'") {
            $end = $index + 1;
            while ($end < $length && $source[$end] !== "'") {
                $end += $source[$end] === '\\' ? 2 : 1;
            }
            if ($depth === 1 && preg_match('/\A\s*=>/', substr($source, $end + 1, 8)) === 1) {
                $keys[] = substr($source, $index + 1, $end - $index - 1);
            }
            $index = $end;
        } elseif ($character === '[' || $character === '(') {
            $depth++;
        } elseif ($character === ']' || $character === ')') {
            $depth--;
            if ($depth === 0) {
                sort($keys, SORT_STRING);
                return $keys;
            }
        }
    }
    return null;
}

$tests['interface catalog has no unused keys and literal parameters match their patterns'] = static function (): void {
    $catalog = Translator::english()->catalog();
    $php = '';
    foreach (interfaceCatalogSources('php') as $file) {
        $php .= (string) file_get_contents($file) . "\n";
    }
    $js = '';
    foreach (interfaceCatalogSources('js') as $file) {
        $js .= (string) file_get_contents($file) . "\n";
    }
    preg_match_all("/'([a-z][a-z0-9_]*(?:\\.[a-z0-9_]*)+)'/", $php, $phpLiterals);
    preg_match_all('/["`]([a-z][a-z0-9_]*(?:\.[a-z0-9_]*)+)(?:["`]|\$\{)/', $js, $jsLiterals);
    $unused = [];
    foreach (array_keys($catalog) as $key) {
        if (str_starts_with($key, 'client.')) {
            $relative = substr($key, 7);
            $base = preg_replace('/_(?:zero|one|two|few|many|other)\z/', '', $relative);
            $used = false;
            foreach ($jsLiterals[1] as $literal) {
                if ($literal === $relative || $literal === $base || (str_ends_with($literal, '.') && str_starts_with($relative, $literal))) {
                    $used = true;
                    break;
                }
            }
        } else {
            $used = false;
            foreach ($phpLiterals[1] as $literal) {
                if ($literal === $key || ((str_ends_with($literal, '.') || str_ends_with($literal, '_')) && str_starts_with($key, $literal))) {
                    $used = true;
                    break;
                }
            }
        }
        if (!$used) {
            $unused[] = $key;
        }
    }
    assertSameValue([], $unused, 'every catalog key should be referenced by engine code');
    $mismatched = [];
    $calls = 0;
    preg_match_all("/(?:->(text|html|markup)|new (?:Message|InvalidInput))\\('([a-z][a-z0-9_]*(?:\\.[a-z0-9_]+)+)'(\\)|, )/", $php, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    foreach ($matches as $match) {
        $key = $match[2][0];
        if (!isset($catalog[$key])) {
            continue;
        }
        $expected = interfaceCatalogPlaceholders($catalog[$key]);
        if ($match[3][0] === ')') {
            $given = [];
        } else {
            $start = $match[3][1] + 2;
            $given = interfaceCatalogArrayKeys($php, $start);
            if ($given === null) {
                continue;
            }
            if ($match[1][0] === 'markup') {
                $after = $start + strlen((string) substr($php, $start, strpos($php, '], [', $start) - $start + 1));
                $markup = interfaceCatalogArrayKeys($php, $after + 2) ?? [];
                $given = [...$given, ...$markup];
                sort($given, SORT_STRING);
            }
        }
        $calls++;
        if ($given !== $expected) {
            $mismatched[] = $key . ' expects [' . implode(', ', $expected) . '] but receives [' . implode(', ', $given) . ']';
        }
    }
    assertTrueValue($calls > 300, 'parameter matching should cover the literal interface calls');
    assertSameValue([], array_values(array_unique($mismatched)), 'literal message parameters should match their catalog placeholders');
};
