<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

use Utsuri\PortableCms\Conventional\HostIo;
use Utsuri\PortableCms\Conventional\JsonSchemaValidator;

final class ProfileEngineMismatch extends \InvalidArgumentException
{
    public function __construct(public readonly string $minimum, public readonly string $maximumExclusive, public readonly string $running)
    {
        parent::__construct("Profile requires engine >= {$minimum} and < {$maximumExclusive}; running engine is {$running}");
    }

    /** @return array{error: string, message: string, minimumEngineVersion: string, maximumEngineVersionExclusive: string, runningEngineVersion: string} */
    public function document(): array
    {
        return ['error' => 'profile_engine_incompatible', 'message' => $this->getMessage(), 'minimumEngineVersion' => $this->minimum, 'maximumEngineVersionExclusive' => $this->maximumExclusive, 'runningEngineVersion' => $this->running];
    }
}

final readonly class ProfileCompatibility
{
    /** @param array<string, int> $contracts */
    public function __construct(
        public string $minimumEngineVersion,
        public string $maximumEngineVersionExclusive,
        public array $contracts,
    ) {
        if (
            !ProfileManifest::semanticVersion($minimumEngineVersion)
            || !ProfileManifest::semanticVersion($maximumEngineVersionExclusive)
            || version_compare($minimumEngineVersion, $maximumEngineVersionExclusive, '>=')
        ) {
            throw new \InvalidArgumentException('Profile engine compatibility range is invalid');
        }
        $expected = [
            'record' => 1,
            'protocol' => 1,
            'snapshot' => 1,
            'artifact' => 1,
            'search' => 1,
            'export' => 1,
            'capabilities' => 1,
        ];
        $normalizedContracts = $contracts;
        ksort($normalizedContracts, SORT_STRING);
        ksort($expected, SORT_STRING);
        if ($normalizedContracts !== $expected) {
            throw new \InvalidArgumentException('Profile contract compatibility is invalid');
        }
    }

    public function supports(string $engineVersion): bool
    {
        return ProfileManifest::semanticVersion($engineVersion)
            && version_compare($engineVersion, $this->minimumEngineVersion, '>=')
            && version_compare($engineVersion, $this->maximumEngineVersionExclusive, '<');
    }
}

final readonly class LocaleDefinition
{
    /** A language in preparation is edited and checked for coverage, but required text is not enforced in it. */
    public function __construct(public string $id, public string $label, public bool $preparation = false)
    {
        if (!ProfileManifest::localeId($id) || !ProfileManifest::label($label)) {
            throw new \InvalidArgumentException('Profile locale is invalid');
        }
    }
}

