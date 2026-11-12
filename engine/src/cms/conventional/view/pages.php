<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\LocaleCoverage;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\RecordLabels;
use Utsuri\PortableCms\Conventional\Content\RecordSnapshot;
use Utsuri\PortableCms\Conventional\Content\RecordTypeDefinition;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Preview\PreviewStatus;

final class Pages
{
    public static function login(string $csrfToken, string $loginValue = '', ?string $error = null, ?ProfileManifest $profile = null, ?Translator $translator = null, ?\Utsuri\PortableCms\Conventional\Content\WebsiteIdentity $identity = null, ?\Utsuri\PortableCms\Conventional\Media\AdministrationLogos $logos = null): string
    {
        $t = $translator ?? Translator::english();
        $message = match ($error) {
            'credentials' => '<div class="admin-notice admin-notice--danger" role="alert">'
                . '<p>' . $t->html('login.error.credentials') . '</p></div>',
            'throttled' => '<div class="admin-notice admin-notice--danger" role="alert">'
                . '<p>' . $t->html('login.error.throttled') . '</p></div>',
            default => '',
        };
        $name = AdminShell::installationName($profile, $identity);
        $logos = $profile === null ? null : $logos;
        $logoLed = $logos !== null && !$logos->icon;
        $body = TrustedHtml::fromEngineMarkup(
            '<div class="admin-auth__brand' . ($logoLed ? ' admin-auth__brand--logo' : '') . '">' . AdministrationMark::render($name, $logos)
            . ($logoLed ? '' : '<span><strong>' . Html::text($name) . '</strong><small>' . $t->html('login.private') . '</small></span>') . '</div>'
            . '<h1 class="admin-visually-hidden">' . $t->html('login.title') . '</h1>'
            . $message
            . '<form class="admin-form" method="post" action="' . Html::url('/login') . '">'
            . '<input type="hidden" name="_csrf" value="' . Html::attribute($csrfToken) . '">'
            . '<div class="admin-field"><label for="login-name">' . $t->html('login.login') . '</label>'
            . '<input id="login-name" name="login" type="text" autocomplete="username" maxlength="254" required value="'
            . Html::attribute($loginValue) . '"></div>'
            . '<div class="admin-field"><label for="password">' . $t->html('login.password') . '</label>'
            . '<input id="password" name="password" type="password" autocomplete="current-password" maxlength="1024" required></div>'
            . '<button type="submit" class="admin-button--primary">' . $t->html('login.submit') . '</button></form>'
            . '<p class="admin-auth__footer">Portable CMS'
            . ($profile === null ? '' : ' · ' . $t->html('installation.site_version', ['version' => $profile->version])) . '</p>',
        );
        return AdminShell::focused($t->text('login.document_title', ['name' => $name]), $body, $t);
    }

