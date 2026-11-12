<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

use Utsuri\PortableCms\Conventional\Http\Request;

/** Flat native controls are an HTTP adapter; only RichText validates durable documents. */
final class RichTextForm
{
    /** @return array<string, mixed> */
    public static function input(Request $request, string $name, FieldDefinition $field): array
    {
        $count = self::count($request->formValue($name . '__rows'));
        if ($request->formValue($name . '__end') !== '1') {
            throw new \InvalidArgumentException('Incomplete rich text form');
        }
        $rows = [];
        $total = $count;
        for ($row = 0; $row < $count; $row++) {
            $prefix = $name . '__' . $row;
            $runs = [];
            $spans = self::count($request->formValue($prefix . '__runs'));
            $total += $spans;
            if ($total > 5000) {
                throw new \InvalidArgumentException('Rich text form node bound exceeded');
            }
            for ($span = 0; $span < $spans; $span++) {
                $run = $prefix . '__' . $span;
                if (!isset($request->form[$run . '__text'])) {
                    throw new \InvalidArgumentException('Incomplete rich text segment');
                }
                $marks = [];
                foreach (['bold', 'italic', 'underline', 'smallCaps'] as $mark) {
                    if ($request->formValue($run . '__' . $mark) === '1') {
                        $marks[] = $mark;
                    }
                }
                $href = $request->formValue($run . '__link');
                if ($href !== '') {
                    $marks[] = ['type' => 'link', 'href' => $href];
                }
                $runs[] = ['text' => $request->formValue($run . '__text'), 'marks' => $marks];
            }
            $rows[] = ['type' => $request->formValue($prefix . '__type'), 'children' => $runs];
        }
        $action = explode(':', $request->formValue('_rich_action'));
        if (($action[0] ?? '') === $name) {
            $rows = self::action($rows, $action[1] ?? '', (int) ($action[2] ?? -1), (int) ($action[3] ?? -1), $field->settings['blocks'][0]);
        }
        return self::document($rows);
    }

    /** @return list<array{type: string, children: list<array{text: string, marks: list<mixed>}>}> */
    public static function rows(mixed $document): array
    {
        if (!is_array($document) || !is_array($document['nodes'] ?? null)) {
            return [];
        }
        $rows = [];
        foreach ($document['nodes'] as $node) {
            if (in_array($node['type'], ['bullet_list', 'ordered_list'], true)) {
                foreach ($node['children'] as $item) {
                    $rows[] = ['type' => $node['type'], 'children' => $item['children']];
                }
            } else {
                $rows[] = $node;
            }
        }
        return $rows;
    }

    /**
     * @param list<array{type: string, children: list<array{text: string, marks: list<mixed>}>}> $rows
     * @return array<string, mixed>
     */
    public static function document(array $rows): array
    {
        $nodes = [];
        foreach ($rows as $row) {
            if (in_array($row['type'], ['bullet_list', 'ordered_list'], true)) {
                $last = count($nodes) - 1;
                $item = ['type' => 'list_item', 'children' => $row['children']];
                if ($last >= 0 && $nodes[$last]['type'] === $row['type']) {
                    $nodes[$last]['children'][] = $item;
                } else {
                    $nodes[] = ['type' => $row['type'], 'children' => [$item]];
                }
            } else {
                $nodes[] = $row;
            }
        }
        $version = 1;
        foreach ($rows as $row) {
            foreach ($row['children'] as $run) {
                if (in_array('smallCaps', $run['marks'], true)) {
                    $version = 2;
                }
            }
        }
        return ['formatVersion' => $version, 'nodes' => $nodes];
    }

    /**
     * @param list<array{type: string, children: list<array{text: string, marks: list<mixed>}>}> $rows
     * @return list<array{type: string, children: list<array{text: string, marks: list<mixed>}>}>
     */
    private static function action(array $rows, string $action, int $row, int $span, string $defaultType): array
    {
        if ($action === 'add' && count($rows) < 5000) {
            $rows[] = ['type' => $defaultType, 'children' => [['text' => '', 'marks' => []]]];
        } elseif (isset($rows[$row])) {
            if ($action === 'remove') {
                array_splice($rows, $row, 1);
            } elseif ($action === 'up' && $row > 0) {
                [$rows[$row - 1], $rows[$row]] = [$rows[$row], $rows[$row - 1]];
            } elseif ($action === 'down' && isset($rows[$row + 1])) {
                [$rows[$row + 1], $rows[$row]] = [$rows[$row], $rows[$row + 1]];
            } elseif ($action === 'run' && count($rows[$row]['children']) < 5000) {
                $rows[$row]['children'][] = ['text' => '', 'marks' => []];
            } elseif ($action === 'delete-run' && isset($rows[$row]['children'][$span])) {
                array_splice($rows[$row]['children'], $span, 1);
            }
        }
        return $rows;
    }

    private static function count(string $value): int
    {
        $count = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 5000]]);
        if (!is_int($count)) {
            throw new \InvalidArgumentException('Rich text form bound is invalid');
        }
        return $count;
    }
}
