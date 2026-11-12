<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional;

use Throwable;
use Utsuri\PortableCms\Conventional\Content\ContentService;
use Utsuri\PortableCms\Conventional\Content\MariaDbRecordRepository;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Export\ExportService;
use Utsuri\PortableCms\Conventional\Export\MariaDbExportRepository;
use Utsuri\PortableCms\Conventional\Http\OriginPolicy;
use Utsuri\PortableCms\Conventional\Identity\IdentityService;
use Utsuri\PortableCms\Conventional\Identity\MariaDbIdentityRepository;
use Utsuri\PortableCms\Conventional\Identity\SystemClock;
use Utsuri\PortableCms\Conventional\Identity\SystemRandomSource;
use Utsuri\PortableCms\Conventional\Media\FilesystemMediaStore;
use Utsuri\PortableCms\Conventional\Media\FilesystemUploadStorage;
use Utsuri\PortableCms\Conventional\Media\ImagickRasterProcessor;
use Utsuri\PortableCms\Conventional\Media\MariaDbMediaJobRepository;
use Utsuri\PortableCms\Conventional\Media\MariaDbUploadRepository;
use Utsuri\PortableCms\Conventional\Media\MediaJobRunner;
use Utsuri\PortableCms\Conventional\Media\MediaUploadService;
use Utsuri\PortableCms\Conventional\Preview\FilesystemPreviewStore;
use Utsuri\PortableCms\Conventional\Preview\GateZeroPreviewCompiler;
use Utsuri\PortableCms\Conventional\Preview\MariaDbPreviewRepository;
use Utsuri\PortableCms\Conventional\Preview\PreviewJobRunner;
use Utsuri\PortableCms\Conventional\Preview\PreviewService;

