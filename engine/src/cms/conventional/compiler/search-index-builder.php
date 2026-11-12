<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Compiler;

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\TextNormalizer;

final readonly class SearchDocument
{
    public function __construct(public string $id, public string $typeId, public string $route, public string $title, public ?string $mediaId = null)
    {
        if (preg_match('/\A[a-f0-9]{32}\z/', $id) !== 1 || !ProfileManifest::canonicalId($typeId)
            || !CompiledRoute::validRoute($route) || trim($title) === '' || !mb_check_encoding($title, 'UTF-8') || mb_strlen($title) > 240
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $title) === 1
            || ($mediaId !== null && preg_match('/\A[a-f0-9]{32}\z/', $mediaId) !== 1)) {
            throw new \InvalidArgumentException('Search document is invalid');
        }
    }

    /** @return array{id: string, typeId: string, route: string, title: string, resultKind: string, mediaId: ?string} */
    public function document(): array
    {
        return ['id' => $this->id, 'typeId' => $this->typeId, 'route' => $this->route, 'title' => $this->title,
            'resultKind' => $this->mediaId === null ? 'text' : 'image', 'mediaId' => $this->mediaId];
    }
}

/** Search v1 accumulation, normalization and ordering, with profile-owned content and weights. */
final class SearchIndexBuilder
{
    /** @var array<string, SearchDocument> */
    private array $documents = [];
    /** @var array<string, array<string, int>> */
    private array $weights = [];

    public function __construct(private readonly string $locale)
    {
        if (preg_match('/\A[a-z]{2,3}(?:-[A-Z]{2})?\z/', $locale) !== 1) {
            throw new \InvalidArgumentException('Search locale is invalid');
        }
    }

    /** @param list<array{text: string, weight: int}> $fields */
    public function add(SearchDocument $document, array $fields): void
    {
        if (isset($this->documents[$document->id]) || count($this->documents) >= 20_000 || !array_is_list($fields) || count($fields) > 1000) {
            throw new \InvalidArgumentException('Search document selection is duplicated or unbounded');
        }
        $tokens = [];
        foreach ($fields as $field) {
            if (array_diff(array_keys($field), ['text', 'weight']) !== [] || !is_string($field['text'] ?? null) || !is_int($field['weight'] ?? null)
                || $field['weight'] < 1 || $field['weight'] > 255 || strlen($field['text']) > 1024 * 1024 || !mb_check_encoding($field['text'], 'UTF-8')) {
                throw new \InvalidArgumentException('Search text or weight is invalid');
            }
            foreach (array_unique(explode(' ', TextNormalizer::search($field['text']))) as $token) {
                if ($token === '') {
                    continue;
                }
                if (mb_strlen($token) > 100) {
                    throw new \InvalidArgumentException('Search token exceeds the contract');
                }
                $tokens[$token] = max($field['weight'], $tokens[$token] ?? 0);
            }
        }
        if (count($this->weights) + count(array_diff_key($tokens, $this->weights)) > 200_000) {
            throw new \InvalidArgumentException('Search term count exceeds the contract');
        }
        $this->documents[$document->id] = $document;
        foreach ($tokens as $token => $weight) {
            $this->weights[$token][$document->id] = $weight;
        }
    }

    public function build(): CompiledSearchIndex
    {
        $terms = [];
        foreach ($this->weights as $token => $postings) {
            $items = [];
            foreach ($postings as $id => $weight) {
                $items[] = ['documentId' => $id, 'weight' => $weight];
            }
            usort($items, static fn(array $a, array $b): int => ($b['weight'] <=> $a['weight']) ?: ($a['documentId'] <=> $b['documentId']));
            $terms[] = ['token' => (string) $token, 'postings' => $items];
        }
        usort($terms, static fn(array $a, array $b): int => $a['token'] <=> $b['token']);
        return new CompiledSearchIndex($this->locale, [
            'formatVersion' => 1, 'locale' => $this->locale,
            'normalization' => 'unicode_nfkd_accent_fold_lower_words_v1', 'ordering' => 'weight_desc_document_id_asc',
            'documents' => array_values(array_map(static fn(SearchDocument $document): array => $document->document(), $this->documents)),
            'terms' => $terms,
        ]);
    }
}
