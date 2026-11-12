<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\ApplicationFactory;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Http\Response;
use Utsuri\PortableCms\Conventional\IdentityApplication;
use Utsuri\PortableCms\Conventional\Preview\MariaDbLivePreviewStore;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;

/** One signed-in browser against the real application, keeping cookies like a user agent. */
final class LivePreviewIntegrationBrowser
{
    /** @var array<string, string> */
    public array $cookies = [];

    public function __construct(private readonly IdentityApplication $app, private readonly string $origin) {}

    /**
     * @param array<string, mixed> $form
     * @param array<string, string> $headers
     * @param array<string, string> $query
     */
    public function request(string $method, string $path, array $form = [], array $headers = [], array $query = [], bool $withCookies = true): Response
    {
        if ($method === 'POST') {
            $headers += ['origin' => $this->origin, 'sec-fetch-site' => 'same-origin'];
        }
        $response = $this->app->handle(new Request($method, $path, $headers, $form, $withCookies ? $this->cookies : [], '127.0.0.1', query: $query));
        foreach ($withCookies ? $response->headers()['set-cookie'] ?? [] : [] as $cookie) {
            [$pair] = explode(';', $cookie, 2);
            [$name, $value] = explode('=', $pair, 2);
            if ($value === '') {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = urldecode($value);
            }
        }
        return $response;
    }

    public function csrf(): string
    {
        return $this->cookies[\Utsuri\PortableCms\Conventional\Identity\IdentityService::CSRF_COOKIE] ?? '';
    }

    /**
     * The values a browser would submit from the record form, without file inputs.
     *
     * @return array<string, mixed>
     */
    public static function formValues(string $html): array
    {
        $document = new DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8">' . $html);
        libxml_clear_errors();
        $xpath = new DOMXPath($document);
        $values = [];
        $add = static function (string $name, string $value) use (&$values): void {
            if (str_ends_with($name, '[]')) {
                $values[substr($name, 0, -2)][] = $value;
            } else {
                $values[$name] = $value;
            }
        };
        foreach ($xpath->query('//form[@data-editorial-form]//*[@name]') ?: [] as $control) {
            if (!$control instanceof DOMElement || $control->hasAttribute('disabled')) {
                continue;
            }
            $name = $control->getAttribute('name');
            $type = strtolower($control->getAttribute('type'));
            if ($control->tagName === 'input' && !in_array($type, ['submit', 'button', 'file', 'image', 'reset'], true)) {
                if (!in_array($type, ['checkbox', 'radio'], true) || $control->hasAttribute('checked')) {
                    $add($name, $control->hasAttribute('value') || !in_array($type, ['checkbox', 'radio'], true) ? $control->getAttribute('value') : 'on');
                }
            } elseif ($control->tagName === 'textarea') {
                $add($name, $control->textContent);
            } elseif ($control->tagName === 'select') {
                $options = $xpath->query('.//option', $control) ?: [];
                $chosen = [];
                foreach ($options as $option) {
                    if ($option instanceof DOMElement && $option->hasAttribute('selected')) {
                        $chosen[] = $option->getAttribute('value');
                    }
                }
                if ($chosen === [] && !$control->hasAttribute('multiple') && ($first = $options->item(0)) instanceof DOMElement) {
                    $chosen[] = $first->getAttribute('value');
                }
                foreach ($chosen as $value) {
                    $add($name, $value);
                }
            }
        }
        return $values;
    }
}

