<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\Http\OriginPolicy;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Http\Response;
use Utsuri\PortableCms\Conventional\Http\SecurityHeaders;
use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\IdentityRepository;
use Utsuri\PortableCms\Conventional\Identity\IdentityService;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;
use Utsuri\PortableCms\Conventional\IdentityApplication;
use Utsuri\PortableCms\Conventional\Installation\InstallationGate;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;
use Utsuri\PortableCms\Conventional\View\AdminShell;

final class IdentityTestClock implements Clock
{
    public function __construct(public \DateTimeImmutable $current) {}

    public function now(): \DateTimeImmutable
    {
        return $this->current;
    }
}

final class IdentityTestRandom implements RandomSource
{
    private int $counter = 0;

    public function bytes(int $length): string
    {
        $result = '';
        while (strlen($result) < $length) {
            $result .= hash('sha256', 'identity-test-' . $this->counter++, true);
        }
        return substr($result, 0, $length);
    }
}

final class MemoryIdentityRepository implements IdentityRepository
{
    /** @var array<string, array<string, mixed>> */
    public array $users = [];
    /** @var array<string, array<string, mixed>> */
    public array $sessions = [];
    /** @var array<string, array{failures: int, windowStartedAt: \DateTimeImmutable, blockedUntil: \DateTimeImmutable|null}> */
    public array $throttles = [];
    /** @var list<array<string, mixed>> */
    public array $audit = [];

    public function addUser(
        string $id,
        string $loginName,
        string $displayName,
        string $passwordHash,
        string $role = 'administrator',
    ): void {
        $this->users[$id] = compact('id', 'loginName', 'displayName', 'passwordHash', 'role') + [
            'status' => 'active',
        ];
    }

    public function findSessionByTokenHash(string $tokenHash): ?array
    {
        foreach ($this->sessions as $session) {
            if (!hash_equals($session['tokenHash'], $tokenHash) || $session['revokedAt'] !== null) {
                continue;
            }
            $user = $session['userId'] !== null ? ($this->users[$session['userId']] ?? null) : null;
            if ($session['kind'] === 'authenticated' && (!is_array($user) || $user['status'] !== 'active' || ($session['securityVersion'] ?? 0) !== ($user['securityVersion'] ?? 0))) {
                return null;
            }
            return [
                'id' => $session['id'],
                'kind' => $session['kind'],
                'userId' => $session['userId'],
                'loginName' => $user['loginName'] ?? null,
                'displayName' => $user['displayName'] ?? null,
                'role' => $user['role'] ?? null,
                'interfaceLanguage' => $user['interfaceLanguage'] ?? null,
                'mustChangePassword' => $user['mustChangePassword'] ?? false,
                'securityVersion' => $user['securityVersion'] ?? 0,
                'csrfHash' => $session['csrfHash'],
                'expiresAt' => $session['expiresAt'],
                'absoluteExpiresAt' => $session['absoluteExpiresAt'],
            ];
        }
        return null;
    }

    public function createSession(
        string $id,
        string $kind,
        ?string $userId,
        string $tokenHash,
        string $csrfHash,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $expiresAt,
        \DateTimeImmutable $absoluteExpiresAt,
        int $securityVersion = 0,
    ): void {
        $this->sessions[$id] = compact(
            'id',
            'kind',
            'userId',
            'tokenHash',
            'csrfHash',
            'createdAt',
            'expiresAt',
            'absoluteExpiresAt',
            'securityVersion',
        ) + ['lastSeenAt' => $createdAt, 'revokedAt' => null];
    }

    public function touchSession(string $id, \DateTimeImmutable $lastSeenAt, \DateTimeImmutable $expiresAt): void
    {
        $this->sessions[$id]['lastSeenAt'] = $lastSeenAt;
        $this->sessions[$id]['expiresAt'] = $expiresAt;
    }

    public function revokeSession(string $id, \DateTimeImmutable $revokedAt): void
    {
        if (isset($this->sessions[$id]) && $this->sessions[$id]['revokedAt'] === null) {
            $this->sessions[$id]['revokedAt'] = $revokedAt;
        }
    }

    public function setInterfaceLanguage(string $userId, ?string $language): void
    {
        if (isset($this->users[$userId]) && $this->users[$userId]['status'] === 'active') {
            $this->users[$userId]['interfaceLanguage'] = $language;
        }
    }