    /**
     * @param array<string, int|string|null>|null $latestMediaJob
     * @param list<RecordSnapshot> $recent
     * @param list<\Utsuri\PortableCms\Conventional\Preview\PreviewSetupItem> $previewSetup
     */
    public static function dashboard(
        string $csrfToken,
        string $displayName,
        ?ProfileManifest $profile = null,
        ?array $latestMediaJob = null,
        ?AdminChrome $chrome = null,
        array $recent = [],
        ?\Utsuri\PortableCms\Conventional\Preview\PreviewCheck $latestCheck = null,
        array $previewSetup = [],
    ): string {
        $t = AdminShell::translator($chrome);
        $intro = '<p class="admin-lede">' . $t->html('overview.signed_in', ['name' => $displayName])
            . ($profile === null ? '' : ' · ' . $t->html('overview.languages', ['count' => count($profile->locales)]))
            . ($recent === [] ? '' : ' · ' . $t->markup('overview.last_change', [], ['time' => AdminShell::time($recent[0]->updatedAt, $chrome?->now, $t)])) . '</p>';
        $continue = '';
        $attention = self::mediaAttention($latestMediaJob, $t)
            . PreviewCheckPanel::failed($latestCheck, $profile, $t, true)
            . PreviewCheckPanel::setup($previewSetup, $profile, $t, true);
        foreach ($recent as $index => $record) {
            $type = $profile?->type($record->typeId);
            if ($type === null || $profile === null) {
                continue;
            }
            $label = $type->kind === 'singleton' ? $type->label : RecordLabels::label($record, $profile);
            $url = '/records/' . $type->id . '/' . $record->id;
            if ($index < 5) {
                $continue .= '<li><span class="admin-rows__main"><a href="' . Html::url($url) . '">' . Html::text($label) . '</a>'
                    . '<span>' . $t->markup('overview.edited', ['type' => $type->singularLabel], ['time' => AdminShell::time($record->updatedAt, $chrome?->now, $t)]) . '</span></span>'
                    . AdminShell::status($record->workflowState, null, $t) . '</li>';
            }
            foreach ($profile->localeDefinitions as $locale) {
                $missing = LocaleCoverage::missing($type, $record->body, $profile)[$locale->id] ?? [];
                if ($missing !== [] && count($profile->locales) > 1) {
                    $attention .= '<li><span class="admin-dot admin-dot--missing" aria-hidden="true"></span><span class="admin-rows__main">'
                        . '<a href="' . Html::url($url) . '">' . Html::text($label) . '</a><span>' . $t->html('overview.missing', ['language' => $locale->label, 'fields' => implode(', ', $missing)]) . '</span></span></li>';
                }
            }
        }
        $continueCard = '<section class="admin-card" aria-labelledby="continue-heading"><div class="admin-card__header"><h2 id="continue-heading">' . $t->html('overview.continue.title') . '</h2></div>'
            . ($continue === '' ? '<p class="admin-meta">' . $t->html('overview.continue.empty') . '</p>' : '<ul class="admin-rows">' . $continue . '</ul>') . '</section>';
        $attentionCard = '<section class="admin-card" aria-labelledby="attention-heading"><div class="admin-card__header"><h2 id="attention-heading">' . $t->html('overview.attention.title') . '</h2></div>'
            . ($attention === '' ? '<p class="admin-meta">' . $t->html('overview.attention.empty') . '</p>' : '<ul class="admin-rows">' . $attention . '</ul>') . '</section>';
        $stats = '';
        foreach ($profile->types ?? [] as $type) {
            if ($type->kind === 'singleton') {
                continue;
            }
            $count = $chrome?->counts[$type->id] ?? 0;
            $stats .= '<li><a href="' . Html::url('/records/' . $type->id) . '"><strong>' . $count . '</strong><span>' . Html::text($type->label) . '</span></a></li>';
        }
        $contentCard = $stats === '' ? '' : '<section class="admin-card" aria-labelledby="content-heading"><div class="admin-card__header"><h2 id="content-heading">' . $t->html('overview.content.title') . '</h2></div><ul class="admin-stats">' . $stats . '</ul></section>';
        $previews = in_array('previews', $chrome->unavailable ?? [], true)
            ? '<div><span>' . $t->html('overview.previews.label') . '</span>' . AdminShell::status('plain', $t->text('overview.previews.unavailable')) . '<p>' . $t->html('overview.previews.unavailable_help') . '</p></div>'
            : '<div><span>' . $t->html('overview.previews.label') . '</span><a class="admin-button admin-button--small" href="' . Html::url('/previews') . '">' . $t->html('overview.previews.open') . '</a><p>' . $t->html('overview.previews.help') . '</p></div>';
        $systemCard = '<section class="admin-card" aria-labelledby="system-heading"><div class="admin-card__header"><h2 id="system-heading">' . $t->html('overview.system.title') . '</h2></div><div class="admin-system">'
            . self::mediaJobStatus($latestMediaJob, $csrfToken, $t) . $previews
            . ($chrome?->role === 'editor' ? '' : '<div><span>' . $t->html('overview.export.label') . '</span><a class="admin-button admin-button--small" href="' . Html::url('/exports') . '">' . $t->html('overview.export.action') . '</a><p>' . $t->html('overview.export.help') . '</p></div>')
            . '</div></section>';
        $body = TrustedHtml::fromEngineMarkup(
            AdminShell::header($t->text('overview.title'), $intro)
            . '<div class="admin-overview"><div class="admin-overview__column">' . $continueCard . $attentionCard . '</div>'
            . '<div class="admin-overview__column">' . $contentCard . $systemCard . '</div></div>',
        );
        return AdminShell::application($t->text('overview.title'), $body, $profile, 'overview', 'wide', $chrome);
    }

