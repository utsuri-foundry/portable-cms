<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Identity;

use Utsuri\PortableCms\Conventional\HostIo;
use Utsuri\PortableCms\Conventional\Installation\InstallationGate;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;

/** Expiring operator authority, independent of accounts and never an admin route. */
final class OwnerRecovery
{
    public static function execute(string $gatePath, string $token, string $password, ?string $documentRoot, Clock $clock, RandomSource $random): void
    {
        $gate = InstallationGate::load($gatePath, $documentRoot, $clock->now());
        if (!$gate->authorizes($token)) {
            throw new AccountFailure('not_permitted', 403);
        }
        $configuration = RuntimeConfiguration::load($gate->runtimeConfigurationPath(), $documentRoot);
        $pdo = $configuration->connect();
        $accounts = new AccountService(new MariaDbAccountRepository($pdo), $clock, $random);
        // A durable receipt closes the replay window even if replacing the gate file fails.
        $accounts->recoverOwner($password, hash('sha256', $token));
        $gate->close($clock->now());
    }

    public static function credentialFile(string $path, string $configurationPath, int $maximum): string
    {
        $resolved = realpath($path);
        $config = realpath($configurationPath);
        $permissions = $resolved === false ? null : HostIo::attempt(fn() => fileperms($resolved))->value;
        if (is_link($path) || $resolved === false || $config === false || !is_file($resolved) || dirname($resolved) !== dirname($config) || !is_int($permissions) || ($permissions & 0o077) !== 0) {
            throw new AccountFailure('not_permitted', 403);
        }
        $bytes = HostIo::attempt(fn() => file_get_contents($resolved, false, null, 0, $maximum + 1))->value;
        if (!is_string($bytes) || strlen($bytes) > $maximum) {
            throw new AccountFailure('invalid_input');
        }
        return rtrim($bytes, "\r\n");
    }
}