    public function findActiveUserByLogin(string $normalizedLogin): ?array
    {
        foreach ($this->users as $user) {
            if ($user['status'] === 'active' && $user['loginName'] === $normalizedLogin) {
                return [
                    'id' => $user['id'],
                    'loginName' => $user['loginName'],
                    'displayName' => $user['displayName'],
                    'role' => $user['role'],
                    'interfaceLanguage' => $user['interfaceLanguage'] ?? null,
                    'passwordHash' => $user['passwordHash'],
                    'mustChangePassword' => $user['mustChangePassword'] ?? false,
                    'securityVersion' => $user['securityVersion'] ?? 0,
                ];
            }
        }
        return null;
    }

    public function replacePasswordHash(string $userId, string $passwordHash, \DateTimeImmutable $changedAt, ?string $expectedHash = null): void
    {
        $this->users[$userId]['passwordHash'] = $passwordHash;
        $this->users[$userId]['passwordChangedAt'] = $changedAt;
    }

    public function loginAllowed(array $keyHashes, \DateTimeImmutable $now): bool
    {
        foreach ($keyHashes as $keyHash) {
            $throttle = $this->throttles[bin2hex($keyHash)] ?? null;
            if (is_array($throttle) && $throttle['blockedUntil'] !== null && $throttle['blockedUntil'] > $now) {
                return false;
            }
        }
        return true;
    }

    public function recordLoginFailure(
        array $keyHashes,
        \DateTimeImmutable $now,
        int $windowSeconds,
        int $maximumFailures,
        int $blockSeconds,
    ): void {
        foreach ($keyHashes as $keyHash) {
            $key = bin2hex($keyHash);
            $existing = $this->throttles[$key] ?? null;
            $expired = !is_array($existing)
                || $existing['windowStartedAt']->modify('+' . $windowSeconds . ' seconds') <= $now;
            $failures = $expired ? 1 : $existing['failures'] + 1;
            $this->throttles[$key] = [
                'failures' => $failures,
                'windowStartedAt' => $expired ? $now : $existing['windowStartedAt'],
                'blockedUntil' => $failures >= $maximumFailures
                    ? $now->modify('+' . $blockSeconds . ' seconds')
                    : null,
            ];
        }
    }

    public function clearLoginFailures(array $keyHashes): void
    {
        foreach ($keyHashes as $keyHash) {
            unset($this->throttles[bin2hex($keyHash)]);
        }
    }

    public function appendAudit(
        string $id,
        ?string $actorUserId,
        string $eventType,
        array $metadata,
        \DateTimeImmutable $occurredAt,
    ): void {
        $this->audit[] = compact('id', 'actorUserId', 'eventType', 'metadata', 'occurredAt');
    }
}

/** @return array{app: IdentityApplication, repository: MemoryIdentityRepository, clock: IdentityTestClock, diagnostics: object{rejections: array<int, array{reason: string, correlationId: string}>}} */
function identityTestApplication(): array
{
    $repository = new MemoryIdentityRepository();
    $repository->addUser(
        '11111111111111111111111111111111',
        'admin@example.test',
        'Test <Administrator>',
        password_hash('correct horse battery staple', PASSWORD_BCRYPT, ['cost' => 4]),
    );
    $clock = new IdentityTestClock(new \DateTimeImmutable('2026-09-30T12:00:00Z'));
    $random = new IdentityTestRandom();
    $identity = new IdentityService($repository, $clock, $random, str_repeat('t', 32));
    $diagnostics = (object) ['rejections' => []];
    return [
        'app' => new IdentityApplication(
            $identity,
            new OriginPolicy('https://cms.example.test'),
            $clock,
            $random,
            null,
            static function (string $reason, string $correlationId) use ($diagnostics): void {
                $diagnostics->rejections[] = compact('reason', 'correlationId');
            },
        ),
        'repository' => $repository,
        'clock' => $clock,
        'diagnostics' => $diagnostics,
    ];
}

/** @return array<string, string> */
function responseCookies(Response $response): array
{
    $result = [];
    foreach ($response->headers()['set-cookie'] ?? [] as $header) {
        $pair = explode(';', $header, 2)[0];
        [$name, $value] = explode('=', $pair, 2);
        $result[$name] = $value;
    }
    return $result;
}