    /** @param array<string, int|string|null>|null $job */
    private static function mediaAttention(?array $job, Translator $t): string
    {
        return ($job['status'] ?? null) === 'needs_attention'
            ? '<li><span class="admin-dot admin-dot--danger" aria-hidden="true"></span><span class="admin-rows__main"><a href="' . Html::url('/media') . '">' . $t->html('overview.media_failed.title') . '</a><span>' . $t->html('overview.media_failed.help') . '</span></span></li>'
            : '';
    }

    /** @param array<string, int|string|null>|null $job */
    private static function mediaJobStatus(?array $job, string $csrfToken, Translator $t): string
    {
        if ($job === null) {
            return '<div aria-labelledby="media-processing"><span id="media-processing">' . $t->html('overview.media.label') . '</span>' . AdminShell::status('plain', $t->text('overview.media.idle'))
                . '<p>' . $t->html('overview.media.idle_help') . '</p></div>';
        }
        $status = is_string($job['status'] ?? null) ? $job['status'] : 'unknown';
        $attempts = is_int($job['attempts'] ?? null) ? $job['attempts'] : 0;
        $maximum = is_int($job['maxAttempts'] ?? null) ? $job['maxAttempts'] : 0;
        $parts = [$t->text(in_array($status, ['queued', 'running', 'retry', 'completed', 'needs_attention'], true) ? 'overview.media.' . $status : 'overview.media.unknown')];
        if (in_array($status, ['running', 'retry', 'needs_attention'], true) && $maximum > 0) {
            $parts[] = $t->text('overview.media.attempt', ['attempt' => (string) $attempts, 'maximum' => (string) $maximum]);
        }
        $errorCode = is_string($job['errorCode'] ?? null) ? $job['errorCode'] : '';
        if ($status === 'needs_attention' && $errorCode !== '') {
            $parts[] = $t->text('overview.media.error', ['code' => $errorCode]);
        }
        $role = $status === 'needs_attention' ? ' role="alert"' : ' role="status"';
        $recovery = in_array($status, ['queued', 'retry', 'needs_attention'], true)
            ? '<form method="post" action="' . Html::url('/jobs/wake') . '"><input type="hidden" name="_csrf" value="' . Html::attribute($csrfToken) . '">'
                . '<button type="submit" class="admin-button--small">' . $t->html('overview.media.recover') . '</button></form>'
            : '';
        return '<div><span>' . $t->html('overview.media.label') . '</span>' . AdminShell::status($status, $status === 'completed' ? $t->text('state.ready') : null, $t)
            . '<p' . $role . '>' . Html::text(implode(' ', $parts)) . '</p>' . $recovery . '</div>';
    }

    public static function mediaWakeQueued(?ProfileManifest $profile = null, ?AdminChrome $chrome = null): string
    {
        $t = AdminShell::translator($chrome);
        $body = TrustedHtml::fromEngineMarkup(
            AdminShell::breadcrumbs([[$t->text('shell.nav.overview'), '/'], [$t->text('overview.media.label'), null]], $t)
            . AdminShell::header($t->text('wake.title'))
            . '<div class="admin-notice admin-notice--success" role="status">'
            . '<p>' . $t->html('wake.notice') . '</p></div>'
            . '<p><a class="admin-button" href="' . Html::url('/') . '">' . $t->html('common.return_overview') . '</a></p>',
        );
        return AdminShell::application($t->text('wake.title'), $body, $profile, 'overview', 'narrow', $chrome);
    }

