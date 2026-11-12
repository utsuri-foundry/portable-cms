<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Identity;

final readonly class SessionContext
{
    public function __construct(
        public string $id,
        public string $kind,
        public ?string $userId,
        public ?string $loginName,
        public ?string $displayName,
        public ?string $role,
        public string $sessionToken,
        public string $csrfToken,
        public \DateTimeImmutable $expiresAt,
        public bool $credentialsChanged,
        public ?string $interfaceLanguage = null,
        public bool $mustChangePassword = false,
        public int $securityVersion = 0,
    ) {}

    public function authenticated(): bool
    {
        return $this->kind === 'authenticated' && $this->userId !== null;
    }

    public function administrator(): bool
    {
        return $this->editorial() && in_array($this->role, ['owner', 'administrator'], true);
    }

    public function editorial(): bool
    {
        return $this->authenticated() && !$this->mustChangePassword && in_array($this->role, ['owner', 'administrator', 'editor'], true);
    }
}

final readonly class LoginResult
{
    public function __construct(
        public bool $authenticated,
        public SessionContext $session,
        public int $failureStatus = 0,
    ) {}
}

final class IdentityService
{
    public const SESSION_COOKIE = '__Host-cms_session';
    public const CSRF_COOKIE = '__Host-cms_csrf';

    private const DUMMY_PASSWORD_HASH = '$2y$10$oItHo/C05cZcGlHrNqvQFea00xdWrp/yE65BDS2Vcl1OuBQxWB3aC';

    public function __construct(
        private readonly IdentityRepository $repository,
        private readonly Clock $clock,
        private readonly RandomSource $random,
        private readonly string $throttleSecret,
        private readonly int $anonymousLifetimeSeconds = 1_800,
        private readonly int $idleLifetimeSeconds = 43_200,
        private readonly int $absoluteLifetimeSeconds = 604_800,
        private readonly int $loginWindowSeconds = 900,
        private readonly int $maximumLoginFailures = 5,
        private readonly int $loginBlockSeconds = 900,
    ) {
        if (strlen($throttleSecret) < 32) {
            throw new \InvalidArgumentException('Login throttle secret must contain at least 32 bytes');
        }
    }

    public function openSession(?string $sessionToken, ?string $csrfToken): SessionContext
    {
        $now = $this->clock->now();
        $record = $this->validOpaqueToken($sessionToken)
            ? $this->repository->findSessionByTokenHash($this->hashToken((string) $sessionToken))
            : null;

        if ($record !== null && $this->recordCurrent($record, $now)) {
            $csrfMatches = $this->validOpaqueToken($csrfToken)
                && hash_equals($record['csrfHash'], $this->hashToken((string) $csrfToken));
            if ($csrfMatches) {
                $expiresAt = $record['kind'] === 'authenticated'
                    ? $this->minimumTime(
                        $now->modify('+' . $this->idleLifetimeSeconds . ' seconds'),
                        $record['absoluteExpiresAt'],
                    )
                    : $record['expiresAt'];
                $this->repository->touchSession($record['id'], $now, $expiresAt);
                return new SessionContext(
                    id: $record['id'],
                    kind: $record['kind'],
                    userId: $record['userId'],
                    loginName: $record['loginName'],
                    displayName: $record['displayName'],
                    role: $record['role'],
                    sessionToken: (string) $sessionToken,
                    csrfToken: (string) $csrfToken,
                    expiresAt: $expiresAt,
                    credentialsChanged: $record['kind'] === 'authenticated'
                        && $expiresAt != $record['expiresAt'],
                    interfaceLanguage: $record['interfaceLanguage'] ?? null,
                    mustChangePassword: $record['mustChangePassword'] ?? false,
                    securityVersion: $record['securityVersion'] ?? 0,
                );
            }
            $this->repository->revokeSession($record['id'], $now);
            try {
                return $this->createSession($record['kind'], $record['userId'], $record, $now);
            } catch (AccountFailure) {
                // A reset or deactivation may supersede the record during rotation.
                return $this->createSession('anonymous', null, null, $now);
            }
        }

        return $this->createSession('anonymous', null, null, $now);
    }

    /** Stores the signed-in account's interface language; null follows the browser. */
    public function changeInterfaceLanguage(SessionContext $session, ?string $language): void
    {
        if (!$session->authenticated() || $session->userId === null || ($language !== null && \Utsuri\PortableCms\Conventional\I18n\InterfaceLanguage::preference($language) === null)) {
            throw new \InvalidArgumentException('Interface language change is not permitted');
        }
        $this->repository->setInterfaceLanguage($session->userId, $language);
        $this->repository->appendAudit(
            $this->randomId(),
            $session->userId,
            'interface_language_changed',
            ['language' => $language ?? 'system'],
            $this->clock->now(),
        );
    }

    public function csrfValid(SessionContext $session, ?string $presentedToken, ?string $cookieToken): bool
    {
        return $this->validOpaqueToken($presentedToken)
            && $this->validOpaqueToken($cookieToken)
            && hash_equals((string) $presentedToken, (string) $cookieToken)
            && hash_equals($session->csrfToken, (string) $presentedToken);
    }