/** @return array<string, string> */
function authenticatedTestCookies(IdentityApplication $app): array
{
    $loginPage = $app->handle(new Request('GET', '/login'));
    $cookies = responseCookies($loginPage);
    $login = $app->handle(new Request(
        'POST',
        '/login',
        ['origin' => 'https://cms.example.test', 'sec-fetch-site' => 'same-origin'],
        [
            '_csrf' => $cookies[IdentityService::CSRF_COOKIE],
            'login' => 'admin@example.test',
            'password' => 'correct horse battery staple',
        ],
        $cookies,
        '192.0.2.1',
    ));
    return responseCookies($login);
}

$tests['escapes every HTML context and rejects active URL schemes'] = static function (): void {
    assertSameValue('&lt;script&gt;&amp;&quot;', Html::text('<script>&"'), 'text must be escaped');
    assertSameValue('/safe?x=&lt;value&gt;', Html::url('/safe?x=<value>'), 'internal URL must be escaped');
    try {
        Html::url('javascript:alert(1)');
        throw new RuntimeException('unsafe URL was accepted');
    } catch (InvalidArgumentException) {
    }
};

$tests['login page creates a pre-auth session with hardened cookies and headers'] = static function (): void {
    ['app' => $app, 'repository' => $repository] = identityTestApplication();
    $response = $app->handle(new Request('GET', '/login'));
    $cookies = responseCookies($response);

    assertSameValue(200, $response->status, 'login page should render');
    assertSameValue(1, count($repository->sessions), 'pre-auth session should be persisted');
    assertTrueValue(isset($cookies[IdentityService::SESSION_COOKIE]), 'session cookie should be set');
    assertTrueValue(isset($cookies[IdentityService::CSRF_COOKIE]), 'CSRF cookie should be set');
    $setCookies = implode("\n", $response->headers()['set-cookie'] ?? []);
    assertTrueValue(str_contains($setCookies, 'HttpOnly'), 'session cookie should be unavailable to scripts');
    assertTrueValue(str_contains($setCookies, 'SameSite=Lax'), 'session cookie should use SameSite=Lax');
    assertTrueValue(str_contains($setCookies, 'SameSite=Strict'), 'CSRF cookie should be strict');
    assertTrueValue(!str_contains($setCookies, 'Domain='), 'cookies should remain host-only');
    assertTrueValue(str_contains($response->body, $cookies[IdentityService::CSRF_COOKIE]), 'form token should match cookie');
    assertSameValue('no-store', $response->headers()['cache-control'][0], 'authenticated plane must not cache');
    assertTrueValue(str_contains($response->headers()['content-security-policy'][0], "default-src 'self'"), 'CSP should be strict');
    assertTrueValue(
        str_contains($response->headers()['content-security-policy'][0], "style-src 'self'"),
        'CSP should allow only same-origin administration styles',
    );
    assertTrueValue(
        str_contains($response->body, 'href="/assets/admin.css?v=' . AdminShell::STYLESHEET_VERSION . '"'),
        'login should use the versioned same-origin administration stylesheet',
    );
};

$tests['unknown routes and bare overview redirects do not allocate anonymous sessions'] = static function (): void {
    ['app' => $app, 'repository' => $repository] = identityTestApplication();
    $missing = $app->handle(new Request('GET', '/automated-scan'));
    assertSameValue(404, $missing->status, 'unknown route should remain unavailable');
    assertSameValue(0, count($repository->sessions), 'unknown route must not allocate server state');
    $overview = $app->handle(new Request('GET', '/'));
    assertSameValue(303, $overview->status, 'bare overview should redirect to login');
    assertSameValue(0, count($repository->sessions), 'bare redirect must not allocate server state');
    $post = $app->handle(new Request(
        'POST',
        '/login',
        ['origin' => 'https://cms.example.test'],
        ['login' => 'admin@example.test', 'password' => 'not relevant'],
    ));
    assertSameValue(403, $post->status, 'a direct login POST without pre-auth state should fail');
    assertSameValue(0, count($repository->sessions), 'rejected direct POST must not allocate server state');
};

