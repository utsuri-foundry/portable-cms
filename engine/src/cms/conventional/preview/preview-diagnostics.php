<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Preview;

use Utsuri\PortableCms\Conventional\Compiler\ProfileBuildFailure;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\I18n\Message;
use Utsuri\PortableCms\Conventional\I18n\Translator;

/** Durable editor messages, separate from private technical diagnostics. */
final readonly class PreviewDiagnostics
{
    /** @param list<Message> $issues */
    public function __construct(public array $issues, public ?string $reference = null)
    {
        if (count($issues) > 100 || ($reference !== null && preg_match('/\A[a-f0-9]{32}\z/', $reference) !== 1)) {
            throw new \InvalidArgumentException('Preview diagnostics are invalid');
        }
    }

    public static function cause(ProfileBuildFailure $error, PreviewSnapshot $snapshot, ProfileManifest $profile): Message
    {
        if ($error->reason === \Utsuri\PortableCms\Conventional\Compiler\ProfileBuildReason::MissingWording && $error->fieldId !== null) {
            return new Message('preflight.issue.wording', ['language' => self::language($profile, $error->locale ?? ''), 'keys' => $error->fieldId]);
        }
        $reason = new Message('preflight.reason.' . $error->reason->value);
        foreach ($snapshot->records as $record) {
            if ($record->id !== $error->recordId) {
                continue;
            }
            $type = $profile->type($record->typeId);
            $field = $error->fieldId ?? '';
            foreach ($type->fields ?? [] as $definition) {
                if ($definition->id === $field) {
                    $field = $definition->label;
                }
            }
            if ($field !== '') {
                $reason = $error->locale === null || $error->locale === ''
                    ? new Message('preflight.issue.field', ['field' => $field, 'reason' => $reason])
                    : new Message('preflight.issue.field_locale', ['field' => $field, 'locale' => $error->locale, 'reason' => $reason]);
            }
            return new Message('preflight.issue.record', ['type' => $type->singularLabel ?? $record->typeId, 'title' => self::title($record, $profile), 'reason' => $reason]);
        }
        if ($error->route !== null) {
            return new Message('preflight.issue.route', ['route' => $error->route, 'reason' => $reason]);
        }
        if ($error->typeId !== null) {
            return new Message('preflight.issue.setting', ['setting' => $profile->type($error->typeId)->singularLabel ?? $error->typeId, 'reason' => $reason]);
        }
        return new Message('preflight.issue.website', ['reason' => $reason]);
    }

    /** Resolve durable wording identities when showing a report, in the current editor's language. */
    public static function present(Message $issue, ?ProfileManifest $profile): Message
    {
        if ($issue->key !== 'preflight.issue.wording' || $profile === null || !is_string($issue->parameters['keys'] ?? null)) {
            return $issue;
        }
        $names = [];
        foreach (explode(', ', $issue->parameters['keys']) as $key) {
            $definition = $profile->wording($key);
            // Reports saved by older engines contain display names rather than identities.
            $names[] = $definition === null ? $key : $definition->label . ' (' . $definition->purpose . ')';
        }
        return new Message($issue->key, [...$issue->parameters, 'keys' => implode(', ', $names)]);
    }

    /** A language's name as the profile writes it, for messages that name a language. */
    public static function language(ProfileManifest $profile, string $locale): string
    {
        foreach ($profile->localeDefinitions as $definition) {
            if ($definition->id === $locale) {
                return $definition->label;
            }
        }
        return $locale;
    }

    public static function title(PreviewRecord $record, ProfileManifest $profile): string
    {
        foreach (['title', 'name', 'label'] as $key) {
            $raw = $record->body[$key] ?? null;
            foreach (is_array($raw) ? [$profile->defaultLocale, ...$profile->locales] : [''] as $locale) {
                $value = is_array($raw) ? ($raw[$locale] ?? null) : $raw;
                if (is_string($value) && trim($value) !== '') {
                    return mb_substr(trim($value), 0, 200);
                }
            }
        }
        return substr($record->id, 0, 8);
    }

    /** @return array{issues: list<Message>, reference: ?string} */
    public function document(): array
    {
        return ['issues' => $this->issues, 'reference' => $this->reference];
    }

    public static function fromDocument(mixed $value): ?self
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || !is_array($value['issues'] ?? null) || !array_is_list($value['issues']) || count($value['issues']) > 100) {
            throw new \InvalidArgumentException('Stored preview diagnostics are invalid');
        }
        $issues = array_map(static fn(mixed $issue): Message => self::message($issue, 0), $value['issues']);
        $reference = $value['reference'] ?? null;
        if ($reference !== null && !is_string($reference)) {
            throw new \InvalidArgumentException('Stored preview reference is invalid');
        }
        return new self($issues, $reference);
    }

    private static function message(mixed $value, int $depth): Message
    {
        if ($depth > 3 || !is_array($value) || !is_string($value['key'] ?? null) || !str_starts_with($value['key'], 'preflight.') || !is_array($value['parameters'] ?? null) || count($value['parameters']) > 10) {
            throw new \InvalidArgumentException('Stored preview message is invalid');
        }
        $parameters = [];
        foreach ($value['parameters'] as $key => $parameter) {
            if (!is_string($key) || (!is_array($parameter) && !is_string($parameter) && !is_int($parameter))) {
                throw new \InvalidArgumentException('Stored preview parameter is invalid');
            }
            $parameters[$key] = is_array($parameter) ? self::message($parameter, $depth + 1) : $parameter;
        }
        $message = new Message($value['key'], $parameters);
        Translator::english()->message($message);
        return $message;
    }
}