    public static function featureUnavailable(
        string $csrfToken,
        ProfileManifest $profile,
        string $heading,
        string $message,
        string $currentNavigation,
        ?AdminChrome $chrome = null,
    ): string {
        $t = AdminShell::translator($chrome);
        $body = TrustedHtml::fromEngineMarkup(
            AdminShell::header($heading, '<p class="admin-meta">' . $t->html('unavailable.eyebrow') . '</p>')
            . '<div class="admin-notice admin-notice--warning" role="status"><p>' . Html::text($message) . '</p></div>'
            . '<p><a class="admin-button" href="' . Html::url('/') . '">' . $t->html('common.return_overview') . '</a></p>',
        );
        return AdminShell::application($t->text('unavailable.title', ['feature' => $heading]), $body, $profile, $currentNavigation, 'narrow', $chrome);
    }

    public static function exportPage(string $csrfToken, ?ProfileManifest $profile = null, ?AdminChrome $chrome = null, ?\Utsuri\PortableCms\Conventional\Export\ExportCapacityStatus $capacity = null, bool $requiresPackage = false): string
    {
        $t = AdminShell::translator($chrome);
        $body = TrustedHtml::fromEngineMarkup(
            AdminShell::breadcrumbs([[$t->text('shell.operations'), null], [$t->text('shell.nav.export'), null]], $t)
            . AdminShell::header($t->text('export.title'), '<p class="admin-lede">' . $t->html('export.lede') . '</p>')
            . '<section class="admin-section">'
            . ($capacity === null ? '' : '<p>' . $t->html('export.capacity.counts', ['records' => $capacity->counts['records'], 'revisions' => $capacity->counts['revisions']]) . '</p>')
            . ($capacity?->exceeded() ? '<div class="admin-notice admin-notice--warning"><p>' . $t->html('export.capacity.exceeded') . '</p></div>' : ($capacity?->approaching() ? '<div class="admin-notice admin-notice--warning"><p>' . $t->html('export.capacity.warning') . '</p></div>' : ''))
            . ($requiresPackage || $capacity?->chunkedRequired() ? '<div id="export-package-required" class="admin-notice admin-notice--warning"><p>' . $t->html('export.capacity.package') . '</p></div>' : '')
            . '<div class="admin-notice admin-notice--warning"><p><strong>' . $t->html('export.warning.strong') . '</strong> '
            . $t->html('export.warning.detail') . '</p></div>'
            . ExportCapacityView::render($t)
            . '<form method="post" action="' . Html::url('/exports') . '">'
            . '<input type="hidden" name="_csrf" value="' . Html::attribute($csrfToken) . '">'
            . '<button type="submit" class="admin-button--primary"' . ($requiresPackage || $capacity?->chunkedRequired() ? ' disabled aria-describedby="export-package-required"' : '') . '>' . $t->html('export.submit') . '</button></form></section>',
        );
        return AdminShell::application($t->text('export.title'), $body, $profile, 'exports', 'standard', $chrome);
    }