$tests['login rejects cross-origin and mismatched CSRF mutations'] = static function (): void {
    ['app' => $app, 'diagnostics' => $diagnostics] = identityTestApplication();
    $cookies = responseCookies($app->handle(new Request('GET', '/login')));
    $form = [
        '_csrf' => $cookies[IdentityService::CSRF_COOKIE],
        'login' => 'admin@example.test',
        'password' => 'correct horse battery staple',
    ];
    $crossOrigin = $app->handle(new Request(
        'POST',
        '/login',
        ['origin' => 'https://attacker.example'],
        $form,
        $cookies,
        '192.0.2.1',
    ));
    assertSameValue(403, $crossOrigin->status, 'cross-origin login must be rejected');

    $form['_csrf'] = str_repeat('a', 43);
    $wrongCsrf = $app->handle(new Request(
        'POST',
        '/login',
        ['origin' => 'https://cms.example.test'],
        $form,
        $cookies,
        '192.0.2.1',
    ));
    assertSameValue(403, $wrongCsrf->status, 'mismatched CSRF token must be rejected');
    $crossSiteMetadata = $app->handle(new Request(
        'POST',
        '/login',
        ['origin' => 'https://cms.example.test', 'sec-fetch-site' => 'cross-site'],
        [
            '_csrf' => $cookies[IdentityService::CSRF_COOKIE],
            'login' => 'admin@example.test',
            'password' => 'correct horse battery staple',
        ],
        $cookies,
        '192.0.2.1',
    ));
    assertSameValue(403, $crossSiteMetadata->status, 'cross-site fetch metadata must be rejected');
    assertSameValue(
        ['origin_host_mismatch', 'csrf_rejected', 'fetch_site_rejected'],
        array_column($diagnostics->rejections, 'reason'),
        'redacted diagnostics should distinguish the rejected security boundary',
    );
};

$tests['origin policy treats an explicit default HTTPS port as the same origin'] = static function (): void {
    $policy = new OriginPolicy('https://cms.example.test');
    $request = new Request(
        'POST',
        '/login',
        ['origin' => 'https://CMS.EXAMPLE.TEST:443/', 'sec-fetch-site' => 'same-origin'],
    );
    assertSameValue(null, $policy->rejection($request), 'equivalent HTTPS origins should normalize');
};

$tests['development HTTP is confined to an explicit loopback origin'] = static function (): void {
    $policy = new OriginPolicy('http://localhost:4181', allowInsecureLoopback: true);
    assertSameValue(
        null,
        $policy->rejection(new Request(
            'POST',
            '/login',
            ['origin' => 'http://localhost:4181', 'sec-fetch-site' => 'same-origin'],
        )),
        'the explicit development loopback origin should be accepted',
    );
    assertSameValue(
        'origin_scheme_mismatch',
        $policy->rejection(new Request(
            'POST',
            '/login',
            ['origin' => 'https://localhost:4181', 'sec-fetch-site' => 'same-origin'],
        )),
        'the configured development scheme should remain exact',
    );
    try {
        new OriginPolicy('http://cms.example.test', allowInsecureLoopback: true);
        throw new RuntimeException('external HTTP origin unexpectedly loaded');
    } catch (InvalidArgumentException) {
    }
    $headers = SecurityHeaders::apply(new Response(200), str_repeat('a', 24), insecureLoopback: true)->headers();
    assertTrueValue(!isset($headers['strict-transport-security']), 'HTTP loopback must not install HSTS');
    assertTrueValue(
        !str_contains($headers['content-security-policy'][0], 'upgrade-insecure-requests'),
        'HTTP loopback must not upgrade itself to unavailable HTTPS',
    );
};

$tests['opaque origins require explicit same-origin fetch metadata'] = static function (): void {
    $policy = new OriginPolicy('https://cms.example.test');
    assertSameValue(
        null,
        $policy->rejection(new Request(
            'POST',
            '/login',
            ['origin' => 'null', 'sec-fetch-site' => 'same-origin'],
        )),
        'same-origin fetch metadata should provide the opaque-origin compatibility signal',
    );
    assertSameValue(
        'origin_opaque',
        $policy->rejection(new Request('POST', '/login', ['origin' => 'null'])),
        'opaque origin without fetch metadata must fail closed',
    );
    assertSameValue(
        'fetch_site_rejected',
        $policy->rejection(new Request(
            'POST',
            '/login',
            ['origin' => 'null', 'sec-fetch-site' => 'cross-site'],
        )),
        'cross-site metadata must override the opaque-origin compatibility path',
    );
};

