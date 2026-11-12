<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Http\OriginPolicy;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\IdentityService;
use Utsuri\PortableCms\Conventional\Identity\MariaDbIdentityRepository;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;
use Utsuri\PortableCms\Conventional\IdentityApplication;
use Utsuri\PortableCms\Conventional\Preview\MariaDbPreviewShareRepository;
use Utsuri\PortableCms\Conventional\Preview\PreviewService;
use Utsuri\PortableCms\Conventional\Preview\PreviewShare;
use Utsuri\PortableCms\Conventional\Preview\PreviewShareHttp;
use Utsuri\PortableCms\Conventional\Preview\PreviewShareService;
use Utsuri\PortableCms\Conventional\Preview\ShareUnavailable;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;

final class PreviewShareIntegrationClock implements Clock
{
    public function __construct(public DateTimeImmutable $current) {}
    public function now(): DateTimeImmutable
    {
        return $this->current;
    }
}

/** Exercises the real database, authenticated HTTP boundary and verified filesystem store. Deletes only the supplied test preview. */
function verifyPreviewSharing(PDO $pdo, PreviewService $previews, string $previewId, string $actor, ProfileManifest $profile, RandomSource $random, RuntimeConfiguration $configuration): void
{
    $clock = new PreviewShareIntegrationClock(new DateTimeImmutable('2026-10-05T12:00:00Z'));
    $repository = new MariaDbPreviewShareRepository($pdo);
    $identityRepository = new MariaDbIdentityRepository($pdo);
    $shares = new PreviewShareService($repository, $identityRepository, $clock, $random, str_repeat('s', 32));
    $origin = 'https://cms.example.test';
    $identity = new IdentityService($identityRepository, $clock, $random, str_repeat('s', 32));
    $policy = new OriginPolicy($origin);
    $http = new PreviewShareHttp($shares, $previews, $policy, $origin);
    $app = new IdentityApplication($identity, $policy, $clock, $random, profile: $profile, previews: $previews, previewShares: $http);
    $created = $shares->create($previewId, $actor, 1);
    $share = $created['share'];
    $token = $created['token'];
    $base = '/shared-previews/' . $share->id . '/';
    $cookies = [PreviewShareHttp::cookieName($share->id) => $token];
    $headers = ['origin' => $origin, 'sec-fetch-site' => 'same-origin'];
    $stored = $pdo->prepare('SELECT token_hash FROM cms_preview_shares WHERE id = :id');
    $stored->execute(['id' => $share->id]);
    assertSameValue(hash('sha256', $token), $stored->fetchColumn(), 'database persists only the secret hash');
    $sessions = (int) $pdo->query('SELECT COUNT(*) FROM cms_sessions')->fetchColumn();
    $opened = $app->handle(new Request('POST', $base . 'open', $headers, ['token' => $token]));
    assertSameValue(303, $opened->status, 'fragment secret exchanges through same-origin POST');
    assertSameValue([$base], $opened->headers()['location'], 'redirect contains no secret');
    $cookie = $opened->headers()['set-cookie'][0];
    foreach (['Secure', 'HttpOnly', 'SameSite=Strict'] as $flag) {
        assertTrueValue(str_contains($cookie, $flag), 'read-only cookie keeps ' . $flag);
    }
    assertSameValue($sessions, (int) $pdo->query('SELECT COUNT(*) FROM cms_sessions')->fetchColumn(), 'guest capability never creates a CMS session');
    assertSameValue(403, $app->handle(new Request('POST', $base . 'open', ['origin' => 'https://foreign.example'], ['token' => $token]))->status, 'cross-origin exchange rejected');
    $viewer = $app->handle(new Request('GET', $base, cookies: $cookies));
    assertSameValue(200, $viewer->status, 'authorized viewer opens without an account');
    assertTrueValue(str_contains($viewer->body, '/assets/preview-vimeo.js'), 'shared viewer supplies the sibling player module');
    assertTrueValue(str_contains($viewer->headers()['content-security-policy'][0], "frame-src 'self' https://player.vimeo.com"), 'shared shell admits its page and fixed provider only');
    assertTrueValue(str_contains($viewer->body, 'data-share-dismiss') && str_contains($viewer->body, 'sandbox="allow-scripts"'), 'separate shell supplies a dismissible notice and bounded frame');
    assertSameValue(1, preg_match('#src="(/shared-preview-files/[a-f0-9]{32}/([a-f0-9]{64})/)"#', $viewer->body, $fileMatch), 'viewer mints a separate file address');
    $fileBase = $fileMatch[1];
    $fileToken = $fileMatch[2];
    assertTrueValue($fileToken !== $token, 'file address never exposes the share secret');
    $fileRows = $pdo->query('SELECT token_hash FROM cms_preview_share_files')->fetchAll(PDO::FETCH_COLUMN);
    assertTrueValue(in_array(hash('sha256', $fileToken), $fileRows, true) && !in_array($fileToken, $fileRows, true), 'file storage retains only hashes');
    foreach (['index.html', 'en/disciplines/index.html', 'artifact.json', 'navigation-rules.json'] as $path) {
        $artifact = $app->handle(new Request('GET', $base . 'artifact/' . $path, cookies: $cookies));
        assertSameValue(200, $artifact->status, 'share serves declared artifact ' . $path);
        assertSameValue($previews->artifact($previewId, $path)?->contents, $artifact->body, 'share bytes equal authenticated frozen bytes');
        assertSameValue(['no-store'], $artifact->headers()['cache-control'], 'shared artifacts cannot be cached');
        assertSameValue(['noindex, nofollow, noarchive'], $artifact->headers()['x-robots-tag'], 'shared artifacts cannot be indexed');
        assertSameValue(['no-referrer'], $artifact->headers()['referrer-policy'], 'navigation cannot disclose capability');
        assertSameValue(str_ends_with($path, '.html') ? ['"' . $base . 'artifact/navigation-rules.json"'] : null, $artifact->headers()['speculation-rules'] ?? null, 'only frozen HTML carries the capability-scoped rules header');
        assertTrueValue(str_contains($artifact->headers()['content-security-policy'][0], "frame-ancestors 'self'") && str_contains($artifact->headers()['content-security-policy'][0], "script-src 'none'"), 'artifact frame excludes foreign embedding and executable islands');
    }
    $uses = (int) $pdo->query("SELECT COUNT(*) FROM cms_audit_events WHERE event_type = 'preview.share.used'")->fetchColumn();
    foreach (['index.html', 'en/disciplines/index.html', 'artifact.json', 'navigation-rules.json'] as $path) {
        $artifact = $app->handle(new Request('GET', $fileBase . $path));
        assertSameValue(200, $artifact->status, 'opaque frame serves declared bytes without cookies');
        assertSameValue($previews->artifact($previewId, $path)?->contents, $artifact->body, 'isolated bytes remain checksum-identical');
        $policy = $artifact->headers()['content-security-policy'][0];
        assertTrueValue(str_starts_with($policy, str_replace("frame-ancestors 'none'", "frame-ancestors 'self'", \Utsuri\PortableCms\Conventional\Compiler\ApachePublicDelivery::CONTENT_SECURITY_POLICY)), 'isolated sharing keeps the public site policy');
        assertTrueValue(str_contains($policy, '; sandbox allow-scripts') && !str_contains($policy, 'allow-same-origin') && !str_contains($policy, 'allow-popups'), 'opaque sandbox forbids forms, storage and escape');
        assertTrueValue(!str_contains($policy, 'player.vimeo.com'), 'build without Vimeo grants no provider permission');
        assertSameValue(['no-store'], $artifact->headers()['cache-control'], 'isolated files never cache');
        assertSameValue(['noindex, nofollow, noarchive'], $artifact->headers()['x-robots-tag'], 'isolated files never index');
        assertSameValue(['no-referrer'], $artifact->headers()['referrer-policy'], 'file addresses never become referrers');
        assertTrueValue(!$artifact->hasHeader('set-cookie'), 'file requests never open CMS sessions');
        assertSameValue(str_ends_with($path, '.html') ? ['"' . $fileBase . 'navigation-rules.json"'] : null, $artifact->headers()['speculation-rules'] ?? null, 'rules remain in the file capability mount');
    }
    foreach (['../artifact.json', '%2e%2e/artifact.json', '/index.html', 'missing.html', '../../records/article'] as $path) {
        assertSameValue(404, $app->handle(new Request('GET', $fileBase . $path, remoteAddress: 'file-path'))->status, 'file route rejects traversal and unavailable pages');
    }
    foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
        assertSameValue(404, $app->handle(new Request($method, $fileBase . 'index.html'))->status, 'file capability is read-only');
    }
    assertSameValue($uses, (int) $pdo->query("SELECT COUNT(*) FROM cms_audit_events WHERE event_type = 'preview.share.used'")->fetchColumn(), 'file requests do not write per-file use audits');
    $other = $shares->create($previewId, $actor, 24);
    assertSameValue(404, $app->handle(new Request('GET', str_replace($share->id, $other['share']->id, $fileBase), remoteAddress: 'wrong-share'))->status, 'token cannot move even to another share of the same preview');
    assertSameValue(404, $app->handle(new Request('GET', str_replace($fileToken, $token, $fileBase), remoteAddress: 'wrong-file'))->status, 'share cookie secret cannot serve isolated files');
    assertSameValue(false, $shares->renewFiles($other['share'], $fileToken), 'another share cannot renew a file token');
    try {
        $shares->createFiles(new PreviewShare($share->id, str_repeat('f', 32), $share->createdAt, $share->expiresAt));
        throw new RuntimeException('A file token was rebound to another preview');
    } catch (ShareUnavailable) {
    }
    assertSameValue(403, $app->handle(new Request('POST', $base . 'renew', ['origin' => 'null', 'sec-fetch-site' => 'cross-site'], ['token' => $fileToken], $cookies))->status, 'sandbox cannot renew its own file address');
    assertSameValue(404, $app->handle(new Request('POST', $base . 'renew', $headers, ['token' => $fileToken]))->status, 'renewal requires the share cookie');
    assertSameValue(204, $app->handle(new Request('POST', $base . 'renew', $headers, ['token' => $fileToken], $cookies))->status, 'outer viewer renews its own file token');
    // Exercise the installed factory's actual diagnostic observer, including an error on a token-bearing path.
    $loggedShares = new PreviewShareService($repository, $identityRepository, new \Utsuri\PortableCms\Conventional\Identity\SystemClock(), $random, str_repeat('s', 32));
    $logged = $loggedShares->create($previewId, $actor, 1);
    $loggedToken = $loggedShares->createFiles($logged['share']);
    $savedManifest = $pdo->prepare('SELECT manifest_json FROM cms_previews WHERE id = :id');
    $savedManifest->execute(['id' => $previewId]);
    $originalManifest = $savedManifest->fetchColumn();
    $setManifest = $pdo->prepare('UPDATE cms_previews SET manifest_json = :manifest WHERE id = :id');
    try {
        $setManifest->execute(['manifest' => '[]', 'id' => $previewId]);
        $installed = \Utsuri\PortableCms\Conventional\ApplicationFactory::identity($configuration);
        $failed = $installed->handle(new Request('GET', '/shared-preview-files/' . $logged['share']->id . '/' . $loggedToken . '/'));
        assertSameValue(500, $failed->status, 'corrupt stored manifest takes the actual private error boundary');
        $diagnostics = $configuration->errorLog === null ? '' : file_get_contents($configuration->errorLog);
        assertTrueValue(is_string($diagnostics) && str_contains($diagnostics, 'error=RuntimeException'), 'request failure is recorded without its path');
        foreach ([$logged['token'], $loggedToken] as $secret) {
            assertTrueValue(!str_contains($diagnostics, $secret) && !str_contains($diagnostics, hash('sha256', $secret)), 'actual diagnostics contain neither share/file tokens nor hashes');
        }
    } finally {
        $setManifest->execute(['manifest' => $originalManifest, 'id' => $previewId]);
    }
    assertTrueValue(str_contains($viewer->headers()['content-security-policy'][0], "frame-ancestors 'none'"), 'review shell excludes all external framing');
    foreach (['artifact/../artifact.json', 'artifact/%2e%2e/artifact.json', 'artifact//index.html', 'artifact/missing.html', 'admin', 'previews/' . $previewId . '/artifact/', 'artifact/../../records/article'] as $path) {
        assertSameValue(404, $app->handle(new Request('GET', $base . $path, cookies: $cookies))->status, 'hostile share path fails: ' . $path);
    }
    assertSameValue(200, $app->handle(new Request('GET', $base . 'artifact/en/disciplines/', cookies: $cookies))->status, 'native deep directory routes resolve to frozen index');
    foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
        assertSameValue(404, $app->handle(new Request($method, $base . 'artifact/index.html', cookies: $cookies))->status, 'capability rejects writes');
    }
    assertSameValue(303, $app->handle(new Request('GET', '/previews', cookies: $cookies))->status, 'capability cannot authenticate CMS pages');
    assertSameValue(403, $app->handle(new Request('POST', '/previews/' . $previewId . '/delete', $headers, cookies: $cookies))->status, 'capability cannot delete previews');
    assertSameValue(404, $app->handle(new Request('GET', '/shared-previews/' . str_repeat('f', 32) . '/artifact/index.html', cookies: $cookies, remoteAddress: 'other-share'))->status, 'capability cannot reach another link');
    assertSameValue(404, $app->handle(new Request('GET', $base . 'artifact/index.html', cookies: [PreviewShareHttp::cookieName($share->id) => str_repeat('f', 64)], remoteAddress: 'wrong-token'))->status, 'wrong secret fails');
    assertSameValue(404, $app->handle(new Request('GET', $base . 'artifact/navigation-rules.json', remoteAddress: 'anonymous-rules'))->status, 'rules require the same capability as every preview page');

    // Authenticated native management checks remain distinct from the capability exchange.
    $anonymous = $identity->openSession(null, null);
    $login = $identity->login($anonymous, 'sandbox@example.test', 'sandbox integration password', 'manager');
    assertTrueValue($login->authenticated, 'sandbox administrator signs in for link management');
    $auth = [IdentityService::SESSION_COOKIE => $login->session->sessionToken, IdentityService::CSRF_COOKIE => $login->session->csrfToken];
    $route = '/previews/' . $previewId . '/shares';
    assertSameValue(403, $app->handle(new Request('POST', $route, $headers, ['expiry_hours' => '24'], $auth))->status, 'link creation requires CSRF');
    assertSameValue(422, $app->handle(new Request('POST', $route, $headers, ['expiry_hours' => '999', '_csrf' => $login->session->csrfToken], $auth))->status, 'native form rejects arbitrary expiry');
    $managed = $app->handle(new Request('POST', $route, $headers, ['expiry_hours' => '24', '_csrf' => $login->session->csrfToken], $auth));
    assertSameValue(200, $managed->status, 'publisher creates link through native form');
    assertTrueValue(str_contains($managed->body, '/#'), 'secret appears only in complete fragment link');
    assertTrueValue(!str_contains($app->handle(new Request('GET', $route, cookies: $auth))->body, '/#'), 'later management cannot recover raw secrets');
    $wrongPreview = str_repeat('f', 32);
    try {
        $shares->revoke($wrongPreview, $share->id, $actor);
        throw new RuntimeException('cross-preview revocation accepted');
    } catch (ShareUnavailable) {
    }
    $auditId = $pdo->query('SELECT id FROM cms_audit_events ORDER BY id LIMIT 1')->fetchColumn();
    if (!is_string($auditId)) {
        throw new RuntimeException('Audit fixture is unavailable');
    }
    $rollback = new PreviewShare(bin2hex($random->bytes(16)), $previewId, $clock->now(), $clock->now()->modify('+1 hour'));
    try {
        $repository->create($rollback, str_repeat('a', 64), $actor, $auditId);
        throw new RuntimeException('duplicate audit unexpectedly committed');
    } catch (PDOException) {
    }
    $check = $pdo->prepare('SELECT COUNT(*) FROM cms_preview_shares WHERE id = :id');
    $check->execute(['id' => $rollback->id]);
    assertSameValue(0, (int) $check->fetchColumn(), 'audit failure rolls back creation');
    try {
        $repository->revoke($previewId, $share->id, $actor, $auditId, $clock->now());
        throw new RuntimeException('duplicate revoke audit unexpectedly committed');
    } catch (PDOException) {
    }
    assertSameValue($share->id, $shares->authorize($share->id, $token, 'rollback')?->id, 'audit failure rolls back revocation');
    // Storage rechecks authority, even when an earlier authenticated request passed.
    // The installation owner cannot be deactivated; exercise a separate administrator.
    $publisher = bin2hex($random->bytes(16));
    $pdo->prepare("INSERT INTO cms_users (id, login_name, display_name, password_hash, role, status, created_at, password_changed_at) VALUES (:id, 'disabled-publisher@example.test', 'Fictional publisher', :hash, 'administrator', 'active', :created, :changed)")
        ->execute(['id' => $publisher, 'hash' => password_hash('fictional publisher password', PASSWORD_DEFAULT), 'created' => $clock->now()->format('Y-m-d H:i:s.u'), 'changed' => $clock->now()->format('Y-m-d H:i:s.u')]);
    $disable = $pdo->prepare('UPDATE cms_users SET status = :status WHERE id = :id');
    $disable->execute(['id' => $publisher, 'status' => 'disabled']);
    try {
        foreach ([static fn() => $shares->create($previewId, $publisher, 1), static fn() => $shares->revoke($previewId, $share->id, $publisher)] as $mutation) {
            try {
                $mutation();
                throw new RuntimeException('Disabled publisher mutated share authority');
            } catch (ShareUnavailable) {
            }
        }
    } finally {
        $disable->execute(['id' => $publisher, 'status' => 'active']);
    }
    $status = $pdo->prepare('UPDATE cms_previews SET status = :status WHERE id = :id');
    $status->execute(['id' => $previewId, 'status' => 'needs_attention']);
    try {
        assertSameValue(null, $shares->authorize($share->id, $token, 'not-ready'), 'non-ready artifact cannot be shared');
        assertSameValue(null, $shares->authorizeFiles($share->id, $fileToken, 'not-ready-files'), 'non-ready artifact closes issued file tokens');
        try {
            $shares->create($previewId, $actor, 1);
            throw new RuntimeException('Non-ready preview acquired a new share');
        } catch (ShareUnavailable) {
        }
    } finally {
        $status->execute(['id' => $previewId, 'status' => 'ready']);
    }
    while (count($shares->active($previewId)) < 50) {
        $shares->create($previewId, $actor, 1);
    }
    try {
        $shares->create($previewId, $actor, 1);
        throw new RuntimeException('Unbounded active share inventory accepted');
    } catch (ShareUnavailable) {
    }
    // A day-long share gets a two-hour idle lease that renews without changing URLs.
    $long = $other;
    $longToken = $shares->createFiles($long['share']);
    $longBase = '/shared-preview-files/' . $long['share']->id . '/' . $longToken . '/';
    for ($viewerNumber = 1; $viewerNumber < 50; ++$viewerNumber) {
        $shares->createFiles($long['share']);
    }
    try {
        $shares->createFiles($long['share']);
        throw new RuntimeException('Unbounded viewer inventory accepted');
    } catch (\Utsuri\PortableCms\Conventional\Preview\ShareRateLimited) {
    }
    $clock->current = $clock->current->modify('+1 hour');
    assertSameValue(true, $shares->renewFiles($long['share'], $longToken), 'real database renews existing lease');
    $clock->current = $clock->current->modify('+1 hour');
    assertSameValue(200, $app->handle(new Request('GET', $longBase))->status, 'renewed lease survives original deadline');
    $clock->current = $clock->current->modify('+1 hour');
    assertSameValue(404, $app->handle(new Request('GET', $longBase, remoteAddress: 'idle-expiry'))->status, 'lease expires exactly after two idle hours');
    assertSameValue(false, $shares->renewFiles($long['share'], $longToken), 'database cannot revive expired file tokens');
    $shares->createFiles($long['share']);
    $remaining = $pdo->prepare('SELECT COUNT(*) FROM cms_preview_share_files WHERE share_id = :id');
    $remaining->execute(['id' => $long['share']->id]);
    assertSameValue(1, (int) $remaining->fetchColumn(), 'minting reclaims expired viewer slots');
    $clock->current = $share->expiresAt;
    assertSameValue(404, $app->handle(new Request('GET', $fileBase, remoteAddress: 'file-share-expiry'))->status, 'share expiry closes isolated addresses');
    assertSameValue(404, $app->handle(new Request('GET', $base . 'artifact/index.html', cookies: $cookies, remoteAddress: 'expired'))->status, 'expiry closes existing cookies exactly at deadline');
    $clock->current = $share->createdAt;
    assertSameValue(303, $app->handle(new Request('POST', $route . '/' . $share->id . '/revoke', $headers, ['_csrf' => $login->session->csrfToken], $auth))->status, 'publisher revokes link through native form');
    assertSameValue(404, $app->handle(new Request('GET', $base . 'artifact/index.html', cookies: $cookies, remoteAddress: 'revoked'))->status, 'revocation closes existing cookies');
    assertSameValue(404, $app->handle(new Request('GET', $base . 'artifact/navigation-rules.json', cookies: $cookies, remoteAddress: 'revoked-rules'))->status, 'revocation also closes rules with the existing capability cookie');
    assertSameValue(404, $app->handle(new Request('GET', $fileBase, remoteAddress: 'file-revocation'))->status, 'revocation closes already issued file addresses');
    assertSameValue(false, $shares->renewFiles($share, $fileToken), 'revocation also blocks renewal');
    $live = $shares->create($previewId, $actor, 24);
    $deletedToken = $shares->createFiles($live['share']);
    $deletedBase = '/shared-preview-files/' . $live['share']->id . '/' . $deletedToken . '/';
    $previews->delete($previewId, $actor);
    assertSameValue(404, $app->handle(new Request('GET', $deletedBase, remoteAddress: 'file-deletion'))->status, 'preview deletion closes every file address');
    assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM cms_preview_share_files')->fetchColumn(), 'deletion cascades through file capabilities');
    assertSameValue(null, $shares->authorize($live['share']->id, $live['token'], 'deleted'), 'preview deletion invalidates all shares');
    assertSameValue([], $shares->active($previewId), 'foreign-key cleanup removes deleted preview links');
    try {
        $shares->create($previewId, $actor, 1);
        throw new RuntimeException('Deleted preview acquired a new share');
    } catch (ShareUnavailable) {
    }
    for ($guess = 0; $guess < 10; ++$guess) {
        assertSameValue(404, $app->handle(new Request('GET', '/shared-previews/' . str_pad(dechex($guess), 32, '0', STR_PAD_LEFT) . '/artifact/', remoteAddress: 'guesser'))->status, 'guessing is denied');
    }
    assertSameValue(429, $app->handle(new Request('GET', $base . 'artifact/', remoteAddress: 'guesser'))->status, 'persistent throttle spans guessed link IDs');
    $audit = $pdo->query("SELECT event_type, metadata_json FROM cms_audit_events WHERE event_type LIKE 'preview.share.%'")->fetchAll(PDO::FETCH_ASSOC);
    $events = array_column($audit, 'event_type');
    foreach (['preview.share.created', 'preview.share.used', 'preview.share.revoked'] as $event) {
        assertTrueValue(in_array($event, $events, true), 'lifecycle audits include ' . $event);
    }
    $encoded = json_encode($audit, JSON_THROW_ON_ERROR);
    assertTrueValue(!str_contains($encoded, $token) && !str_contains($encoded, hash('sha256', $token)), 'audit metadata contains neither secrets nor secret hashes');
    foreach ([$fileToken, $longToken, $deletedToken] as $secret) {
        assertTrueValue(!str_contains($encoded, $secret) && !str_contains($encoded, hash('sha256', $secret)), 'audits contain neither file tokens nor their hashes');
    }
    fwrite(STDOUT, "PASS preview sharing: native management, immutable bytes, scoped capability, expiry, revocation, deletion, traversal, throttles and transactional audit\n");
}
