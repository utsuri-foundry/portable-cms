<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\RuntimeConfiguration;

/** @return list<string> */
function installConfigTestArguments(string $root): array
{
    file_put_contents($root . '/database-password', 'installation-fixture-secret');
    chmod($root . '/database-password', 0600);
    return [
        '--runtime-output=' . $root . '/cms.json', '--runtime-config-path=' . $root . '/cms.json',
        '--gate-output=' . $root . '/install.json', '--token-output=' . $root . '/install-token',
        '--origin=https://cms.example.test', '--dsn=mysql:host=database.example.test;dbname=cms;charset=utf8mb4',
        '--username=cms', '--database-password-file=' . $root . '/database-password', '--error-log=' . $root . '/errors.log',
    ];
}

$tests['installation command preserves absent upgrade settings and effective defaults'] = static function (): void {
    $root = hostIoFixture();
    try {
        $result = runHostIoTool('conventional-create-install-config.php', installConfigTestArguments($root));
        assertSameValue(0, $result['status'], 'default installation configuration succeeds: ' . $result['stderr']);
        $document = json_decode((string) file_get_contents($root . '/cms.json'), true, flags: JSON_THROW_ON_ERROR);
        assertSameValue(32, strlen((string) base64_decode($document['throttleSecretBase64'], true)), 'the throttle secret retains its original size');
        unset($document['throttleSecretBase64']);
        assertSameValue([
            'origin' => 'https://cms.example.test',
            'database' => ['dsn' => 'mysql:host=database.example.test;dbname=cms;charset=utf8mb4', 'username' => 'cms', 'password' => 'installation-fixture-secret'],
            'storage' => ['mediaRoot' => $root . '/media'], 'mediaWakeStrategy' => 'post_response', 'errorLog' => $root . '/errors.log',
        ], $document, 'omitting the options preserves all original configuration fields and values');
        $loaded = RuntimeConfiguration::load($root . '/cms.json');
        assertSameValue(['table_set', null], [$loaded->databaseUpgradeMode, $loaded->databaseMaximumBytes], 'the runtime applies its unchanged defaults');
        assertSameValue('UTC', $loaded->timeZone, 'an omitted installation time zone has a stable default');
        assertTrueValue(str_contains($result['stdout'], 'Upgrade mode: table_set (runtime default).'), 'the tool states the effective default mode');
        assertSameValue([null, null, null], [$loaded->profileRoot, $loaded->publicUrlContext, $loaded->publicationRoot], 'omitted publication settings retain the runtime defaults');
        assertTrueValue(str_contains($result['stdout'], 'Installation will not use a packaged profile.') && str_contains($result['stdout'], 'Installation will not publish;'), 'the default installation reports its disabled capabilities');
        foreach (['cms.json', 'install.json', 'install-token'] as $file) {
            assertSameValue(0600, fileperms($root . '/' . $file) & 0777, 'every generated file stays owner-only');
        }
        $gate = json_decode((string) file_get_contents($root . '/install.json'), true, flags: JSON_THROW_ON_ERROR);
        assertSameValue(hash('sha256', trim((string) file_get_contents($root . '/install-token'))), $gate['tokenHash'], 'the gate authenticates the generated token');
        assertTrueValue(!str_contains($result['stdout'] . $result['stderr'], 'installation-fixture-secret'), 'no database password is printed');
    } finally {
        removeHostIoFixture($root);
    }
};

$tests['installation command records each remote publication setting without local filesystem effects'] = static function (): void {
    foreach (['profile-root', 'public-base-url', 'publication-root'] as $option) {
        $root = hostIoFixture();
        try {
            $values = ['profile-root' => $root . '/remote/private/profile', 'public-base-url' => 'https://public.example.test:8443/portfolio/', 'publication-root' => $root . '/remote/website'];
            $result = runHostIoTool('conventional-create-install-config.php', [...installConfigTestArguments($root), '--' . $option . '=' . $values[$option]]);
            assertSameValue(0, $result['status'], 'a remote setting succeeds without existing host directories: ' . $result['stderr']);
            $document = json_decode((string) file_get_contents($root . '/cms.json'), true, flags: JSON_THROW_ON_ERROR);
            $expected = match ($option) {
                'profile-root' => ['profileRoot' => $values[$option]],
                'public-base-url' => ['publicUrlContext' => ['formatVersion' => 1, 'baseUrl' => 'https://public.example.test:8443/portfolio']],
                'publication-root' => ['publicationRoot' => $values[$option]],
            };
            assertSameValue($expected, array_intersect_key($document, array_flip(['profileRoot', 'publicUrlContext', 'publicationRoot'])), 'only the selected setting is added');
            assertTrueValue(!file_exists($root . '/remote'), 'the tool never creates or resolves remote folders');
            assertTrueValue(str_contains($result['stdout'], 'Installation will not publish;'), 'an incomplete publication pair remains visibly disabled');
        } finally {
            removeHostIoFixture($root);
        }
    }
};

