<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Http\OriginPolicy;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Identity\IdentityService;
use Utsuri\PortableCms\Conventional\Identity\MariaDbIdentityRepository;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;
use Utsuri\PortableCms\Conventional\IdentityApplication;
use Utsuri\PortableCms\Conventional\Preview\MariaDbNewestPreviewLinkRepository;
use Utsuri\PortableCms\Conventional\Preview\NewestPreviewHttp;
use Utsuri\PortableCms\Conventional\Preview\NewestPreviewLink;
use Utsuri\PortableCms\Conventional\Preview\NewestPreviewLinkService;
use Utsuri\PortableCms\Conventional\Preview\PreviewService;
use Utsuri\PortableCms\Conventional\Preview\ShareUnavailable;

/** Both supplied previews are real verified builds; this test deletes only $newer. */
function verifyNewestPreviewLinks(PDO $pdo, PreviewService $previews, string $first, string $newer, string $actor, ProfileManifest $profile, RandomSource $random, \Utsuri\PortableCms\Conventional\RuntimeConfiguration $configuration): void
{
    $clock = new PreviewShareIntegrationClock(new DateTimeImmutable('2026-10-30T12:00:00Z'));
    $repository = new MariaDbNewestPreviewLinkRepository($pdo);
    $identities = new MariaDbIdentityRepository($pdo);
    $links = new NewestPreviewLinkService($repository, $identities, $clock, $random, str_repeat('s', 32));
    $origin = 'https://cms.example.test';
    $policy = new OriginPolicy($origin);
    $identity = new IdentityService($identities, $clock, $random, str_repeat('s', 32));
    $http = new NewestPreviewHttp($links, $previews, $policy, $origin);
    $app = new IdentityApplication($identity, $policy, $clock, $random, profile: $profile, previews: $previews, newestPreviews: $http);
    $states = $pdo->query('SELECT id, status, build_kind FROM cms_previews')->fetchAll(PDO::FETCH_ASSOC);
    $status = $pdo->prepare('UPDATE cms_previews SET status = :status, build_kind = :kind WHERE id = :id');
    $set = static function (string $id, string $state, string $kind = 'website') use ($status): void {
        $status->execute(['id' => $id, 'status' => $state, 'kind' => $kind]);
    };
    try {
        $created = $links->create($actor, 720);
        $link = $created['link'];
        $secret = $created['token'];
        $base = '/newest-previews/' . $link->id . '/';
        $bearer = ['authorization' => 'Bearer ' . $secret];
        $cookie = [NewestPreviewHttp::cookieName($link->id) => $secret];
        $stored = $pdo->prepare('SELECT token_hash FROM cms_preview_follow_links WHERE id = :id');
        $stored->execute(['id' => $link->id]);
        assertSameValue(hash('sha256', $secret), $stored->fetchColumn(), 'newest link stores only the secret hash');
        $uses = static function () use ($pdo, $link): int {
            $statement = $pdo->prepare("SELECT COUNT(*) FROM cms_audit_events WHERE event_type = 'preview.follow.used' AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.linkId')) = :id");
            $statement->execute(['id' => $link->id]);
            return (int) $statement->fetchColumn();
        };
        foreach (['queued', 'building', 'needs_attention'] as $state) {
            $set($newer, $state);
            assertSameValue($first, $links->authorize($link->id, $secret, 'resolution')?->previewId, 'skip newer ' . $state . ' builds');
        }
        $set($newer, 'ready', 'holding');
        assertSameValue($first, $links->authorize($link->id, $secret, 'holding')?->previewId, 'ready holding builds never enter a newest website link');
        $set($newer, 'needs_attention');
        assertSameValue(1, $uses(), 'repeated resolution audits only one link/preview pair');
        $redirect = $app->handle(new Request('GET', $base . 'artifact/artifact.json', $bearer));
        $pinned = $base . 'pinned/' . $first . '/artifact/';
        assertSameValue(307, $redirect->status, 'bearer manifest resolves with a same-origin redirect');
        assertSameValue([$pinned . 'artifact.json'], $redirect->headers()['location'], 'redirect pins the resolved preview without a secret');
        assertSameValue(['no-store'], $redirect->headers()['cache-control'], 'redirect is never cacheable');
        $manifest = $app->handle(new Request('GET', $pinned . 'artifact.json', $bearer));
        assertSameValue($previews->artifact($first, 'artifact.json')?->contents, $manifest->body, 'pinned manifest is byte-identical to the verified build');
        $firstViewer = $app->handle(new Request('GET', $base, cookies: $cookie));
        assertSameValue(1, preg_match('#src="(/newest-preview-files/[a-f0-9]{32}/([a-f0-9]{64})/)"#', $firstViewer->body, $firstFile), 'browser mints an independent file address');
        $firstFiles = $firstFile[1];
        $firstFileToken = $firstFile[2];
        assertTrueValue($firstFileToken !== $secret, 'file address never exposes the parent secret');
        $storedFile = $pdo->prepare('SELECT token_hash, preview_id, expires_at FROM cms_preview_follow_files WHERE token_hash = :hash');
        $storedFile->execute(['hash' => hash('sha256', $firstFileToken)]);
        $fileRow = $storedFile->fetch(PDO::FETCH_ASSOC);
        assertSameValue($first, $fileRow['preview_id'], 'file capability stores its exact resolved preview');
        assertSameValue(hash('sha256', $firstFileToken), $fileRow['token_hash'], 'only the file token hash is persisted');
        assertSameValue($clock->now()->modify('+2 hours')->format('Y-m-d H:i:s.u'), $fileRow['expires_at'], 'browser file address lives at most two idle hours');
        $set($newer, 'ready');
        $updated = $app->handle(new Request('GET', $base . 'artifact/artifact.json', $bearer));
        $newMount = $base . 'pinned/' . $newer . '/artifact/';
        assertSameValue([$newMount . 'artifact.json'], $updated->headers()['location'], 'next manifest fetch follows a newer ready preview');
        foreach (json_decode($manifest->body, true, flags: JSON_THROW_ON_ERROR)['files'] as $file) {
            $response = $app->handle(new Request('GET', $pinned . $file['path'], $bearer));
            assertSameValue(200, $response->status, 'all original manifest files remain on the first build after a newer arrival: ' . $file['path']);
            assertSameValue(['no-store'], $response->headers()['cache-control'], 'artifact remains private');
            assertSameValue(['noindex, nofollow, noarchive'], $response->headers()['x-robots-tag'], 'artifact remains unindexed');
        }
        assertSameValue($manifest->body, $app->handle(new Request('GET', $pinned . 'artifact.json', $bearer))->body, 'in-flight pin retains the first manifest');
        assertSameValue(2, $uses(), 'whole builds create one use per resolved preview, never one per file');
        foreach (['index.html', 'artifact.json', 'navigation-rules.json'] as $path) {
            $file = $app->handle(new Request('GET', $firstFiles . $path));
            assertSameValue(200, $file->status, 'opaque file requests need no cookies');
            assertSameValue($previews->artifact($first, $path)?->contents, $file->body, 'newer arrival never changes issued file bytes');
            $csp = $file->headers()['content-security-policy'][0];
            assertTrueValue(str_contains($csp, "script-src 'self'") && str_contains($csp, "frame-ancestors 'self'") && str_contains($csp, '; sandbox allow-scripts'), 'browser files use public scripts inside an opaque CMS-only frame');
            assertTrueValue(!str_contains($csp, 'allow-same-origin') && !str_contains($csp, 'allow-popups') && !str_contains($csp, 'player.vimeo.com'), 'ordinary builds gain neither CMS origin, escape nor Vimeo');
            assertSameValue(['no-store'], $file->headers()['cache-control'], 'browser files are never cached');
            assertSameValue(['no-referrer'], $file->headers()['referrer-policy'], 'file token never becomes a referrer');
            assertSameValue(['noindex, nofollow, noarchive'], $file->headers()['x-robots-tag'], 'browser files are never indexed');
            assertTrueValue(!$file->hasHeader('set-cookie'), 'file delivery opens no CMS session');
        }
        assertSameValue(2, $uses(), 'browser file reads never add use audits');
        $loggedLinks = new NewestPreviewLinkService($repository, $identities, new \Utsuri\PortableCms\Conventional\Identity\SystemClock(), $random, str_repeat('s', 32));
        $logged = $loggedLinks->create($actor, 1);
        $loggedResolution = $loggedLinks->authorize($logged['link']->id, $logged['token'], 'diagnostic');
        assertTrueValue($loggedResolution !== null, 'diagnostic fixture resolves an actual preview');
        $loggedToken = $loggedLinks->createFiles($loggedResolution);
        $storedFile->execute(['hash' => hash('sha256', $loggedToken)]);
        assertSameValue($loggedResolution->link->expiresAt->format('Y-m-d H:i:s.u'), $storedFile->fetch(PDO::FETCH_ASSOC)['expires_at'], 'a short-lived parent caps its browser file lease');
        assertSameValue(null, $repository->files($logged['link']->id, hash('sha256', $loggedToken), $loggedResolution->link->expiresAt), 'browser address closes exactly with a short-lived parent');
        $originalManifest = $pdo->prepare('SELECT manifest_json FROM cms_previews WHERE id = :id');
        $originalManifest->execute(['id' => $loggedResolution->previewId]);
        $savedManifest = $originalManifest->fetchColumn();
        $setManifest = $pdo->prepare('UPDATE cms_previews SET manifest_json = :manifest WHERE id = :id');
        try {
            $setManifest->execute(['id' => $loggedResolution->previewId, 'manifest' => '[]']);
            $installed = \Utsuri\PortableCms\Conventional\ApplicationFactory::identity($configuration);
            $failure = $installed->handle(new Request('GET', '/newest-preview-files/' . $logged['link']->id . '/' . $loggedToken . '/'));
            assertSameValue(500, $failure->status, 'actual newest file request reaches the private error boundary');
            $diagnostics = $configuration->errorLog === null ? '' : file_get_contents($configuration->errorLog);
            assertTrueValue(is_string($diagnostics) && str_contains($diagnostics, 'correlation=' . $failure->headers()['x-correlation-id'][0]), 'the actual request error is logged with its correlation only');
            foreach ([$logged['token'], $loggedToken] as $credential) {
                assertTrueValue(!str_contains($diagnostics, $credential) && !str_contains($diagnostics, hash('sha256', $credential)), 'newest diagnostics contain neither secrets nor hashes');
                assertTrueValue(!str_contains(json_encode([$failure->body, $failure->headers()], JSON_THROW_ON_ERROR), $credential), 'error response never echoes a private address or secret');
            }
        } finally {
            $setManifest->execute(['id' => $loggedResolution->previewId, 'manifest' => $savedManifest]);
        }
        $html = $app->handle(new Request('GET', $pinned . 'index.html', $bearer));
        assertTrueValue(str_contains($html->headers()['content-security-policy'][0], "frame-ancestors 'self'") && str_contains($html->headers()['content-security-policy'][0], "script-src 'none'"), 'pinned documents retain the fixed-share framing and script policy');
        assertSameValue(['no-referrer'], $manifest->headers()['referrer-policy'], 'manifest never discloses a referrer');
        $sessions = (int) $pdo->query('SELECT COUNT(*) FROM cms_sessions')->fetchColumn();
        $opened = $app->handle(new Request('POST', $base . 'open', ['origin' => $origin, 'sec-fetch-site' => 'same-origin'], ['token' => $secret]));
        assertSameValue(303, $opened->status, 'fragment exchange works without a CMS account');
        foreach (['Secure', 'HttpOnly', 'SameSite=Strict'] as $flag) {
            assertTrueValue(str_contains($opened->headers()['set-cookie'][0], $flag), 'isolated capability cookie keeps ' . $flag);
        }
        assertSameValue($sessions, (int) $pdo->query('SELECT COUNT(*) FROM cms_sessions')->fetchColumn(), 'link use and exchange never create a CMS session');
        $viewer = $app->handle(new Request('GET', $base, cookies: $cookie));
        assertTrueValue(str_contains($viewer->body, '/assets/preview-vimeo.js'), 'newest viewers share the sibling player runtime');
        assertTrueValue(str_contains($viewer->headers()['content-security-policy'][0], "frame-src 'self' https://player.vimeo.com"), 'newest shell permits only its page and Vimeo provider');
        assertTrueValue(str_contains($viewer->body, 'data-share-notice') && str_contains($viewer->body, 'sandbox="allow-scripts"'), 'viewer keeps the notice outside an opaque scripted frame');
        assertSameValue(1, preg_match('#src="(/newest-preview-files/[a-f0-9]{32}/([a-f0-9]{64})/)"#', $viewer->body, $newFile), 'reload mints a new file address');
        $newFiles = $newFile[1];
        assertSameValue($newer, $links->authorizeFiles($link->id, $newFile[2], 'reloaded')?->previewId, 'reload follows the newer preview');
        assertTrueValue(str_contains($viewer->headers()['content-security-policy'][0], "frame-ancestors 'none'"), 'viewer cannot be embedded');
        assertSameValue(403, $app->handle(new Request('POST', $base . 'open', ['origin' => 'https://foreign.example'], ['token' => $secret]))->status, 'foreign fragment exchanges fail');
        $other = $links->create($actor, 24);
        assertSameValue(404, $app->handle(new Request('GET', str_replace($link->id, $other['link']->id, $firstFiles), remoteAddress: 'file-other-link'))->status, 'file capability cannot move to another link');
        assertSameValue(404, $app->handle(new Request('GET', str_replace($firstFileToken, $secret, $firstFiles), remoteAddress: 'file-wrong-purpose'))->status, 'bearer secret cannot substitute for a browser file token');
        assertSameValue(404, $app->handle(new Request('GET', $base . 'artifact/artifact.json', ['authorization' => 'Bearer ' . $firstFileToken], remoteAddress: 'file-as-bearer'))->status, 'file token cannot authenticate a tool download');
        $renewHeaders = ['origin' => $origin, 'sec-fetch-site' => 'same-origin'];
        assertSameValue(404, $app->handle(new Request('POST', $base . 'renew', $renewHeaders, ['token' => $firstFileToken], remoteAddress: 'no-parent-cookie'))->status, 'renewal requires the parent cookie');
        assertSameValue(403, $app->handle(new Request('POST', $base . 'renew', ['origin' => 'null', 'sec-fetch-site' => 'cross-site'], ['token' => $firstFileToken], $cookie))->status, 'opaque frame cannot renew its own address');
        assertSameValue(404, $app->handle(new Request('POST', $base . 'renew', [...$renewHeaders, ...$bearer], ['token' => $firstFileToken], $cookie))->status, 'bearer requests gain no renewal operation');
        foreach (['../artifact.json', '%2e%2e/artifact.json', '/index.html', 'missing.html', '../../records'] as $path) {
            assertSameValue(404, $app->handle(new Request('GET', $firstFiles . $path, remoteAddress: 'file-path'))->status, 'file address refuses undeclared or hostile paths');
        }
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            assertSameValue(404, $app->handle(new Request($method, $firstFiles . 'index.html'))->status, 'browser file address is read-only');
        }
        $clock->current = $clock->current->modify('+1 hour');
        assertSameValue(204, $app->handle(new Request('POST', $base . 'renew', $renewHeaders, ['token' => $firstFileToken], $cookie))->status, 'viewer renews the existing file address');
        assertSameValue(2, $uses(), 'renewal does not resolve or audit the newer preview');
        $clock->current = $clock->current->modify('+1 hour');
        assertSameValue(200, $app->handle(new Request('GET', $firstFiles))->status, 'renewed address survives its original deadline');
        assertSameValue($first, $links->authorizeFiles($link->id, $firstFileToken, 'still-pinned')?->previewId, 'renewal stays on the original preview');
        $clock->current = $clock->current->modify('+1 hour');
        assertSameValue(404, $app->handle(new Request('GET', $firstFiles, remoteAddress: 'idle-file-expiry'))->status, 'idle file lease closes at its exact deadline');
        assertSameValue(404, $app->handle(new Request('POST', $base . 'renew', $renewHeaders, ['token' => $firstFileToken], $cookie, 'expired-renewal'))->status, 'expired file token cannot be revived');
        $clock->current = $link->createdAt;
        $boundedResolution = $links->authorize($other['link']->id, $other['token'], 'bounded-viewers');
        assertTrueValue($boundedResolution !== null, 'separate parent resolves for bounded viewer inventory');
        for ($viewerNumber = 0; $viewerNumber < 50; ++$viewerNumber) {
            $links->createFiles($boundedResolution);
        }
        try {
            $links->createFiles($boundedResolution);
            throw new RuntimeException('Unbounded newest viewer inventory accepted');
        } catch (\Utsuri\PortableCms\Conventional\Preview\ShareRateLimited) {
        }
        $clock->current = $clock->current->modify('+3 hours');
        $links->createFiles($boundedResolution);
        $countFiles = $pdo->prepare('SELECT COUNT(*) FROM cms_preview_follow_files WHERE link_id = :id');
        $countFiles->execute(['id' => $other['link']->id]);
        assertSameValue(1, (int) $countFiles->fetchColumn(), 'minting reclaims expired viewer slots');
        $clock->current = $link->createdAt;
        assertSameValue(404, $app->handle(new Request('GET', '/newest-previews/' . $other['link']->id . '/artifact/artifact.json', $bearer, remoteAddress: 'cross-link'))->status, 'a bearer secret belongs only to its own link');
        assertSameValue(404, $app->handle(new Request('GET', $base . 'artifact/artifact.json', ['authorization' => 'Basic wrong'], cookies: $cookie, remoteAddress: 'bad-header'))->status, 'bad explicit header cannot fall back to a valid cookie');
        foreach (['artifact/../artifact.json', 'artifact/%2e%2e/artifact.json', 'artifact//index.html', 'admin', 'artifact/../../records/article'] as $path) {
            assertSameValue(404, $app->handle(new Request('GET', $base . $path, $bearer, remoteAddress: 'paths'))->status, 'hostile path rejected: ' . $path);
        }
        assertSameValue(404, $app->handle(new Request('GET', $base . 'pinned/' . str_repeat('e', 32) . '/artifact/artifact.json', $bearer, remoteAddress: 'unknown-pin'))->status, 'unresolved pins grant no access');
        $unresolved = array_values(array_filter($states, static fn(array $state): bool => $state['id'] !== $first && $state['id'] !== $newer && $state['status'] === 'ready' && $state['build_kind'] === 'website'))[0];
        assertSameValue(404, $app->handle(new Request('GET', $base . 'pinned/' . $unresolved['id'] . '/artifact/artifact.json', $bearer, remoteAddress: 'other-ready-pin'))->status, 'a valid secret cannot select another existing ready preview without a resolution');
        assertSameValue(303, $app->handle(new Request('GET', '/previews', $bearer, cookies: $cookie))->status, 'bearer and cookie cannot authenticate administration');
        assertSameValue(403, $app->handle(new Request('POST', '/previews/' . $first . '/delete', [...$bearer, 'origin' => $origin], cookies: $cookie))->status, 'capability grants no write authority');
        foreach (['POST', 'PUT', 'DELETE'] as $method) {
            assertSameValue(404, $app->handle(new Request($method, $pinned . 'artifact.json', $bearer))->status, 'artifact routes never write');
        }
        $clock->current = $link->expiresAt;
        assertSameValue(404, $app->handle(new Request('GET', $newFiles, remoteAddress: 'parent-file-expiry'))->status, 'parent expiry closes browser addresses');
        foreach ([$base . 'artifact/artifact.json', $pinned . 'artifact.json'] as $path) {
            assertSameValue(404, $app->handle(new Request('GET', $path, $bearer, remoteAddress: 'expiry'))->status, 'expiry immediately closes resolving and pinned addresses');
        }
        $clock->current = $link->createdAt;
        $previews->delete($newer, $actor);
        assertSameValue(404, $app->handle(new Request('GET', $newFiles, remoteAddress: 'deleted-browser-preview'))->status, 'deleted preview closes its browser address');
        $remainingFiles = $pdo->prepare('SELECT COUNT(*) FROM cms_preview_follow_files WHERE preview_id = :preview');
        $remainingFiles->execute(['preview' => $newer]);
        assertSameValue(0, (int) $remainingFiles->fetchColumn(), 'preview deletion cascades through resolved browser files');
        assertSameValue(404, $app->handle(new Request('GET', $newMount . 'artifact.json', $bearer, remoteAddress: 'deleted'))->status, 'deleted preview pin stops working');
        assertSameValue([$pinned . 'artifact.json'], $app->handle(new Request('GET', $base . 'artifact/artifact.json', $bearer))->headers()['location'], 'deleting newest falls back to next newest ready build');
        foreach ($states as $state) {
            $set($state['id'], 'needs_attention', $state['build_kind']);
        }
        $absent = $app->handle(new Request('GET', $base . 'artifact/artifact.json', $bearer, remoteAddress: 'no-ready'));
        $invalid = $app->handle(new Request('GET', '/newest-previews/' . str_repeat('f', 32) . '/artifact/artifact.json', $bearer, remoteAddress: 'invalid'));
        assertSameValue(404, $absent->status, 'no ready preview is unavailable');
        assertSameValue($invalid->body, $absent->body, 'no-ready and invalid links expose the same safe response');
        foreach ($states as $state) {
            $set($state['id'], $state['status'], $state['build_kind']);
        }
        $links->revoke($link->id, $actor);
        assertSameValue(404, $app->handle(new Request('GET', $firstFiles, remoteAddress: 'revoked-browser-files'))->status, 'revocation closes original browser addresses');
        assertSameValue(404, $app->handle(new Request('POST', $base . 'renew', $renewHeaders, ['token' => $firstFileToken], $cookie, 'revoked-browser-renew'))->status, 'revocation closes renewal too');
        foreach ([$base . 'artifact/artifact.json', $pinned . 'artifact.json'] as $path) {
            assertSameValue(404, $app->handle(new Request('GET', $path, $bearer, remoteAddress: 'revocation'))->status, 'revocation closes current and pinned addresses');
        }
        for ($guess = 0; $guess < 10; ++$guess) {
            assertSameValue(404, $app->handle(new Request('GET', '/newest-previews/' . str_pad(dechex($guess), 32, '0', STR_PAD_LEFT) . '/artifact/artifact.json', ['authorization' => 'Bearer ' . str_repeat('e', 64)], remoteAddress: 'bearer-guesser'))->status, 'bearer guesses fail');
        }
        assertSameValue(429, $app->handle(new Request('GET', $base . 'artifact/artifact.json', $bearer, remoteAddress: 'bearer-guesser'))->status, 'bearer guessing is persistently rate limited across link IDs');
        verifyNewestPreviewManagement($pdo, $app, $identity, $repository, $links, $actor, $clock, $random, $origin);
        $audit = $pdo->query("SELECT event_type, metadata_json FROM cms_audit_events WHERE event_type LIKE 'preview.follow.%'")->fetchAll(PDO::FETCH_ASSOC);
        foreach (['preview.follow.created', 'preview.follow.used', 'preview.follow.revoked'] as $event) {
            assertTrueValue(in_array($event, array_column($audit, 'event_type'), true), 'lifecycle audit includes ' . $event);
        }
        $encoded = json_encode($audit, JSON_THROW_ON_ERROR);
        assertTrueValue(!str_contains($encoded, $secret) && !str_contains($encoded, hash('sha256', $secret)), 'audit metadata excludes raw secrets and hashes');
        foreach ([$firstFileToken, $newFile[2], $loggedToken] as $token) {
            assertTrueValue(!str_contains($encoded, $token) && !str_contains($encoded, hash('sha256', $token)), 'audits exclude browser file tokens and hashes');
        }
        fwrite(STDOUT, "PASS newest preview links: native management, newest ready website resolution, bearer pinned downloads, concurrent arrival, one use per build, expiry/revocation/deletion, guessing, traversal and private headers.\n");
    } finally {
        foreach ($states as $state) {
            $set($state['id'], $state['status'], $state['build_kind']);
        }
    }
}

