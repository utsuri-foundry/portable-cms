<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Compiler;

use Utsuri\PortableCms\Conventional\Http\Html;

/** Reviewed visible-page facts only. There is no editor-authored JSON or property bag. */
final readonly class StructuredData
{
    private function __construct(public string $json) {}

    /** @param list<array{name: string, path: string}> $breadcrumbs */
    public static function page(RouteDiscovery $routes, string $path, string $locale, string $title, string $description, ?string $organizationName, bool $article, array $breadcrumbs): ?self
    {
        if ($routes->context === null) {
            return null;
        }
        self::text($title, 240);
        self::text($description, 500, optional: true);
        if (preg_match('/\A[a-z]{2,3}(?:-[A-Z]{2})?\z/', $locale) !== 1 || count($breadcrumbs) > 8 || !array_is_list($breadcrumbs)) {
            throw new \InvalidArgumentException('Structured page facts are invalid');
        }
        $url = $routes->context->route($routes->canonicalPath($path));
        $graph = [];
        if ($organizationName !== null) {
            self::text($organizationName, 160);
            $graph[] = ['@type' => 'Organization', 'name' => $organizationName, 'url' => $url];
        }
        if ($article) {
            $graph[] = ['@type' => 'Article', 'headline' => $title, 'inLanguage' => $locale, 'url' => $url,
                ...($description === '' ? [] : ['description' => $description])];
        }
        $items = [];
        $seen = [];
        foreach ($breadcrumbs as $breadcrumb) {
            if (count($breadcrumb) !== 2 || array_diff(array_keys($breadcrumb), ['name', 'path']) !== []) {
                throw new \InvalidArgumentException('Structured breadcrumb facts are invalid');
            }
            self::text($breadcrumb['name'], 240);
            $item = $routes->context->route($routes->canonicalPath($breadcrumb['path']));
            if (isset($seen[$item])) {
                throw new \InvalidArgumentException('Structured breadcrumbs contain duplicate destinations');
            }
            $seen[$item] = true;
            $items[] = ['@type' => 'ListItem', 'position' => count($items) + 1, 'name' => $breadcrumb['name'], 'item' => $item];
        }
        if (count($items) > 1) {
            if ($items[count($items) - 1]['item'] !== $url) {
                throw new \InvalidArgumentException('Structured breadcrumbs do not end at the visible page');
            }
            $graph[] = ['@type' => 'BreadcrumbList', 'itemListElement' => $items];
        }
        if ($graph === []) {
            return null;
        }
        $json = json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($json) > 20_000) {
            throw new \InvalidArgumentException('Structured data exceeds its byte budget');
        }
        return new self($json);
    }

    public function scriptHash(): string
    {
        return 'sha256-' . base64_encode(hash('sha256', $this->json, true));
    }

    public function policy(bool $vimeo = false): string
    {
        return VimeoPresentation::policy("default-src 'none'; script-src 'self' '" . $this->scriptHash()
            . "'; style-src 'self'; img-src 'self'; font-src 'self'; media-src 'self'; connect-src 'self'; base-uri 'none'; object-src 'none'; form-action 'self'", $vimeo);
    }

    public function policyMeta(bool $vimeo = false): string
    {
        return '<meta http-equiv="Content-Security-Policy" content="' . Html::attribute($this->policy($vimeo)) . '">';
    }

    public function html(): string
    {
        return '<script type="application/ld+json">' . $this->json . '</script>';
    }

    private static function text(string $value, int $maximum, bool $optional = false): void
    {
        if ((!$optional && trim($value) === '') || mb_strlen($value) > $maximum || !mb_check_encoding($value, 'UTF-8')
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) === 1) {
            throw new \InvalidArgumentException('Structured data text is invalid');
        }
    }
}