    /**
     * @param list<PreviewStatus> $previews
     * @param list<\Utsuri\PortableCms\Conventional\Preview\PreviewSetupItem> $previewSetup
     * @param list<string> $preparation Languages in preparation that a review preview may include.
     */
    public static function previewList(
        string $csrfToken,
        ?ProfileManifest $profile,
        array $previews,
        ?AdminChrome $chrome = null,
        ?\Utsuri\PortableCms\Conventional\Preview\PreviewCheck $latestCheck = null,
        array $previewSetup = [],
        ?\Utsuri\PortableCms\Conventional\Preview\PreviewCleanupPage $cleanup = null,
        ?string $pageSelection = null,
        ?string $cleanupRecovery = null,
        array $preparation = [],
        int $pendingPublication = 0,
        ?TrustedHtml $newestLinks = null,
    ): string {
        $t = AdminShell::translator($chrome);
        $items = '';
        foreach ($previews as $preview) {
            $items .= '<li class="admin-list__item"><div><a class="admin-table__title" href="'
                . Html::url('/previews/' . $preview->id) . '">' . $t->html('preview.name', ['id' => substr($preview->id, 0, 8)]) . '</a><p class="admin-meta">'
                . AdminShell::time($preview->createdAt, $chrome?->now, $t) . ' · ' . self::previewMode($preview, $t) . '</p></div>'
                . AdminShell::status($preview->status, null, $t) . '</li>';
        }
        $list = $items === ''
            ? '<div class="admin-section admin-empty"><h2>' . $t->html('preview.empty.title') . '</h2><p class="admin-meta">' . $t->html('preview.empty.help') . '</p></div>'
            : '<ul class="admin-list">' . $items . '</ul>';
        if ($cleanup !== null) {
            $list = PreviewCleanupPageView::listing($cleanup, $pageSelection, $csrfToken, $chrome, $cleanupRecovery);
        }
        $body = TrustedHtml::fromEngineMarkup(
            AdminShell::breadcrumbs([[$t->text('shell.group.publish'), null], [$t->text('preview.list.title'), null]], $t)
            . AdminShell::header(
                $t->text('preview.list.title'),
                '<p class="admin-lede">' . $t->html('preview.list.lede') . '</p>',
                '<form method="post" action="' . Html::url('/previews') . '"><input type="hidden" name="_csrf" value="' . Html::attribute($csrfToken) . '">'
                . '<label><input type="checkbox" name="include_drafts" value="1"> ' . $t->html('preview.include_drafts') . '</label>'
                . self::preparationChoice($profile, $preparation, $t)
                . '<button type="submit" class="admin-button--primary">' . $t->html('preview.create') . '</button></form>',
            )
            . '<p class="admin-notice" data-pending-publication="' . $pendingPublication . '">' . $t->html('preview.pending_publication', ['count' => $pendingPublication]) . '</p>'
            . PreviewCheckPanel::failed($latestCheck, $profile, $t)
            . PreviewCheckPanel::setup($previewSetup, $profile, $t)
            . '<section aria-labelledby="recent-previews" class="admin-form"><h2 id="recent-previews">' . $t->html('preview.list.recent') . '</h2>' . $list . '</section>' . ($newestLinks->value ?? ''),
        );
        return AdminShell::application($t->text('preview.list.title'), $body, $profile, 'previews', 'wide', $chrome);
    }

    /** What a preview draws, and whether it can ever be released. */
    public static function previewMode(PreviewStatus $preview, Translator $t): string
    {
        $mode = $t->html($preview->includeDrafts ? 'preview.mode.drafts' : 'preview.mode.published');
        if (!$preview->includePreparation) {
            return $mode;
        }
        return ($preview->includeDrafts ? $mode . ' ' : '') . $t->html('preview.mode.preparation');
    }

    /** @param list<string> $preparation */
    private static function preparationChoice(?ProfileManifest $profile, array $preparation, Translator $t): string
    {
        $names = [];
        foreach ($profile === null ? [] : $profile->localeDefinitions as $definition) {
            if (in_array($definition->id, $preparation, true)) {
                $names[] = $definition->label;
            }
        }
        return $names === [] ? '' : '<label><input type="checkbox" name="include_preparation" value="1"> '
            . $t->html('preview.include_preparation', ['languages' => implode(', ', $names)]) . '</label>';
    }

    public static function previewQueued(?ProfileManifest $profile, PreviewStatus $preview, ?AdminChrome $chrome = null): string
    {
        $t = AdminShell::translator($chrome);
        $body = TrustedHtml::fromEngineMarkup(
            AdminShell::breadcrumbs([[$t->text('preview.list.title'), '/previews'], [$t->text('preview.name', ['id' => substr($preview->id, 0, 8)]), null]], $t)
            . AdminShell::header($t->text('preview.queued.title'))
            . '<div class="admin-notice admin-notice--warning" role="status">'
            . '<p>' . $t->html('preview.queued.notice') . '</p></div>'
            . '<p><a class="admin-button" href="' . Html::url('/previews/' . $preview->id) . '">' . $t->html('preview.queued.check') . '</a></p>',
        );
        return AdminShell::application($t->text('preview.queued.title'), $body, $profile, 'previews', 'narrow', $chrome);
    }

