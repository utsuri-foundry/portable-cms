<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional;

use Closure;
use Utsuri\PortableCms\Conventional\Media\MediaRunResult;
use Utsuri\PortableCms\Conventional\Preview\PreviewRunResult;

/** One shared job and elapsed-time budget for the scheduled worker's two lanes. */
final readonly class MediaPreviewWorker
{
    /**
     * @param Closure(): MediaRunResult $media
     * @param Closure(): PreviewRunResult $preview
     * @param Closure(): float $seconds Monotonic time in seconds.
     */
    public function __construct(private Closure $media, private Closure $preview, private Closure $seconds) {}

    /** @return array{media: list<MediaRunResult>, preview: list<PreviewRunResult>} */
    public function run(int $maximumJobs, float $deadline): array
    {
        $maximumJobs = max(1, min(10, $maximumJobs));
        $results = ['media' => [], 'preview' => []];
        $mediaEmpty = false;
        $previewEmpty = false;
        while (count($results['media']) + count($results['preview']) < $maximumJobs && ($this->seconds)() < $deadline) {
            // Reserve the first claim for previews, then let media use the rest.
            // An empty lane lends its slots to the other lane in the same pass.
            if (!$previewEmpty && ($results['preview'] === [] || $mediaEmpty)) {
                $result = ($this->preview)();
                if ($result->claimed) {
                    $results['preview'][] = $result;
                } else {
                    $previewEmpty = true;
                }
            } elseif (!$mediaEmpty) {
                $result = ($this->media)();
                if ($result->claimed) {
                    $results['media'][] = $result;
                } else {
                    $mediaEmpty = true;
                }
            } else {
                break;
            }
        }
        return $results;
    }
}
