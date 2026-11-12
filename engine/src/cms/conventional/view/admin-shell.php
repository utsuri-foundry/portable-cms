<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\NavigationDefinition;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\RecordTypeDefinition;
use Utsuri\PortableCms\Conventional\Content\WebsiteIdentity;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\InterfaceLanguage;
use Utsuri\PortableCms\Conventional\I18n\Translator;

final readonly class TrustedHtml
{
    private function __construct(public string $value) {}

    public static function fromEngineMarkup(string $value): self
    {
        return new self($value);
    }
}

/** A per-browser presentation preference. It never changes content or authority. */
enum ThemePreference: string
{
    case System = 'system';
    case Light = 'light';
    case Dark = 'dark';

    public const COOKIE = '__Host-cms-theme';

    public static function fromCookie(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::System;
    }

    public function label(?Translator $translator = null): string
    {
        return ($translator ?? Translator::english())->text('shell.theme.' . $this->value);
    }
}

/** Request-scoped shell facts supplied by the authenticated application boundary. */
final readonly class AdminChrome
{
    /**
     * @param array<string, int> $counts Record totals by type ID.
     * @param list<string> $unavailable System destinations that cannot be served here.
     */
    public function __construct(
        public string $csrfToken,
        public string $displayName,
        public ThemePreference $theme = ThemePreference::System,
        public string $returnPath = '/',
        public array $counts = [],
        public ?\DateTimeImmutable $now = null,
        public array $unavailable = [],
        private ?Translator $translator = null,
        public ?string $interfaceLanguage = null,
        public string $role = 'administrator',
        public bool $accountsAvailable = false,
        public ?WebsiteIdentity $websiteIdentity = null,
        public ?\Utsuri\PortableCms\Conventional\Media\AdministrationLogos $logos = null,
    ) {}

    public function translator(): Translator
    {
        return $this->translator ?? Translator::english();
    }

    /** Only a bounded same-origin path may be used as a post-preference destination. */
    public static function returnPath(string $path): string
    {
        return preg_match('#\A/(?![/\\\\])[A-Za-z0-9/_.-]{0,300}\z#', $path) === 1 && !str_contains($path, '..') ? $path : '/';
    }
}

final class AdminShell
{
    public const STYLESHEET_VERSION = '20';
    public const GROUP_STYLESHEET_VERSION = '3';
    public const SCRIPT_VERSION = '4';

    public static function translator(?AdminChrome $chrome): Translator
    {
        return $chrome?->translator() ?? Translator::english();
    }

    public static function application(
        string $title,
        TrustedHtml $body,
        ?ProfileManifest $profile,
        string $currentNavigationId,
        string $measure = 'wide',
        ?AdminChrome $chrome = null,
    ): string {
        $t = self::translator($chrome);
        $content = '<a class="admin-skip-link" href="#main-content">' . $t->html('shell.skip_link') . '</a>'
            . '<div class="admin-shell" data-admin-shell>'
            . '<header class="admin-topbar">' . self::brand($profile, $chrome)
            . '<button type="button" class="admin-button--small admin-menu-button" popovertarget="admin-menu">' . $t->html('shell.menu') . '</button></header>'
            . '<aside class="admin-sidebar" id="admin-menu" popover>' . self::brand($profile, $chrome)
            . self::navigation($profile, $currentNavigationId, $chrome, $t)
            . self::footer($currentNavigationId, $chrome, $t, $profile) . '</aside>'
            . '<main class="admin-main" id="main-content" tabindex="-1">'
            . '<div class="admin-page admin-page--' . Html::attribute($measure) . '">'
            . $body->value . '</div></main></div>'
            . '<script type="module" data-admin-enhancement="shell" src="' . Html::url('/assets/admin-shell.js?v=' . self::SCRIPT_VERSION) . '"></script>';
        // Record editors also style their group fields; a stylesheet in the body would pause parsing before the form.
        return self::document($title, 'admin-application', $content, $t->locale(), $measure === 'editor' ? '/assets/groups.css?v=' . self::GROUP_STYLESHEET_VERSION : null);
    }