$tests['installation command generates a complete configuration that loads on the host'] = static function (): void {
    $root = hostIoFixture();
    try {
        foreach (['profile', 'website', 'cms', 'media'] as $directory) {
            mkdir($root . '/' . $directory, 0700);
        }
        $result = runHostIoTool('conventional-create-install-config.php', [...installConfigTestArguments($root), '--profile-root=' . $root . '/profile', '--public-base-url=https://public.example.test/', '--publication-root=' . $root . '/website', '--time-zone=Europe/Andorra']);
        assertSameValue(0, $result['status'], 'complete installation settings succeed: ' . $result['stderr']);
        $loaded = RuntimeConfiguration::load($root . '/cms.json', $root . '/cms');
        assertSameValue('Europe/Andorra', $loaded->timeZone, 'generated named time zone loads identically in web and CLI runtimes');
        assertSameValue([$root . '/profile', ['formatVersion' => 1, 'baseUrl' => 'https://public.example.test'], $root . '/website'], [$loaded->profileRoot, $loaded->publicUrlContext?->document(), $loaded->publicationRoot], 'the generated settings load unchanged on a qualified host layout');
        assertTrueValue(str_contains($result['stdout'], 'Packaged profile configured.') && str_contains($result['stdout'], 'Publication configured;'), 'configured capabilities are reported');
        $valid = json_decode((string) file_get_contents($root . '/cms.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ([['profileRoot' => 'relative/profile'], ['profileRoot' => []], ['publicationRoot' => 'relative/public'], ['publicationRoot' => '/'], ['profileRoot' => $root . '/cms'], ['publicationRoot' => $root . '/media'], ['publicationRoot' => $root . '/absent'], ['publicUrlContext' => ['formatVersion' => 1, 'baseUrl' => 'https://cms.example.test:443/site']]] as $invalid) {
            file_put_contents($root . '/cms.json', json_encode([...$valid, ...$invalid], JSON_THROW_ON_ERROR));
            try {
                RuntimeConfiguration::load($root . '/cms.json', $root . '/cms');
                throw new LogicException('Invalid installation settings loaded');
            } catch (RuntimeException) {
                // The runtime shares the lexical rules and still qualifies the real host layout.
            }
        }
    } finally {
        removeHostIoFixture($root);
    }
};

$tests['installation command rejects invalid publication settings before writing any outputs'] = static function (): void {
    $invalidOptions = [
        ['--time-zone='], ['--time-zone'], ['--time-zone=Invalid/Zone'], ['--time-zone=+02:00'], ['--time-zone=UTC', '--time-zone=Europe/Andorra'],
        ['--profile-root=relative/profile'], ['--profile-root='], ['--profile-root'],
        ['--publication-root=relative/public'], ['--publication-root=/'], ['--publication-root='], ['--publication-root'],
        ['--public-base-url=http://public.example.test'], ['--public-base-url=https://user:secret@public.example.test'],
        ['--public-base-url=https://public.example.test?query'], ['--public-base-url=https://public.example.test#fragment'],
        ['--public-base-url=https://public.example.test/../escape/'], ['--public-base-url=https://public.example.test/%2e%2e/'],
        ['--public-base-url=https://cms.example.test'], ['--public-base-url=https://cms.example.test:443/portfolio/'],
        ['--public-base-url='], ['--public-base-url'], ['--profile-root=/first', '--profile-root=/second'],
        ['--publication-root=/first', '--publication-root=/second'], ['--public-base-url=https://one.example.test', '--public-base-url=https://two.example.test'],
    ];
    foreach ($invalidOptions as $invalid) {
        $root = hostIoFixture();
        try {
            $result = runHostIoTool('conventional-create-install-config.php', [...installConfigTestArguments($root), ...$invalid]);
            assertSameValue(1, $result['status'], 'invalid publication setting must fail: ' . implode(' ', $invalid));
            assertSameValue(['database-password'], array_values(array_diff(scandir($root) ?: [], ['.', '..'])), 'no runtime configuration, gate or token may remain');
            assertTrueValue(!str_contains($result['stdout'] . $result['stderr'], 'installation-fixture-secret'), 'a rejected setting does not disclose the password');
        } finally {
            removeHostIoFixture($root);
        }
    }
    foreach (['https://CMS.example.test', 'https://cms.example.test:443', 'https://cms.example.test.'] as $origin) {
        try {
            RuntimeConfiguration::publicUrlContext(['formatVersion' => 1, 'baseUrl' => 'https://cms.example.test/portfolio'], $origin);
            throw new LogicException('Equivalent CMS origin accepted');
        } catch (RuntimeException) {
        }
    }
};

$tests['installation command writes each upgrade mode and exact integer quotas'] = static function (): void {
    foreach (['table_set', 'in_place', 'separate_database'] as $mode) {
        $root = hostIoFixture();
        try {
            $result = runHostIoTool('conventional-create-install-config.php', [...installConfigTestArguments($root), '--upgrade-mode=' . $mode, '--database-maximum-bytes=' . PHP_INT_MAX, '--table-prefix=site_']);
            assertSameValue(0, $result['status'], 'a chosen mode and maximum supported integer succeed: ' . $result['stderr']);
            $document = json_decode((string) file_get_contents($root . '/cms.json'), true, flags: JSON_THROW_ON_ERROR);
            assertSameValue([$mode, PHP_INT_MAX, 'site_'], [$document['database']['upgradeMode'], $document['database']['maximumBytes'], $document['database']['tablePrefix']], 'policy and prefix are recorded without integer precision loss');
            $loaded = RuntimeConfiguration::load($root . '/cms.json');
            assertSameValue([$mode, PHP_INT_MAX, 'site_'], [$loaded->databaseUpgradeMode, $loaded->databaseMaximumBytes, $loaded->tablePrefix->value], 'generated settings load through the runtime');
            assertTrueValue(str_contains($result['stdout'], 'Upgrade mode: ' . $mode . '.'), 'the selected mode is reported');
        } finally {
            removeHostIoFixture($root);
        }
    }
};

$tests['installation command accepts a quota with the default mode'] = static function (): void {
    $root = hostIoFixture();
    try {
        $result = runHostIoTool('conventional-create-install-config.php', [...installConfigTestArguments($root), '--database-maximum-bytes=1']);
        assertSameValue(0, $result['status'], 'the smallest positive quota succeeds');
        $document = json_decode((string) file_get_contents($root . '/cms.json'), true, flags: JSON_THROW_ON_ERROR);
        assertTrueValue(!array_key_exists('upgradeMode', $document['database']), 'a quota does not add an omitted mode');
        $loaded = RuntimeConfiguration::load($root . '/cms.json');
        assertSameValue(['table_set', 1], [$loaded->databaseUpgradeMode, $loaded->databaseMaximumBytes], 'quota and default mode load together');
    } finally {
        removeHostIoFixture($root);
    }
};

$tests['installation command rejects invalid upgrade policies before writing any outputs'] = static function (): void {
    foreach (['--upgrade-mode=standby', '--upgrade-mode=TABLE_SET', '--upgrade-mode=', '--upgrade-mode', '--database-maximum-bytes=0', '--database-maximum-bytes=-1', '--database-maximum-bytes=1.5', '--database-maximum-bytes=1e6', '--database-maximum-bytes=true', '--database-maximum-bytes=' . PHP_INT_MAX . '0', '--database-maximum-bytes=', '--database-maximum-bytes'] as $invalid) {
        $root = hostIoFixture();
        try {
            $result = runHostIoTool('conventional-create-install-config.php', [...installConfigTestArguments($root), $invalid]);
            assertSameValue(1, $result['status'], 'invalid policy must fail: ' . $invalid);
            assertSameValue(['database-password'], array_values(array_diff(scandir($root) ?: [], ['.', '..'])), 'no runtime configuration, gate or token may remain');
            assertTrueValue(!str_contains($result['stdout'] . $result['stderr'], 'installation-fixture-secret'), 'a rejected policy does not disclose the password');
        } finally {
            removeHostIoFixture($root);
        }
    }
};
