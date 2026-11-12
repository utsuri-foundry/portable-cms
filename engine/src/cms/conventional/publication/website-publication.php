<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Publication;

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;
use Utsuri\PortableCms\Conventional\Identity\SessionContext;

/** Portable editorial intent, never destination activation authority. */
final readonly class WebsitePublication
{
    /** @param array{formatVersion: int, profileId: string, revision: int, mode: string, websiteName: string, iconMediaId: string|null, translations: list<array{locale: string, message: string, contact: string}>} $value */
    private function __construct(private array $value, public ?IdentityUpgradeNote $identityUpgrade = null, public AdministrationBranding $administrationBranding = new AdministrationBranding()) {}

    public static function from(mixed $value): self
    {
        if (!is_array($value) || count($value) !== 7 + (int) array_key_exists('identityUpgrade', $value) + (int) array_key_exists('administrationBranding', $value)
            || array_diff(array_keys($value), ['formatVersion', 'profileId', 'revision', 'mode', 'websiteName', 'iconMediaId', 'translations', 'identityUpgrade', 'administrationBranding']) !== [] || ($value['formatVersion'] ?? null) !== 1
            || (array_key_exists('administrationBranding', $value) && $value['administrationBranding'] === null)
            || !is_string($value['profileId'] ?? null) || !ProfileManifest::canonicalId($value['profileId'])
            || !is_int($value['revision'] ?? null) || $value['revision'] < 0 || $value['revision'] > 4_294_967_294
            || !in_array($value['mode'] ?? null, ['coming_soon', 'live'], true)
            || !self::text($value['websiteName'] ?? null, 180, true)
            || !array_key_exists('iconMediaId', $value)
            || ($value['iconMediaId'] !== null && (!is_string($value['iconMediaId']) || preg_match('/\A[a-f0-9]{32}\z/', $value['iconMediaId']) !== 1))
            || !is_array($value['translations'] ?? null) || !array_is_list($value['translations'])
            || count($value['translations']) < 1 || count($value['translations']) > 16) {
            throw new PublicationFailure('website_publication_invalid');
        }
        try {
            new \Utsuri\PortableCms\Conventional\Content\WebsiteIdentity($value['websiteName'], $value['iconMediaId']);
        } catch (\InvalidArgumentException) {
            throw new PublicationFailure('website_publication_invalid');
        }
        $translations = [];
        $seen = [];
        foreach ($value['translations'] as $entry) {
            if (!is_array($entry) || count($entry) !== 3 || !is_string($entry['locale'] ?? null)
                || !ProfileManifest::localeId($entry['locale']) || isset($seen[$entry['locale']])
                || !self::text($entry['message'] ?? null, 600, true) || !self::text($entry['contact'] ?? null, 240, false)) {
                throw new PublicationFailure('website_publication_invalid');
            }
            $seen[$entry['locale']] = true;
            $translations[] = ['locale' => $entry['locale'], 'message' => $entry['message'], 'contact' => $entry['contact']];
        }
        return new self(['formatVersion' => 1, 'profileId' => $value['profileId'], 'revision' => $value['revision'],
            'mode' => $value['mode'], 'websiteName' => $value['websiteName'], 'iconMediaId' => $value['iconMediaId'], 'translations' => $translations], array_key_exists('identityUpgrade', $value) ? IdentityUpgradeNote::from($value['identityUpgrade']) : null, AdministrationBranding::from($value['administrationBranding'] ?? null));
    }

    /** @param list<string> $locales */
    public static function defaults(string $profileId, array $locales, bool $existingLive = false): self
    {
        return self::from(['formatVersion' => 1, 'profileId' => $profileId, 'revision' => 0,
            'mode' => $existingLive ? 'live' : 'coming_soon', 'websiteName' => \Utsuri\PortableCms\Conventional\Content\WebsiteIdentity::defaults($profileId)->name, 'iconMediaId' => null,
            'translations' => array_map(static fn(string $locale): array => ['locale' => $locale, 'message' => self::defaultMessage($locale), 'contact' => ''], $locales)]);
    }

    /** The holding message a language starts with; languages without one start in English. */
    public static function defaultMessage(string $locale): string
    {
        $messages = ['ca' => 'Properament.', 'es' => 'Próximamente.', 'en' => 'Coming soon.', 'fr' => 'Bientôt.', 'de' => 'Demnächst.'];
        return $messages[explode('-', $locale)[0]] ?? $messages['en'];
    }

    /**
     * The settings for the installation's current languages: a language added since they were saved
     * starts with its default message, and one no longer installed is left out.
     * @param list<string> $locales
     */
    public function within(array $locales): self
    {
        $translations = [];
        foreach ($this->value['translations'] as $entry) {
            $translations[$entry['locale']] = $entry;
        }
        return self::from([...$this->document(), 'translations' => array_map(static fn(string $locale): array => $translations[$locale] ?? ['locale' => $locale, 'message' => self::defaultMessage($locale), 'contact' => ''], $locales)]);
    }

    /** @return array{formatVersion: int, profileId: string, revision: int, mode: string, websiteName: string, iconMediaId: string|null, translations: list<array{locale: string, message: string, contact: string}>, identityUpgrade?: array{kept: string, previous: list<array{typeId: string, recordId: string, name: ?string, iconMediaId: ?string}>}, administrationBranding?: array{logoMediaId: ?string, darkLogoMediaId: ?string}} */
    public function document(): array
    {
        return [...$this->value, ...($this->identityUpgrade === null ? [] : ['identityUpgrade' => $this->identityUpgrade->document()]),
            ...($this->administrationBranding->logoMediaId === null ? [] : ['administrationBranding' => $this->administrationBranding->document()])];
    }

    /** @param list<string> $locales */
    public function assertScope(string $profileId, array $locales): void
    {
        if ($this->value['profileId'] !== $profileId || array_column($this->value['translations'], 'locale') !== $locales) {
            throw new PublicationFailure('website_publication_scope_changed');
        }
    }

    /**
     * The same settings with only these languages, in their order, for a build that publishes only them.
     * @param list<string> $locales
     */
    public function only(array $locales): self
    {
        $translations = [];
        foreach ($this->value['translations'] as $entry) {
            $translations[$entry['locale']] = $entry;
        }
        return self::from([...$this->document(), 'translations' => array_values(array_map(static fn(string $locale): array => $translations[$locale] ?? throw new PublicationFailure('website_publication_scope_changed'), $locales))]);
    }

    public function mode(): string
    {
        return $this->value['mode'];
    }

    public function revision(): int
    {
        return $this->value['revision'];
    }

    public function identity(): \Utsuri\PortableCms\Conventional\Content\WebsiteIdentity
    {
        return new \Utsuri\PortableCms\Conventional\Content\WebsiteIdentity($this->value['websiteName'], $this->value['iconMediaId']);
    }

    /** Public builds must not receive administration-only media selections. */
    public function forWebsite(): self
    {
        return new self($this->value, $this->identityUpgrade);
    }

    private static function text(mixed $value, int $maximum, bool $required): bool
    {
        return is_string($value) && mb_check_encoding($value, 'UTF-8') && mb_strlen($value) <= $maximum
            && (!$required || trim($value) !== '') && preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', $value) !== 1;
    }
}