/** The live preview renders unsaved values without saving, and its isolated pages die with their tokens and sessions. */
function verifyLivePreview(PDO $pdo, RuntimeConfiguration $configuration, ProfilePackage $package, string $login, string $password): void
{
    $app = ApplicationFactory::identity($configuration, $package->manifest);
    $browser = new LivePreviewIntegrationBrowser($app, $configuration->origin);
    $browser->request('GET', '/login');
    assertSameValue(303, $browser->request('POST', '/login', ['_csrf' => $browser->csrf(), 'login' => $login, 'password' => $password])->status, 'the test editor signs in');
    $recordPath = '/records/project/10000000000000000000000000000001';
    $page = $browser->request('GET', $recordPath);
    assertSameValue(200, $page->status, 'the record opens');
    foreach (['data-live-preview', 'data-live-toggle', 'data-live-fallback', 'formaction="' . $recordPath . '/live-preview"', 'sandbox="allow-scripts allow-popups allow-popups-to-escape-sandbox"', 'role="separator"'] as $marker) {
        assertTrueValue(str_contains($page->body, $marker), 'the record page offers the live preview: ' . $marker);
    }
    $values = LivePreviewIntegrationBrowser::formValues($page->body);
    $values['title__ca'] = 'Arxiu de la boira, sense desar';
    $revisions = (int) $pdo->query('SELECT COUNT(*) FROM cms_record_revisions')->fetchColumn();
    $audits = (int) $pdo->query('SELECT COUNT(*) FROM cms_audit_events')->fetchColumn();
    $files = static function () use ($configuration): int {
        $count = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($configuration->mediaRoot, FilesystemIterator::SKIP_DOTS)) as $file) {
            $count += $file->isFile() ? 1 : 0;
        }
        return $count;
    };
    $stored = $files();
    $json = ['accept' => 'application/json'];
    $endpoint = $recordPath . '/live-preview';
    $live = [...$values, '_live_page' => 'record', '_live_locale' => 'ca', '_live_sequence' => '7'];

    assertSameValue(403, $browser->request('POST', $endpoint, array_diff_key($live, ['_csrf' => true]), $json)->status, 'a render without the form token is refused');
    assertSameValue(403, $browser->request('POST', $endpoint, $live, ['origin' => 'https://foreign.example', ...$json])->status, 'a render from another origin is refused');
    $rendered = $browser->request('POST', $endpoint, $live, $json);
    assertSameValue(200, $rendered->status, 'unsaved values render');
    $result = json_decode($rendered->body, true, 8, JSON_THROW_ON_ERROR);
    assertSameValue([7, 'record', ['record', 'home'], 'ca'], [$result['sequence'], $result['page'], $result['pages'], $result['locale']], 'the response answers its request with the record page and the choices');
    assertTrueValue(preg_match('#\A/live-previews/([a-f0-9]{64})/ca/projects/[a-z0-9-]+/\?render=([a-f0-9]{32})\z#', $result['url'], $address) === 1, 'the page address carries an unguessable token and the render');
    [, $token, $render] = $address;
    assertSameValue($revisions, (int) $pdo->query('SELECT COUNT(*) FROM cms_record_revisions')->fetchColumn(), 'a live render never saves a revision');
    assertSameValue($audits, (int) $pdo->query('SELECT COUNT(*) FROM cms_audit_events')->fetchColumn(), 'a live render records no editorial event');
    assertSameValue($stored, $files(), 'a live render writes no files');
    assertSameValue(1, (int) $pdo->query("SELECT COUNT(*) FROM cms_live_previews WHERE token_hash = '" . hash('sha256', $token) . "'")->fetchColumn(), 'only the token hash is stored');
    assertSameValue(0, (int) $pdo->query("SELECT COUNT(*) FROM cms_live_previews WHERE token_hash = '" . $token . "' OR document_json LIKE '%" . $token . "%'")->fetchColumn(), 'the token itself is never stored');

    $path = (string) parse_url($result['url'], PHP_URL_PATH);
    $document = $browser->request('GET', $path, query: ['render' => $render], withCookies: false);
    assertSameValue(200, $document->status, 'the page loads without CMS cookies, as the opaque frame requests it');
    assertTrueValue(str_contains($document->body, 'Arxiu de la boira, sense desar'), 'the page shows the unsaved title');
    assertTrueValue(str_contains($document->body, '_cms/live-bridge.js'), 'the engine bridge is added to live pages');
    $policy = $document->headers()['content-security-policy'][0];
    foreach (['sandbox allow-scripts', "frame-ancestors 'self'", "script-src 'self'", "default-src 'none'"] as $directive) {
        assertTrueValue(str_contains($policy, $directive), 'the live page keeps the public policy, framed only by the CMS: ' . $directive);
    }
    assertTrueValue(!str_contains($policy, 'allow-same-origin') && !str_contains($policy, "frame-ancestors 'none'"), 'the live page never shares the CMS origin');
    assertSameValue(['cross-origin', '*', 'no-store', 'noindex, nofollow, noarchive', 'no-referrer'], [
        $document->headers()['cross-origin-resource-policy'][0], $document->headers()['access-control-allow-origin'][0], $document->headers()['cache-control'][0],
        $document->headers()['x-robots-tag'][0], $document->headers()['referrer-policy'][0],
    ], 'the page is readable only by its sandboxed frame, uncached and unindexed');
    $base = '/live-previews/' . $token . '/';
    $stylesheet = $browser->request('GET', $base . 'assets/site.css', withCookies: false);
    assertSameValue([200, 'private, max-age=600'], [$stylesheet->status, $stylesheet->headers()['cache-control'][0]], 'verified profile assets stay cached for the workspace');
    $bridge = $browser->request('GET', $base . '_cms/live-bridge.js', withCookies: false);
    assertTrueValue($bridge->status === 200 && str_contains($bridge->body, 'cms-live'), 'the bridge is served beside the page');
    assertSameValue(['no-store'], $browser->request('GET', $base . 'assets/media-focal.css', withCookies: false)->headers()['cache-control'], 'generated styles follow each render');
    foreach (['../assets/site.css', 'assets/../../records/project', '%2e%2e/assets/site.css', 'assets//site.css', 'artifact.json', 'delivery/apache24.conf', 'assets/missing.css'] as $hostile) {
        assertSameValue(404, $browser->request('GET', $base . $hostile, withCookies: false)->status, 'hostile or unknown live paths fail: ' . $hostile);
    }
    assertSameValue(404, $browser->request('GET', '/live-previews/' . str_repeat('a', 64) . '/ca/', withCookies: false)->status, 'an unknown token fails');
    assertSameValue(404, $browser->request('GET', $path, query: ['render' => str_repeat('b', 32)], withCookies: false)->status, 'an unknown render fails');
    assertSameValue(404, $browser->request('POST', $path, withCookies: false)->status, 'live pages are read-only');
    $elsewhere = $browser->request('GET', '/live-previews/' . $token . '/ca/about/', withCookies: false);
    assertTrueValue($elsewhere->status === 404 && str_contains($elsewhere->body, 'not part of the live preview'), 'pages the render did not draw explain how to choose one');

    $next = json_decode($browser->request('POST', $endpoint, [...$live, '_live_sequence' => '8', '_live_page' => 'home', '_live_token' => $token], $json)->body, true, 8, JSON_THROW_ON_ERROR);
    assertTrueValue(str_starts_with($next['url'], $base . 'ca/?render='), 'the workspace keeps its token, so asset addresses stay the same');
    assertSameValue(200, $browser->request('GET', $path, query: ['render' => $render], withCookies: false)->status, 'the visible page still resolves while the next one loads');
    $invalid = $browser->request('POST', $endpoint, [...$live, 'title__ca' => ''], $json);
    $reason = json_decode($invalid->body, true, 8, JSON_THROW_ON_ERROR);
    assertTrueValue($invalid->status === 422 && str_contains($reason['reason'], 'Title'), 'a value Save would refuse is reported as a plain reason');
    $plain = $browser->request('POST', $endpoint, $live);
    assertSameValue(303, $plain->status, 'without scripts the render opens through a redirect');
    assertTrueValue(str_starts_with($plain->headers()['location'][0], '/live-previews/'), 'the redirect leads to the isolated page');

    $other = new LivePreviewIntegrationBrowser($app, $configuration->origin);
    $other->request('GET', '/login');
    $other->request('POST', '/login', ['_csrf' => $other->csrf(), 'login' => $login, 'password' => $password]);
    $foreign = json_decode($other->request('POST', $endpoint, [...$live, '_csrf' => $other->csrf(), '_live_token' => $token], $json)->body, true, 8, JSON_THROW_ON_ERROR);
    assertTrueValue(!str_contains($foreign['url'], $token), 'a token is never adopted by another session');

    $store = new MariaDbLivePreviewStore($pdo);
    assertTrueValue($store->find(hash('sha256', $token), null, new DateTimeImmutable('+9 minutes')) !== null, 'a render lives for minutes');
    assertSameValue(null, $store->find(hash('sha256', $token), null, new DateTimeImmutable('+11 minutes')), 'a render expires within minutes');
    assertSameValue(303, $browser->request('POST', '/logout', ['_csrf' => $browser->csrf()])->status, 'the editor signs out');
    assertSameValue(404, $browser->request('GET', $path, query: ['render' => $render], withCookies: false)->status, 'signing out ends every live page of that session');
    assertSameValue(404, $browser->request('GET', $base . 'assets/site.css', withCookies: false)->status, 'signing out ends the live assets too');
    assertTrueValue(str_contains($other->request('GET', (string) parse_url($foreign['url'], PHP_URL_PATH), query: ['render' => (string) substr((string) strstr($foreign['url'], 'render='), 7)], withCookies: false)->body, 'sense desar'), 'other sessions keep their own live pages');
    fwrite(STDOUT, "PASS live preview: unsaved render without saving, origin and form token, isolated policy, stable workspace token, hostile paths, expiry and sign-out\n");
}