$tests['successful login rotates credentials and revokes the anonymous session'] = static function (): void {
    ['app' => $app, 'repository' => $repository, 'clock' => $clock] = identityTestApplication();
    $before = responseCookies($app->handle(new Request('GET', '/login')));
    $response = $app->handle(new Request(
        'POST',
        '/login',
        ['origin' => 'https://cms.example.test', 'sec-fetch-site' => 'same-origin'],
        [
            '_csrf' => $before[IdentityService::CSRF_COOKIE],
            'login' => 'ADMIN@example.test',
            'password' => 'correct horse battery staple',
        ],
        $before,
        '192.0.2.1',
    ));
    $after = responseCookies($response);

    assertSameValue(303, $response->status, 'login should use POST/redirect/GET');
    assertSameValue(['/'], $response->headers()['location'], 'login should redirect to overview');
    assertTrueValue(
        $before[IdentityService::SESSION_COOKIE] !== $after[IdentityService::SESSION_COOKIE],
        'session token must rotate after login',
    );
    assertTrueValue(
        $before[IdentityService::CSRF_COOKIE] !== $after[IdentityService::CSRF_COOKIE],
        'CSRF token must rotate after login',
    );
    $revoked = array_values(array_filter($repository->sessions, static fn(array $session): bool => $session['revokedAt'] !== null));
    assertSameValue(1, count($revoked), 'anonymous session should be revoked');
    assertSameValue('login_succeeded', $repository->audit[array_key_last($repository->audit)]['eventType'], 'success should be audited');

    $clock->current = $clock->current->modify('+1 second');
    $overview = $app->handle(new Request('GET', '/', cookies: $after));
    assertSameValue(200, $overview->status, 'new session should authenticate');
    assertTrueValue(str_contains($overview->body, 'Test &lt;Administrator&gt;'), 'display name should be escaped');
    assertSameValue(
        2,
        count($overview->headers()['set-cookie'] ?? []),
        'an active authenticated request should refresh both browser cookie lifetimes',
    );
    $stale = $app->handle(new Request('GET', '/', cookies: $before));
    assertSameValue(303, $stale->status, 'rotated anonymous token must not authenticate');
};

$tests['login failures stay generic and become durably throttled'] = static function (): void {
    ['app' => $app] = identityTestApplication();
    $cookies = responseCookies($app->handle(new Request('GET', '/login')));
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $response = $app->handle(new Request(
            'POST',
            '/login',
            ['origin' => 'https://cms.example.test'],
            [
                '_csrf' => $cookies[IdentityService::CSRF_COOKIE],
                'login' => 'missing@example.test',
                'password' => 'wrong',
            ],
            $cookies,
            '192.0.2.5',
        ));
        assertSameValue(401, $response->status, 'attempt within limit should be rejected generically');
        assertTrueValue(str_contains($response->body, 'not accepted'), 'error should remain generic');
        assertTrueValue(!str_contains($response->body, 'wrong'), 'password must never be reflected');
    }
    $blocked = $app->handle(new Request(
        'POST',
        '/login',
        ['origin' => 'https://cms.example.test'],
        [
            '_csrf' => $cookies[IdentityService::CSRF_COOKIE],
            'login' => 'missing@example.test',
            'password' => 'wrong',
        ],
        $cookies,
        '192.0.2.5',
    ));
    assertSameValue(429, $blocked->status, 'attempt after limit should be throttled');
};

$tests['logout requires same-origin CSRF and revokes the authenticated session'] = static function (): void {
    ['app' => $app, 'repository' => $repository] = identityTestApplication();
    $cookies = authenticatedTestCookies($app);
    $rejected = $app->handle(new Request(
        'POST',
        '/logout',
        ['origin' => 'https://cms.example.test'],
        ['_csrf' => str_repeat('z', 43)],
        $cookies,
        '192.0.2.1',
    ));
    assertSameValue(403, $rejected->status, 'logout without valid CSRF must fail');

    $logout = $app->handle(new Request(
        'POST',
        '/logout',
        ['origin' => 'https://cms.example.test'],
        ['_csrf' => $cookies[IdentityService::CSRF_COOKIE]],
        $cookies,
        '192.0.2.1',
    ));
    assertSameValue(303, $logout->status, 'logout should redirect');
    $expired = implode("\n", $logout->headers()['set-cookie'] ?? []);
    assertTrueValue(substr_count($expired, 'Max-Age=0') === 2, 'both browser credentials must expire');
    $current = $app->handle(new Request('GET', '/', cookies: $cookies));
    assertSameValue(303, $current->status, 'revoked session must stop authenticating');
    assertSameValue('logout', $repository->audit[array_key_last($repository->audit)]['eventType'], 'logout should be audited');
};