    public static function previewStatus(
        string $csrfToken,
        ?ProfileManifest $profile,
        PreviewStatus $preview,
        ?AdminChrome $chrome = null,
        bool $sharingAvailable = false,
        string $scheduleNotice = '',
        int $missingRevisions = 0,
    ): string {
        $t = AdminShell::translator($chrome);
        $message = $t->text(in_array($preview->status, ['queued', 'building', 'ready', 'needs_attention'], true) ? 'preview.status.' . $preview->status : 'preview.status.unknown');
        $open = $preview->status === 'ready'
            ? '<a class="admin-button admin-button--primary" href="' . Html::url('/previews/' . $preview->id . '/artifact/')
                . '" target="_blank" rel="noopener">' . $t->html('preview.open') . '</a>'
            : '';
        if ($preview->status === 'ready' && $preview->manifest !== null) {
            foreach (\Utsuri\PortableCms\Conventional\Preview\PreviewBuild::fromManifest($preview->manifest)->artifacts as $artifact) {
                if (preg_match('#\Afeed/v1/snapshots/[a-f0-9]{64}/manifest\.json\z#', $artifact->path) === 1) {
                    $open .= '<a class="admin-button" href="' . Html::url('/previews/' . $preview->id . '/artifact/' . $artifact->path) . '">' . $t->html('preview.content_feed') . '</a>';
                }
            }
        }
        if ($sharingAvailable && $preview->status === 'ready' && ($chrome === null || $chrome->role !== 'editor')) {
            $open .= '<a class="admin-button" href="' . Html::url('/previews/' . $preview->id . '/shares') . '">' . $t->html('share.title') . '</a>';
        }
        $error = $preview->status === 'needs_attention' && $preview->errorCode !== null
            ? '<p>' . $t->markup('preview.error', [], ['code' => '<code>' . Html::text($preview->errorCode) . '</code>']) . '</p>'
            : '';
        if ($preview->diagnostics !== null && $preview->status !== 'ready') {
            $error = '<div class="admin-notice admin-notice--danger" role="alert">' . PreviewDiagnosticList::render($preview->diagnostics, $t, $profile) . '</div>';
        }
        $delete = in_array($preview->status, ['ready', 'needs_attention'], true)
            ? '<form method="post" action="' . Html::url('/previews/' . $preview->id . '/delete') . '">'
                . '<input type="hidden" name="_csrf" value="' . Html::attribute($csrfToken) . '">'
                . '<button type="submit" class="admin-button--danger">' . $t->html('preview.delete') . '</button></form>'
            : '';
        $refresh = $preview->status === 'queued' || $preview->status === 'building'
            ? '<a class="admin-button" href="' . Html::url('/previews/' . $preview->id) . '">' . $t->html('preview.refresh') . '</a>'
            : '';
        $name = $t->text('preview.name', ['id' => substr($preview->id, 0, 8)]);
        $body = TrustedHtml::fromEngineMarkup(
            AdminShell::breadcrumbs([[$t->text('preview.list.title'), '/previews'], [$name, null]], $t)
            . $scheduleNotice . AdminShell::header($name, '<p class="admin-meta-line"><span class="admin-status admin-status--'
                . Html::attribute($preview->status) . '" data-preview-state="' . Html::attribute($preview->status) . '">' . Html::text(AdminShell::stateLabel($preview->status, $t)) . '</span>'
                . '<span>' . $t->markup('preview.created', [], ['time' => AdminShell::time($preview->createdAt, $chrome?->now, $t)]) . '</span></p>')
            . '<section class="admin-section"><p class="admin-meta" data-preview-mode="' . ($preview->includeDrafts ? 'drafts' : 'published') . '"' . ($preview->includePreparation ? ' data-preview-languages="preparation"' : '') . '>' . self::previewMode($preview, $t) . '</p><p role="status">' . Html::text($message) . '</p>' . $error
            . ($missingRevisions === 0 ? '' : '<p class="admin-help">' . $t->html('history.preview_missing', ['count' => $missingRevisions]) . '</p>')
            . '<div class="admin-actions">' . $open . $refresh . $delete . '</div></section>',
        );
        return AdminShell::application($t->text('preview.status.title'), $body, $profile, 'previews', 'standard', $chrome);
    }