final class ApplicationFactory
{
    public static function identity(
        RuntimeConfiguration $configuration,
        ?ProfileManifest $installedProfile = null,
        bool $previewAvailable = true,
        ?string $installedReleaseId = null,
    ): IdentityApplication {
        $clock = new SystemClock();
        $random = new SystemRandomSource();
        $pdo = $configuration->connect();
        $identity = new IdentityService(
            new MariaDbIdentityRepository($pdo),
            $clock,
            $random,
            $configuration->throttleSecret,
        );
        $writeLog = static function (string $entry) use ($configuration): void {
            PrivateDiagnosticLog::append($configuration->errorLog, $entry);
        };
        $observer = static function (Throwable $error, string $correlationId) use ($writeLog): void {
            $writeLog(sprintf(
                "%s correlation=%s error=%s\n",
                gmdate('Y-m-d\TH:i:s\Z'),
                $correlationId,
                $error::class,
            ));
        };
        $rejectionObserver = static function (string $reason, string $correlationId) use ($writeLog): void {
            $writeLog(sprintf(
                "%s correlation=%s event=security_rejection reason=%s\n",
                gmdate('Y-m-d\TH:i:s\Z'),
                $correlationId,
                $reason,
            ));
        };
        $package = $configuration->profileRoot === null ? null : Content\ProfilePackage::load($configuration->profileRoot);
        if ($package !== null && $installedProfile !== null && $package->manifest->id !== $installedProfile->id) {
            throw new \InvalidArgumentException('Configured and requested profile differ');
        }
        // Languages the installation added join the profile's own wherever records are edited, checked and published.
        $profile = (new Content\MariaDbSiteLanguages($pdo))->installed($package->manifest ?? $installedProfile ?? ProfileManifest::gateZero());
        $terminology = new \Utsuri\PortableCms\Conventional\Content\TerminologyService(
            new \Utsuri\PortableCms\Conventional\Content\MariaDbTerminologyRepository($pdo),
            $profile,
            $clock,
            $random,
            $package?->administrationLabels,
        );
        $design = $profile->designRegistries === null ? null : new Design\RegistryService(
            new Design\MariaDbRegistryRepository($pdo),
            $profile->designRegistries,
            $clock,
            $random,
            new Design\FontIngestion(self::fontSanitizer($configuration, $random), new Design\FilesystemFontStore($configuration->mediaRoot . '/fonts')),
        );
        $mediaStore = new FilesystemMediaStore($configuration->mediaRoot);
        $free = HostIo::attempt(fn() => disk_free_space($configuration->mediaRoot))->value;
        $videoUploads = Media\VideoUploadPolicy::qualify($configuration->videoUploads, EnvironmentReport::runtimeLimits(), is_float($free) ? (int) $free : null, Media\VideoSupport::available());
        $mediaLibrary = new Media\MediaLibrary(new Media\MariaDbMediaLibrary($pdo), $mediaStore, $clock, $random, $videoUploads);
        $mediaJobs = new MariaDbMediaJobRepository($pdo);
        $reporter = $package === null ? null : new Preview\PreviewErrorReporter($clock, $random, $configuration->errorLog, $package->root);
        $compiler = $package === null ? null : new Preview\ProfilePreviewCompiler($package, $configuration->mediaRoot, dirname(__DIR__, 4) . '/schemas', $reporter === null ? null : $reporter->report(...));
        $previewRepository = new MariaDbPreviewRepository($pdo, $profile->designRegistries, $package?->checksum, $compiler === null ? null : $compiler->preflight(...), $configuration->publicUrlContext);
        $previewStore = new FilesystemPreviewStore($configuration->mediaRoot . '/previews');
        $siteLanguages = self::siteLanguages($profile, $pdo);
        $previews = $previewAvailable
            ? new PreviewService($previewRepository, $profile, $clock, $random, $previewStore, checks: new Preview\MariaDbPreviewCheckHistory($pdo), startup: $compiler?->startup(), preparation: static fn(): array => $siteLanguages->load()->preparation())
            : null;
        return new IdentityApplication(
            $identity,
            new OriginPolicy($configuration->origin, $configuration->allowInsecureLoopback),
            $clock,
            $random,
            $observer,
            $rejectionObserver,
            new ContentService(
                new MariaDbRecordRepository($pdo),
                $profile,
                $clock,
                $random,
                $mediaLibrary,
                $design,
                static fn(): array => $siteLanguages->load()->public(),
                static fn(): Content\WebsiteIdentity => self::websitePublication($profile, $pdo)->load()->identity(),
            ),
            $profile,
            new MediaUploadService(
                new MariaDbUploadRepository($pdo),
                new FilesystemUploadStorage($configuration->mediaRoot, clock: $clock),
                $clock,
                $random,
                \Utsuri\PortableCms\Conventional\Media\MediaContract::profileVideoEnabled($profile),
                $videoUploads,
                $profile,
            ),
            $mediaJobs,
            $previews,
            new ExportService(new MariaDbExportRepository($pdo, $profile->designRegistries, $configuration->publicUrlContext, wordingProfile: $profile), $profile, $clock, $random),
            $configuration->allowInsecureLoopback,
            $terminology,
            $configuration->showTechnicalData,
            $mediaLibrary,
            $design,
            self::assistance($configuration, $profile, $pdo),
            $package?->administrationLabels,
            $previews === null ? null : new Preview\PreviewShareHttp(
                new Preview\PreviewShareService(new Preview\MariaDbPreviewShareRepository($pdo), new MariaDbIdentityRepository($pdo), $clock, $random, $configuration->throttleSecret),
                $previews,
                new OriginPolicy($configuration->origin, $configuration->allowInsecureLoopback),
                $configuration->origin,
            ),
            $previews === null ? null : self::publication($configuration, $profile, $previews, $pdo),
            self::websitePublication($profile, $pdo),
            new Identity\AccountService(new Identity\MariaDbAccountRepository($pdo), $clock, $random),
            $previews === null || $package === null || $compiler === null ? null : new Preview\LivePreviewHttp(new Preview\LivePreviewService(
                $package,
                // Without the private reporter: incomplete values while typing are expected, not incidents to log.
                new Preview\ProfilePreviewCompiler($package, $configuration->mediaRoot, dirname(__DIR__, 4) . '/schemas'),
                // Editors also write in languages in preparation; the live preview is never published.
                static fn(\DateTimeImmutable $now): Preview\PreviewSnapshot => $previewRepository->freezeSnapshot($profile->id, $profile->version, $profile->locales, $now, false, true, true),
                new Preview\MariaDbLivePreviewStore($pdo),
                $clock,
                $random,
            ), $package, $configuration->mediaRoot),
            $previews === null ? null : new Preview\PreviewCleanupHttp(new Preview\PreviewCleanupService(
                $previewRepository,
                new Preview\PreviewDeletion($previewRepository, $previewStore, $clock, $random),
                new Preview\PreviewSelection($configuration->throttleSecret, $clock),
                $clock,
            ), $previews),
            $siteLanguages,
            self::siteWording($profile, $pdo),
            self::languageText($profile, $pdo, $siteLanguages, $mediaLibrary, $design),
            $installedReleaseId,
            $previews === null ? null : new Preview\NewestPreviewHttp(
                new Preview\NewestPreviewLinkService(new Preview\MariaDbNewestPreviewLinkRepository($pdo), new MariaDbIdentityRepository($pdo), $clock, $random, $configuration->throttleSecret),
                $previews,
                new OriginPolicy($configuration->origin, $configuration->allowInsecureLoopback),
                $configuration->origin,
            ),
            new Content\RecordHistoryService(new Content\MariaDbRecordHistory($pdo), $clock, $random),
            $previews === null ? null : new Preview\EditorExactPreview($previews, $previewRepository, new Preview\MariaDbEditorPreviewFiles($pdo), $profile, $clock, $random),
            $configuration->mediaWakeStrategy === 'cron_only',
        );
    }

