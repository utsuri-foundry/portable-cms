<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Media\MediaContract;
use Utsuri\PortableCms\Conventional\Media\VideoUploadPolicy;

final class VideoUploadHelp
{
    public static function limits(Translator $t, VideoUploadPolicy $policy, int $fieldMaximum): string
    {
        $maximum = min($policy->maximumBytes, $fieldMaximum);
        $line = $t->html('video.upload_limits', ['limit' => (string) round($maximum / 1048576, 2)]);
        if ($policy->nativeMaximumBytes < $maximum) {
            $line .= ' ' . $t->html('video.native_upload_limit', ['limit' => (string) round($policy->nativeMaximumBytes / 1048576, 2)]);
        }
        return $line;
    }

    public static function render(Translator $t, VideoUploadPolicy $policy, int $profileMaximum): string
    {
        $maximum = min($policy->maximumBytes, $profileMaximum);
        return $t->html('video.upload_help', ['limit' => (string) round($maximum / 1048576, 2), 'native' => (string) round(min($maximum, $policy->nativeMaximumBytes) / 1048576, 2)])
            . ($policy->maximumBytes <= MediaContract::MAX_BYTES ? ' ' . $t->html('video.qualification_required') : '');
    }
}