$tests['expired sessions are rejected and password hashes upgrade on login'] = static function (): void {
    ['app' => $app, 'repository' => $repository, 'clock' => $clock] = identityTestApplication();
    $beforeHash = $repository->users['11111111111111111111111111111111']['passwordHash'];
    $cookies = authenticatedTestCookies($app);
    $afterHash = $repository->users['11111111111111111111111111111111']['passwordHash'];
    assertTrueValue($beforeHash !== $afterHash, 'successful login should rehash an outdated password');
    assertTrueValue(!password_needs_rehash($afterHash, PASSWORD_DEFAULT), 'replacement should use current policy');

    $clock->current = $clock->current->modify('+8 days');
    $expired = $app->handle(new Request('GET', '/', cookies: $cookies));
    assertSameValue(303, $expired->status, 'absolute expiry must reject old credentials');
};

$tests['private runtime configuration is bounded and cannot live below the document root'] = static function (): void {
    $root = sys_get_temp_dir() . '/portable-cms-config-' . bin2hex(random_bytes(8));
    $public = $root . '/public';
    $private = $root . '/private';
    mkdir($public, 0700, true);
    mkdir($private, 0700, true);
    $valid = [
        'origin' => 'https://cms.example.test',
        'publicUrlContext' => ['formatVersion' => 1, 'baseUrl' => 'https://public.example.test/portfolio'],
        'database' => [
            'dsn' => 'mysql:host=database.example.test;dbname=cms;charset=utf8mb4',
            'username' => 'cms',
            'password' => 'secret',
        ],
        'throttleSecretBase64' => base64_encode(str_repeat('s', 32)),
        'errorLog' => $private . '/errors.log',
    ];
    $privateConfig = $private . '/cms.json';
    $publicConfig = $public . '/cms.json';
    file_put_contents($privateConfig, json_encode($valid, JSON_THROW_ON_ERROR));
    file_put_contents($publicConfig, json_encode($valid, JSON_THROW_ON_ERROR));
    chmod($privateConfig, 0600);
    chmod($publicConfig, 0600);
    try {
        $configuration = RuntimeConfiguration::load($privateConfig, $public);
        assertSameValue('https://cms.example.test', $configuration->origin, 'valid private config should load');
        assertSameValue('https://public.example.test/portfolio', $configuration->publicUrlContext?->baseUrl, 'publication origin must remain separate from CMS origin');
        assertSameValue('post_response', $configuration->mediaWakeStrategy, 'post-response wake should be the default');
        assertSameValue(false, $configuration->allowInsecureLoopback, 'production defaults must require HTTPS');
        assertSameValue('cms_', $configuration->tablePrefix->value, 'tables keep their canonical names by default');
        foreach (['next_' => true, 'Next_' => false, 'next' => false, 'a_b_' => false, 'x`_' => false, 7 => false] as $prefix => $accepted) {
            file_put_contents($privateConfig, json_encode([...$valid, 'database' => [...$valid['database'], 'tablePrefix' => $prefix]], JSON_THROW_ON_ERROR));
            chmod($privateConfig, 0600);
            try {
                assertSameValue((string) $prefix, RuntimeConfiguration::load($privateConfig, $public)->tablePrefix->value, 'a valid table prefix loads');
                assertTrueValue($accepted, 'an invalid table prefix must not load');
            } catch (RuntimeException $error) {
                assertTrueValue(!$accepted && str_contains($error->getMessage(), 'table prefix'), 'an invalid table prefix fails closed');
            }
        }
        file_put_contents($privateConfig, json_encode($valid, JSON_THROW_ON_ERROR));
        chmod($privateConfig, 0600);
        $valid['origin'] = 'http://localhost:4181';
        $valid['development'] = ['allowInsecureLoopback' => true];
        assertSameValue(['table_set', null], [$configuration->databaseUpgradeMode, $configuration->databaseMaximumBytes], 'upgrades build a second table set by default');
        foreach ([['in_place', 1_000_000, true], ['separate_database', null, true], ['standby', null, false], ['in_place', 0, false], ['in_place', '1000', false]] as [$mode, $bytes, $accepted]) {
            file_put_contents($privateConfig, json_encode([...$valid, 'database' => [...$valid['database'], 'upgradeMode' => $mode, 'maximumBytes' => $bytes]], JSON_THROW_ON_ERROR));
            chmod($privateConfig, 0600);
            try {
                $loaded = RuntimeConfiguration::load($privateConfig, $public);
                assertSameValue([$mode, $bytes], [$loaded->databaseUpgradeMode, $loaded->databaseMaximumBytes], 'a valid upgrade mode and quota load');
                assertTrueValue($accepted, 'an invalid upgrade mode or quota must not load');
            } catch (RuntimeException $error) {
                assertTrueValue(!$accepted && str_contains($error->getMessage(), 'database'), 'an invalid upgrade mode or quota fails closed');
            }
        }
        file_put_contents($privateConfig, json_encode($valid, JSON_THROW_ON_ERROR));
        chmod($privateConfig, 0600);
        $development = RuntimeConfiguration::load($privateConfig, $public);
        assertSameValue(true, $development->allowInsecureLoopback, 'explicit loopback development should load');
        $valid['origin'] = 'http://cms.example.test';
        file_put_contents($privateConfig, json_encode($valid, JSON_THROW_ON_ERROR));
        chmod($privateConfig, 0600);
        try {
            RuntimeConfiguration::load($privateConfig, $public);
            throw new RuntimeException('external insecure origin unexpectedly loaded');
        } catch (RuntimeException $error) {
            assertTrueValue(str_contains($error->getMessage(), 'HTTPS'), 'external HTTP must fail closed');
        }
        $valid['origin'] = 'https://cms.example.test';
        unset($valid['development']);
        $valid['mediaWakeStrategy'] = 'cron_only';
        file_put_contents($privateConfig, json_encode($valid, JSON_THROW_ON_ERROR));
        chmod($privateConfig, 0600);
        assertSameValue(
            'cron_only',
            RuntimeConfiguration::load($privateConfig, $public)->mediaWakeStrategy,
            'qualified hosts should support an explicit cron-only recovery mode',
        );
        $valid['mediaWakeStrategy'] = 'unbounded_request';
        file_put_contents($privateConfig, json_encode($valid, JSON_THROW_ON_ERROR));
        chmod($privateConfig, 0600);
        try {
            RuntimeConfiguration::load($privateConfig, $public);
            throw new RuntimeException('invalid wake strategy unexpectedly loaded');
        } catch (RuntimeException $error) {
            assertTrueValue(str_contains($error->getMessage(), 'wake strategy'), 'unknown wake strategy should fail closed');
        }
        $valid['mediaWakeStrategy'] = 'post_response';
        file_put_contents($privateConfig, json_encode($valid, JSON_THROW_ON_ERROR));
        chmod($privateConfig, 0600);
        try {
            RuntimeConfiguration::load($publicConfig, $public);
            throw new RuntimeException('public config unexpectedly loaded');
        } catch (RuntimeException $error) {
            assertTrueValue(str_contains($error->getMessage(), 'outside'), 'public config should be rejected');
        }
        chmod($privateConfig, 0640);
        try {
            RuntimeConfiguration::load($privateConfig, $public);
            throw new RuntimeException('group-readable config unexpectedly loaded');
        } catch (RuntimeException $error) {
            assertTrueValue(str_contains($error->getMessage(), 'permissions'), 'weak permissions should be rejected');
        }
    } finally {
        @unlink($privateConfig);
        @unlink($publicConfig);
        @rmdir($private);
        @rmdir($public);
        @rmdir($root);
    }
};

