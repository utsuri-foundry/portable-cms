<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Preview;

use Utsuri\PortableCms\Conventional\Compiler\CompilationRequest;
use Utsuri\PortableCms\Conventional\Compiler\CompilerSource;
use Utsuri\PortableCms\Conventional\Compiler\FilesystemMediaSource;
use Utsuri\PortableCms\Conventional\Compiler\FrozenSnapshot;
use Utsuri\PortableCms\Conventional\Compiler\ProfileBuildFailure;
use Utsuri\PortableCms\Conventional\Compiler\ProfileBuildFailures;
use Utsuri\PortableCms\Conventional\Compiler\ProfileBuildReason;
use Utsuri\PortableCms\Conventional\Compiler\ProfileSiteBuilder;
use Utsuri\PortableCms\Conventional\Compiler\StaticSiteCompiler;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Conventional\Design\FilesystemFontStore;
use Utsuri\PortableCms\Conventional\JsonSchemaValidator;

final class PreviewPreflightFailure extends \RuntimeException
{
    /**
     * @param list<\Utsuri\PortableCms\Conventional\I18n\Message> $issues
     * @param list<string|null> $links
     */
    public function __construct(public readonly array $issues, public readonly ?string $reference = null, ?\Throwable $previous = null, public readonly ?\DateTimeImmutable $checkedAt = null, public readonly array $links = [])
    {
        parent::__construct('Saved website content needs attention before building.', previous: $previous);
    }
}

/** Adapts a frozen database projection to the ordinary static compiler, not a second renderer. */
final class ProfilePreviewCompiler implements PreviewCompiler
{
    private readonly ProfileSiteBuilder $builder;

    /** @param null|\Closure(\Throwable): string $report */
    public function __construct(private readonly ProfilePackage $package, private readonly string $mediaRoot, private readonly string $schemaRoot, private readonly ?\Closure $report = null)
    {
        $this->builder = $package->siteBuilder();
    }

    public function preflight(PreviewSnapshot $snapshot): void
    {
        try {
            $this->checkRequired($snapshot);
            $frozen = $this->freeze($snapshot);
            $site = $this->builder->build($frozen);
            \Utsuri\PortableCms\Conventional\Compiler\MediaPublicationValidation::assertSite($site, $frozen);
            if ($frozen->publishedSlugHistory !== null) {
                if ($site->discovery === null) {
                    throw new \InvalidArgumentException('Published history requires verified route identities');
                }
                $frozen->publishedSlugHistory->redirectsFor($site->discovery, $frozen->profileId, $frozen->defaultLocale);
            }
        } catch (PreviewPreflightFailure|PreviewBuildFailure $error) {
            throw $error;
        } catch (\Throwable $error) {
            $diagnostics = $this->diagnostics($error, $snapshot);
            throw new PreviewPreflightFailure($diagnostics->issues, $diagnostics->reference, $error);
        }
    }

    /**
     * Values the profile requires on publication, checked before building so the reason names the field.
     * Languages in preparation never block: their missing values show the primary language.
     */
    public function checkRequired(PreviewSnapshot $snapshot): void
    {
        $issues = [];
        $links = [];
        foreach ($snapshot->records as $record) {
            if (!$snapshot->includes($record)) {
                continue;
            }
            $type = $this->profile($snapshot)->type($record->typeId);
            if ($type === null) {
                $issues[] = new \Utsuri\PortableCms\Conventional\I18n\Message('preflight.issue.unknown_record_type', ['type' => $record->typeId, 'title' => PreviewDiagnostics::title($record, $this->profile($snapshot))]);
                $links[] = null;
                continue;
            }
            foreach ($type->fields as $field) {
                if (!$field->requiredOnPublish) {
                    continue;
                }
                foreach ($field->localized ? array_values(array_diff($snapshot->locales, $snapshot->preparationLocales)) : [''] as $locale) {
                    $raw = $record->body[$field->id] ?? null;
                    $value = $field->localized && is_array($raw) ? ($raw[$locale] ?? null) : $raw;
                    $missing = $value === null || $value === [] || (is_string($value) && trim($value) === '');
                    if ($field->type === 'rich_text' && is_array($value)) {
                        try {
                            $missing = !\Utsuri\PortableCms\Conventional\Content\RichText::from($value, $field)->hasText();
                        } catch (\InvalidArgumentException $error) {
                            $issues[] = PreviewDiagnostics::cause(new ProfileBuildFailure(ProfileBuildReason::InvalidContent, $record->id, $record->typeId, fieldId: $field->id, locale: $locale, previous: $error), $snapshot, $this->profile($snapshot));
                            $links[] = '/records/' . $type->id . '/' . $record->id;
                            continue;
                        }
                    }
                    if ($missing) {
                        $issues[] = PreviewDiagnostics::cause(new ProfileBuildFailure($locale === '' ? ProfileBuildReason::RequiredValue : ProfileBuildReason::MissingTranslation, $record->id, $record->typeId, fieldId: $field->id, locale: $locale), $snapshot, $this->profile($snapshot));
                        $links[] = '/records/' . $type->id . '/' . $record->id;
                    }
                }
            }
        }
        foreach (self::missingWording($this->profile($snapshot), $snapshot) as $issue) {
            $issues[] = $issue;
            $links[] = '/settings/wording';
        }
        if ($issues !== []) {
            $error = new PreviewPreflightFailure(array_slice($issues, 0, 100), links: array_slice($links, 0, 100));
            throw new PreviewPreflightFailure($error->issues, $this->report === null ? null : ($this->report)($error), $error, links: $error->links);
        }
    }

