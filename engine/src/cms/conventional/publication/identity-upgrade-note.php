<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Publication;

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\WebsiteIdentity;

/** Bounded, portable preservation of former profile values, not another editable source. */
final readonly class IdentityUpgradeNote
{
    /** @param list<array{typeId: string, recordId: string, name: ?string, iconMediaId: ?string}> $previous */
    public function __construct(public string $kept, public array $previous)
    {
        if (!in_array($kept, ['website-settings', 'profile-settings'], true) || !array_is_list($previous) || $previous === [] || count($previous) > 64) {
            throw new PublicationFailure('website_publication_invalid');
        }
        $seen = [];
        foreach ($previous as $entry) {
            if (count($entry) !== 4 || !ProfileManifest::canonicalId($entry['typeId']) || preg_match('/\A[a-f0-9]{32}\z/', $entry['recordId']) !== 1
                || isset($seen[$entry['recordId']]) || ($entry['name'] === null && $entry['iconMediaId'] === null)) {
                throw new PublicationFailure('website_publication_invalid');
            }
            try {
                new WebsiteIdentity($entry['name'] ?? 'Icon', $entry['iconMediaId']);
            } catch (\InvalidArgumentException) {
                throw new PublicationFailure('website_publication_invalid');
            }
            $seen[$entry['recordId']] = true;
        }
    }

    public static function from(mixed $document): self
    {
        if (!is_array($document) || count($document) !== 2 || !is_string($document['kept'] ?? null)
            || !is_array($document['previous'] ?? null) || !array_is_list($document['previous']) || count($document['previous']) > 64) {
            throw new PublicationFailure('website_publication_invalid');
        }
        $entries = [];
        foreach ($document['previous'] as $entry) {
            if (!is_array($entry) || !is_string($entry['typeId'] ?? null) || !is_string($entry['recordId'] ?? null)
                || !array_key_exists('name', $entry) || ($entry['name'] !== null && !is_string($entry['name']))
                || !array_key_exists('iconMediaId', $entry) || ($entry['iconMediaId'] !== null && !is_string($entry['iconMediaId'])) || count($entry) !== 4) {
                throw new PublicationFailure('website_publication_invalid');
            }
            $entries[] = ['typeId' => $entry['typeId'], 'recordId' => $entry['recordId'], 'name' => $entry['name'], 'iconMediaId' => $entry['iconMediaId']];
        }
        return new self($document['kept'], $entries);
    }

    /** @return array{kept: string, previous: list<array{typeId: string, recordId: string, name: ?string, iconMediaId: ?string}>} */
    public function document(): array
    {
        return ['kept' => $this->kept, 'previous' => $this->previous];
    }
}