$tests['no-shell installation gate is private expiring authenticated and one-use'] = static function (): void {
    $root = sys_get_temp_dir() . '/portable-cms-gate-' . bin2hex(random_bytes(8));
    $public = $root . '/public';
    $private = $root . '/private';
    mkdir($public, 0700, true);
    mkdir($private, 0700, true);
    $path = $private . '/install.json';
    $token = bin2hex(random_bytes(32));
    $now = new DateTimeImmutable('2026-09-30T12:00:00Z');
    file_put_contents($path, json_encode([
        'status' => 'open',
        'expiresAt' => '2026-09-30T13:00:00Z',
        'tokenHash' => hash('sha256', $token),
        'runtimeConfig' => $private . '/cms.json',
    ], JSON_THROW_ON_ERROR));
    chmod($path, 0600);
    try {
        $gate = InstallationGate::load($path, $public, $now);
        assertSameValue(true, $gate->authorizes($token), 'random operator token should authorize');
        assertSameValue(false, $gate->authorizes(str_repeat('0', 64)), 'different token must fail');
        $gate->close($now);
        $closed = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        assertSameValue('closed', $closed['status'] ?? null, 'successful gate should close');
        assertTrueValue(!array_key_exists('tokenHash', $closed), 'closed gate must erase token digest');
        try {
            InstallationGate::load($path, $public, $now);
            throw new RuntimeException('closed gate unexpectedly reopened');
        } catch (RuntimeException $error) {
            assertTrueValue(str_contains($error->getMessage(), 'closed'), 'closed gate should be unavailable');
        }
    } finally {
        @unlink($path);
        @rmdir($private);
        @rmdir($public);
        @rmdir($root);
    }
};