function verifyNewestPreviewManagement(PDO $pdo, IdentityApplication $app, IdentityService $identity, MariaDbNewestPreviewLinkRepository $repository, NewestPreviewLinkService $links, string $actor, PreviewShareIntegrationClock $clock, RandomSource $random, string $origin): void
{
    $login = $identity->login($identity->openSession(null, null), 'sandbox@example.test', 'sandbox integration password', 'newest-manager');
    assertTrueValue($login->authenticated, 'publisher signs in');
    $csrf = $login->session->csrfToken;
    $cookies = [IdentityService::SESSION_COOKIE => $login->session->sessionToken, IdentityService::CSRF_COOKIE => $csrf];
    $headers = ['origin' => $origin, 'sec-fetch-site' => 'same-origin'];
    $route = '/previews/shares/newest';
    assertSameValue(403, $app->handle(new Request('POST', $route, $headers, ['expiry_hours' => '24'], $cookies))->status, 'newest link creation requires CSRF');
    assertSameValue(422, $app->handle(new Request('POST', $route, $headers, ['_csrf' => $csrf, 'expiry_hours' => '721'], $cookies))->status, 'native expiry is bounded to thirty days');
    $created = $app->handle(new Request('POST', $route, $headers, ['_csrf' => $csrf, 'expiry_hours' => '720'], $cookies));
    assertSameValue(200, $created->status, 'publisher creates newest link natively from the list');
    assertTrueValue(str_contains($created->body, 'id="newest-share-link"') && str_contains($created->body, 'including drafts') && str_contains($created->body, 'id="newest-share-manifest"'), 'creation shows the once-only link, tool address and draft warning');
    $listed = $app->handle(new Request('GET', '/previews', cookies: $cookies));
    assertTrueValue(str_contains($listed->body, 'id="newest-preview-links"') && !str_contains($listed->body, 'id="newest-share-link"'), 'active links stay on the list without recovering their secrets');
    $active = $links->active()[0];
    assertSameValue(303, $app->handle(new Request('POST', $route . '/' . $active->id . '/revoke', $headers, ['_csrf' => $csrf], $cookies))->status, 'publisher revokes natively');
    $audit = (string) $pdo->query('SELECT id FROM cms_audit_events LIMIT 1')->fetchColumn();
    $rollback = new NewestPreviewLink(bin2hex($random->bytes(16)), $clock->now(), $clock->now()->modify('+1 hour'));
    try {
        $repository->create($rollback, str_repeat('a', 64), $actor, $audit);
        throw new RuntimeException('Duplicate audit committed');
    } catch (PDOException) {
    }
    assertTrueValue(!in_array($rollback->id, array_map(static fn($link): string => $link->id, $links->active()), true), 'audit failure rolls back creation');
    $protected = $links->create($actor, 1);
    try {
        $repository->resolve($protected['link']->id, hash('sha256', $protected['token']), null, $audit, $clock->now());
        throw new RuntimeException('Duplicate use audit committed');
    } catch (PDOException) {
    }
    $pins = $pdo->prepare('SELECT COUNT(*) FROM cms_preview_follow_resolutions WHERE link_id = :id');
    $pins->execute(['id' => $protected['link']->id]);
    assertSameValue(0, (int) $pins->fetchColumn(), 'audit failure rolls back a new pin');
    try {
        $repository->revoke($protected['link']->id, $actor, $audit, $clock->now());
        throw new RuntimeException('Duplicate revoke audit committed');
    } catch (PDOException) {
    }
    assertSameValue($protected['link']->id, $links->authorize($protected['link']->id, $protected['token'], 'rollback-use')?->link->id, 'audit failure rolls back revocation');
    $editorId = bin2hex($random->bytes(16));
    $pdo->prepare("INSERT INTO cms_users (id, login_name, display_name, password_hash, role, status, created_at, password_changed_at) VALUES (:id, 'newest-editor@example.test', 'Fictional editor', :hash, 'editor', 'active', :created, :changed)")
        ->execute(['id' => $editorId, 'hash' => password_hash('fictional editor password', PASSWORD_DEFAULT), 'created' => $clock->now()->format('Y-m-d H:i:s.u'), 'changed' => $clock->now()->format('Y-m-d H:i:s.u')]);
    $editor = $identity->login($identity->openSession(null, null), 'newest-editor@example.test', 'fictional editor password', 'newest-editor');
    assertTrueValue($editor->authenticated, 'ordinary editor signs in');
    $editorCookies = [IdentityService::SESSION_COOKIE => $editor->session->sessionToken, IdentityService::CSRF_COOKIE => $editor->session->csrfToken];
    assertSameValue(403, $app->handle(new Request('POST', $route, $headers, ['_csrf' => $editor->session->csrfToken, 'expiry_hours' => '24'], $editorCookies))->status, 'ordinary editors have no publication capability');
    assertTrueValue(!str_contains($app->handle(new Request('GET', '/previews', cookies: $editorCookies))->body, 'id="newest-preview-links"'), 'ordinary editor list omits capability controls');
    foreach ([static fn() => $links->create($editorId, 1), static fn() => $links->revoke($protected['link']->id, $editorId)] as $mutation) {
        try {
            $mutation();
            throw new RuntimeException('Repository accepted an editor without publication rights');
        } catch (ShareUnavailable) {
        }
    }
    try {
        $links->create(str_repeat('f', 32), 1);
        throw new RuntimeException('Missing publication actor mutated links');
    } catch (ShareUnavailable) {
    }
    while (count($links->active()) < 50) {
        $links->create($actor, 1);
    }
    try {
        $links->create($actor, 1);
        throw new RuntimeException('Unbounded active newest link inventory accepted');
    } catch (ShareUnavailable) {
    }
}