final readonly class FieldDefinition
{
    public bool $required;
    /** @var list<string> */
    public array $targetTypes;
    /** @var array<string, BlockTypeDefinition> */
    public array $blockTypes;

    /**
     * @param array<string, mixed> $settings
     * @param list<FieldDefinition> $fields
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $type,
        public bool $localized,
        public bool $requiredOnDraft,
        public bool $requiredOnPublish,
        public array $settings,
        public array $fields = [],
        public ?int $maximumLength = null,
        public ?string $targetType = null,
        public ?int $maximumItems = null,
        public ?string $editorialSection = null,
        public ?string $hint = null,
    ) {
        if (!ProfileManifest::fieldId($id) || !ProfileManifest::label($label)) {
            throw new \InvalidArgumentException('Profile field identity is invalid');
        }
        if ($hint !== null && !self::validHint($hint)) {
            throw new \InvalidArgumentException('Profile field hint is invalid');
        }
        if ($editorialSection !== null && !in_array(
            $editorialSection,
            ['details', 'content', 'media', 'relationships', 'layout', 'discovery'],
            true,
        )) {
            throw new \InvalidArgumentException('Profile editorial section is invalid');
        }
        if (!in_array($type, self::types(), true)) {
            throw new \InvalidArgumentException('Profile field type is not supported');
        }
        if (($settings['purpose'] ?? null) === 'website-icon' && ($type !== 'media_references' || $maximumItems !== 1 || ($settings['kinds'] ?? null) !== ['image'])) {
            throw new \InvalidArgumentException('Website icon fields require one image selection');
        }
        if ($requiredOnDraft && !$requiredOnPublish) {
            throw new \InvalidArgumentException('Draft-required field must also be required for publication');
        }
        if ($localized && !in_array($type, [
            'localized_short_text',
            'web_address',
            'vimeo_video',
            'multiline_text',
            'rich_text',
            'group',
            'repeatable_group',
            'blocks',
            'composition',
        ], true)) {
            throw new \InvalidArgumentException('Profile field cannot be localized');
        }
        if ($type === 'vimeo_video' && $settings !== []) {
            throw new \InvalidArgumentException('Vimeo fields do not accept settings');
        }
        if ($type === 'video_reference') {
            VideoLimits::settings($settings);
        }
        if (array_key_exists('maximumBlocks', $settings) && ($type !== 'rich_text' || $settings['maximumBlocks'] !== 1)) {
            throw new \InvalidArgumentException('Rich text maximumBlocks must be 1');
        }
        $this->blockTypes = $type === 'blocks' ? Blocks::definitions($settings) : [];
        $this->required = $requiredOnDraft;
        if ($type === 'web_address' && ($maximumLength === null || $maximumLength < 1 || $maximumLength > WebAddress::MAXIMUM_LENGTH)) {
            throw new \InvalidArgumentException('Web address maximum length must be between 1 and 2048 bytes');
        }
        $targets = $settings['targetTypes'] ?? ($targetType === null ? [] : [$targetType]);
        if (!is_array($targets) || !array_is_list($targets) || count($targets) > 8
            || count(array_unique($targets, SORT_REGULAR)) !== count($targets)) {
            throw new \InvalidArgumentException('Reference target types are invalid');
        }
        foreach ($targets as $target) {
            if (!is_string($target) || !ProfileManifest::canonicalId($target)) {
                throw new \InvalidArgumentException('Reference target type is invalid');
            }
        }
        if (isset($settings['targetTypes']) && ($targets === [] || $targetType !== null || !in_array($type, ['record_reference', 'record_references', 'composition'], true))) {
            throw new \InvalidArgumentException('Mixed targets require a record reference field');
        }
        $this->targetTypes = $targets;
        if ($type === 'composition' && $targets !== [] && ($settings['formatVersion'] ?? 1) !== 2) {
            throw new \InvalidArgumentException('Composition links require the v2 field capability');
        }
        $ids = [];
        foreach ($fields as $field) {
            if (!$field instanceof self || isset($ids[$field->id])) {
                throw new \InvalidArgumentException('Nested profile fields are invalid');
            }
            if (in_array($field->type, ['group', 'repeatable_group', 'blocks'], true)) {
                throw new \InvalidArgumentException('Profile field groups cannot recurse');
            }
            $ids[$field->id] = true;
        }
        if ($type === 'repeatable_group') {
            $presentation = $settings['presentation'] ?? null;
            if ($presentation !== null && !in_array($presentation, ['rows', 'cards'], true)) {
                throw new \InvalidArgumentException('Repeatable group presentation is invalid');
            }
            if ($presentation === 'rows' && (!self::inline($fields) || count($fields) > 4)) {
                throw new \InvalidArgumentException('Repeatable group rows need at most four short inline fields');
            }
            $itemLabel = $settings['itemLabel'] ?? null;
            if ($itemLabel !== null && (!is_string($itemLabel) || !ProfileManifest::label($itemLabel))) {
                throw new \InvalidArgumentException('Repeatable group item label is invalid');
            }
        }
    }

    /**
     * Whether a repeatable group is edited as one aligned list of rows: by default when it has
     * at most three short inline fields, or as the profile's presentation chooses.
     */
    public function rows(): bool
    {
        if ($this->type !== 'repeatable_group') {
            return false;
        }
        $presentation = $this->settings['presentation'] ?? null;
        return $presentation === 'rows' || ($presentation === null && count($this->fields) <= 3 && self::inline($this->fields));
    }

    /** A short, single-line administration hint, independent of the content language. */
    public static function validHint(mixed $hint): bool
    {
        return is_string($hint) && mb_check_encoding($hint, 'UTF-8') && trim($hint) !== '' && mb_strlen($hint, 'UTF-8') <= 160 && preg_match('/[\x00-\x1f\x7f]/u', $hint) === 0;
    }

    /** The profile's name for one item of a repeatable group, as it reads after "Add". */
    public function itemLabel(): ?string
    {
        $label = $this->settings['itemLabel'] ?? null;
        return is_string($label) ? $label : null;
    }

    /** @param list<FieldDefinition> $fields */
    private static function inline(array $fields): bool
    {
        foreach ($fields as $field) {
            if (!in_array($field->type, ['short_text', 'localized_short_text', 'integer', 'decimal', 'enum', 'boolean', 'web_address'], true)) {
                return false;
            }
        }
        return $fields !== [];
    }

    public function typedReferences(): bool
    {
        return in_array($this->type, ['record_reference', 'record_references'], true) && isset($this->settings['targetTypes']);
    }

    /** @return list<string> */
    private static function types(): array
    {
        return [
            'short_text',
            'localized_short_text',
            'multiline_text',
            'integer',
            'decimal',
            'boolean',
            'date',
            'enum',
            'rich_text',
            'media_references',
            'video_reference',
            'vimeo_video',
            'web_address',
            'record_reference',
            'record_references',
            'taxonomy_references',
            'group',
            'repeatable_group',
            'blocks',
            'composition',
        ];
    }
}

