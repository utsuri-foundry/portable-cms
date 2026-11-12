<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Media;

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Http\Response;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;
use Utsuri\PortableCms\Conventional\View\AdminShell;
use Utsuri\PortableCms\Conventional\View\TrustedHtml;

final readonly class NativeUploadBatch
{
    /** @param list<string> $sessions Completed upload sessions, in file order. */
    public function __construct(public array $sessions, public bool $video, public ?UploadFailure $failure) {}
}

final class NativeUploadHttp
{
    public static function handle(Request $request, ?string $typeId, ?string $recordId, string $actorId, MediaUploadService $uploads, RandomSource $random, ProfileManifest $profile, ?Translator $translator = null, ?int $ownerRevision = null, ?MediaLibrary $library = null, string $csrf = ''): Response
    {
        $t = $translator ?? Translator::english();
        $batch = self::receive($request, $typeId, $recordId, $actorId, $uploads, $random, $profile, $ownerRevision);
        $images = [];
        foreach ($batch->sessions as $sessionId) {
            $image = $library?->find((string) $uploads->status($sessionId, $actorId)->mediaId);
            if ($image !== null && str_starts_with($image->mime, 'image/')) {
                $images[] = $image;
            }
        }
        $markup = '<h1>' . $t->html('upload.title') . '</h1><p role="status">' . $t->html($batch->video ? 'video.accepted' : 'upload.accepted', ['count' => (string) count($batch->sessions)]) . '</p>'
            . ($batch->failure === null ? '' : '<p role="alert">' . Html::text($t->failure($batch->failure)) . '</p>')
            . '<p>' . ($recordId === null ? '' : '<a href="' . Html::url('/records/' . $typeId . '/' . $recordId) . '">' . $t->html('upload.return') . '</a> · ') . '<a href="/media">' . $t->html('upload.library') . '</a></p>'
            . ($images === [] ? '' : '<h2>' . $t->html('media.descriptions.title') . '</h2>' . \Utsuri\PortableCms\Conventional\View\MediaDescriptionsForm::form($images, $profile, $t, $csrf));
        return new Response($batch->failure === null ? 202 : 422, AdminShell::application($t->text('upload.title'), TrustedHtml::fromEngineMarkup($markup), $profile, 'media'));
    }

    public static function receive(Request $request, ?string $typeId, ?string $recordId, string $actorId, MediaUploadService $uploads, RandomSource $random, ProfileManifest $profile, ?int $ownerRevision = null, bool $imagesOnly = false): NativeUploadBatch
    {
        $revision = $ownerRevision ?? filter_var($request->formValue('_revision'), FILTER_VALIDATE_INT);
        $website = $typeId === null && $recordId === null;
        $type = $typeId === null ? null : $profile->type($typeId);
        if ((!$website && ($type === null || $type->kind !== 'collection' || !is_int($revision) || $revision < 1)) || $request->files === [] || count($request->files) > 8) {
            throw new UploadFailure('invalid_upload_intent', 422, 'upload.error.select_up_to_eight_valid_images_from');
        }
        $sessions = [];
        $videoBatch = false;
        $error = null;
        foreach ($request->files as $file) {
            try {
                if ($file->error !== UPLOAD_ERR_OK) {
                    throw new UploadFailure('incomplete_native_upload', 422, 'upload.error.the_uploaded_image_is_incomplete');
                }
                if ($file->byteSize > MediaContract::MAX_BYTES) {
                    throw new UploadFailure('native_upload_too_large', 413, 'upload.error.the_upload_exceeds_the_40_mb_limit');
                }
                if ($imagesOnly && !in_array($file->mime, MediaContract::uploadTypes(false), true)) {
                    throw new UploadFailure('invalid_upload_type', 422, 'upload.error.select_up_to_eight_valid_images_from');
                }
                $client = 'native-' . bin2hex($random->bytes(16));
                $session = $website ? $uploads->createWebsite($file->name, $file->mime, $file->byteSize, $client, $actorId)
                    : $uploads->create((string) $typeId, (string) $recordId, (int) $revision, $file->name, $file->mime, $file->byteSize, $client, $actorId);
                $stream = $file->open();
                try {
                    for ($part = 1; $part <= $session->partCount; $part++) {
                        $size = MediaContract::expectedPartSize($session, $part);
                        $bytes = stream_get_contents($stream, $size);
                        if (!is_string($bytes) || strlen($bytes) !== $size) {
                            throw new UploadFailure('invalid_part_size', 422, 'upload.error.the_uploaded_image_is_incomplete');
                        }
                        $body = \Utsuri\PortableCms\Conventional\Http\RequestBody::fromString($bytes)->open();
                        try {
                            $uploads->uploadPart($session->id, $part, $size, $body, $actorId);
                        } finally {
                            fclose($body);
                        }
                    }
                } finally {
                    fclose($stream);
                }
                $uploads->complete($session->id, $actorId);
                $videoBatch = $videoBatch || str_starts_with($file->mime, 'video/');
                $sessions[] = $session->id;
            } catch (UploadFailure $failure) {
                $error = $failure;
                break;
            }
        }
        return new NativeUploadBatch($sessions, $videoBatch, $error);
    }
}
