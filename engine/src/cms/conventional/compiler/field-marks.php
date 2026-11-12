<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Compiler;

use Utsuri\PortableCms\Conventional\Http\Html;

/**
 * Names the fields of the record being edited on its live preview pages, so the workspace can
 * link page elements and form fields. Published pages, exact previews and every other record
 * get nothing, so their bytes never change.
 */
final readonly class FieldMarks
{
    private function __construct(private bool $active) {}

    public static function none(): self
    {
        return new self(false);
    }

    public static function for(FrozenSnapshot $snapshot, FrozenRecord $record): self
    {
        return new self($snapshot->liveRecordId !== null && $snapshot->liveRecordId === $record->id);
    }

    /** A leading-space ` data-cms-field` attribute for the element showing this field, or nothing. */
    public function attribute(string $fieldId): string
    {
        if (preg_match('/\A[A-Za-z][A-Za-z0-9_-]{0,79}\z/', $fieldId) !== 1) {
            throw new \InvalidArgumentException('Field mark names an invalid field');
        }
        return $this->active ? ' data-cms-field="' . Html::attribute($fieldId) . '"' : '';
    }

    /** A rich-text row: paragraphs/headings and individual list items, in document order. */
    public function blockAttribute(string $fieldId, int $position): string
    {
        self::none()->attribute($fieldId);
        if ($position < 0 || $position >= 5000) {
            throw new \InvalidArgumentException('Field mark names an invalid rich-text position');
        }
        return $this->active ? ' data-cms-field="' . Html::attribute($fieldId) . ':' . $position . '"' : '';
    }

    /** A top-level repeatable group's child, addressed by identity, never by list position. */
    public function itemAttribute(string $groupId, string $itemId, string $childId): string
    {
        $path = self::itemPath($groupId, $itemId, $childId);
        return $this->active ? ' data-cms-field="' . Html::attribute($path) . '"' : '';
    }

    /** Shared address for a renderer's mark and the corresponding native form control. */
    public static function itemPath(string $groupId, string $itemId, string $childId): string
    {
        self::none()->attribute($groupId);
        self::none()->attribute($childId);
        if (preg_match('/\A[a-f0-9]{32}\z/', $itemId) !== 1) {
            throw new \InvalidArgumentException('Field mark names an invalid group item');
        }
        return $groupId . '.' . $itemId . '.' . $childId;
    }
}
