<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\QualityGate;

final readonly class QualityFinding
{
    public function __construct(
        public string $rule,
        public string $path,
        public int $line,
        public string $source,
        public string $message,
    ) {}

    public function fingerprint(): string
    {
        return hash('sha256', $this->rule . "\0" . trim($this->source));
    }
}

final class QualityScanner
{
    /** @var list<string> */
    private const ROOTS = ['engine', 'profiles', 'tools', 'tests'];

    /** @return list<QualityFinding> */
    public function scan(string $root): array
    {
        $findings = [];
        foreach ($this->sources($root) as $path) {
            $relative = substr($path, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1);
            $extension = pathinfo($path, PATHINFO_EXTENSION);
            $source = file_get_contents($path);
            if (!is_string($source)) {
                throw new \RuntimeException('Quality source could not be read');
            }
            if ($extension === 'php') {
                $findings = [...$findings, ...$this->phpFindings($relative, $source)];
            }
            if ($extension === 'js' || $extension === 'mjs') {
                $findings = [...$findings, ...$this->javascriptFindings($relative, $source)];
            }
            $findings = [...$findings, ...$this->directiveFindings($relative, $source)];
        }
        $findings = [...$findings, ...$this->configurationFindings($root)];
        usort($findings, static fn(QualityFinding $left, QualityFinding $right): int => [
            $left->path,
            $left->line,
            $left->rule,
        ] <=> [
            $right->path,
            $right->line,
            $right->rule,
        ]);
        return $findings;
    }

    /** @return list<string> */
    private function sources(string $root): array
    {
        $sources = [];
        foreach (self::ROOTS as $sourceRoot) {
            $directory = $root . '/' . $sourceRoot;
            if (!is_dir($directory)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $path = $file->getPathname();
                $relative = substr($path, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1);
                if (str_contains('/' . $relative . '/', '/vendor/')) {
                    continue;
                }
                if (in_array($file->getExtension(), ['php', 'js', 'mjs'], true)) {
                    $sources[] = $path;
                }
            }
        }
        sort($sources, SORT_STRING);
        return $sources;
    }

    /** @return list<QualityFinding> */
    private function phpFindings(string $path, string $source): array
    {
        $findings = [];
        if (preg_match('/\A<\?php\s+declare\(strict_types=1\);/s', $source) !== 1) {
            $findings[] = new QualityFinding(
                'php.strict-types',
                $path,
                1,
                '<?php',
                'PHP source must declare strict_types=1 immediately after its opening tag.',
            );
        }
        $line = 1;
        $lines = preg_split('/\R/', $source) ?: [];
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                $line = $token[2] + substr_count($token[1], "\n");
                continue;
            }
            if ($token === '@' && !str_starts_with($path, 'tests/')) {
                $text = $lines[$line - 1] ?? '@';
                $findings[] = new QualityFinding(
                    'php.error-suppression',
                    $path,
                    $line,
                    $text,
                    'PHP error-control operators require an exact expiring exception.',
                );
            }
            $line += substr_count($token, "\n");
        }
        return $findings;
    }

    /** @return list<QualityFinding> */
    private function javascriptFindings(string $path, string $source): array
    {
        $patterns = [
            'javascript.eval' => '/\beval\s*\(/',
            'javascript.function-constructor' => '/\bnew\s+Function\s*\(/',
            'javascript.raw-html' => '/\b(?:innerHTML|outerHTML|insertAdjacentHTML|document\.write)\b/',
        ];
        $findings = [];
        foreach ($patterns as $rule => $pattern) {
            foreach ($this->matchingLines($source, $pattern) as [$line, $text]) {
                $findings[] = new QualityFinding(
                    $rule,
                    $path,
                    $line,
                    $text,
                    'Unsafe browser escape hatch requires an exact expiring exception.',
                );
            }
        }
        return $findings;
    }

    /** @return list<QualityFinding> */
    private function directiveFindings(string $path, string $source): array
    {
        $patterns = [
            'analysis.suppression' => '/(?:@phpstan-' . 'ignore|@psalm-' . 'suppress|biome-' . 'ignore|eslint-' . 'disable)/i',
        ];
        $findings = [];
        foreach ($patterns as $rule => $pattern) {
            foreach ($this->matchingLines($source, $pattern) as [$line, $text]) {
                $findings[] = new QualityFinding(
                    $rule,
                    $path,
                    $line,
                    $text,
                    'Static-analysis suppression requires an exact expiring exception.',
                );
            }
        }
        return $findings;
    }

    /** @return list<QualityFinding> */
    private function configurationFindings(string $root): array
    {
        $findings = [];
        $phpstan = file_get_contents($root . '/phpstan.neon.dist');
        if (!is_string($phpstan)) {
            throw new \RuntimeException('PHPStan configuration is missing');
        }
        foreach ($this->matchingLines($phpstan, '/^\s*ignoreErrors\s*:/m') as [$line, $text]) {
            $findings[] = new QualityFinding(
                'analysis.broad-baseline',
                'phpstan.neon.dist',
                $line,
                $text,
                'PHPStan ignoreErrors is not an accepted baseline mechanism.',
            );
        }
        foreach (new \DirectoryIterator($root) as $file) {
            if ($file->isFile() && preg_match('/baseline/i', $file->getFilename()) === 1) {
                $findings[] = new QualityFinding(
                    'analysis.broad-baseline',
                    $file->getFilename(),
                    1,
                    $file->getFilename(),
                    'Broad baseline files are forbidden.',
                );
            }
        }
        return $findings;
    }

    /** @return list<array{int, string}> */
    private function matchingLines(string $source, string $pattern): array
    {
        $matches = [];
        foreach (preg_split('/\R/', $source) ?: [] as $index => $line) {
            if (preg_match($pattern, $line) === 1) {
                $matches[] = [$index + 1, $line];
            }
        }
        return $matches;
    }
}
