<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Compiler;

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;

/**
 * The site wording one build draws, in the languages of its snapshot. Profile code reads every
 * fixed text of its pages here, by the key its manifest declares, and never keeps its own copy.
 */
final readonly class SnapshotWording
{
    /** @param array<string, array<string, string>> $texts Key to language to text. */
    private function __construct(private ProfileManifest $profile, private array $texts) {}

    /**
     * The snapshot's `wording`, or the profile's defaults for a snapshot frozen without it.
     * @param list<string> $locales
     */
    public static function from(mixed $value, ProfileManifest $profile, array $locales): self
    {
        $texts = [];
        if ($value === null) {
            foreach ($profile->wording as $definition) {
                foreach ($locales as $locale) {
                    if (isset($definition->defaults[$locale])) {
                        $texts[$definition->key][$locale] = $definition->defaults[$locale];
                    }
                }
            }
            return new self($profile, $texts);
        }
        if (!is_array($value) || !array_is_list($value) || count($value) > 3_200) {
            throw new \InvalidArgumentException('Frozen wording is invalid');
        }
        foreach ($value as $entry) {
            $definition = is_array($entry) && is_string($entry['key'] ?? null) ? $profile->wording($entry['key']) : null;
            if ($definition === null || count($entry) !== 3 || !in_array($entry['locale'] ?? null, $locales, true) || !is_string($entry['text'] ?? null)
                || isset($texts[$entry['key']][$entry['locale']]) || $definition->problem($entry['text'], $profile->reserved()) !== null) {
                throw new \InvalidArgumentException('Frozen wording text is invalid');
            }
            $texts[$entry['key']][$entry['locale']] = $entry['text'];
        }
        return new self($profile, $texts);
    }

    /** The text of a declared key in a language of this build; a missing one fails the build naming the key. */
    public function text(string $key, string $locale): string
    {
        if ($this->profile->wording($key) === null) {
            throw new \InvalidArgumentException('Wording key is not declared by the profile');
        }
        return $this->texts[$key][$locale] ?? throw new ProfileBuildFailure(ProfileBuildReason::MissingWording, fieldId: $key, locale: $locale);
    }
}
