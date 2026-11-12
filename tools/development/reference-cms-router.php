<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\ApplicationFactory;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\Http\RequestBodyTooLarge;
use Utsuri\PortableCms\Conventional\Http\Response;
use Utsuri\PortableCms\Conventional\Http\SecurityHeaders;
use Utsuri\PortableCms\Conventional\Media\MediaContract;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;
use Utsuri\PortableCms\Conventional\View\Pages;

$repository = dirname(__DIR__, 2);
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$path = parse_url($uri, PHP_URL_PATH);
$assets = [
    '/assets/admin-start.js' => [$repository . '/engine/src/cms/conventional/client/admin-start.js', 'text/javascript; charset=utf-8'],
    '/assets/record-autosave.js' => [$repository . '/engine/src/cms/conventional/client/record-autosave.js', 'text/javascript; charset=utf-8'],
    '/assets/record-keeper.js' => [$repository . '/engine/src/cms/conventional/client/record-keeper.js', 'text/javascript; charset=utf-8'],
    '/assets/video-previews.js' => [$repository . '/engine/src/cms/conventional/client/video-previews.js', 'text/javascript; charset=utf-8'],
    '/assets/video-previews.css' => [$repository . '/engine/src/cms/conventional/client/video-previews.css', 'text/css; charset=utf-8'],
    '/assets/focal-point.js' => [$repository . '/engine/src/cms/conventional/client/focal-point.js', 'text/javascript; charset=utf-8'],
    '/assets/focal-point.css' => [$repository . '/engine/src/cms/conventional/client/focal-point.css', 'text/css; charset=utf-8'],
    '/assets/preview-share.js' => [$repository . '/engine/src/cms/conventional/client/preview-share.js', 'text/javascript; charset=utf-8'],
    '/assets/preview-cleanup.js' => [$repository . '/engine/src/cms/conventional/client/preview-cleanup.js', 'text/javascript; charset=utf-8'],
    '/assets/preview-cleanup.css' => [$repository . '/engine/src/cms/conventional/client/preview-cleanup.css', 'text/css; charset=utf-8'],
    '/assets/preview-share.css' => [$repository . '/engine/src/cms/conventional/client/preview-share.css', 'text/css; charset=utf-8'],
    '/assets/composition-editor.js' => [$repository . '/engine/src/cms/conventional/client/composition-editor.js', 'text/javascript; charset=utf-8'],
    '/assets/composition-model.js' => [$repository . '/engine/src/cms/conventional/client/composition-model.js', 'text/javascript; charset=utf-8'],
    '/assets/groups.css' => [$repository . '/engine/src/cms/conventional/client/groups.css', 'text/css; charset=utf-8'],
    '/assets/group-rows.js' => [$repository . '/engine/src/cms/conventional/client/group-rows.js', 'text/javascript; charset=utf-8'],
    '/assets/live-preview.js' => [$repository . '/engine/src/cms/conventional/client/live-preview.js', 'text/javascript; charset=utf-8'],
    '/assets/live-vimeo.js' => [$repository . '/engine/src/cms/conventional/client/live-vimeo.js', 'text/javascript; charset=utf-8'],
    '/assets/preview-vimeo.js' => [$repository . '/engine/src/cms/conventional/client/preview-vimeo.js', 'text/javascript; charset=utf-8'],
    '/assets/vimeo-player.js' => [$repository . '/engine/src/cms/conventional/client/vimeo-player.js', 'text/javascript; charset=utf-8'],
    '/assets/live-preview.css' => [$repository . '/engine/src/cms/conventional/client/live-preview.css', 'text/css; charset=utf-8'],
    '/assets/rich-text-editor.js' => [$repository . '/engine/src/cms/conventional/client/rich-text-editor.js', 'text/javascript; charset=utf-8'],
    '/assets/rich-text-model.js' => [$repository . '/engine/src/cms/conventional/client/rich-text-model.js', 'text/javascript; charset=utf-8'],
    '/assets/rich-text-preview.js' => [$repository . '/engine/src/cms/conventional/client/rich-text-preview.js', 'text/javascript; charset=utf-8'],
    '/assets/rich-text-surface.js' => [$repository . '/engine/src/cms/conventional/client/rich-text-surface.js', 'text/javascript; charset=utf-8'],
    '/assets/rich-text-clipboard.js' => [$repository . '/engine/src/cms/conventional/client/rich-text-clipboard.js', 'text/javascript; charset=utf-8'],
    '/assets/rich-text-history.js' => [$repository . '/engine/src/cms/conventional/client/rich-text-history.js', 'text/javascript; charset=utf-8'],
    '/assets/rich-text-selection.js' => [$repository . '/engine/src/cms/conventional/client/rich-text-selection.js', 'text/javascript; charset=utf-8'],
    '/assets/admin-shell.js' => [$repository . '/engine/src/cms/conventional/client/admin-shell.js', 'text/javascript; charset=utf-8'],
    '/assets/messages.js' => [$repository . '/engine/src/cms/conventional/client/messages.js', 'text/javascript; charset=utf-8'],
    '/assets/media-descriptions.js' => [$repository . '/engine/src/cms/conventional/client/media-descriptions.js', 'text/javascript; charset=utf-8'],
    '/assets/media-descriptions-model.js' => [$repository . '/engine/src/cms/conventional/client/media-descriptions-model.js', 'text/javascript; charset=utf-8'],
    '/assets/editorial-form.js' => [$repository . '/engine/src/cms/conventional/client/editorial-form.js', 'text/javascript; charset=utf-8'],
    '/assets/admin.css' => [$repository . '/engine/src/cms/conventional/client/admin.css', 'text/css; charset=utf-8'],
    '/assets/resumable-upload.js' => [
        $repository . '/engine/src/cms/conventional/client/resumable-upload.js',
        'text/javascript; charset=utf-8',
    ],
];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && is_string($path) && isset($assets[$path])) {
    [$asset, $mime] = $assets[$path];
    header('Content-Type: ' . $mime);
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($asset);
    return;
}

require_once $repository . '/engine/src/cms/conventional/bootstrap.php';
$configurationPath = getenv('CMS_CONFIG');
$profileRoot = getenv('CMS_PROFILE_ROOT');
try {
    if (!is_string($configurationPath) || !is_string($profileRoot)) {
        throw new RuntimeException('Local CMS environment is unavailable');
    }
    $configuration = RuntimeConfiguration::load($configurationPath);
    $profile = ProfilePackage::load($profileRoot)->manifest;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && is_string($path)) {
        $package = ProfilePackage::load($profileRoot);
        foreach ($profile->assets as $asset) {
            if ($asset->mime === 'font/woff2' && $path === '/' . $asset->path) {
                header('Content-Type: font/woff2');
                header('Cache-Control: no-store');
                header('X-Content-Type-Options: nosniff');
                readfile($package->assetPath($asset->path));
                return;
            }
        }
    }
    $application = ApplicationFactory::identity($configuration, $profile);
    try {
        $request = Request::fromGlobals(4_194_304, MediaContract::PART_BYTES);
        $response = $application->handle($request);
    } catch (RequestBodyTooLarge) {
        $correlationId = bin2hex(random_bytes(12));
        $response = SecurityHeaders::apply(
            new Response(413, Pages::error(413, $correlationId)),
            $correlationId,
            insecureLoopback: true,
        );
    }
    $response->send();
} catch (Throwable) {
    $correlationId = bin2hex(random_bytes(12));
    SecurityHeaders::apply(
        new Response(503, Pages::error(503, $correlationId)),
        $correlationId,
        insecureLoopback: true,
    )->send();
}