    public function login(
        SessionContext $anonymousSession,
        string $login,
        string $password,
        string $remoteAddress,
    ): LoginResult {
        $now = $this->clock->now();
        $normalizedLogin = self::normalizeLogin($login);
        $keys = $this->throttleKeys($normalizedLogin, $remoteAddress);

        if (!$this->repository->loginAllowed($keys, $now)) {
            password_verify($password, self::DUMMY_PASSWORD_HASH);
            return new LoginResult(false, $anonymousSession, 429);
        }

        $user = $normalizedLogin !== '' && strlen($password) <= 1_024
            ? $this->repository->findActiveUserByLogin($normalizedLogin)
            : null;
        $passwordHash = $user['passwordHash'] ?? self::DUMMY_PASSWORD_HASH;
        $verified = password_verify($password, $passwordHash);

        if (!$verified || $user === null) {
            $this->repository->recordLoginFailure(
                $keys,
                $now,
                $this->loginWindowSeconds,
                $this->maximumLoginFailures,
                $this->loginBlockSeconds,
            );
            $this->repository->appendAudit(
                $this->randomId(),
                null,
                'login_failed',
                ['reason' => 'credentials'],
                $now,
            );
            return new LoginResult(false, $anonymousSession, 401);
        }

        $this->repository->clearLoginFailures($keys);
        $this->repository->revokeSession($anonymousSession->id, $now);
        if (password_needs_rehash($passwordHash, PASSWORD_DEFAULT)) {
            $replacement = password_hash($password, PASSWORD_DEFAULT);
            $this->repository->replacePasswordHash($user['id'], $replacement, $now, $passwordHash);
        }

        try {
            $session = $this->createSession('authenticated', $user['id'], $user, $now);
        } catch (AccountFailure) {
            return new LoginResult(false, $anonymousSession, 401);
        }
        $this->repository->appendAudit(
            $this->randomId(),
            $user['id'],
            'login_succeeded',
            ['sessionId' => $session->id],
            $now,
        );
        return new LoginResult(true, $session);
    }

    public function logout(SessionContext $session): void
    {
        $now = $this->clock->now();
        $this->repository->revokeSession($session->id, $now);
        $this->repository->appendAudit(
            $this->randomId(),
            $session->userId,
            'logout',
            ['sessionId' => $session->id],
            $now,
        );
    }

    public static function normalizeLogin(string $login): string
    {
        $normalized = \Normalizer::normalize(trim($login), \Normalizer::FORM_KC);
        if (!is_string($normalized)) {
            return '';
        }
        $normalized = mb_strtolower($normalized, 'UTF-8');
        return mb_strlen($normalized, 'UTF-8') <= 254 ? $normalized : '';
    }

    /**
     * @param array<string, mixed>|null $identity
     */
    private function createSession(
        string $kind,
        ?string $userId,
        ?array $identity,
        \DateTimeImmutable $now,
    ): SessionContext {
        $id = $this->randomId();
        $sessionToken = $this->opaqueToken();
        $csrfToken = $this->opaqueToken();
        $lifetime = $kind === 'authenticated'
            ? $this->idleLifetimeSeconds
            : $this->anonymousLifetimeSeconds;
        $expiresAt = $now->modify('+' . $lifetime . ' seconds');
        $absoluteExpiresAt = $kind === 'authenticated'
            ? $now->modify('+' . $this->absoluteLifetimeSeconds . ' seconds')
            : $expiresAt;
        $this->repository->createSession(
            $id,
            $kind,
            $userId,
            $this->hashToken($sessionToken),
            $this->hashToken($csrfToken),
            $now,
            $expiresAt,
            $absoluteExpiresAt,
            is_int($identity['securityVersion'] ?? null) ? $identity['securityVersion'] : 0,
        );
        return new SessionContext(
            id: $id,
            kind: $kind,
            userId: $userId,
            loginName: is_string($identity['loginName'] ?? null) ? $identity['loginName'] : null,
            displayName: is_string($identity['displayName'] ?? null) ? $identity['displayName'] : null,
            role: is_string($identity['role'] ?? null) ? $identity['role'] : null,
            sessionToken: $sessionToken,
            csrfToken: $csrfToken,
            expiresAt: $expiresAt,
            credentialsChanged: true,
            interfaceLanguage: is_string($identity['interfaceLanguage'] ?? null) ? $identity['interfaceLanguage'] : null,
            mustChangePassword: ($identity['mustChangePassword'] ?? false) === true,
            securityVersion: is_int($identity['securityVersion'] ?? null) ? $identity['securityVersion'] : 0,
        );
    }

    /** @param array<string, mixed> $record */
    private function recordCurrent(array $record, \DateTimeImmutable $now): bool
    {
        return in_array($record['kind'], ['anonymous', 'authenticated'], true)
            && $record['expiresAt'] > $now
            && $record['absoluteExpiresAt'] > $now
            && ($record['kind'] !== 'authenticated' || $record['userId'] !== null);
    }

    /** @return list<string> */
    private function throttleKeys(string $normalizedLogin, string $remoteAddress): array
    {
        $client = filter_var($remoteAddress, FILTER_VALIDATE_IP) !== false
            ? $remoteAddress
            : 'unknown';
        return [
            hash_hmac('sha256', "account\0" . $normalizedLogin, $this->throttleSecret, true),
            hash_hmac('sha256', "client\0" . $client, $this->throttleSecret, true),
        ];
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token, true);
    }

    private function opaqueToken(): string
    {
        return rtrim(strtr(base64_encode($this->random->bytes(32)), '+/', '-_'), '=');
    }

    private function randomId(): string
    {
        return bin2hex($this->random->bytes(16));
    }

    private function validOpaqueToken(?string $token): bool
    {
        return is_string($token) && preg_match('/\A[A-Za-z0-9_-]{43}\z/', $token) === 1;
    }

    private function minimumTime(\DateTimeImmutable $first, \DateTimeImmutable $second): \DateTimeImmutable
    {
        return $first < $second ? $first : $second;
    }
}
