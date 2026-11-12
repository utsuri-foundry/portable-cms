<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

/** A language the engine knows how to name, write and format, without any PHP extension. */
final readonly class Language
{
    public function __construct(
        public string $id,
        /** The language's name in itself. */
        public string $name,
        /** ISO 15924 script code. */
        public string $script,
        public string $direction,
        /** Numeric date with the tokens d, dd, M, MM and y. */
        public string $datePattern,
        public string $decimalSeparator,
        public string $groupSeparator,
        /** The letters it writes beyond a to z, in lowercase. */
        public string $letters = '',
    ) {}

    /** @return list<int> The code points a font needs to write the language: a to z, its own letters and their capitals. */
    public function characters(): array
    {
        $characters = [...range(0x41, 0x5A), ...range(0x61, 0x7A)];
        foreach (mb_str_split($this->letters) as $letter) {
            foreach (mb_str_split($letter . mb_strtoupper($letter)) as $character) {
                $characters[] = mb_ord($character);
            }
        }
        return array_values(array_unique($characters));
    }

    public function date(\DateTimeInterface $date): string
    {
        return (string) preg_replace_callback('/d{1,2}|M{1,2}|y+/', static fn(array $token): string => match ($token[0]) {
            'd' => $date->format('j'),
            'dd' => $date->format('d'),
            'M' => $date->format('n'),
            'MM' => $date->format('m'),
            default => $date->format('Y'),
        }, $this->datePattern);
    }

    public function number(int|float $value, int $decimals = 0): string
    {
        return number_format($value, $decimals, $this->decimalSeparator, $this->groupSeparator);
    }
}

/**
 * The bounded, reviewed languages an installation can add within a profile's envelope. Names, date
 * patterns (the `yMd` skeleton), number separators and letters (the main exemplar characters, without
 * digraphs) were taken from ICU 73.2 (CLDR 43).
 */
final class LanguageCatalog
{
    /** Scripts the engine supports, with the search normalization their words use. */
    public const SCRIPTS = ['Latn' => 'unicode_nfkd_accent_fold_lower_words_v1'];

    public const DIRECTIONS = ['ltr'];

    /** @var array<string, array{string, string, string, string, string, string, string}> */
    private const LANGUAGES = [
        'af' => ['Afrikaans', 'Latn', 'ltr', 'y-MM-dd', ',', "\u{00A0}", 'áâéèêëîïôöû'],
        'ast' => ['Asturianu', 'Latn', 'ltr', 'd/M/y', ',', '.', 'áéḥíḷñóúü'],
        'ca' => ['Català', 'Latn', 'ltr', 'd/M/y', ',', '.', '·àçéèíïóòúü'],
        'cs' => ['Čeština', 'Latn', 'ltr', 'd. M. y', ',', "\u{00A0}", 'áčďéěíňóřšťúůýž'],
        'cy' => ['Cymraeg', 'Latn', 'ltr', 'd/M/y', '.', ',', 'áàâäéèêëíìîïóòôöúùûüẃẁŵẅýỳŷÿ'],
        'da' => ['Dansk', 'Latn', 'ltr', 'd.M.y', ',', '.', 'æøå'],
        'de' => ['Deutsch', 'Latn', 'ltr', 'd.M.y', ',', '.', 'äößü'],
        'en' => ['English', 'Latn', 'ltr', 'M/d/y', '.', ',', ''],
        'es' => ['Español', 'Latn', 'ltr', 'd/M/y', ',', '.', 'áéíñóúü'],
        'et' => ['Eesti', 'Latn', 'ltr', 'd.M.y', ',', "\u{00A0}", 'šžõäöü'],
        'eu' => ['Euskara', 'Latn', 'ltr', 'y/M/d', ',', '.', 'çñ'],
        'fi' => ['Suomi', 'Latn', 'ltr', 'd.M.y', ',', "\u{00A0}", 'šžåäö'],
        'fr' => ['Français', 'Latn', 'ltr', 'dd/MM/y', ',', "\u{202F}", 'àâæçéèêëîïôœùûüÿ'],
        'fy' => ['Frysk', 'Latn', 'ltr', 'd-M-y', ',', '.', 'áàâäéèêëíïýóôöúûü'],
        'ga' => ['Gaeilge', 'Latn', 'ltr', 'dd/MM/y', '.', ',', 'áéíóú'],
        'gl' => ['Galego', 'Latn', 'ltr', 'd/M/y', ',', '.', 'áéíïñóúü'],
        'hr' => ['Hrvatski', 'Latn', 'ltr', 'dd. MM. y.', ',', '.', 'čćđšž'],
        'hu' => ['Magyar', 'Latn', 'ltr', 'y. MM. dd.', ',', "\u{00A0}", 'áéíóöőúüű'],
        'id' => ['Indonesia', 'Latn', 'ltr', 'd/M/y', ',', '.', ''],
        'is' => ['Íslenska', 'Latn', 'ltr', 'd.M.y', ',', '.', 'áðéíóúýþæö'],
        'it' => ['Italiano', 'Latn', 'ltr', 'd/M/y', ',', '.', 'àéèìóòù'],
        'lb' => ['Lëtzebuergesch', 'Latn', 'ltr', 'd.M.y', ',', '.', 'äéë'],
        'lt' => ['Lietuvių', 'Latn', 'ltr', 'y-MM-dd', ',', "\u{00A0}", 'ąčęėįšųūž'],
        'lv' => ['Latviešu', 'Latn', 'ltr', 'd.MM.y.', ',', "\u{00A0}", 'āčēģīķļņšūž'],
        'ms' => ['Melayu', 'Latn', 'ltr', 'd/M/y', '.', ',', ''],
        'mt' => ['Malti', 'Latn', 'ltr', 'M/d/y', '.', ',', 'àċèġħìòùż'],
        'nb' => ['Norsk bokmål', 'Latn', 'ltr', 'd.M.y', ',', "\u{00A0}", 'àéóòôæøå'],
        'nl' => ['Nederlands', 'Latn', 'ltr', 'd-M-y', ',', '.', 'áäéëíïóöúü'],
        'nn' => ['Norsk nynorsk', 'Latn', 'ltr', 'd.M.y', ',', "\u{00A0}", 'àéóòôæøå'],
        'pl' => ['Polski', 'Latn', 'ltr', 'd.MM.y', ',', "\u{00A0}", 'ąćęłńóśźż'],
        'pt' => ['Português', 'Latn', 'ltr', 'dd/MM/y', ',', '.', 'áàâãçéêíóòôõú'],
        'ro' => ['Română', 'Latn', 'ltr', 'dd.MM.y', ',', '.', 'ăâîșț'],
        'sk' => ['Slovenčina', 'Latn', 'ltr', 'd. M. y', ',', "\u{00A0}", 'áäčďéíĺľňóôŕšťúýž'],
        'sl' => ['Slovenščina', 'Latn', 'ltr', 'd. M. y', ',', '.', 'čšž'],
        'sq' => ['Shqip', 'Latn', 'ltr', 'd.M.y', ',', "\u{00A0}", 'çë'],
        'sv' => ['Svenska', 'Latn', 'ltr', 'y-MM-dd', ',', "\u{00A0}", 'àéåäö'],
        'sw' => ['Kiswahili', 'Latn', 'ltr', 'd/M/y', '.', ',', ''],
        'tr' => ['Türkçe', 'Latn', 'ltr', 'dd.MM.y', ',', '.', 'çğıİöşü'],
        'vi' => ['Tiếng Việt', 'Latn', 'ltr', 'd/M/y', ',', '.', 'àảãáạăằẳẵắặâầẩẫấậđèẻẽéẹêềểễếệìỉĩíịòỏõóọôồổỗốộơờởỡớợùủũúụưừửữứựỳỷỹýỵ'],
    ];

