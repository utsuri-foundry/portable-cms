<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

use Utsuri\PortableCms\Conventional\I18n\InvalidInput;

/** One field policy for editor choices, authoring and portable/frozen graphs. */
final class VideoLimits
{
    /** @param array<string, mixed> $settings */
    public static function settings(array $settings): void
    {
        foreach (['maximumDurationSeconds' => 7200, 'maximumWidth' => 4096, 'maximumHeight' => 4096] as $key => $maximum) {
            if (array_key_exists($key, $settings) && (!is_int($settings[$key]) || $settings[$key] < 1 || $settings[$key] > $maximum)) {
                throw new \InvalidArgumentException('Invalid video field setting: ' . $key);
            }
        }
        if (array_key_exists('sound', $settings) && !in_array($settings['sound'], ['allowed', 'none'], true)) {
            throw new \InvalidArgumentException('Invalid video field setting: sound');
        }
    }

    public static function needsFacts(FieldDefinition $field): bool
    {
        return isset($field->settings['maximumDurationSeconds']) || isset($field->settings['maximumWidth'])
            || isset($field->settings['maximumHeight']) || ($field->settings['sound'] ?? 'allowed') === 'none';
    }

    /** Missing facts are unknown, including the sound status of legacy recipes.
     * @param array<string, mixed> $facts
     */
    public static function assert(FieldDefinition $field, int $byteSize, array $facts): void
    {
        $limits = ['maximumBytes' => ['bytes', $byteSize, 1], 'maximumDurationSeconds' => ['duration', $facts['durationMilliseconds'] ?? null, 1000],
            'maximumWidth' => ['width', $facts['width'] ?? null, 1], 'maximumHeight' => ['height', $facts['height'] ?? null, 1]];
        foreach ($limits as $setting => [$name, $actual, $scale]) {
            $maximum = $field->settings[$setting] ?? null;
            if (!is_int($maximum)) {
                continue;
            }
            if (!is_int($actual) || $actual < 1) {
                throw new InvalidInput('video.facts_unavailable', ['field' => $field->label]);
            }
            if ($actual > $maximum * $scale) {
                throw new InvalidInput('video.limit_' . $name, ['field' => $field->label, 'actual' => $actual / $scale, 'limit' => $maximum]);
            }
        }
        if (($field->settings['sound'] ?? 'allowed') === 'none') {
            if (!is_bool($facts['hasAudio'] ?? null)) {
                throw new InvalidInput('video.facts_unavailable', ['field' => $field->label]);
            }
            if ($facts['hasAudio']) {
                throw new InvalidInput('video.limit_sound', ['field' => $field->label]);
            }
        }
    }
}
