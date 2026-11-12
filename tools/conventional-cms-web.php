<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\ApplicationFactory;
use Utsuri\PortableCms\Conventional\Http\InvalidRequestBody;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Http\RequestBodyTooLarge;
use Utsuri\PortableCms\Conventional\Http\Response;
use Utsuri\PortableCms\Conventional\Http\SecurityHeaders;
use Utsuri\PortableCms\Conventional\Media\MediaContract;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;
use Utsuri\PortableCms\Conventional\View\Pages;

require_once dirname(__DIR__) . '/engine/src/cms/conventional/bootstrap.php';

$configPath = getenv('CMS_CONFIG');
try {
    if (!is_string($configPath) || $configPath === '') {
        throw new RuntimeException('CMS configuration path is unavailable');
    }
    $runtimeLease ??= \Utsuri\PortableCms\Conventional\Upgrade\RuntimeLease::guard($configPath, dirname(__DIR__));
    $configuration = RuntimeConfiguration::load(
        $configPath,
        is_string($_SERVER['DOCUMENT_ROOT'] ?? null) ? $_SERVER['DOCUMENT_ROOT'] : null,
    );
    // The stable dispatcher holds the runtime lease and supplies this selected release.
    $releaseId = isset($active) ? ($active['releaseId'] ?? null) : 'initial';
    if (!is_string($releaseId) || preg_match('/\A(?:initial|[a-f0-9]{64})\z/', $releaseId) !== 1) {
        throw new RuntimeException('Installed release identity is invalid');
    }
    $application = ApplicationFactory::identity($configuration, installedReleaseId: $releaseId);
    try {
        $request = Request::fromGlobals(4_194_304, MediaContract::PART_BYTES);
        $response = $application->handle($request);
    } catch (RequestBodyTooLarge) {
        $correlationId = bin2hex(random_bytes(12));
        $response = SecurityHeaders::apply(
            new Response(413, Pages::error(413, $correlationId)),
            $correlationId,
        );
    } catch (InvalidRequestBody) {
        $correlationId = bin2hex(random_bytes(12));
        $response = SecurityHeaders::apply(new Response(400, Pages::error(400, $correlationId)), $correlationId);
    }
    $wakeMedia = $configuration->mediaWakeStrategy === 'post_response'
        && isset($request)
        && $request->method === 'POST'
        && (
            ($response->status === 202
                && preg_match('#\A/api/media-uploads/[a-f0-9]{32}/complete\z#', $request->path) === 1)
            || ($response->status === 202 && $request->path === '/jobs/wake')
            || ($response->status === 202 && preg_match('#\A/records/[a-z][a-z0-9_-]*/[a-f0-9]{32}/upload\z#', $request->path) === 1)
        );
    $wakePreview = $configuration->mediaWakeStrategy === 'post_response'
        && isset($request)
        && $request->method === 'POST'
        && (($response->status === 202 && $request->path === '/previews')
            || ($response->status === 303
                && (\Utsuri\PortableCms\Conventional\Preview\EditorExactPreviewHttp::route($request)['open'] ?? false)
                && preg_match('/[?&]waiting=[a-f0-9]{32}\z/', $response->headers()['location'][0] ?? '') === 1)
            || ($response->status === 303 && $request->path === '/releases' && $request->formValue('action') === 'build_holding'));
    $wakePublication = $configuration->mediaWakeStrategy === 'post_response' && isset($request)
        && $request->method === 'POST' && $response->status === 303 && $request->path === '/releases' && $request->formValue('action') !== 'build_holding';
    if ((!$wakeMedia && !$wakePreview && !$wakePublication) || !function_exists('fastcgi_finish_request')) {
        $response->emit();
    }
    $response->send();
    fastcgi_finish_request();
    ignore_user_abort(true);
    try {
        $workerId = 'web-' . substr(hash('sha256', gethostname() ?: 'host'), 0, 16);
        if ($wakePublication) {
            $publication = ApplicationFactory::publicationRunner($configuration);
            $deadline = microtime(true) + 40;
            for ($batch = 0; $batch < 32 && microtime(true) < $deadline && $publication?->runnable() === true; ++$batch) {
                $publication->runBatch(8);
            }
        } elseif ($wakePreview) {
            ApplicationFactory::previewRunner($configuration)->runOne($workerId);
        } else {
            ApplicationFactory::mediaRunner($configuration)->runBounded($workerId, 1, 50);
        }
    } catch (Throwable) {
        // Durable queued work remains available to cron recovery.
    }
    exit;
} catch (Throwable) {
    $correlationId = bin2hex(random_bytes(12));
    SecurityHeaders::apply(
        new Response(503, Pages::error(503, $correlationId)),
        $correlationId,
    )->emit();
}
