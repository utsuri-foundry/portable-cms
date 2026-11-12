<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

use Utsuri\PortableCms\Conventional\I18n\InvalidInput;

/** An absolute web link, never a fetch request or an executable URL. */
final readonly class WebAddress
{
    public const MAXIMUM_LENGTH = 2048;
    public const MAXIMUM_TEXT = 200;

    private function __construct(public string $url, public string $linkText) {}

    public static function from(mixed $value, int $maximum = self::MAXIMUM_LENGTH): self
    {
        if ($maximum < 1 || $maximum > self::MAXIMUM_LENGTH || !is_array($value) || count($value) !== 3
            || ($value['formatVersion'] ?? null) !== 1 || !is_string($value['url'] ?? null)
            || !mb_check_encoding($value['url'], 'UTF-8') || strlen($value['url']) > $maximum
            || !preg_match('#\Ahttps?://#', $value['url']) || !RichText::validLink($value['url'])) {
            throw new InvalidInput('address.invalid', ['maximum' => $maximum]);
        }
        if (!is_string($value['linkText'] ?? null) || !mb_check_encoding($value['linkText'], 'UTF-8')
            || mb_strlen($value['linkText'], 'UTF-8') > self::MAXIMUM_TEXT || preg_match('/[\x00-\x1f\x7f]/u', $value['linkText']) !== 0) {
            throw new InvalidInput('address.text_invalid');
        }
        return new self($value['url'], $value['linkText']);
    }

    /** @return array{formatVersion: int, url: string, linkText: string} */
    public function document(): array
    {
        return ['formatVersion' => 1, 'url' => $this->url, 'linkText' => $this->linkText];
    }

    /** Validate optional/localized values through the same policy at every boundary.
     * @param list<string> $locales
     * @param list<string>|null $requiredIn Languages a required address must be filled in; every language when null.
     * @return array<string, mixed>|null
     */
    public static function field(mixed $raw, FieldDefinition $field, array $locales, bool $required, ?array $requiredIn = null): ?array
    {
        if ($field->localized && $raw !== null && (!is_array($raw) || array_diff(array_keys($raw), $locales) !== [])) {
            throw new InvalidInput('address.locales_invalid');
        }
        $values = [];
        foreach ($field->localized ? $locales : [''] as $locale) {
            $value = $field->localized ? ($raw[$locale] ?? null) : $raw;
            if ($value === null && $required && ($requiredIn === null || $locale === '' || in_array($locale, $requiredIn, true))) {
                throw new InvalidInput('address.required');
            }
            $values[$locale] = $value === null ? null : self::from($value, $field->maximumLength ?? self::MAXIMUM_LENGTH)->document();
        }
        return $field->localized && $raw !== null ? $values : ($field->localized ? null : $values['']);
    }

    /** @param list<FieldDefinition> $fields
     * @param array<string, mixed> $body
     * @param list<string> $locales
     */
    public static function assertFields(array $fields, array $body, array $locales, bool $published): void
    {
        [$fields, $body] = \Utsuri\PortableCms\Conventional\Content\Blocks::expand($fields, $body, $locales, $published);
        foreach ($fields as $field) {
            $raw = $body[$field->id] ?? null;
            if ($field->type === 'web_address') {
                self::field($raw, $field, $locales, $published ? $field->requiredOnPublish : $field->requiredOnDraft);
            } elseif (in_array($field->type, ['group', 'repeatable_group'], true) && is_array($raw)) {
                foreach ($field->localized ? $locales : [''] as $locale) {
                    $container = $field->localized ? ($raw[$locale] ?? null) : $raw;
                    if (!is_array($container)) {
                        continue;
                    }
                    foreach ($field->type === 'group' ? [$container] : $container as $item) {
                        if (is_array($item)) {
                            self::assertFields(array_map(static fn(FieldDefinition $child): FieldDefinition => EditorialControls::childDefinition($child, $child->id, $locale !== ''), $field->fields), $item, $locales, $published);
                        }
                    }
                }
            }
        }
    }
}
