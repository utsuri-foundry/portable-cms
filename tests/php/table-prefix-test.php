<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Database\TablePrefix;

$tests['table prefixes are one lowercase word and an underscore'] = static function (): void {
    foreach (['cms_', 'a_', 'site2_', 'abcdefghijklmno_'] as $value) {
        assertSameValue($value, (new TablePrefix($value))->value, 'valid prefix ' . $value);
    }
    foreach (['', '_', 'cms', 'CMS_', 'a_b_', '1a_', 'a-b_', 'a`_', 'a _', "cms_\n", 'abcdefghijklmnop_', 'cms_; DROP TABLE x;_'] as $value) {
        try {
            new TablePrefix($value);
            throw new RuntimeException('invalid table prefix accepted: ' . json_encode($value));
        } catch (InvalidArgumentException) {
        }
    }
};

$tests['table prefixes map engine identifiers and leave other text alone'] = static function (): void {
    $canonical = TablePrefix::canonical();
    $sql = "SELECT r.id FROM cms_records r JOIN `cms_users` u ON u.id = r.created_by WHERE xcms_flag = '__Host-cms_session' AND \$cms_x = 1";
    assertSameValue($sql, $canonical->sql($sql), 'canonical prefix changes nothing');
    assertSameValue(
        "SELECT r.id FROM next_records r JOIN `next_users` u ON u.id = r.created_by WHERE xcms_flag = '__Host-cms_session' AND \$cms_x = 1",
        (new TablePrefix('next_'))->sql($sql),
        'only canonical identifiers are mapped',
    );
    assertSameValue(
        'CONSTRAINT next_sessions_user_fk FOREIGN KEY (user_id) REFERENCES next_users (id)',
        (new TablePrefix('next_'))->sql('CONSTRAINT cms_sessions_user_fk FOREIGN KEY (user_id) REFERENCES cms_users (id)'),
        'constraint names are mapped so table sets never clash in one database',
    );
    assertSameValue('cms_ ', (new TablePrefix('next_'))->sql('cms_ '), 'a bare prefix is not an identifier');
};

$tests['table prefixes own only their table set'] = static function (): void {
    $next = new TablePrefix('next_');
    assertSameValue('cms_records', $next->canonicalName('next_records'), 'own table maps to its canonical name');
    assertSameValue(null, $next->canonicalName('cms_records'), 'the canonical set belongs to another installation');
    assertSameValue(null, $next->canonicalName('next_'), 'the bare prefix is not a table');
    assertSameValue(null, TablePrefix::canonical()->canonicalName('cms2_records'), 'a longer word is a different prefix');
    assertSameValue(null, TablePrefix::canonical()->canonicalName('wp_posts'), 'unrelated tables are ignored');
    $physical = "CREATE TABLE `next_sessions` (\n  `id` binary(32) NOT NULL,\n  `next_value` int,\n  PRIMARY KEY (`id`),\n  UNIQUE KEY `next_sessions_token_hash_unique` (`next_value`),\n  KEY `next_sessions_user_id_index` (`id`),\n"
        . "  CONSTRAINT `next_sessions_user_fk` FOREIGN KEY (`id`) REFERENCES `next_users` (`id`)\n) ENGINE=InnoDB";
    assertSameValue(
        "CREATE TABLE `cms_sessions` (\n  `id` binary(32) NOT NULL,\n  `next_value` int,\n  PRIMARY KEY (`id`),\n  UNIQUE KEY `cms_sessions_token_hash_unique` (`next_value`),\n  KEY `cms_sessions_user_id_index` (`id`),\n"
            . "  CONSTRAINT `cms_sessions_user_fk` FOREIGN KEY (`id`) REFERENCES `cms_users` (`id`)\n) ENGINE=InnoDB",
        $next->canonicalSchema($physical),
        'schemas become canonical without touching column names',
    );
    assertSameValue($physical, $next->sql($next->canonicalSchema($physical)), 'a canonical schema maps back exactly');
};

$tests['engine identifiers leave room for every table prefix'] = static function (): void {
    $root = dirname(__DIR__, 2);
    $files = [...glob($root . '/engine/migrations/*.sql') ?: []];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/engine/src', FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
    $longest = '';
    foreach ($files as $path) {
        preg_match_all('/(?<![A-Za-z0-9_$-])cms_[a-z0-9_]+/', (string) file_get_contents($path), $matches);
        foreach ($matches[0] as $identifier) {
            $longest = strlen($identifier) > strlen($longest) ? $identifier : $longest;
        }
    }
    assertTrueValue($longest !== '' && strlen($longest) <= TablePrefix::MAX_CANONICAL_IDENTIFIER, 'identifier too long for a 16-character prefix: ' . $longest);
};

$tests['production code connects to the CMS database only through its table prefix'] = static function (): void {
    $root = dirname(__DIR__, 2);
    // The host probe opens its own connection and touches no CMS tables.
    $allowed = ['engine/src/cms/conventional/database-report.php'];
    $found = [];
    foreach (['engine/src', 'tools'] as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if (preg_match('/new\s+\\\\?PDO\s*\(|PDO::connect\s*\(/', (string) file_get_contents($file->getPathname())) === 1 && !in_array($relative, $allowed, true)) {
                $found[] = $relative;
            }
        }
    }
    assertSameValue([], $found, 'direct PDO connections bypass the table prefix');
};
