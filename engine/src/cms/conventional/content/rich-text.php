<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\InvalidInput;

/** The only trusted rich-text fragment constructor validates the complete bounded AST. */
final readonly class RichText
{
    /** @param list<array{type: string, children: list<mixed>}> $nodes */
    private function __construct(public array $nodes, private int $formatVersion) {}

    public static function from(mixed $value, FieldDefinition $field): self
    {
        self::keys($value, ['formatVersion', 'nodes']);
        if (!in_array($value['formatVersion'], [1, 2], true)) {
            throw new InvalidInput('validation.rich.rich_text_version_is_invalid');
        }
        $remaining = $field->settings['maximumNodes'];
        $blocks = 0;
        $nodes = [];
        foreach (self::items($value['nodes']) as $node) {
            self::keys($node, ['type', 'children']);
            $type = $node['type'];
            if (!is_string($type) || !in_array($type, $field->settings['blocks'], true)) {
                throw new InvalidInput('validation.rich.rich_text_block_is_not_declared');
            }
            $blocks += in_array($type, ['bullet_list', 'ordered_list'], true) ? count(self::items($node['children'])) : 1;
            if (isset($field->settings['maximumBlocks']) && $blocks > $field->settings['maximumBlocks']) {
                throw new InvalidInput('validation.rich.single_block');
            }
            self::consume($remaining);
            $children = [];
            if (in_array($type, ['bullet_list', 'ordered_list'], true)) {
                foreach (self::items($node['children']) as $item) {
                    self::keys($item, ['type', 'children']);
                    if ($item['type'] !== 'list_item') {
                        throw new InvalidInput('validation.rich.rich_text_list_item_is_invalid');
                    }
                    self::consume($remaining);
                    $children[] = ['type' => 'list_item', 'children' => self::runs($item['children'], $field, $remaining, $value['formatVersion'])];
                }
                if ($children === []) {
                    throw new InvalidInput('validation.rich.rich_text_list_is_empty');
                }
            } else {
                $children = self::runs($node['children'], $field, $remaining, $value['formatVersion']);
            }
            $last = count($nodes) - 1;
            if (in_array($type, ['bullet_list', 'ordered_list'], true) && $last >= 0 && $nodes[$last]['type'] === $type) {
                $nodes[$last]['children'] = [...$nodes[$last]['children'], ...$children];
            } else {
                $nodes[] = ['type' => $type, 'children' => $children];
            }
        }
        $result = new self($nodes, $value['formatVersion']);
        if (strlen(json_encode($result->document(), JSON_THROW_ON_ERROR)) > 1_048_576) {
            throw new InvalidInput('validation.rich.rich_text_is_too_large');
        }
        return $result;
    }

    /** @return array{formatVersion: int, nodes: list<array{type: string, children: list<mixed>}>} */
    public function document(): array
    {
        return ['formatVersion' => $this->formatVersion, 'nodes' => $this->nodes];
    }

    /** @param null|\Closure(int): string $blockAttributes Reviewed live-preview attributes only. */
    public function html(?string $artifactRoute = null, ?\Closure $blockAttributes = null): string
    {
        $html = '';
        $position = 0;
        foreach ($this->nodes as $node) {
            $tag = ['paragraph' => 'p', 'heading_2' => 'h2', 'heading_3' => 'h3', 'bullet_list' => 'ul', 'ordered_list' => 'ol'][$node['type']];
            $list = in_array($tag, ['ul', 'ol'], true);
            $attributes = $list ? '' : ($blockAttributes !== null ? $blockAttributes($position++) : '');
            $body = '';
            foreach ($node['children'] as $child) {
                $body .= $list
                    ? '<li' . ($blockAttributes !== null ? $blockAttributes($position++) : '') . '>' . self::inlineHtml($child['children'], $artifactRoute) . '</li>'
                    : self::inlineHtml([$child], $artifactRoute);
            }
            $html .= '<' . $tag . $attributes . '>' . $body . '</' . $tag . '>';
        }
        return $html;
    }

    public function hasText(): bool
    {
        foreach ($this->nodes as $node) {
            $groups = in_array($node['type'], ['bullet_list', 'ordered_list'], true) ? $node['children'] : [$node];
            foreach ($groups as $group) {
                foreach ($group['children'] as $run) {
                    if (trim($run['text']) !== '') {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /** Complete plain text for bounded search extraction, preserving adjacent text runs. */
    public function plainText(): string
    {
        $parts = [];
        foreach ($this->nodes as $node) {
            foreach (in_array($node['type'], ['bullet_list', 'ordered_list'], true) ? $node['children'] : [$node] as $group) {
                $parts[] = implode('', array_column($group['children'], 'text'));
            }
        }
        return implode(' ', $parts);
    }

    /** A bounded first-block description; never HTML or a search-engine length rule. */
    public function excerpt(): string
    {
        $node = $this->nodes[0] ?? null;
        if ($node === null) {
            return '';
        }
        $parts = [];
        $groups = in_array($node['type'], ['bullet_list', 'ordered_list'], true) ? $node['children'] : [$node];
        foreach ($groups as $group) {
            foreach ($group['children'] as $run) {
                $parts[] = $run['text'];
            }
        }
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', implode('', $parts))), 0, 500);
    }

    public static function validLink(string $href): bool
    {
        if ($href === '' || strlen($href) > 2048 || preg_match('/[\x00-\x20\x7f\\\\<>"\']/u', $href) !== 0) {
            return false;
        }
        if (str_starts_with($href, '/') && !str_starts_with($href, '//')) {
            return true;
        }
        $parts = parse_url($href);
        return is_array($parts) && in_array($parts['scheme'] ?? '', ['https', 'http'], true)
            && isset($parts['host']) && !isset($parts['user']) && !isset($parts['pass'])
            && filter_var($href, FILTER_VALIDATE_URL) !== false;
    }

    /** @return list<array{text: string, marks: list<string|array{type: string, href: string}>}> */
    private static function runs(mixed $value, FieldDefinition $field, int &$remaining, int $version): array
    {
        $runs = [];
        foreach (self::items($value) as $run) {
            self::keys($run, ['text', 'marks']);
            self::consume($remaining);
            if (!is_string($run['text']) || !mb_check_encoding($run['text'], 'UTF-8') || mb_strlen($run['text']) > 10_000
                || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', $run['text']) !== 0) {
                throw new InvalidInput('validation.rich.rich_text_run_is_invalid');
            }
            $marks = [];
            foreach (self::items($run['marks']) as $mark) {
                if (is_string($mark) && in_array($mark, ['bold', 'italic', 'underline', 'smallCaps'], true)) {
                    $kind = $mark;
                    if ($mark === 'smallCaps' && $version !== 2) {
                        throw new InvalidInput('validation.rich.rich_text_mark_is_invalid');
                    }
                } else {
                    self::keys($mark, ['type', 'href']);
                    if ($mark['type'] !== 'link' || !is_string($mark['href']) || !self::validLink($mark['href'])) {
                        throw new InvalidInput('validation.rich.rich_text_link_is_invalid');
                    }
                    $kind = 'link';
                    $mark = ['type' => 'link', 'href' => $mark['href']];
                }
                if (!in_array($kind, $field->settings['marks'], true) || isset($marks[$kind])) {
                    throw new InvalidInput('validation.rich.rich_text_mark_is_invalid');
                }
                $marks[$kind] = $mark;
            }
            $ordered = [];
            foreach (['bold', 'italic', 'underline', 'smallCaps', 'link'] as $kind) {
                if (isset($marks[$kind])) {
                    $ordered[] = $marks[$kind];
                }
            }
            $text = str_replace("\r\n", "\n", $run['text']);
            $last = count($runs) - 1;
            if ($last >= 0 && $runs[$last]['marks'] === $ordered && mb_strlen($runs[$last]['text'] . $text) <= 10_000) {
                $runs[$last]['text'] .= $text;
            } else {
                $runs[] = ['text' => $text, 'marks' => $ordered];
            }
        }
        return $runs;
    }

    /** @param list<array{text: string, marks: list<string|array{type: string, href: string}>}> $runs */
    private static function inlineHtml(array $runs, ?string $artifactRoute = null): string
    {
        $html = '';
        foreach ($runs as $run) {
            $text = nl2br(Html::text($run['text']), false);
            foreach (array_reverse($run['marks']) as $mark) {
                if (is_array($mark)) {
                    $url = $artifactRoute !== null && str_starts_with($mark['href'], '/')
                        ? \Utsuri\PortableCms\Conventional\Compiler\ArtifactUrl::escaped($mark['href'], $artifactRoute)
                        : Html::attribute($mark['href']);
                    $text = '<a href="' . $url . '">' . $text . '</a>';
                } elseif ($mark === 'smallCaps') {
                    $text = '<span class="cms-small-caps">' . $text . '</span>';
                } else {
                    $tag = ['bold' => 'strong', 'italic' => 'em', 'underline' => 'u'][$mark];
                    $text = '<' . $tag . '>' . $text . '</' . $tag . '>';
                }
            }
            $html .= $text;
        }
        return $html;
    }

    private static function consume(int &$remaining): void
    {
        if (--$remaining < 0) {
            throw new InvalidInput('validation.rich.rich_text_node_bound_exceeded');
        }
    }

    /** @param list<string> $keys */
    private static function keys(mixed $value, array $keys): void
    {
        if (!is_array($value) || count($value) !== count($keys) || array_diff($keys, array_keys($value)) !== []) {
            throw new InvalidInput('validation.rich.rich_text_attributes_are_invalid');
        }
    }

    /** @return list<mixed> */
    private static function items(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 5000) {
            throw new InvalidInput('validation.rich.rich_text_collection_is_invalid');
        }
        return $value;
    }
}