    /**
     * A public language is published only with its site wording complete; the issue names every
     * missing text. Languages in preparation show the primary language's wording instead.
     * @return list<\Utsuri\PortableCms\Conventional\I18n\Message>
     */
    public static function missingWording(\Utsuri\PortableCms\Conventional\Content\ProfileManifest $profile, PreviewSnapshot $snapshot): array
    {
        $wording = self::wording($profile, $snapshot);
        $issues = [];
        foreach (array_values(array_diff($snapshot->locales, $snapshot->preparationLocales)) as $locale) {
            $missing = $wording->missing($profile, $locale);
            if ($missing !== []) {
                $issues[] = new \Utsuri\PortableCms\Conventional\I18n\Message('preflight.issue.wording', ['language' => PreviewDiagnostics::language($profile, $locale), 'keys' => implode(', ', $missing)]);
            }
        }
        return $issues;
    }

    /** The installation's wording as the installed profile reads it, its defaults filling the rest. */
    private static function wording(\Utsuri\PortableCms\Conventional\Content\ProfileManifest $profile, PreviewSnapshot $snapshot): \Utsuri\PortableCms\Conventional\Content\SiteWording
    {
        return \Utsuri\PortableCms\Conventional\Content\SiteWording::stored(['formatVersion' => 1, 'profileId' => $profile->id, 'revision' => 0, 'values' => $snapshot->wording], $profile);
    }

    /** The profile with the languages the installation added, as the snapshot draws them. */
    private function profile(PreviewSnapshot $snapshot): \Utsuri\PortableCms\Conventional\Content\ProfileManifest
    {
        try {
            return $this->package->manifest->carrying($snapshot->locales);
        } catch (\InvalidArgumentException) {
            // Building refuses such a snapshot; explaining why needs only the profile's own languages.
            return $this->package->manifest;
        }
    }

    public function startup(): PreviewStartup
    {
        return new PreviewStartup($this->package->manifest, $this->builder instanceof PreviewStartupRequirements ? $this->builder->requiredSingletons() : []);
    }

    public function compile(PreviewSnapshot $snapshot, string $outputRoot): PreviewBuild
    {
        try {
            $this->checkRequired($snapshot);
            return $this->compileChecked($snapshot, $outputRoot);
        } catch (PreviewPreflightFailure $error) {
            throw new PreviewBuildFailure('preview_content_invalid', false, 'Saved website content needs attention.', $error, new PreviewDiagnostics($error->issues, $error->reference));
        } catch (PreviewBuildFailure $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new PreviewBuildFailure('preview_build_failed', !$error instanceof \InvalidArgumentException, 'Preview build failed.', $error, $this->diagnostics($error, $snapshot));
        }
    }

    public function diagnostics(\Throwable $error, PreviewSnapshot $snapshot): PreviewDiagnostics
    {
        if ($error instanceof ProfileBuildFailures) {
            $issues = array_map(fn(ProfileBuildFailure $failure): \Utsuri\PortableCms\Conventional\I18n\Message => PreviewDiagnostics::cause($failure, $snapshot, $this->profile($snapshot)), $error->failures);
        } elseif ($error instanceof \Utsuri\PortableCms\Conventional\I18n\InvalidInput) {
            $issues = [$error->text()];
        } else {
            $cause = $error instanceof ProfileBuildFailure ? $error : new ProfileBuildFailure(ProfileBuildReason::ProfileError, previous: $error);
            $issues = [PreviewDiagnostics::cause($cause, $snapshot, $this->profile($snapshot))];
        }
        return new PreviewDiagnostics($issues, $this->report === null ? null : ($this->report)($error));
    }

