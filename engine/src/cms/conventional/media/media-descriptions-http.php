<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Media;

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Http\Response;
use Utsuri\PortableCms\Conventional\I18n\InvalidInput;
use Utsuri\PortableCms\Conventional\Identity\SessionContext;
use Utsuri\PortableCms\Conventional\View\AdminChrome;
use Utsuri\PortableCms\Conventional\View\AdminShell;
use Utsuri\PortableCms\Conventional\View\MediaDescriptionsForm;
use Utsuri\PortableCms\Conventional\View\MediaTextForm;
use Utsuri\PortableCms\Conventional\View\TrustedHtml;

/** Application authentication, origin and CSRF checks precede this bounded native/JSON adapter. */
final readonly class MediaDescriptionsHttp
{
    public function __construct(private MediaLibrary $library, private ProfileManifest $profile, private ?AdminChrome $chrome = null) {}

    public function handle(Request $request, SessionContext $session): Response
    {
        $t = AdminShell::translator($this->chrome);
        $json = str_contains($request->header('accept') ?? '', 'application/json');
        $ids = $request->method === 'GET' ? explode(',', $request->queryValue('ids')) : $request->formValues('media_ids');
        $items = [];
        $error = null;
        if ($ids === [] || count($ids) > MediaDescriptionsForm::LIMIT || count(array_unique($ids)) !== count($ids)) {
            $error = $t->text('media.descriptions.select');
        } else {
            foreach ($ids as $id) {
                $item = $this->library->find($id);
                if ($item === null || !str_starts_with($item->mime, 'image/')) {
                    $error = $t->text('media.descriptions.select');
                    break;
                }
                $items[] = $item;
            }
        }
        if ($error !== null) {
            return $this->rejected($error, $json);
        }
        $submitted = [];
        $errors = [];
        $saved = [];
        $status = 200;
        if ($request->method === 'POST' && $request->formValue('_mode') !== 'open') {
            if ($request->formValue('_descriptions_end') !== '1' || $session->userId === null) {
                return $this->rejected($t->text('media.descriptions.incomplete'), $json);
            }
            foreach ($items as $item) {
                $prefix = 'description_' . $item->id . '__';
                $submitted[$item->id] = MediaTextForm::input($request, $this->profile, $prefix);
                $revision = filter_var($request->formValue($prefix . 'revision'), FILTER_VALIDATE_INT);
                try {
                    $text = MediaText::edited($submitted[$item->id], $this->profile->locales, $item->text);
                    if (!$this->library->rename($item->id, is_int($revision) ? $revision : 0, $item->name, $session->userId, $text)) {
                        $errors[$item->id] = $t->text('media.name.conflict');
                        $status = 409;
                    } else {
                        $saved[] = $item->id;
                    }
                } catch (\InvalidArgumentException $failure) {
                    $errors[$item->id] = $failure instanceof InvalidInput ? $t->failure($failure) : $t->text('media.text.invalid');
                    if ($status !== 409) {
                        $status = 422;
                    }
                }
            }
            $items = array_map(fn(LibraryMedia $item): LibraryMedia => $this->library->find($item->id) ?? $item, $items);
        }
        if ($json) {
            return Response::json(['items' => array_map(fn(LibraryMedia $item): array => ['id' => $item->id, 'name' => $item->name, 'mime' => $item->mime, 'revision' => $item->metadataRevision, 'text' => ($item->text ?? MediaText::from())->document(), 'warning' => MediaDescriptionsForm::warning($item, $this->profile, $t), 'error' => $errors[$item->id] ?? null, 'saved' => in_array($item->id, $saved, true)], $items)], $status);
        }
        $markup = AdminShell::breadcrumbs([[$t->text('media.title'), '/media'], [$t->text('media.descriptions.title'), null]], $t)
            . AdminShell::header($t->text('media.descriptions.title'), '<p>' . $t->html('media.descriptions.help') . '</p>')
            . MediaDescriptionsForm::form($items, $this->profile, $t, $session->csrfToken, $submitted, $errors, $saved);
        return new Response($status, AdminShell::application($t->text('media.descriptions.title'), TrustedHtml::fromEngineMarkup($markup), $this->profile, 'media', 'wide', $this->chrome));
    }

    private function rejected(string $message, bool $json): Response
    {
        if ($json) {
            return Response::json(['message' => $message], 422);
        }
        $t = AdminShell::translator($this->chrome);
        $markup = '<p role="alert">' . \Utsuri\PortableCms\Conventional\Http\Html::text($message) . '</p><p><a href="/media">' . $t->html('upload.library') . '</a></p>';
        return new Response(422, AdminShell::application($t->text('media.descriptions.title'), TrustedHtml::fromEngineMarkup($markup), $this->profile, 'media', 'wide', $this->chrome));
    }
}