final readonly class RecordTypeDefinition
{
    /** @param list<FieldDefinition> $fields */
    public function __construct(
        public string $id,
        public string $label,
        public string $singularLabel,
        public array $fields,
        public string $kind,
        public string $archetype,
        public string $renderer,
        public ?string $route = null,
    ) {
        if (
            !ProfileManifest::canonicalId($id)
            || !ProfileManifest::label($label)
            || !ProfileManifest::label($singularLabel)
            || !in_array($kind, ['collection', 'singleton', 'taxonomy'], true)
            || !in_array($archetype, [
                'portfolio_entry',
                'article',
                'person',
                'organisation',
                'page',
                'site_settings',
                'taxonomy_term',
            ], true)
            || !ProfileManifest::canonicalId($renderer)
            || $fields === []
            || count($fields) > 64
        ) {
            throw new \InvalidArgumentException('Record type definition is invalid');
        }
        if (
            ($kind === 'taxonomy') !== ($archetype === 'taxonomy_term')
            || ($kind === 'singleton') !== in_array($archetype, ['page', 'site_settings'], true)
        ) {
            throw new \InvalidArgumentException('Record type archetype is invalid');
        }
        if ($kind === 'collection' && ($route === null || !ProfileManifest::canonicalId($route))) {
            throw new \InvalidArgumentException('Collection route prefix is invalid');
        }
        if ($kind === 'singleton' && ($route === null || !ProfileManifest::routePath($route))) {
            throw new \InvalidArgumentException('Singleton route path is invalid');
        }
        if ($kind === 'taxonomy' && $route !== null) {
            throw new \InvalidArgumentException('Taxonomy cannot declare a public route');
        }
        $ids = [];
        foreach ($fields as $field) {
            if (!$field instanceof FieldDefinition || isset($ids[$field->id])) {
                throw new \InvalidArgumentException('Record type fields are invalid');
            }
            $ids[$field->id] = true;
        }
    }
}

final readonly class NavigationDefinition
{
    public function __construct(
        public string $id,
        public string $label,
        public string $targetKind,
        public string $targetId,
        public int $position,
    ) {
        if (
            !ProfileManifest::canonicalId($id)
            || !ProfileManifest::label($label)
            || !in_array($targetKind, ['collection', 'singleton', 'taxonomy', 'system'], true)
            || !ProfileManifest::canonicalId($targetId)
            || $position < 0
            || $position > 255
        ) {
            throw new \InvalidArgumentException('Profile navigation item is invalid');
        }
    }
}

final readonly class RendererDefinition
{
    public function __construct(
        public string $id,
        public string $kind,
        public string $entrypoint,
    ) {
        if (
            !ProfileManifest::canonicalId($id)
            || !in_array($kind, ['record', 'index', 'singleton', 'taxonomy', 'search', 'feed'], true)
            || !ProfileManifest::safePath($entrypoint)
            || !str_ends_with($entrypoint, '.php')
        ) {
            throw new \InvalidArgumentException('Profile renderer is invalid');
        }
    }
}

final readonly class ProfileAsset
{
    public function __construct(
        public string $path,
        public string $role,
        public string $mime,
        public int $byteSize,
        public string $checksum,
    ) {
        if (
            !ProfileManifest::safePath($path)
            || !in_array($role, ['style', 'script', 'font', 'image', 'icon', 'other', 'speculation-rules'], true)
            || preg_match('/\A[a-z0-9.+-]+\/[a-z0-9.+-]+\z/', $mime) !== 1
            || $byteSize < 1
            || $byteSize > 268_435_456
            || preg_match('/\A[a-f0-9]{64}\z/', $checksum) !== 1
        ) {
            throw new \InvalidArgumentException('Profile asset is invalid');
        }
        SpeculationRules::asset($this);
    }
}

final readonly class CapabilityDeclaration
{
    /**
     * @param list<string> $required
     * @param list<string> $optional
     */
    public function __construct(public array $required, public array $optional)
    {
        $allowed = [
            'raster_images',
            'preencoded_video',
            'scheduled_jobs',
            'outbound_https',
            'outgoing_email',
            'ai_drafts',
            'public_forms',
        ];
        if (
            count($required) !== count(array_unique($required))
            || count($optional) !== count(array_unique($optional))
            || array_diff($required, $allowed) !== []
            || array_diff($optional, $allowed) !== []
            || array_intersect($required, $optional) !== []
        ) {
            throw new \InvalidArgumentException('Profile capability declaration is invalid');
        }
    }
}

final readonly class PrivacyDeclaration
{
    public function __construct(
        public bool $publicInteractions,
        public string $analytics,
        public string $personalData,
    ) {
        if (
            !in_array($analytics, ['none', 'first_party', 'profile_review_required'], true)
            || !in_array($personalData, ['none', 'editorial_accounts', 'public_submissions'], true)
            || ($publicInteractions && $personalData !== 'public_submissions')
        ) {
            throw new \InvalidArgumentException('Profile privacy declaration is invalid');
        }
    }
}