    private function compileChecked(PreviewSnapshot $snapshot, string $outputRoot): PreviewBuild
    {
        $frozen = $this->freeze($snapshot);
        $result = (new StaticSiteCompiler($this->schemaRoot . '/portable-cms-artifact-v1.schema.json', $this->schemaRoot . '/portable-cms-search-v1.schema.json', new \Utsuri\PortableCms\Conventional\Compiler\ImagickSharingImageProcessor(), new \Utsuri\PortableCms\Conventional\Compiler\ImagickWebsiteIconProcessor()))->compile(
            $this->package,
            $frozen,
            $this->builder,
            new FilesystemMediaSource($this->mediaRoot, privateDerivatives: true),
            new CompilationRequest($frozen->id, $snapshot->createdAt, CompilerSource::identity()),
            $outputRoot,
            new FilesystemFontStore($this->mediaRoot . '/fonts'),
        );
        $artifacts = array_map(static fn(array $file): PreviewArtifact => new PreviewArtifact($file['path'], $file['mime'], $file['byteSize'], $file['sha256']), $result->files);
        $manifestPath = $outputRoot . '/artifact.json';
        $size = filesize($manifestPath);
        $checksum = hash_file('sha256', $manifestPath);
        if (!is_int($size) || !is_string($checksum)) {
            throw new PreviewBuildFailure('preview_manifest_missing', false, 'The static artifact manifest is unavailable.');
        }
        $artifacts[] = new PreviewArtifact('artifact.json', 'application/json', $size, $checksum);
        return new PreviewBuild($artifacts);
    }