    /** @param list<RecordSnapshot> $records */
    public static function recordList(
        string $csrfToken,
        ProfileManifest $profile,
        RecordTypeDefinition $type,
        array $records,
        ?AdminChrome $chrome = null,
        ?RecordListing $listing = null,
    ): string {
        $states = [];
        foreach ($records as $record) {
            $states[$record->workflowState] = ($states[$record->workflowState] ?? 0) + 1;
        }
        return RecordPages::listing($profile, $type, $listing ?? new RecordListing($records, states: $states), $chrome);
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, \Utsuri\PortableCms\Conventional\I18n\Message> $errors
     * @param array<string, list<RecordSnapshot>> $choices
     * @param array<string, list<array{media: \Utsuri\PortableCms\Conventional\Media\LibraryMedia, jobId: ?string}>> $uploadDescriptions
     */
    public static function recordForm(
        string $csrfToken,
        ProfileManifest $profile,
        RecordTypeDefinition $type,
        array $values,
        ?RecordSnapshot $record = null,
        array $errors = [],
        ?RecordSnapshot $conflict = null,
        ?int $submittedRevision = null,
        array $choices = [],
        bool $mediaUploadEnabled = false,
        bool $technicalData = false,
        ?ReferenceControls $referenceControls = null,
        bool $pendingControls = false,
        bool $structureChanged = false,
        ?CompositionEditor $compositionEditor = null,
        ?AdminChrome $chrome = null,
        string $assistancePanel = '',
        ?\Utsuri\PortableCms\Conventional\I18n\Message $mediaNotice = null,
        array $uploadDescriptions = [],
        string $scheduleNotice = '',
        ?int $submittedEditSequence = null,
        ?bool $exactHasPage = null,
    ): string {
        return RecordPages::form(
            $csrfToken,
            $profile,
            $type,
            $values,
            $record,
            $errors,
            $conflict,
            $submittedRevision,
            $choices,
            $mediaUploadEnabled,
            $technicalData,
            $referenceControls,
            $pendingControls,
            $structureChanged,
            $compositionEditor,
            $chrome,
            $assistancePanel,
            $mediaNotice,
            $uploadDescriptions,
            $scheduleNotice,
            $submittedEditSequence,
            $exactHasPage,
        );
    }

    public static function error(int $status, string $correlationId, ?Translator $translator = null): string
    {
        $t = $translator ?? Translator::english();
        $kind = in_array($status, [400, 403, 404, 409, 413, 503], true) ? (string) $status : 'default';
        $heading = $t->text('error.' . $kind . '.title');
        $body = TrustedHtml::fromEngineMarkup(
            '<div class="admin-auth__brand"><span class="admin-mark" aria-hidden="true">P</span><span><strong>Portable CMS</strong><small>' . $t->html('error.code', ['status' => (string) $status]) . '</small></span></div>'
            . '<h1>' . Html::text($heading) . '</h1>'
            . '<p class="admin-help">' . $t->html('error.' . $kind . '.help') . '</p>'
            . '<div class="admin-actions"><a class="admin-button admin-button--primary" href="' . Html::url('/') . '">' . $t->html('error.go_overview') . '</a></div>'
            . '<p class="admin-auth__footer">' . $t->markup('error.reference', [], ['reference' => '<code class="admin-error-reference">' . Html::text($correlationId) . '</code>']) . '</p>',
        );
        return AdminShell::focused($heading, $body, $t);
    }
}