interface WebsitePublicationRepository
{
    /** @param list<string> $locales */
    public function load(string $profileId, array $locales): WebsitePublication;

    public function save(WebsitePublication $next, int $expected, string $actorId, string $auditId, \DateTimeImmutable $now): void;
}

final readonly class WebsitePublicationService
{
    public function __construct(private WebsitePublicationRepository $repository, private ProfileManifest $profile, private Clock $clock, private RandomSource $random) {}

    public function load(): WebsitePublication
    {
        $value = $this->repository->load($this->profile->id, $this->profile->locales);
        $value->assertScope($this->profile->id, $this->profile->locales);
        return $value;
    }

    public function save(mixed $input, int $expected, SessionContext $actor, bool $confirmed): WebsitePublication
    {
        if (!$actor->administrator() || $actor->userId === null) {
            throw new PublicationFailure('authorization_required');
        }
        $current = $this->load();
        if (is_array($input)) {
            unset($input['identityUpgrade']);
            if ($current->identityUpgrade !== null) {
                $input['identityUpgrade'] = $current->identityUpgrade->document();
            }
        }
        $next = WebsitePublication::from($input);
        $next->assertScope($this->profile->id, $this->profile->locales);
        if ($expected !== $current->revision() || $next->revision() !== $expected + 1) {
            throw new PublicationConflict();
        }
        if ($current->mode() === 'live' && $next->mode() === 'coming_soon' && !$confirmed) {
            throw new PublicationFailure('holding_confirmation_required');
        }
        $this->repository->save($next, $expected, $actor->userId, bin2hex($this->random->bytes(16)), $this->clock->now());
        return $next;
    }
}
