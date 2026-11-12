<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Preview;

use Utsuri\PortableCms\Conventional\I18n\Message;

/** The saved result of a check, independent of whether a build job could be queued. */
final readonly class PreviewCheck
{
    /**
     * @param list<Message> $issues An empty list means the check passed.
     * @param list<string|null> $links Engine record links in issue order.
     */
    public function __construct(public \DateTimeImmutable $checkedAt, public bool $includeDrafts, public array $issues, public array $links = [], public ?string $reference = null)
    {
        if ($reference !== null && preg_match('/\A[a-f0-9]{32}\z/', $reference) !== 1) {
            throw new \InvalidArgumentException('Preview check reference is invalid');
        }
        if (!array_is_list($issues) || count($issues) > 100) {
            throw new \InvalidArgumentException('Preview check issues exceed their bound');
        }
        foreach ($issues as $issue) {
            if (!$issue instanceof Message) {
                throw new \InvalidArgumentException('Preview check issues must be catalog messages');
            }
        }
        if (!array_is_list($links) || ($links !== [] && count($links) !== count($issues))) {
            throw new \InvalidArgumentException('Preview check links are invalid');
        }
        foreach ($links as $link) {
            if ($link !== null && (!is_string($link) || strlen($link) > 128 || preg_match('#\A/records/[a-z][a-z0-9_-]*/[a-f0-9]{32}\z#', $link) !== 1)) {
                throw new \InvalidArgumentException('Preview check link is not an engine record route');
            }
        }
    }

    /** @return array{includeDrafts: bool, issues: list<Message>, links: list<string|null>, reference: ?string} */
    public function document(): array
    {
        return ['includeDrafts' => $this->includeDrafts, 'issues' => $this->issues, 'links' => $this->links, 'reference' => $this->reference];
    }

    /** @param array<string, mixed> $document */
    public static function fromDocument(\DateTimeImmutable $checkedAt, array $document): self
    {
        $issues = $document['issues'] ?? null;
        if (!is_bool($document['includeDrafts'] ?? null) || !is_array($issues) || !array_is_list($issues) || count($issues) > 100) {
            throw new \RuntimeException('Stored preview check is invalid');
        }
        $links = $document['links'] ?? [];
        if (!is_array($links)) {
            throw new \RuntimeException('Stored preview check links are invalid');
        }
        $reference = $document['reference'] ?? null;
        if ($reference !== null && !is_string($reference)) {
            throw new \RuntimeException('Stored preview check reference is invalid');
        }
        return new self($checkedAt, $document['includeDrafts'], array_map(static fn(mixed $issue): Message => self::message($issue, 0), $issues), $links, $reference);
    }

    private static function message(mixed $value, int $depth): Message
    {
        if ($depth > 4 || !is_array($value) || !is_string($value['key'] ?? null) || !is_array($value['parameters'] ?? null) || count($value['parameters']) > 16) {
            throw new \RuntimeException('Stored preview check message is invalid');
        }
        $parameters = [];
        foreach ($value['parameters'] as $name => $parameter) {
            if (!is_string($name) || strlen($name) > 64) {
                throw new \RuntimeException('Stored preview check parameter is invalid');
            }
            if (is_array($parameter)) {
                $parameters[$name] = self::message($parameter, $depth + 1);
            } elseif (is_string($parameter) || is_int($parameter) || is_float($parameter)) {
                $parameters[$name] = $parameter;
            } else {
                throw new \RuntimeException('Stored preview check parameter is invalid');
            }
        }
        return new Message($value['key'], $parameters);
    }
}

interface PreviewCheckHistory
{
    public function failed(string $profileId, string $actorUserId, string $auditId, PreviewCheck $check): void;

    public function latest(string $profileId): ?PreviewCheck;
}