$tests['theme preference is a validated CSRF-protected per-browser cookie'] = static function (): void {
    ['app' => $app] = identityTestApplication();
    $cookies = authenticatedTestCookies($app);
    $same = ['origin' => 'https://cms.example.test', 'sec-fetch-site' => 'same-origin'];
    $csrf = $cookies[IdentityService::CSRF_COOKIE];
    $post = static fn(array $headers, array $form, array $jar = []): Response => $app->handle(new Request('POST', '/settings/theme', $headers, $form, $jar === [] ? $cookies : $jar));

    $saved = $post($same, ['_csrf' => $csrf, 'theme' => 'dark', '_return' => '/records/project?q=a']);
    assertSameValue(303, $saved->status, 'native preference form redirects');
    assertSameValue('/', $saved->headers()['location'][0], 'query strings and unknown paths never become redirect targets');
    $set = implode("\n", $saved->headers()['set-cookie'] ?? []);
    assertTrueValue(str_contains($set, '__Host-cms-theme=dark; Path=/; Max-Age=31536000; Secure; SameSite=Lax; HttpOnly'), 'preference is a hardened host cookie');
    $themed = $app->handle(new Request('GET', '/', [], [], $cookies + ['__Host-cms-theme' => 'dark']));
    assertTrueValue(str_starts_with($themed->body, '<!doctype html><html lang="en" data-theme="dark">'), 'server renders the chosen theme without script');
    assertTrueValue(str_contains($themed->body, 'value="dark" checked'), 'the current choice is checked');

    $local = $post($same, ['_csrf' => $csrf, 'theme' => 'light', '_return' => '/records/project']);
    assertSameValue('/records/project', $local->headers()['location'][0], 'same-origin paths are preserved');
    foreach (['//evil.example/x', '/\\evil.example', 'https://evil.example/', '/../etc', "/a\r\nx: y"] as $hostile) {
        $redirect = $post($same, ['_csrf' => $csrf, 'theme' => 'light', '_return' => $hostile]);
        assertSameValue('/', $redirect->headers()['location'][0], 'hostile return paths fall back to Overview');
    }
    $json = $post($same + ['accept' => 'application/json'], ['_csrf' => $csrf, 'theme' => 'system']);
    assertSameValue(200, $json->status, 'island persistence returns JSON');
    assertTrueValue(str_contains(implode("\n", $json->headers()['set-cookie'] ?? []), '__Host-cms-theme=; Path=/; Max-Age=0'), 'system clears the cookie');

    assertSameValue(400, $post($same, ['_csrf' => $csrf, 'theme' => 'neon'])->status, 'unknown themes are rejected');
    assertSameValue(403, $post($same, ['theme' => 'dark'])->status, 'missing CSRF is rejected');
    assertSameValue(403, $post(['origin' => 'https://evil.example', 'sec-fetch-site' => 'cross-site'], ['_csrf' => $csrf, 'theme' => 'dark'])->status, 'cross-origin preference changes are rejected');
    $anonymous = responseCookies($app->handle(new Request('GET', '/login')));
    assertSameValue(403, $post($same, ['_csrf' => $anonymous[IdentityService::CSRF_COOKIE], 'theme' => 'dark'], $anonymous)->status, 'anonymous sessions cannot set preferences');

    $hostile = $app->handle(new Request('GET', '/login', [], [], ['__Host-cms-theme' => '"><script>']));
    assertTrueValue(str_starts_with($hostile->body, '<!doctype html><html lang="en"><head>'), 'invalid cookie values fall back to the system theme');
};