    public static function focused(string $title, TrustedHtml $body, ?Translator $translator = null): string
    {
        $t = $translator ?? Translator::english();
        $content = '<a class="admin-skip-link" href="#main-content">' . $t->html('shell.skip_link') . '</a>'
            . '<main class="admin-auth__main" id="main-content" tabindex="-1">'
            . '<div class="admin-auth__card">' . $body->value . '</div></main>';
        return self::document($title, 'admin-auth', $content, $t->locale());
    }

    /** Applies a validated theme to an engine document; every other body is returned unchanged. */
    public static function withTheme(string $html, ThemePreference $theme): string
    {
        if ($theme === ThemePreference::System || preg_match('/\A<!doctype html><html lang="[a-z]{2}">/', $html, $prefix) !== 1) {
            return $html;
        }
        return substr($prefix[0], 0, -1) . ' data-theme="' . $theme->value . '">' . substr($html, strlen($prefix[0]));
    }

    /** @param list<array{0: string, 1: ?string}> $trail Label and optional internal link. */
    public static function breadcrumbs(array $trail, ?Translator $translator = null): string
    {
        $items = '';
        foreach ($trail as [$label, $href]) {
            $items .= '<li>' . ($href === null
                ? '<span aria-current="page">' . Html::text($label) . '</span>'
                : '<a href="' . Html::url($href) . '">' . Html::text($label) . '</a>') . '</li>';
        }
        return '<nav class="admin-breadcrumbs" aria-label="' . ($translator ?? Translator::english())->html('shell.breadcrumb') . '"><ol>' . $items . '</ol></nav>';
    }

    /** Page title block. $intro and $actions are trusted engine markup. */
    public static function header(string $title, string $intro = '', string $actions = ''): string
    {
        return '<header class="admin-page-header"><div class="admin-page-header__copy"><h1>' . Html::text($title) . '</h1>'
            . $intro . '</div>' . $actions . '</header>';
    }

    /** Human text for a workflow or job state; unknown states keep a readable diagnostic form. */
    public static function stateLabel(string $state, ?Translator $translator = null): string
    {
        $t = $translator ?? Translator::english();
        if ($state === 'published_changes') {
            return $t->text('state.published_changes');
        }
        $modifier = preg_match('/\A[a-z_]{1,40}\z/', $state) === 1 ? $state : 'unknown';
        return $t->has('state.' . $modifier) ? $t->text('state.' . $modifier) : ucfirst(str_replace('_', ' ', $modifier));
    }

    /** A workflow or job state as text plus a stable modifier; color never carries it alone. */
    public static function status(string $state, ?string $label = null, ?Translator $translator = null): string
    {
        $modifier = preg_match('/\A[a-z_]{1,40}\z/', $state) === 1 ? $state : 'unknown';
        return '<span class="admin-status admin-status--' . $modifier . '">'
            . Html::text($label ?? self::stateLabel($state, $translator)) . '</span>';
    }

    /** Readable relative day with the exact instant preserved for assistive technology. */
    public static function time(\DateTimeImmutable $instant, ?\DateTimeImmutable $now, ?Translator $translator = null): string
    {
        $t = $translator ?? Translator::english();
        $zone = $now?->getTimezone() ?? $instant->getTimezone();
        $text = $t->date($instant, 'format.date_time', $zone);
        if ($now !== null) {
            $day = $instant->setTimezone($zone)->format('Y-m-d');
            $text = match ($day) {
                $now->format('Y-m-d') => $t->text('time.today', ['time' => $t->date($instant, 'format.time', $zone)]),
                $now->modify('-1 day')->format('Y-m-d') => $t->text('time.yesterday', ['time' => $t->date($instant, 'format.time', $zone)]),
                default => $t->date($instant, $instant->format('Y') === $now->format('Y') ? 'format.day_month_time' : 'format.date', $zone),
            };
        }
        return '<time datetime="' . Html::attribute($instant->format(DATE_ATOM)) . '" title="'
            . Html::attribute($instant->format('Y-m-d H:i T')) . '">' . Html::text($text) . '</time>';
    }

