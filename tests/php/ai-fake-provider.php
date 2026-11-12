<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Ai\AssistanceCandidate;
use Utsuri\PortableCms\Conventional\Ai\AssistanceProvider;
use Utsuri\PortableCms\Conventional\Ai\AssistanceRequest;

/** Test-only provider; production has no fake-provider switch or fixture back door. */
final class AiFakeProvider implements AssistanceProvider
{
    public int $calls = 0;
    public ?Throwable $failure = null;
    public ?Closure $afterDispatch = null;

    public function suggest(AssistanceRequest $request, string $imageBytes, string $idempotencyKey): AssistanceCandidate
    {
        $this->calls++;
        assertTrueValue(strlen($imageBytes) > 0, 'provider receives verified derivative bytes');
        assertSameValue($request->frozen['derivativeChecksum'], hash('sha256', $imageBytes), 'dispatch freezes exact derivative checksum');
        if ($this->afterDispatch !== null) {
            ($this->afterDispatch)();
        }
        if ($this->failure !== null) {
            throw $this->failure;
        }
        $suggestions = [];
        foreach ($request->locales() as $locale) {
            $suggestions[$locale] = ['description' => 'Draft image description (' . $locale . ')',
                'caption' => 'Draft caption (' . $locale . ')', 'alt' => 'Draft contextual alternative text (' . $locale . ')',
                'taxonomy' => ['Draft taxonomy label (' . $locale . ')']];
        }
        return AssistanceCandidate::from(['formatVersion' => 1, 'suggestions' => $suggestions], $request->locales());
    }
}
