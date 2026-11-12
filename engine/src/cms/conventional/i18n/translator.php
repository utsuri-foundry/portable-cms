<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\I18n;

use Utsuri\PortableCms\Conventional\Http\Html;

/** A translatable administration message: a stable catalog key and its named parameters. */
final readonly class Message
{
    /** @param array<string, string|int|float|Message> $parameters Nested messages are translated first. */
    public function __construct(public string $key, public array $parameters = [])
    {
        if (preg_match('/\A[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+\z/', $key) !== 1) {
            throw new \LogicException('Message key is invalid');
        }
    }

    /** User text for a caught validation failure; non-catalog failures keep their diagnostic text. */
    public static function fromFailure(\Throwable $error): self
    {
        return $error instanceof LocalizedFailure ? $error->text() : new self('validation.diagnostic', ['text' => $error->getMessage()]);
    }
}

/**
 * A failure whose editor-facing text is a catalog message. Its exception message is the
 * English rendering, kept for logs and existing diagnostics.
 */
interface LocalizedFailure extends \Throwable
{
    public function text(): Message;
}

/** Invalid editorial input with catalog text. */
final class InvalidInput extends \InvalidArgumentException implements LocalizedFailure
{
    private readonly Message $text;

    /** @param array<string, string|int|float|Message> $parameters */
    public function __construct(string $key, array $parameters = [], ?\Throwable $previous = null)
    {
        $this->text = new Message($key, $parameters);
        parent::__construct(Translator::english()->message($this->text), 0, $previous);
    }

    public function text(): Message
    {
        return $this->text;
    }
}

/**
 * Engine-owned interface text. Catalogs are bounded PHP arrays per locale and area;
 * patterns use ICU MessageFormat with named arguments. Missing keys fail closed.
 */
final class Translator
{
    public const DEFAULT_LOCALE = 'en';

    /** @param array<string, string> $catalog */
    private function __construct(private readonly string $locale, private readonly array $catalog) {}

    public static function english(): self
    {
        return self::load(self::DEFAULT_LOCALE);
    }

    public static function load(string $locale): self
    {
        if (preg_match('/\A[a-z]{2}\z/', $locale) !== 1) {
            throw new \LogicException('Interface locale is invalid');
        }
        $catalog = [];
        $files = glob(__DIR__ . '/messages/' . $locale . '/*.php') ?: [];
        sort($files, SORT_STRING);
        if ($files === []) {
            throw new \LogicException('Interface catalog is unavailable');
        }
        foreach ($files as $file) {
            $messages = require $file;
            if (!is_array($messages)) {
                throw new \LogicException('Interface catalog file is invalid');
            }
            foreach ($messages as $key => $pattern) {
                if (!is_string($key) || !is_string($pattern) || isset($catalog[$key])) {
                    throw new \LogicException('Interface catalog key is invalid or duplicated: ' . $key);
                }
                $catalog[$key] = $pattern;
            }
        }
        return new self($locale, $catalog);
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /** @return array<string, string> Every key and pattern, for catalog verification. */
    public function catalog(): array
    {
        return $this->catalog;
    }

    /**
     * Plain text; callers escape for their context.
     *
     * @param array<string, string|int|float|Message> $parameters
     */
    public function text(string $key, array $parameters = []): string
    {
        foreach ($parameters as $name => $parameter) {
            if ($parameter instanceof Message) {
                $parameters[$name] = $this->message($parameter);
            }
        }
        $pattern = $this->catalog[$key] ?? throw new \LogicException('Unknown interface message: ' . $key);
        if ($parameters === []) {
            if (str_contains($pattern, '{')) {
                throw new \LogicException('Interface message requires parameters: ' . $key);
            }
            return $pattern;
        }
        $formatter = \MessageFormatter::create($this->locale, $pattern);
        if ($formatter === null) {
            throw new \LogicException('Interface message pattern is invalid: ' . $key);
        }
        $text = $formatter->format($parameters);
        if (!is_string($text)) {
            throw new \LogicException('Interface message parameters are invalid: ' . $key);
        }
        return $text;
    }

    /** A profile label placed inside a sentence: lower-cased unless the language keeps noun capitals. */
    public function inlineLabel(string $label): string
    {
        return ($this->catalog['grammar.inline_label_case'] ?? 'lower') === 'keep' ? $label : mb_strtolower($label);
    }

    public function has(string $key): bool
    {
        return isset($this->catalog[$key]);
    }

    /** Formats a date with an ICU date pattern stored under a `format.*` catalog key. */
    public function date(\DateTimeInterface $value, string $formatKey, \DateTimeZone $zone): string
    {
        if (!str_starts_with($formatKey, 'format.')) {
            throw new \LogicException('Date formats use format keys');
        }
        $pattern = $this->catalog[$formatKey] ?? throw new \LogicException('Unknown date format: ' . $formatKey);
        // ICU accepts zone identifiers and GMT offsets, not ext/date's "Z" or bare "+01:00" names.
        $name = $zone->getName();
        $icuZone = $name === 'Z' ? 'UTC' : (preg_match('/\A[+-]\d{2}:\d{2}\z/', $name) === 1 ? 'GMT' . $name : $name);
        $formatter = new \IntlDateFormatter($this->locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $icuZone, \IntlDateFormatter::GREGORIAN, $pattern);
        $text = $formatter->format($value);
        if (!is_string($text)) {
            throw new \LogicException('Date format failed: ' . $formatKey);
        }
        return $text;
    }

    /** @param array<string, string|int|float|Message> $parameters */
    public function html(string $key, array $parameters = []): string
    {
        return Html::text($this->text($key, $parameters));
    }

    /**
     * Escaped text with trusted engine markup placed where the pattern names it.
     *
     * @param array<string, string|int|float> $parameters Plain values, escaped with the text.
     * @param array<string, string> $markup Trusted engine markup, inserted unescaped.
     */
    public function markup(string $key, array $parameters, array $markup): string
    {
        $slots = [];
        $index = 0;
        foreach ($markup as $name => $html) {
            $slot = "\u{E000}" . $index++ . "\u{E001}";
            $parameters[$name] = $slot;
            $slots[$slot] = $html;
        }
        return strtr($this->html($key, $parameters), $slots);
    }

    public function message(Message $message): string
    {
        return $this->text($message->key, $message->parameters);
    }

    /** User text for a caught validation failure; non-catalog exceptions keep their diagnostic text. */
    public function failure(\Throwable $error): string
    {
        return $error instanceof LocalizedFailure ? $this->message($error->text()) : $error->getMessage();
    }

    /**
     * Patterns for a browser island. Islands receive simple `{name}` placeholders only.
     *
     * @return array<string, string> Keys relative to the prefix.
     */
    public function bundle(string $prefix): array
    {
        $bundle = [];
        foreach ($this->catalog as $key => $pattern) {
            if (str_starts_with($key, $prefix . '.')) {
                $bundle[substr($key, strlen($prefix) + 1)] = $pattern;
            }
        }
        return $bundle;
    }

    /** Escaped attribute value carrying an island's message bundle. */
    public function bundleAttribute(string $prefix): string
    {
        return Html::attribute(json_encode($this->bundle($prefix), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