final readonly class ProfileManifest
{
    public const ENGINE_VERSION = '0.12.6';

    /** @var array<string, RecordTypeDefinition> */
    private array $typesById;

    /** @var array<string, WordingDefinition> */
    private array $wordingByKey;

    /**
     * @param list<string> $locales
     * @param list<LocaleDefinition> $localeDefinitions
     * @param list<RecordTypeDefinition> $types
     * @param list<NavigationDefinition> $navigation
     * @param list<RendererDefinition> $renderers
     * @param list<ProfileAsset> $assets
     * @param list<string>|null $supportedLocales
     * @param list<WordingDefinition> $wording
     * @param list<string> $addedLocales
     */
    public function __construct(
        public string $id,
        public string $version,
        public ProfileCompatibility $compatibility,
        public array $locales,
        public array $localeDefinitions,
        public string $defaultLocale,
        public array $types,
        public array $navigation,
        public array $renderers,
        public array $assets,
        public CapabilityDeclaration $capabilities,
        public PrivacyDeclaration $privacy,
        public ?\Utsuri\PortableCms\Conventional\Design\RegistryDocument $designRegistries = null,
        /** Every language the profile supports when $locales shows only those in use; null when they are the same. */
        public ?array $supportedLocales = null,
        /** The fixed texts the profile's pages show, which editors write per language. */
        public array $wording = [],
        /** The scripts and directions within which an installation may add catalog languages; null when it may not. */
        public ?LanguageEnvelope $envelope = null,
        /** Supported languages the installation added from the catalog, after the profile's own. */
        public array $addedLocales = [],
        public ?ContentFeed $contentFeed = null,
        /** Validated expanded definition bytes for operator comparison; package integrity still hashes the authored file. */
        public ?string $definitionJson = null,
    ) {
        if ($supportedLocales !== null && array_diff($locales, $supportedLocales) !== []) {
            throw new \InvalidArgumentException('Profile locales are invalid');
        }
        $supported = $supportedLocales ?? $locales;
        foreach ($addedLocales as $position => $locale) {
            $language = LanguageCatalog::language($locale);
            if ($language === null || $envelope === null || !$envelope->admits($language)
                || array_slice($supported, count($supported) - count($addedLocales))[$position] !== $locale) {
                throw new \InvalidArgumentException('Profile added locales are invalid');
            }
        }
        if ($designRegistries !== null && ($designRegistries->profileId() !== $id || $designRegistries->revision() !== 0)) {
            throw new \InvalidArgumentException('Profile design defaults must identify this profile at revision zero');
        }
        if (!self::canonicalId($id) || !self::semanticVersion($version)) {
            throw new \InvalidArgumentException('Profile identity is invalid');
        }
        if ($locales === [] || count($locales) > 16 || count($locales) !== count($localeDefinitions)) {
            throw new \InvalidArgumentException('Profile locales are invalid');
        }
        $localeIds = [];
        foreach ($localeDefinitions as $index => $locale) {
            if (
                ($locales[$index] ?? null) !== $locale->id
                || isset($localeIds[$locale->id])
            ) {
                throw new \InvalidArgumentException('Profile locales are invalid');
            }
            $localeIds[$locale->id] = true;
        }
        if (!isset($localeIds[$defaultLocale])) {
            throw new \InvalidArgumentException('Profile default locale is not installed');
        }

        $indexed = [];
        foreach ($types as $type) {
            if (!$type instanceof RecordTypeDefinition || isset($indexed[$type->id])) {
                throw new \InvalidArgumentException('Profile record types are invalid');
            }
            $indexed[$type->id] = $type;
        }
        if ($indexed === [] || count($indexed) > 64) {
            throw new \InvalidArgumentException('Profile must define a bounded record type');
        }

        $rendererIds = [];
        foreach ($renderers as $renderer) {
            if (!$renderer instanceof RendererDefinition || isset($rendererIds[$renderer->id])) {
                throw new \InvalidArgumentException('Profile renderers are invalid');
            }
            $rendererIds[$renderer->id] = $renderer;
        }
        $pagePaths = [];
        foreach ($types as $type) {
            $renderer = $rendererIds[$type->renderer] ?? null;
            $expectedKind = match ($type->kind) {
                'collection' => 'record',
                'singleton' => 'singleton',
                'taxonomy' => 'taxonomy',
                default => throw new \InvalidArgumentException('Record type kind is invalid'),
            };
            if (!$renderer instanceof RendererDefinition || $renderer->kind !== $expectedKind) {
                throw new \InvalidArgumentException('Record type renderer is not installed');
            }
            self::validateFieldTargets($type->fields, $indexed);
            foreach ($type->fields as $field) {
                $mapping = $field->settings['searchSharing'] ?? [];
                if (!isset($mapping['pagePath'])) {
                    continue;
                }
                $path = $mapping['pagePath'];
                if ($type->kind !== 'singleton' || $mapping['settingsType'] !== $type->id || isset($pagePaths[$path])) {
                    throw new \InvalidArgumentException('Recordless metadata mapping must uniquely belong to its settings singleton');
                }
                $pagePaths[$path] = true;
            }
        }

        $navigationIds = [];
        $positions = [];
        $systemTargets = ['previews', 'exports', 'health', 'releases', 'backups', 'settings'];
        foreach ($navigation as $item) {
            if (
                !$item instanceof NavigationDefinition
                || isset($navigationIds[$item->id])
                || isset($positions[$item->position])
            ) {
                throw new \InvalidArgumentException('Profile navigation is invalid');
            }
            if ($item->targetKind === 'system') {
                if (!in_array($item->targetId, $systemTargets, true)) {
                    throw new \InvalidArgumentException('Profile system navigation target is invalid');
                }
            } else {
                $target = $indexed[$item->targetId] ?? null;
                if (!$target instanceof RecordTypeDefinition || $target->kind !== $item->targetKind) {
                    throw new \InvalidArgumentException('Profile navigation target is invalid');
                }
            }
            $navigationIds[$item->id] = true;
            $positions[$item->position] = true;
        }

        $assetPaths = [];
        $speculationRules = 0;
        foreach ($assets as $asset) {
            if (!$asset instanceof ProfileAsset || isset($assetPaths[$asset->path])) {
                throw new \InvalidArgumentException('Profile assets are invalid');
            }
            $assetPaths[$asset->path] = true;
            if (SpeculationRules::asset($asset) && ++$speculationRules > 1) {
                throw new \InvalidArgumentException('Profile can declare only one speculation rules asset');
            }
        }
        $this->typesById = $indexed;
        ContentFeed::from($contentFeed?->document(), $types);
        $this->wordingByKey = self::indexWording($wording, $supported, $this->reserved());
    }

    public function wording(string $key): ?WordingDefinition
    {
        return $this->wordingByKey[$key] ?? null;
    }

    public function type(string $id): ?RecordTypeDefinition
    {
        return $this->typesById[$id] ?? null;
    }

    /** @return list<string> Every language the profile supports, including any hidden from editing. */
    public function supported(): array
    {
        return $this->supportedLocales ?? $this->locales;
    }

    /** @return list<string> The languages the profile itself lists, without those the installation added. */
    public function listed(): array
    {
        return array_values(array_diff($this->supported(), $this->addedLocales));
    }

    /** @return list<Language> Catalog languages inside the envelope that the installation can still add. */
    public function addable(): array
    {
        return array_values(array_filter($this->envelope?->languages() ?? [], fn(Language $language): bool => !in_array($language->id, $this->supported(), true)));
    }

    /** Whether the profile's design can draw a language: one it lists or one the installation may add. */
    public function carries(string $locale): bool
    {
        $language = LanguageCatalog::language($locale);
        return in_array($locale, $this->supported(), true) || ($language !== null && $this->envelope?->admits($language) === true);
    }

    /**
     * @return list<string> Language codes no address segment may take, so a language added later finds its
     * prefix free: also those of the envelope's scripts that its fonts do not draw yet.
     */
    public function reserved(): array
    {
        return array_values(array_unique([...$this->supported(), ...array_map(static fn(Language $language): string => $language->id, $this->envelope?->languages(false) ?? [])]));
    }

    /**
     * The profile as a frozen snapshot draws it: languages among $locales that it does not list are the
     * installation's added languages, and must be inside the envelope.
     * @param list<string> $locales
     */
    public function carrying(array $locales): self
    {
        $added = array_values(array_filter($locales, fn(string $locale): bool => !in_array($locale, $this->supported(), true)));
        foreach ($added as $locale) {
            if (!$this->carries($locale)) {
                throw new \InvalidArgumentException('Snapshot language is outside the profile envelope');
            }
        }
        return $added === [] ? $this : $this->withLanguages($added);
    }

    /**
     * The installed profile: the listed languages followed by those the installation added from the catalog.
     * @param list<string> $added
     */
    public function withLanguages(array $added): self
    {
        if ($added === $this->addedLocales) {
            return $this;
        }
        if ($this->supportedLocales !== null || $this->addedLocales !== [] || array_intersect($added, $this->locales) !== []) {
            throw new \InvalidArgumentException('Profile added locales are invalid');
        }
        $definitions = $this->localeDefinitions;
        foreach ($added as $locale) {
            $definitions[] = new LocaleDefinition($locale, LanguageCatalog::language($locale)->name ?? throw new \InvalidArgumentException('Profile added locales are invalid'));
        }
        return new self(
            $this->id,
            $this->version,
            $this->compatibility,
            [...$this->locales, ...$added],
            $definitions,
            $this->defaultLocale,
            $this->types,
            $this->navigation,
            $this->renderers,
            $this->assets,
            $this->capabilities,
            $this->privacy,
            $this->designRegistries,
            null,
            $this->wording,
            $this->envelope,
            $added,
            $this->contentFeed,
            $this->definitionJson,
        );
    }

    public static function gateZero(): self
    {
        return self::load(dirname(__DIR__) . '/profile/gate-zero.json');
    }

    public static function load(string $path, string $engineVersion = self::ENGINE_VERSION, bool $allowLegacyProfile = false): self
    {
        $size = HostIo::attempt(fn() => filesize($path))->value;
        if (!is_int($size) || $size < 2 || $size > 262_144) {
            throw new \InvalidArgumentException('Profile document size is invalid');
        }
        try {
            $json = (string) HostIo::attempt(fn() => file_get_contents($path))->value;
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            throw new \InvalidArgumentException('Profile document is not valid JSON', previous: $error);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \InvalidArgumentException('Profile document must be an object');
        }
        $format = $decoded['formatVersion'] ?? null;
        if (!in_array($format, [1, 2, 3], true)) {
            throw new \InvalidArgumentException('Profile format version is unsupported');
        }
        $schemaRoot = dirname(__DIR__, 5) . '/schemas/';
        JsonSchemaValidator::validateJsonWithSchemaFile($json, $schemaRoot . 'portable-cms-profile-v' . $format . '.schema.json');
        if ($format === 3) {
            $expanded = ProfileFieldGroups::expand(json_decode($json, false, 32, JSON_THROW_ON_ERROR));
            $json = json_encode($expanded, JSON_THROW_ON_ERROR);
            JsonSchemaValidator::validateJsonWithSchemaFile($json, $schemaRoot . 'portable-cms-profile-v2.schema.json');
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        }

        $compatibilityValue = self::objectValue($decoded, 'compatibility');
        $engine = self::objectValue($compatibilityValue, 'engine');
        $contracts = self::objectValue($compatibilityValue, 'contracts');
        /** @var array<string, int> $contractVersions */
        $contractVersions = [];
        foreach ($contracts as $name => $version) {
            if (!is_string($name) || !is_int($version)) {
                throw new \InvalidArgumentException('Profile contract compatibility is invalid');
            }
            $contractVersions[$name] = $version;
        }
        $compatibility = new ProfileCompatibility(
            self::stringValue($engine, 'minimum'),
            self::stringValue($engine, 'maximumExclusive'),
            $contractVersions,
        );
        if (!$compatibility->supports($engineVersion)) {
            throw new ProfileEngineMismatch($compatibility->minimumEngineVersion, $compatibility->maximumEngineVersionExclusive, $engineVersion);
        }

        $localeDefinitions = [];
        $localeIds = [];
        foreach (self::listValue($decoded, 'locales') as $locale) {
            $value = self::object($locale, 'Profile locale is invalid');
            $definition = new LocaleDefinition(
                self::stringValue($value, 'id'),
                self::stringValue($value, 'label'),
            );
            $localeDefinitions[] = $definition;
            $localeIds[] = $definition->id;
        }

        $types = [];
        foreach ([
            'recordTypes' => 'collection',
            'singletons' => 'singleton',
            'taxonomyNamespaces' => 'taxonomy',
        ] as $key => $kind) {
            foreach (self::listValue($decoded, $key) as $type) {
                $value = self::object($type, 'Profile record type is invalid');
                $route = match ($kind) {
                    'collection' => self::stringValue($value, 'routePrefix'),
                    'singleton' => self::stringValue($value, 'routePath'),
                    'taxonomy' => null,
                };
                $types[] = new RecordTypeDefinition(
                    self::stringValue($value, 'id'),
                    self::stringValue($value, 'label'),
                    self::stringValue($value, 'singularLabel'),
                    self::parseFields(self::listValue($value, 'fields')),
                    $kind,
                    self::stringValue($value, 'archetype'),
                    self::stringValue($value, 'renderer'),
                    $route,
                );
            }
        }

        $navigation = [];
        foreach (self::listValue($decoded, 'navigation') as $item) {
            $value = self::object($item, 'Profile navigation item is invalid');
            $navigation[] = new NavigationDefinition(
                self::stringValue($value, 'id'),
                self::stringValue($value, 'label'),
                self::stringValue($value, 'targetKind'),
                self::stringValue($value, 'targetId'),
                self::intValue($value, 'position'),
            );
        }

        $renderers = [];
        foreach (self::listValue($decoded, 'renderers') as $renderer) {
            $value = self::object($renderer, 'Profile renderer is invalid');
            $renderers[] = new RendererDefinition(
                self::stringValue($value, 'id'),
                self::stringValue($value, 'kind'),
                self::stringValue($value, 'entrypoint'),
            );
        }

        $assets = [];
        foreach (self::listValue($decoded, 'assets') as $asset) {
            $value = self::object($asset, 'Profile asset is invalid');
            $assets[] = new ProfileAsset(
                self::stringValue($value, 'path'),
                self::stringValue($value, 'role'),
                self::stringValue($value, 'mime'),
                self::intValue($value, 'byteSize'),
                self::stringValue($value, 'sha256'),
            );
        }

        $wording = [];
        foreach (isset($decoded['wording']) ? self::listValue($decoded, 'wording') : [] as $entry) {
            $value = self::object($entry, 'Profile wording is invalid');
            $defaults = self::object($value['defaults'] ?? null, 'Profile wording defaults are invalid');
            // The default language starts complete, so a new installation can publish it; editors
            // write any text another language lacks before that language becomes public.
            if (!isset($defaults[self::stringValue($decoded, 'defaultLocale')])) {
                throw new \InvalidArgumentException('Profile wording needs a default in the default language');
            }
            $wording[] = new WordingDefinition(
                self::stringValue($value, 'key'),
                self::stringValue($value, 'label'),
                self::stringValue($value, 'purpose'),
                self::stringValue($value, 'kind'),
                self::intValue($value, 'maximumLength'),
                $defaults,
                isset($value['uniqueIn']) ? self::stringValue($value, 'uniqueIn') : null,
            );
        }

        $capabilitiesValue = self::objectValue($decoded, 'capabilities');
        $privacyValue = self::objectValue($decoded, 'privacy');
        $profile = new self(
            self::stringValue($decoded, 'id'),
            self::stringValue($decoded, 'version'),
            $compatibility,
            $localeIds,
            $localeDefinitions,
            self::stringValue($decoded, 'defaultLocale'),
            $types,
            $navigation,
            $renderers,
            $assets,
            new CapabilityDeclaration(
                self::stringList(self::listValue($capabilitiesValue, 'required')),
                self::stringList(self::listValue($capabilitiesValue, 'optional')),
            ),
            new PrivacyDeclaration(
                self::boolValue($privacyValue, 'publicInteractions'),
                self::stringValue($privacyValue, 'analytics'),
                self::stringValue($privacyValue, 'personalData'),
            ),
            isset($decoded['designRegistries']) ? \Utsuri\PortableCms\Conventional\Design\RegistryDocument::fromDocument($decoded['designRegistries']) : null,
            wording: $wording,
            envelope: isset($decoded['languageEnvelope']) ? new LanguageEnvelope(
                self::stringList(self::listValue(self::objectValue($decoded, 'languageEnvelope'), 'scripts')),
                self::stringList(self::listValue(self::objectValue($decoded, 'languageEnvelope'), 'directions')),
                isset($decoded['languageEnvelope']['characters']) ? self::stringList(self::listValue(self::objectValue($decoded, 'languageEnvelope'), 'characters')) : [],
            ) : null,
            contentFeed: ContentFeed::from($decoded['contentFeed'] ?? null, $types),
            definitionJson: $json,
        );
        if (!$allowLegacyProfile) {
            AdministrationMenuLabels::assert($profile, AdministrationLabels::load(dirname($path), $profile));
            WebsiteIdentityProfile::assert($profile);
        }
        return $profile;
    }

    /**
     * Keys are unique, defaults name supported languages, and the default address segments of a
     * group differ within each language.
     * @param list<WordingDefinition> $wording
     * @param list<string> $languages
     * @param list<string> $reserved Language codes a default address segment may not take.
     * @return array<string, WordingDefinition>
     */
    private static function indexWording(array $wording, array $languages, array $reserved): array
    {
        if (!array_is_list($wording) || count($wording) > 200) {
            throw new \InvalidArgumentException('Profile wording is invalid');
        }
        $indexed = [];
        $segments = [];
        foreach ($wording as $definition) {
            if (!$definition instanceof WordingDefinition || isset($indexed[$definition->key])) {
                throw new \InvalidArgumentException('Profile wording keys are invalid');
            }
            foreach ($definition->defaults as $locale => $text) {
                if (!in_array($locale, $languages, true) || $definition->problem($text, $reserved) !== null
                    || ($definition->uniqueIn !== null && isset($segments[$definition->uniqueIn][$locale][$text]))) {
                    throw new \InvalidArgumentException('Profile wording defaults are invalid');
                }
                if ($definition->uniqueIn !== null) {
                    $segments[$definition->uniqueIn][$locale][$text] = true;
                }
            }
            $indexed[$definition->key] = $definition;
        }
        return $indexed;
    }

    public static function canonicalId(string $value): bool
    {
        return strlen($value) <= 160
            && preg_match('/\A[a-z][a-z0-9]*(?:[-_][a-z0-9]+)*\z/', $value) === 1;
    }

    public static function fieldId(string $value): bool
    {
        return strlen($value) <= 160
            && preg_match('/\A[a-z][A-Za-z0-9]*(?:[-_][a-z0-9]+)*\z/', $value) === 1;
    }

    public static function localeId(string $value): bool
    {
        return preg_match('/\A[a-z]{2,3}(?:-[A-Z]{2})?\z/', $value) === 1;
    }

    public static function label(string $value): bool
    {
        return $value !== ''
            && mb_strlen($value, 'UTF-8') <= 100
            && preg_match('/[\x00-\x1f\x7f]/u', $value) !== 1;
    }

    public static function semanticVersion(string $value): bool
    {
        return strlen($value) <= 64
            && preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.-]+)?\z/', $value) === 1;
    }

    public static function routePath(string $value): bool
    {
        return strlen($value) <= 200
            && preg_match('#\A/(?:[a-z0-9]+(?:-[a-z0-9]+)*/?)*\z#', $value) === 1;
    }

    public static function safePath(string $value): bool
    {
        return strlen($value) <= 500
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9\/_.-]*\z/', $value) === 1
            && !str_starts_with($value, '/')
            && !str_contains($value, '..')
            && !str_contains($value, '//')
            && !str_ends_with($value, '/');
    }

    /**
     * @param list<mixed> $values
     * @return list<FieldDefinition>
     */
    public static function parseFields(array $values): array
    {
        $fields = [];
        foreach ($values as $field) {
            $value = self::object($field, 'Profile field is invalid');
            $settings = self::objectValue($value, 'settings');
            $sourceType = self::stringValue($value, 'type');
            $localized = self::boolValue($value, 'localized');
            $type = $sourceType === 'short_text' && $localized ? 'localized_short_text' : $sourceType;
            $nested = [];
            if (in_array($sourceType, ['group', 'repeatable_group'], true)) {
                $nested = self::parseFields(self::listValue($settings, 'fields'));
            }
            $maximumLength = isset($settings['maximumLength']) && is_int($settings['maximumLength'])
                ? $settings['maximumLength']
                : null;
            $targetType = isset($settings['targetType']) && is_string($settings['targetType'])
                ? $settings['targetType']
                : null;
            $maximumItems = isset($settings['maximumItems']) && is_int($settings['maximumItems'])
                ? $settings['maximumItems']
                : null;
            $fields[] = new FieldDefinition(
                self::stringValue($value, 'id'),
                self::stringValue($value, 'label'),
                $type,
                $localized,
                self::boolValue($value, 'requiredOnDraft'),
                self::boolValue($value, 'requiredOnPublish'),
                $settings,
                $nested,
                $maximumLength,
                $targetType,
                $maximumItems,
                isset($value['editorialSection']) ? self::stringValue($value, 'editorialSection') : null,
                isset($value['hint']) ? self::stringValue($value, 'hint') : null,
            );
        }
        return $fields;
    }

    /**
     * @param list<FieldDefinition> $fields
     * @param array<string, RecordTypeDefinition> $types
     */
    private static function validateFieldTargets(array $fields, array $types): void
    {
        foreach ($fields as $field) {
            $mapping = $field->settings['searchSharing'] ?? [];
            if (isset($mapping['defaultDescriptionField'])) {
                $settings = $types[$mapping['settingsType']] ?? null;
                $sources = $settings === null ? [] : array_column($settings->fields, null, 'id');
                $source = $sources[$mapping['defaultDescriptionField']] ?? null;
                if ($settings?->kind !== 'singleton' || $source === null || !$source->localized
                    || !in_array($source->type, ['localized_short_text', 'multiline_text'], true) || $source->maximumLength === null || $source->maximumLength > 500) {
                    throw new \InvalidArgumentException('Website description mapping requires a bounded localized text field');
                }
            }
            foreach ($field->targetTypes as $targetId) {
                $target = $types[$targetId] ?? null;
                if (!$target instanceof RecordTypeDefinition) {
                    throw new \InvalidArgumentException('Reference field target is not installed');
                }
                if ($field->type === 'taxonomy_references' && $target->kind !== 'taxonomy') {
                    throw new \InvalidArgumentException('Taxonomy relationship must target a taxonomy namespace');
                }
                if (in_array($field->type, ['record_reference', 'record_references', 'composition'], true) && $target->kind === 'taxonomy') {
                    throw new \InvalidArgumentException('Record relationship cannot target a taxonomy namespace');
                }
            }
            self::validateFieldTargets($field->fields, $types);
            foreach ($field->blockTypes as $block) {
                self::validateFieldTargets($block->fields, $types);
            }
        }
    }

    /** @param array<array-key, mixed> $value */
    private static function stringValue(array $value, string $key): string
    {
        $result = $value[$key] ?? null;
        if (!is_string($result)) {
            throw new \InvalidArgumentException('Profile string property is invalid');
        }
        return $result;
    }

    /** @param array<array-key, mixed> $value */
    private static function intValue(array $value, string $key): int
    {
        $result = $value[$key] ?? null;
        if (!is_int($result)) {
            throw new \InvalidArgumentException('Profile integer property is invalid');
        }
        return $result;
    }

    /** @param array<array-key, mixed> $value */
    private static function boolValue(array $value, string $key): bool
    {
        $result = $value[$key] ?? null;
        if (!is_bool($result)) {
            throw new \InvalidArgumentException('Profile boolean property is invalid');
        }
        return $result;
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<string, mixed>
     */
    private static function objectValue(array $value, string $key): array
    {
        return self::object($value[$key] ?? null, 'Profile object property is invalid');
    }

    /**
     * @param array<array-key, mixed> $value
     * @return list<mixed>
     */
    private static function listValue(array $value, string $key): array
    {
        $result = $value[$key] ?? null;
        if (!is_array($result) || !array_is_list($result)) {
            throw new \InvalidArgumentException('Profile list property is invalid');
        }
        return $result;
    }

    /** @return array<string, mixed> */
    private static function object(mixed $value, string $message): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new \InvalidArgumentException($message);
        }
        return $value;
    }

    /**
     * @param list<mixed> $values
     * @return list<string>
     */
    private static function stringList(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException('Profile string list is invalid');
            }
            $result[] = $value;
        }
        return $result;
    }
}