    public function freeze(PreviewSnapshot $snapshot): FrozenSnapshot
    {
        if ($snapshot->profileChecksum !== $this->package->checksum) {
            throw new PreviewBuildFailure('preview_profile_changed', false, 'The installed profile changed. Create a new preview.');
        }
        $languages = $snapshot->languages($this->profile($snapshot));
        $records = [];
        $media = [];
        $targetTypes = [];
        foreach ($snapshot->records as $record) {
            $targetTypes[$record->id] = $record->typeId;
        }
        foreach ($snapshot->records as $record) {
            $relations = [];
            $type = $this->profile($snapshot)->type($record->typeId);
            $sourceLocales = $this->profile($snapshot)->locales;
            foreach ($type->fields ?? [] as $definition) {
                if ($definition->type === 'blocks' && $definition->localized && is_array($record->body[$definition->id] ?? null)) {
                    $sourceLocales = array_values(array_unique([...$sourceLocales, ...array_keys($record->body[$definition->id])]));
                }
            }
            $sourceProfile = $this->package->manifest->carrying($sourceLocales);
            foreach ($record->relationships as $fieldId => $targets) {
                $field = null;
                foreach ($type->fields ?? [] as $definition) {
                    if ($definition->id === $fieldId) {
                        $field = $definition;
                    }
                }
                $field ??= $type === null ? null : \Utsuri\PortableCms\Conventional\Content\EditorialControls::nestedDefinition($type, $sourceProfile, $record->body, $fieldId);
                foreach ($targets as $position => $id) {
                    $relations[] = ['fieldId' => $fieldId, 'kind' => $field?->type === 'taxonomy_references' ? 'taxonomy' : 'record', 'targetRecordId' => $id, 'position' => $position, ...($field?->typedReferences() ? ['targetTypeId' => $targetTypes[$id] ?? 'unavailable'] : [])];
                }
            }
            if ($type !== null) {
                $relations = \Utsuri\PortableCms\Conventional\Content\Blocks::relationships($type->fields, $record->body, $languages->record($type, $record->body), $sourceProfile->locales, $languages->locales, $relations);
            }
            $records[] = ['id' => $record->id, 'typeId' => $record->typeId, 'workflowState' => $record->workflowState, 'revisionId' => $record->revisionId, 'revisionNumber' => $record->revisionNumber, 'body' => $type === null ? $record->body : $languages->record($type, $record->body), 'relationships' => $relations];
            foreach ($record->media as $asset) {
                $video = [];
                if (str_starts_with($asset->mime, 'video/')) {
                    $source = new \Utsuri\PortableCms\Conventional\Compiler\FrozenMedia($asset->id, $record->id, 'prepared_video', $asset->mime, $asset->byteSize, $asset->checksum, $asset->width, $asset->height);
                    $path = (new FilesystemMediaSource($this->mediaRoot, privateDerivatives: true))->file($source);
                    $facts = \Utsuri\PortableCms\Conventional\Media\PreencodedVideoProcessor::inspectFile($path, $asset->mime, $asset->byteSize);
                    if ($facts['width'] !== $asset->width || $facts['height'] !== $asset->height) {
                        throw new \InvalidArgumentException('Prepared video geometry changed');
                    }
                    $video = ['durationMilliseconds' => $facts['durationMilliseconds'], 'hasAudio' => $facts['hasAudio']];
                }
                $media[] = ['id' => $asset->id, 'ownerRecordId' => $record->id, ...$video, 'variant' => str_starts_with($asset->mime, 'video/') ? 'prepared_video' : 'display_960_webp', 'mime' => $asset->mime, 'byteSize' => $asset->byteSize, 'sha256' => $asset->checksum, 'width' => $asset->width, 'height' => $asset->height, 'focalPoint' => $asset->focalPoint->document(), 'text' => $languages->mediaText(($asset->text ?? \Utsuri\PortableCms\Conventional\Media\MediaText::from())->document()), 'widths' => array_map(static fn(\Utsuri\PortableCms\Conventional\Media\RasterImageWidth $width): array => $width->document(), $asset->widths)];
            }
        }
        foreach ($snapshot->websiteMedia as $asset) {
            $media[] = ['id' => $asset->id, 'ownerRecordId' => null, 'variant' => 'display_960_webp', 'mime' => $asset->mime, 'byteSize' => $asset->byteSize, 'sha256' => $asset->checksum, 'width' => $asset->width, 'height' => $asset->height, 'focalPoint' => $asset->focalPoint->document(), 'text' => $languages->mediaText(($asset->text ?? \Utsuri\PortableCms\Conventional\Media\MediaText::from())->document()), 'widths' => array_map(static fn(\Utsuri\PortableCms\Conventional\Media\RasterImageWidth $width): array => $width->document(), $asset->widths)];
        }
        $document = [
            'formatVersion' => 1,
            'snapshotId' => str_repeat('0', 32),
            'createdAt' => $snapshot->createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            'profile' => ['id' => $snapshot->profileId, 'version' => $snapshot->profileVersion, 'packageSha256' => $snapshot->profileChecksum, 'defaultLocale' => $languages->primary, 'locales' => $languages->locales],
            'records' => $records,
            'media' => $media,
        ];
        if ($snapshot->includeDrafts) {
            $document['includeDrafts'] = true;
        }
        if ($snapshot->websiteIdentity !== null) {
            $document['websiteIdentity'] = $snapshot->websiteIdentity->document();
        }
        if ($languages->preparation !== []) {
            $document['preparationLocales'] = $languages->preparation;
        }
        if ($this->profile($snapshot)->wording !== []) {
            $document['wording'] = $languages->wording(self::wording($this->profile($snapshot), $snapshot)->entries($this->profile($snapshot), $languages->locales));
        }
        if ($snapshot->designRegistries !== null) {
            $document['designRegistries'] = $snapshot->designRegistries->document();
        }
        if ($snapshot->publicUrlContext !== null) {
            $document['publicUrlContext'] = $snapshot->publicUrlContext->document();
        }
        if ($snapshot->publishedSlugHistory !== null) {
            $document['publishedSlugHistory'] = $snapshot->publishedSlugHistory->document();
        }
        $document['snapshotId'] = substr(hash('sha256', json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), 0, 32);
        $schema = file_get_contents($this->schemaRoot . '/portable-cms-snapshot-v1.schema.json');
        if (!is_string($schema)) {
            throw new \RuntimeException('Preview snapshot schema is unavailable');
        }
        $json = json_encode($document, JSON_THROW_ON_ERROR);
        if (strlen($json) > \Utsuri\PortableCms\Conventional\Export\ExportCapacity::SNAPSHOT_BYTES) {
            throw new PreviewPreflightFailure([new \Utsuri\PortableCms\Conventional\I18n\Message('preflight.issue.capacity')]);
        }
        JsonSchemaValidator::validateSnapshotJson($json, $schema);
        return FrozenSnapshot::fromDocument($document, $this->package);
    }
}
