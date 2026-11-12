<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Installation;

use PDO;
use Utsuri\PortableCms\Conventional\Database\MigrationRunner;
use Utsuri\PortableCms\Conventional\HostIo;
use Utsuri\PortableCms\Conventional\Identity\AdministratorBootstrap;
use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;

final class InstallationGate
{
    /** @param array<string, mixed> $document */
    private function __construct(
        private readonly string $path,
        private array $document,
    ) {}

    public static function load(
        string $path,
        ?string $documentRoot,
        ?\DateTimeImmutable $now = null,
    ): self {
        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
            throw new \RuntimeException('Installation gate is unavailable');
        }
        $root = $documentRoot !== null ? realpath($documentRoot) : false;
        if ($root !== false && self::within($resolved, $root)) {
            throw new \RuntimeException('Installation gate must be private');
        }
        $permissions = HostIo::attempt(fn() => fileperms($resolved))->value;
        if (!is_int($permissions) || ($permissions & 0o077) !== 0) {
            throw new \RuntimeException('Installation gate permissions are unsafe');
        }
        try {
            $document = json_decode((string) HostIo::attempt(fn() => file_get_contents($resolved))->value, true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            throw new \RuntimeException('Installation gate is invalid', previous: $error);
        }
        if (!is_array($document)) {
            throw new \RuntimeException('Installation gate must contain an object');
        }
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $expiresAt = isset($document['expiresAt']) ? strtotime((string) $document['expiresAt']) : false;
        if (
            ($document['status'] ?? null) !== 'open'
            || !is_int($expiresAt)
            || $expiresAt <= $now->getTimestamp()
            || $expiresAt > $now->getTimestamp() + 86_400
        ) {
            throw new \RuntimeException('Installation gate is closed');
        }
        if (
            !is_string($document['tokenHash'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/', $document['tokenHash']) !== 1
            || !is_string($document['runtimeConfig'] ?? null)
        ) {
            throw new \RuntimeException('Installation gate is incomplete');
        }
        return new self($resolved, $document);
    }

    public function authorizes(string $token): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/', $token) === 1
            && hash_equals($this->document['tokenHash'], hash('sha256', $token));
    }

    public function runtimeConfigurationPath(): string
    {
        return $this->document['runtimeConfig'];
    }

    public function close(\DateTimeImmutable $now): void
    {
        $this->document['status'] = 'closed';
        $this->document['completedAt'] = $now
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
        unset($this->document['tokenHash']);
        $temporary = dirname($this->path) . '/.install-' . bin2hex(random_bytes(12)) . '.tmp';
        $encoded = json_encode(
            $this->document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";
        try {
            HostIo::createPrivateFile($temporary, $encoded);
            if (!HostIo::attempt(fn() => rename($temporary, $this->path))->value) {
                throw new \RuntimeException('Could not replace installation gate');
            }
        } finally {
            if (is_file($temporary)) {
                HostIo::cleanup(fn() => unlink($temporary));
            }
        }
    }

    private static function within(string $path, string $directory): bool
    {
        $directory = rtrim($directory, DIRECTORY_SEPARATOR);
        return $path === $directory || str_starts_with($path, $directory . DIRECTORY_SEPARATOR);
    }
}

final readonly class InstallationResult
{
    public function __construct(
        public string $status,
        public int $migrationsApplied,
    ) {}
}

final readonly class InstallationService
{
    /** @param list<string> $migrationPaths */
    public function __construct(
        private InstallationGate $gate,
        private RuntimeConfiguration $configuration,
        private array $migrationPaths,
        private Clock $clock,
        private RandomSource $random,
    ) {}

    public function install(
        string $token,
        string $login,
        string $displayName,
        string $password,
        ?\DateTimeImmutable $now = null,
    ): InstallationResult {
        if (!$this->gate->authorizes($token)) {
            throw new \UnexpectedValueException('Installation authorization failed');
        }
        // Check compatibility before connecting, migrating or creating the administrator.
        if ($this->configuration->profileRoot !== null) {
            \Utsuri\PortableCms\Conventional\Content\ProfilePackage::load($this->configuration->profileRoot);
        }
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $pdo = $this->configuration->connect();
        $migration = (new MigrationRunner($pdo))->apply($this->migrationPaths, $now);
        if ($this->bootstrapClosed($pdo)) {
            $this->gate->close($now);
            return new InstallationResult('installed', $migration->applied);
        }
        (new AdministratorBootstrap($pdo, $this->clock, $this->random))
            ->create($login, $displayName, $password);
        $this->gate->close($now);
        return new InstallationResult('installed', $migration->applied);
    }

    private function bootstrapClosed(PDO $pdo): bool
    {
        return $pdo->query(
            'SELECT status FROM cms_bootstrap_state WHERE singleton_id = 1',
        )->fetchColumn() === 'closed';
    }
}