    public static function siteLanguages(ProfileManifest $profile, \PDO $pdo): Content\SiteLanguagesService
    {
        return new Content\SiteLanguagesService(new Content\MariaDbSiteLanguages($pdo), $profile, new SystemClock(), new SystemRandomSource());
    }

    /** Editors write the wording of the languages they work in: public and in preparation. */
    /** @param ProfileManifest $profile The installed profile, with the languages the installation added. */
    public static function languageText(ProfileManifest $profile, \PDO $pdo, Content\SiteLanguagesService $languages, ?Media\MediaLibrary $media, ?Design\RegistryService $design): Content\LanguageTextDeletionService
    {
        $clock = new SystemClock();
        $random = new SystemRandomSource();
        return new Content\LanguageTextDeletionService(
            new Content\MariaDbLanguageTextDeletion($pdo),
            // Text is required only in public languages, so the language being deleted never is.
            new ContentService(new MariaDbRecordRepository($pdo), $profile, $clock, $random, $media, $design, static fn(): array => $languages->load()->public()),
            $media,
            new Content\MariaDbSiteWording($pdo),
            new Publication\MariaDbWebsitePublication($pdo),
            $languages,
            $profile,
            $clock,
            $random,
        );
    }

    public static function siteWording(ProfileManifest $profile, \PDO $pdo): Content\SiteWordingService
    {
        $languages = self::siteLanguages($profile, $pdo);
        return new Content\SiteWordingService(new Content\MariaDbSiteWording($pdo), $profile, new SystemClock(), new SystemRandomSource(), static fn(): array => $languages->load()->editing());
    }

    public static function assistance(RuntimeConfiguration $configuration, ProfileManifest $profile, ?\PDO $pdo = null): Ai\AssistanceService
    {
        return new Ai\AssistanceService(new Ai\MariaDbAssistanceRepository($pdo ?? $configuration->connect(), $profile), $configuration->ai, $profile, new SystemClock(), new SystemRandomSource());
    }

    public static function publication(RuntimeConfiguration $configuration, ProfileManifest $profile, PreviewService $previews, ?\PDO $pdo = null): ?Publication\PublicationService
    {
        if ($configuration->publicationRoot === null || $configuration->publicUrlContext === null) {
            return null;
        }
        $journalRoot = $configuration->mediaRoot . '/publication';
        if (is_link($journalRoot)) {
            throw new Publication\PublicationFailure('publication_roots_unsafe');
        }
        if (!is_dir($journalRoot)) {
            HostIo::required(fn() => mkdir($journalRoot, 0700));
        }
        $schema = HostIo::attempt(fn() => file_get_contents(dirname(__DIR__, 4) . '/schemas/portable-cms-artifact-v1.schema.json'))->value;
        if (!is_string($schema)) {
            throw new Publication\PublicationFailure('artifact_schema_missing');
        }
        return new Publication\PublicationService(
            new Publication\MariaDbPublicationRepository($pdo ?? $configuration->connect()),
            $previews,
            new Publication\FilesystemPublicationTarget($configuration->publicationRoot, $journalRoot, hash_hmac('sha256', 'publication-target-v1', $configuration->throttleSecret, true)),
            $configuration->publicUrlContext,
            $profile->id,
            $schema,
            new SystemClock(),
            new SystemRandomSource(),
            self::holding($configuration, $profile, $pdo ?? $configuration->connect()),
            new \DateTimeZone($configuration->timeZone),
        );
    }

    public static function websitePublication(ProfileManifest $profile, \PDO $pdo): Publication\WebsitePublicationService
    {
        return new Publication\WebsitePublicationService(new Publication\MariaDbWebsitePublication($pdo), $profile, new SystemClock(), new SystemRandomSource());
    }

    private static function holding(RuntimeConfiguration $configuration, ProfileManifest $profile, \PDO $pdo): Publication\HoldingPublication
    {
        $package = $configuration->profileRoot === null ? null : Content\ProfilePackage::load($configuration->profileRoot);
        $context = $configuration->publicUrlContext ?? throw new Publication\PublicationFailure('public_context_missing');
        $repository = new MariaDbPreviewRepository($pdo, holdingSource: new Publication\HoldingSnapshotSource($pdo, new Publication\MariaDbWebsitePublication($pdo), $package?->checksum, $context));
        return new Publication\HoldingPublication(self::websitePublication($profile, $pdo), new PreviewService($repository, $profile, new SystemClock(), new SystemRandomSource(), new FilesystemPreviewStore($configuration->mediaRoot . '/previews'), 'holding'), static fn(): array => self::siteLanguages($profile, $pdo)->load()->public());
    }