    public static function language(string $id): ?Language
    {
        $entry = self::LANGUAGES[$id] ?? null;
        return $entry === null ? null : new Language($id, ...$entry);
    }

    /** @return list<Language> */
    public static function all(): array
    {
        return array_map(static fn(string $id): Language => new Language($id, ...self::LANGUAGES[$id]), array_keys(self::LANGUAGES));
    }

    /** The search normalization of a language's words; languages outside the catalog are Latin script. */
    public static function normalization(string $locale): string
    {
        return self::SCRIPTS[self::language($locale)->script ?? 'Latn'];
    }
}

/**
 * The scripts and text directions a profile's fonts and layout support, within which installations add
 * languages, and optionally the characters its fonts draw, so a language needing a letter they lack is not offered.
 */
final readonly class LanguageEnvelope
{
    /** @var list<array{int, int}> */
    private array $ranges;

    /**
     * @param list<string> $scripts
     * @param list<string> $directions
     * @param list<string> $characters Hexadecimal code point ranges such as `0000-00FF` or `20AC`; empty for no limit.
     */
    public function __construct(public array $scripts, public array $directions, public array $characters = [])
    {
        if ($scripts === [] || $directions === [] || !array_is_list($scripts) || !array_is_list($directions) || !array_is_list($characters) || count($characters) > 64
            || count(array_unique($scripts)) !== count($scripts) || count(array_unique($directions)) !== count($directions)
            || array_diff($scripts, array_keys(LanguageCatalog::SCRIPTS)) !== [] || array_diff($directions, LanguageCatalog::DIRECTIONS) !== []) {
            throw new \InvalidArgumentException('Profile language envelope is invalid');
        }
        $ranges = [];
        foreach ($characters as $range) {
            if (!is_string($range) || preg_match('/\A([0-9A-F]{4,6})(?:-([0-9A-F]{4,6}))?\z/', $range, $bounds) !== 1
                || hexdec($bounds[2] ?? $bounds[1]) < hexdec($bounds[1]) || hexdec($bounds[2] ?? $bounds[1]) > 0x10FFFF) {
                throw new \InvalidArgumentException('Profile language envelope is invalid');
            }
            $ranges[] = [(int) hexdec($bounds[1]), (int) hexdec($bounds[2] ?? $bounds[1])];
        }
        $this->ranges = $ranges;
    }

    /** With $characters false, whether the language is in the envelope's scripts and directions whatever the fonts draw. */
    public function admits(Language $language, bool $characters = true): bool
    {
        if (!in_array($language->script, $this->scripts, true) || !in_array($language->direction, $this->directions, true)) {
            return false;
        }
        foreach ($this->ranges === [] || !$characters ? [] : $language->characters() as $character) {
            if (array_filter($this->ranges, static fn(array $range): bool => $character >= $range[0] && $character <= $range[1]) === []) {
                return false;
            }
        }
        return true;
    }

    /** @return list<Language> Catalog languages inside the envelope, in catalog order. */
    public function languages(bool $characters = true): array
    {
        return array_values(array_filter(LanguageCatalog::all(), fn(Language $language): bool => $this->admits($language, $characters)));
    }
}