    /** Engine-owned presentation of an installation identifier; never profile markup. */
    public static function installationName(?ProfileManifest $profile, ?WebsiteIdentity $identity = null): string
    {
        if ($profile === null) {
            return 'Portable CMS';
        }
        return $identity->name ?? WebsiteIdentity::defaults($profile->id)->name;
    }

    public static function monogram(string $name): string
    {
        $initials = '';
        foreach (preg_split('/[\s<>]+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $initials .= mb_strtoupper(mb_substr($word, 0, 1));
        }
        return mb_substr($initials === '' ? 'P' : $initials, 0, 2);
    }

    public static function brand(?ProfileManifest $profile, ?AdminChrome $chrome = null): string
    {
        $name = self::installationName($profile, $chrome?->websiteIdentity);
        $t = self::translator($chrome);
        $logos = $profile === null ? null : $chrome?->logos;
        if ($logos !== null && !$logos->icon) {
            return '<a class="admin-brand admin-brand--logo" href="' . Html::url('/') . '">' . AdministrationMark::render($name, $logos) . '</a>';
        }
        return '<a class="admin-brand" href="' . Html::url('/') . '">' . AdministrationMark::render($name, $logos)
            . '<span class="admin-brand__text"><span class="admin-brand__name">' . Html::text($name) . '</span>'
            . '<span class="admin-brand__meta">' . ($profile === null ? 'Portable CMS' : $t->html('installation.site_version', ['version' => $profile->version])) . '</span></span></a>';
    }

    private static function document(string $title, string $bodyClass, string $content, string $language, ?string $stylesheet = null): string
    {
        return '<!doctype html><html lang="' . Html::attribute($language) . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
            . '<meta name="color-scheme" content="light dark">'
            . '<title>' . Html::text($title) . '</title>'
            . ($bodyClass === 'admin-application' ? '<script src="/assets/admin-start.js?v=1"></script>' : '')
            . '<link rel="stylesheet" href="'
            . Html::url('/assets/admin.css?v=' . self::STYLESHEET_VERSION) . '">'
            . ($bodyClass === 'admin-application' ? '<link rel="stylesheet" href="/assets/video-previews.css?v=1">' : '')
            . ($stylesheet === null ? '' : '<link rel="stylesheet" href="' . Html::url($stylesheet) . '">')
            . '</head><body class="' . Html::attribute($bodyClass) . '">'
            . $content . '</body></html>';
    }

    private static function navigation(?ProfileManifest $profile, string $current, ?AdminChrome $chrome, Translator $t): string
    {
        $groups = ['content' => [], 'pages' => [], 'library' => [self::link('/media', $t->text('shell.nav.media'), $current === 'media')], 'publish' => [], 'website' => []];
        if ($profile !== null) {
            $navigation = $profile->navigation;
            usort(
                $navigation,
                static fn(NavigationDefinition $left, NavigationDefinition $right): int =>
                    $left->position <=> $right->position,
            );
            foreach ($navigation as $item) {
                if ($item->targetKind === 'system') {
                    if (in_array($item->targetId, ['previews', 'releases'], true) && !in_array($item->targetId, $chrome->unavailable ?? [], true)) {
                        $groups['publish'][] = self::link(self::systemRoute($item->targetId), $item->label, $current === $item->targetId);
                    }
                    continue;
                }
                $type = $profile->type($item->targetId);
                if ($chrome?->role === 'editor' && $type?->archetype === 'site_settings') {
                    continue;
                }
                $count = $type !== null && $type->kind !== 'singleton' ? ($chrome?->counts[$type->id] ?? null) : null;
                $groups[self::groupFor($item, $type)][] = self::link('/records/' . $item->targetId, $item->label, $current === $item->targetId, $count);
            }
        }
        $groups['website'][] = self::link('/settings/terminology', $t->text('shell.nav.content_names'), $current === 'terminology');
        if ($profile !== null) {
            $groups['website'][] = self::link('/settings/installation', $t->text('installation.title'), $current === 'installation');
            $groups['website'][] = self::link('/settings/history', $t->text('history.title'), $current === 'history');
        }
        if ($profile !== null && !in_array('publication-settings', $chrome === null ? [] : $chrome->unavailable, true)) {
            $groups['website'][] = self::link('/settings/publication', $t->text('holding.settings'), $current === 'publication-settings');
        }
        // Editors see the site's languages but cannot change them.
        $languages = $profile !== null && count($profile->locales) > 1 && !in_array('languages', $chrome === null ? [] : $chrome->unavailable, true)
            ? self::link('/settings/languages', $t->text('languages.title'), $current === 'languages') : null;
        if ($languages !== null) {
            $groups['website'][] = $languages;
        }
        // Editors write the site's wording as well as its content.
        $wording = $profile !== null && $profile->wording !== [] && !in_array('wording', $chrome === null ? [] : $chrome->unavailable, true)
            ? self::link('/settings/wording', $t->text('wording.title'), $current === 'wording') : null;
        if ($wording !== null) {
            $groups['website'][] = $wording;
        }
        if ($profile?->designRegistries !== null) {
            $groups['website'][] = self::link('/settings/design', $t->text('shell.nav.website_design'), $current === 'design');
        }
        if ($chrome?->accountsAvailable === true) {
            $groups['website'][] = self::link('/accounts', $t->text('account.team'), $current === 'accounts');
        }
        if ($chrome?->role === 'editor') {
            $groups['website'] = array_values(array_filter([$languages, $wording]));
        }
        $markup = '<nav class="admin-navigation" aria-label="' . $t->html('shell.nav.label') . '"><ul class="admin-navigation__list"><li>'
            . self::link('/', $t->text('shell.nav.overview'), $current === 'overview') . '</li></ul>';
        foreach ($groups as $group => $links) {
            if ($links !== []) {
                $markup .= self::navigationGroup($group, $t->text('shell.group.' . $group), $links);
            }
        }
        return $markup . '</nav>';
    }

    private static function groupFor(NavigationDefinition $item, ?RecordTypeDefinition $type): string
    {
        return match (true) {
            $item->targetKind === 'taxonomy' || $type?->kind === 'taxonomy' => 'library',
            $type?->archetype === 'site_settings' => 'website',
            $item->targetKind === 'singleton' || $type?->kind === 'singleton' => 'pages',
            default => 'content',
        };
    }

    private static function footer(string $current, ?AdminChrome $chrome, Translator $t, ?ProfileManifest $profile): string
    {
        $markup = '<div class="admin-sidebar__footer">';
        if ($chrome?->role === 'editor') {
            $markup .= '<nav aria-label="' . $t->html('shell.operations') . '"><ul class="admin-navigation__list"><li>' . self::link('/releases', $t->text('publication.title'), $current === 'releases') . '</li></ul></nav>';
        }
        if ($chrome?->role !== 'editor') {
            $markup .= '<nav aria-label="' . $t->html('shell.operations') . '"><ul class="admin-navigation__list"><li>'
            . self::link('/exports', $t->text('shell.nav.export'), $current === 'exports') . '</li><li>'
            . self::link('/releases', $t->text('publication.title'), $current === 'releases') . '</li></ul></nav>';
        }
        if ($chrome === null) {
            return $markup . '</div>';
        }
        $choices = '';
        foreach (ThemePreference::cases() as $theme) {
            $choices .= '<label><input type="radio" name="theme" value="' . $theme->value . '"'
                . ($theme === $chrome->theme ? ' checked' : '') . '>' . Html::text($theme->label($t)) . '</label>';
        }
        $csrf = '<input type="hidden" name="_csrf" value="' . Html::attribute($chrome->csrfToken) . '">';
        return $markup . '<details class="admin-account"><summary><span class="admin-avatar" aria-hidden="true">'
            . Html::text(self::monogram($chrome->displayName)) . '</span><span class="admin-account__name">'
            . Html::text($chrome->displayName) . '</span></summary><div class="admin-account__panel">'
            . ($chrome->accountsAvailable ? '<p>' . self::link('/account', $t->text('account.personal'), $current === 'account') . '</p>' : '')
            . '<form class="admin-theme" method="post" action="' . Html::url('/settings/theme') . '" data-theme-form>' . $csrf
            . '<input type="hidden" name="_return" value="' . Html::attribute(AdminChrome::returnPath($chrome->returnPath)) . '">'
            . '<fieldset><legend>' . $t->html('shell.theme.legend') . '</legend><div class="admin-segmented">' . $choices . '</div></fieldset>'
            . '<button type="submit" class="admin-button--small" data-theme-apply data-script-fallback="shell">' . $t->html('shell.theme.apply') . '</button></form>'
            . self::languageForm($chrome, $csrf, $t)
            . '<form class="admin-signout" method="post" action="' . Html::url('/logout') . '">' . $csrf
            . '<button type="submit" class="admin-button--quiet admin-button--small">' . $t->html('shell.sign_out') . '</button></form>'
            . '<p class="admin-account__versions">' . $t->html('installation.cms_version', ['version' => ProfileManifest::ENGINE_VERSION])
            . ($profile === null ? '' : ' · ' . $t->html('installation.site_version', ['version' => $profile->version])) . '</p>'
            . '</div></details></div>';
    }

    /** Interface language for this account; language names are autonyms so anyone can find their own. */
    private static function languageForm(AdminChrome $chrome, string $csrf, Translator $t): string
    {
        $options = '<option value="' . InterfaceLanguage::SYSTEM . '"' . ($chrome->interfaceLanguage === null ? ' selected' : '') . '>' . $t->html('shell.language.system') . '</option>';
        foreach (InterfaceLanguage::AUTONYMS as $language => $name) {
            $options .= '<option value="' . $language . '" lang="' . $language . '"' . ($chrome->interfaceLanguage === $language ? ' selected' : '') . '>' . Html::text($name) . '</option>';
        }
        return '<form class="admin-language" method="post" action="' . Html::url('/settings/language') . '">' . $csrf
            . '<input type="hidden" name="_return" value="' . Html::attribute(AdminChrome::returnPath($chrome->returnPath)) . '">'
            . '<label for="admin-language">' . $t->html('shell.language.label') . '</label><div class="admin-language__row">'
            . '<select id="admin-language" name="language">' . $options . '</select>'
            . '<button type="submit" class="admin-button--small">' . $t->html('shell.language.apply') . '</button></div></form>';
    }

    /** @param list<string> $links */
    private static function navigationGroup(string $group, string $label, array $links): string
    {
        $id = 'nav-' . $group;
        return '<section class="admin-navigation__group" aria-labelledby="' . Html::attribute($id) . '">'
            . '<h2 class="admin-navigation__label" id="' . Html::attribute($id) . '">' . Html::text($label) . '</h2>'
            . '<ul class="admin-navigation__list"><li>' . implode('</li><li>', $links) . '</li></ul></section>';
    }

    private static function link(string $href, string $label, bool $current, ?int $count = null): string
    {
        return '<a class="admin-navigation__link" href="' . Html::url($href) . '"'
            . ($current ? ' aria-current="page"' : '') . '><span>' . Html::text($label) . '</span>'
            . ($count === null ? '' : '<span class="admin-navigation__count">' . $count . '</span>') . '</a>';
    }

    private static function systemRoute(string $targetId): string
    {
        return match ($targetId) {
            'previews' => '/previews',
            'exports' => '/exports',
            'health' => '/health',
            'releases' => '/releases',
            'backups' => '/backups',
            'settings' => '/settings',
            default => throw new \InvalidArgumentException('CMS navigation target is not supported'),
        };
    }
}