    public static function publicationRunner(RuntimeConfiguration $configuration): ?Publication\PublicationService
    {
        $package = $configuration->profileRoot === null ? null : Content\ProfilePackage::load($configuration->profileRoot);
        $pdo = $configuration->connect();
        $profile = (new Content\MariaDbSiteLanguages($pdo))->installed($package->manifest ?? ProfileManifest::gateZero());
        $previews = new PreviewService(new MariaDbPreviewRepository($pdo), $profile, new SystemClock(), new SystemRandomSource(), new FilesystemPreviewStore($configuration->mediaRoot . '/previews'));
        return self::publication($configuration, $profile, $previews, $pdo);
    }

    public static function assistanceRunner(RuntimeConfiguration $configuration, ProfileManifest $profile): Ai\AssistanceRunner
    {
        $clock = new SystemClock();
        $random = new SystemRandomSource();
        $pdo = $configuration->connect();
        $profile = (new Content\MariaDbSiteLanguages($pdo))->installed($profile);
        $repository = new Ai\MariaDbAssistanceRepository($pdo, $profile);
        $service = new Ai\AssistanceService($repository, $configuration->ai, $profile, $clock, $random);
        $media = new Media\MediaLibrary(new Media\MariaDbMediaLibrary($pdo), new FilesystemMediaStore($configuration->mediaRoot), $clock, $random);
        return new Ai\AssistanceRunner($repository, $service, $configuration->ai === null ? null : new Ai\HttpAssistanceProvider($configuration->ai, new Ai\CurlAssistanceTransport($configuration->ai->publicAddress)), new Ai\LibraryAssistanceImageSource($media), $clock, $random);
    }

    private static function fontSanitizer(RuntimeConfiguration $configuration, SystemRandomSource $random): ?Design\FontSanitizer
    {
        if ($configuration->fontSanitizer === null || $configuration->fontCompressor === null) {
            return null;
        }
        try {
            return new Design\NativeFontSanitizer($configuration->fontSanitizer, $configuration->fontCompressor, $configuration->mediaRoot, $random);
        } catch (Design\FontUnavailable) {
            return null;
        }
    }

    public static function mediaRunner(RuntimeConfiguration $configuration): MediaJobRunner
    {
        $clock = new SystemClock();
        $random = new SystemRandomSource();
        $observe = static function (array $entry) use ($configuration): void {
            if ($configuration->errorLog === null) {
                return;
            }
            $entry['at'] = gmdate('Y-m-d\TH:i:s\Z');
            $encoded = json_encode($entry, JSON_UNESCAPED_SLASHES);
            if (!is_string($encoded)) {
                return;
            }
            PrivateDiagnosticLog::append($configuration->errorLog, $encoded . "\n");
        };
        return new MediaJobRunner(
            new MariaDbMediaJobRepository($configuration->connect(), prepareWidths: extension_loaded('imagick') && class_exists(\Imagick::class)),
            new FilesystemMediaStore($configuration->mediaRoot),
            new \Utsuri\PortableCms\Conventional\Media\PreparedMediaProcessor(new ImagickRasterProcessor()),
            $clock,
            $random,
            $observe,
        );
    }

    public static function previewRunner(RuntimeConfiguration $configuration): PreviewJobRunner
    {
        $clock = new SystemClock();
        $random = new SystemRandomSource();
        $observe = static function (array $entry) use ($configuration): void {
            if ($configuration->errorLog === null) {
                return;
            }
            $entry['at'] = gmdate('Y-m-d\TH:i:s\Z');
            $encoded = json_encode($entry, JSON_UNESCAPED_SLASHES);
            if (!is_string($encoded)) {
                return;
            }
            PrivateDiagnosticLog::append($configuration->errorLog, $encoded . "\n");
        };
        $package = $configuration->profileRoot === null ? null : Content\ProfilePackage::load($configuration->profileRoot);
        $reporter = $package === null ? null : new Preview\PreviewErrorReporter($clock, $random, $configuration->errorLog, $package->root);
        $compiler = $package === null ? new GateZeroPreviewCompiler($configuration->mediaRoot) : new Preview\ProfilePreviewCompiler($package, $configuration->mediaRoot, dirname(__DIR__, 4) . '/schemas', $reporter === null ? null : $reporter->report(...));
        return new PreviewJobRunner(
            new MariaDbPreviewRepository($configuration->connect(), $package?->manifest->designRegistries, $package?->checksum),
            new FilesystemPreviewStore($configuration->mediaRoot . '/previews'),
            new Preview\PublicationBuildCompiler($compiler, new Compiler\HoldingPageCompiler(dirname(__DIR__, 4) . '/schemas', $configuration->mediaRoot, $package, new Compiler\ImagickWebsiteIconProcessor())),
            $clock,
            $random,
            $observe,
        );
    }
}
