<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Media\LibraryMedia;
use Utsuri\PortableCms\Conventional\Media\MediaText;

/** One native description form, also used as the enhanced uploader's inert template. */
final class MediaDescriptionsForm
{
    public const LIMIT = 8;
    public const TEMPLATE_ID = '00000000000000000000000000000000';

    public static function warning(LibraryMedia $media, ProfileManifest $profile, Translator $t): string
    {
        if (!str_starts_with($media->mime, 'image/')) {
            return '';
        }
        $missing = [];
        foreach ($profile->locales as $locale) {
            if (!($media->text ?? MediaText::from())->publishable($locale, $profile->defaultLocale)) {
                $missing[] = strtoupper($locale);
            }
        }
        return $missing === [] ? '' : $t->text('media.descriptions.missing', ['languages' => implode(', ', $missing)]);
    }

    /** @param ?array<string, mixed> $submitted */
    public static function card(LibraryMedia $media, ProfileManifest $profile, Translator $t, string $form = '', ?array $submitted = null, ?string $error = null, bool $saved = false, ?string $jobId = null): string
    {
        $prefix = 'description_' . $media->id . '__';
        $association = $form === '' ? '' : ' form="' . Html::attribute($form) . '"';
        $warning = self::warning($media, $profile, $t);
        return '<fieldset class="admin-section admin-media-description" data-media-description="' . $media->id . '"' . ($jobId === null ? '' : ' data-description-job="' . Html::attribute($jobId) . '"') . '><legend data-description-name>' . Html::text($media->name) . '</legend>'
            . ReferenceControls::thumbnail($media)
            . '<input type="hidden" name="media_ids[]" value="' . $media->id . '"' . $association . '>'
            . '<input type="hidden" data-description-revision name="' . $prefix . 'revision" value="' . $media->metadataRevision . '"' . $association . '>'
            . '<p data-description-result role="' . ($error === null ? 'status' : 'alert') . '"' . ($error === null && !$saved ? ' hidden' : '') . '>' . Html::text($error ?? ($saved ? $t->text('media.descriptions.saved') : '')) . '</p>'
            . '<p class="admin-field__error" data-description-warning' . ($warning === '' ? ' hidden' : '') . '>' . Html::text($warning) . '</p>'
            . ($error === null ? '' : '<p><a href="' . Html::url('/media/' . $media->id) . '" target="_blank" rel="noopener">' . $t->html('media.descriptions.review') . '</a></p>')
            . MediaTextForm::render($media->text, $profile, $t, $submitted, $prefix, $form, instructions: false) . '</fieldset>';
    }

    /** @param list<LibraryMedia> $items
     * @param array<string, array<string, mixed>> $submitted
     * @param array<string, string> $errors
     * @param list<string> $saved
     */
    public static function form(array $items, ProfileManifest $profile, Translator $t, string $csrf, array $submitted = [], array $errors = [], array $saved = []): string
    {
        $markup = '<p class="admin-help">' . $t->html('media.text.help', ['locale' => $profile->defaultLocale]) . '</p><form class="admin-form" method="post" action="/media/descriptions" data-media-descriptions-form><input type="hidden" name="_csrf" value="' . Html::attribute($csrf) . '">';
        foreach ($items as $item) {
            $markup .= self::card($item, $profile, $t, submitted: $submitted[$item->id] ?? null, error: $errors[$item->id] ?? null, saved: in_array($item->id, $saved, true));
        }
        return $markup . '<input type="hidden" name="_descriptions_end" value="1"><button type="submit" class="admin-button--primary">' . $t->html('media.descriptions.save') . '</button></form>';
    }

    /** @param list<array{media: LibraryMedia, jobId: ?string}> $items */
    public static function template(ProfileManifest $profile, Translator $t, array $items = [], string $form = 'media-descriptions-form', bool $instructions = true): string
    {
        $media = new LibraryMedia(self::TEMPLATE_ID, self::TEMPLATE_ID, '', 'uploaded', 'image/webp', 0, null, null, 1, null, null);
        $messages = ['saved' => $t->text('media.descriptions.saved'), 'failed' => $t->text('media.descriptions.failed'), 'load_failed' => $t->text('media.descriptions.load_failed'), 'title' => $t->text('media.descriptions.title'), 'review' => $t->text('media.descriptions.review')];
        $cards = '';
        foreach ($items as $item) {
            $cards .= self::card($item['media'], $profile, $t, $form, jobId: $item['jobId']);
        }
        return '<template data-media-description-template>' . self::card($media, $profile, $t, $form) . '</template>'
            . '<section data-upload-descriptions data-descriptions-form="' . Html::attribute($form) . '"' . ($items === [] ? ' hidden' : '') . ' data-messages="' . Html::attribute(json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) . '"><h3>' . $t->html('media.descriptions.title') . '</h3>'
            . ($instructions ? '<p class="admin-help">' . $t->html('media.text.help', ['locale' => $profile->defaultLocale]) . '</p><noscript><p class="admin-help">' . $t->html('media.descriptions.keep_record') . '</p></noscript>' : '')
            . '<div data-description-cards>' . $cards . '</div><button type="submit" form="' . Html::attribute($form) . '">' . $t->html('media.descriptions.save') . '</button>'
            . '<p role="status" data-descriptions-status></p></section>';
    }
}
