<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

/** The one public name and icon, and the llms.txt choice, frozen from engine Website settings. */
final readonly class WebsiteIdentity
{
    public function __construct(public string $name, public ?string $iconMediaId = null, public ?LlmsTxtSettings $llmsTxt = null)
    {
        if (trim($name) === '' || !mb_check_encoding($name, 'UTF-8') || mb_strlen($name) > 180
            || preg_match('/[\x00-\x1f\x7f]/u', $name) === 1
            || ($iconMediaId !== null && preg_match('/\A[a-f0-9]{32}\z/', $iconMediaId) !== 1)) {
            throw new \InvalidArgumentException('Website identity is invalid');
        }
    }

    public static function from(mixed $document): self
    {
        if (!is_array($document) || count($document) !== 2 + (int) array_key_exists('llmsTxt', $document) || !is_string($document['name'] ?? null)
            || !array_key_exists('iconMediaId', $document) || ($document['iconMediaId'] !== null && !is_string($document['iconMediaId']))) {
            throw new \InvalidArgumentException('Website identity document is invalid');
        }
        return new self($document['name'], $document['iconMediaId'], array_key_exists('llmsTxt', $document) ? LlmsTxtSettings::from($document['llmsTxt']) : null);
    }

    public static function defaults(string $profileId): self
    {
        if (!ProfileManifest::canonicalId($profileId)) {
            throw new \InvalidArgumentException('Website identity profile is invalid');
        }
        return new self(ucwords(str_replace(['-', '_'], ' ', $profileId)));
    }

    /** @return array{name: string, iconMediaId: ?string, llmsTxt?: array{enabled: bool, introductions: list<array{locale: string, text: string}>}} */
    public function document(): array
    {
        return ['name' => $this->name, 'iconMediaId' => $this->iconMediaId, ...($this->llmsTxt === null ? [] : ['llmsTxt' => $this->llmsTxt->document()])];
    }
}
