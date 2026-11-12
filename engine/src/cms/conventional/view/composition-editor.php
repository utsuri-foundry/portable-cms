<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\Composition;
use Utsuri\PortableCms\Conventional\Content\EditorialControls;
use Utsuri\PortableCms\Conventional\Content\FieldDefinition;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\RecordTypeDefinition;
use Utsuri\PortableCms\Conventional\Design\RegistryDocument;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\Media\MediaLibrary;

/** Native row workspace. Source IDs stay implementation details behind named controls. */
final class CompositionEditor
{
    private readonly Translator $t;

    /** @param array<string, string> $undo */
    public function __construct(
        private readonly ProfileManifest $profile,
        private readonly RecordTypeDefinition $type,
        private readonly Request $request,
        private readonly ?MediaLibrary $media,
        private readonly ?RegistryDocument $registry,
        private readonly array $undo = [],
        ?Translator $translator = null,
        private readonly ?\Utsuri\PortableCms\Conventional\Content\ContentService $content = null,
    ) {
        $this->t = $translator ?? Translator::english();
    }

    /** @param list<FieldDefinition> $fields */
    public function within(array $fields): self
    {
        return new self($this->profile, \Utsuri\PortableCms\Conventional\Content\BlocksForm::type($this->type, $fields), $this->request, $this->media, $this->registry, $this->undo, $this->t, $this->content);
    }

    public function field(FieldDefinition $field, mixed $value, ?string $error): string
    {
        $t = $this->t;
        $value = EditorialControls::structured($value);
        $value = $value === '' ? null : $value;
        $value ??= ['formatVersion' => 1, 'rows' => []];
        $transport = $value;
        try {
            $layout = Composition::from($value, $field, $this->type, $this->registry, $this->profile->supported());
            $value = $layout->document();
            $transport = $layout->compact();
        } catch (\InvalidArgumentException) { /* Preserve rejected input in the bounded native form. */
        }
        if (!is_array($value) || !is_array($value['rows'] ?? null)) {
            $value = ['formatVersion' => 1, 'rows' => []];
        }
        if ($field->localized) {
            return '<fieldset class="admin-field"><legend>' . Html::text($field->label) . '</legend><p>' . $t->html('composition.localized') . '</p></fieldset>';
        }
        $id = $field->id;
        $action = explode(':', $this->request->formValue('_composition_action'));
        $active = $this->request->formValue($id . '__active');
        if ($action[0] === 'add-row' && ($action[1] ?? '') === $id && $value['rows'] !== []) {
            $active = $value['rows'][array_key_last($value['rows'])]['id'];
        }
        if (($action[0] ?? '') === 'edit-row' && ($action[1] ?? '') === $id) {
            $active = $action[2] ?? '';
        }
        if (!in_array($active, array_column($value['rows'], 'id'), true)) {
            $active = $value['rows'][0]['id'] ?? '';
        }
        $markup = '<fieldset class="admin-field admin-composition" id="field-' . Html::attribute($id) . '" tabindex="-1" aria-describedby="' . Html::attribute($id . '-layout-help' . ($error === null ? '' : ' ' . $id . '-layout-error')) . '"' . ($error === null ? '' : ' aria-invalid="true"') . ($this->request->method === 'POST' && ($action[1] ?? '') === $id ? ' autofocus' : '') . ' data-composition-editor data-composition-name="' . Html::attribute($id) . '"><legend>' . Html::text($field->label) . '</legend>'
            . '<p class="admin-help" id="' . Html::attribute($id . '-layout-help') . '">' . $t->html('composition.help') . '</p>'
            . ($error === null ? '' : '<p class="admin-field__error" id="' . Html::attribute($id . '-layout-error') . '" role="alert">' . Html::text($error) . '</p>')
            . '<input type="hidden" name="' . Html::attribute($id . '__document') . '" value="' . Html::attribute(json_encode($transport, JSON_THROW_ON_ERROR)) . '">'
            . '<input type="hidden" name="' . Html::attribute($id . '__active') . '" value="' . Html::attribute($active) . '">';
        $workspaceOpen = $error !== null || ($this->request->method === 'POST' && ($action[1] ?? '') === $id);
        $markup .= $this->summary($value['rows'])
            . '<details class="admin-layout-workspace" data-layout-workspace' . ($workspaceOpen ? ' open' : '') . '><summary class="admin-layout-workspace__toggle">' . $t->html('composition.edit_layout') . '</summary>'
            . '<div class="admin-composition-toolbar"><details><summary>' . $t->html('composition.page_settings') . '</summary><div class="admin-composition-settings">'
            . $this->length($id . '__columnGap', $t->text('composition.column_gap'), $value['columnGap'] ?? null)
            . $this->length($id . '__rowGap', $t->text('composition.row_gap'), $value['rowGap'] ?? null)
            . $this->length($id . '__padding', $t->text('composition.padding'), $value['padding'] ?? null);
        $ranges = ['' => $t->text('composition.never_stack')];
        foreach ($this->registry?->document()['responsive'] ?? [] as $range) {
            $ranges[$range['id']] = $t->text('composition.stack_below', ['range' => $range['label'], 'width' => (string) $range['minWidth']]);
        }
        $markup .= $this->select($id . '__stackAt', $t->text('composition.responsive'), $value['stackAt'] ?? '', $ranges) . '</div></details>';
        $undo = $this->undo[$id] ?? $this->request->formValue($id . '__undo');
        if ($undo !== '') {
            $markup .= '<input type="hidden" name="' . Html::attribute($id . '__undo') . '" value="' . Html::attribute($undo) . '">' . self::button('undo:' . $id, '↶ ' . $t->text('composition.undo'), class: 'admin-button--quiet');
        }
        $markup .= '</div><div class="admin-composition-workspace"><div class="admin-composition-design"><h3>' . $t->html('composition.rows') . ' <span class="admin-meta">(' . count($value['rows']) . ')</span></h3><ol class="admin-composition-map">';
        $activePosition = 0;
        foreach ($value['rows'] as $position => $row) {
            if (!is_array($row) || !is_string($row['id'] ?? null)) {
                continue;
            }
            $rowId = $row['id'];
            $activePosition = $rowId === $active ? $position : $activePosition;
            $name = ['position' => (string) ($position + 1)];
            $markup .= '<li><button type="submit" name="_composition_action" value="' . Html::attribute('edit-row:' . $id . ':' . $rowId) . '" formnovalidate class="admin-composition-strip"'
                . ($rowId === $active ? ' aria-current="true"' : '') . ' aria-label="' . $t->html('composition.edit_row', $name + ['columns' => (string) count($row['columns'] ?? [])]) . '">' . $this->cells($row) . '</button>'
                . '<span class="admin-composition-row-actions">'
                . ($position > 0 ? self::button('up-row:' . $id . ':' . $rowId, '↑', label: $t->text('composition.row_up', $name)) : '')
                . ($position + 1 < count($value['rows']) ? self::button('down-row:' . $id . ':' . $rowId, '↓', label: $t->text('composition.row_down', $name)) : '')
                . self::button('remove-row:' . $id . ':' . $rowId, '×', label: $t->text('composition.row_remove', $name)) . '</span></li>';
        }
        $markup .= '</ol>' . self::button('add-row:' . $id, '+ ' . $t->text('composition.add_row'), class: 'admin-button--dashed');
        $markup .= '<h3>' . ($active === '' ? $t->html('composition.no_rows') : $t->html('composition.row', ['position' => (string) ($activePosition + 1)])) . '</h3>';
        $query = mb_substr($this->request->formValue($id . '__query'), 0, 100);
        $page = max(1, min(1000, (int) ($this->request->formValue($id . '__page') ?: '1')));
        if ($action[0] === 'media-page' && ($action[1] ?? '') === $id) {
            $page = max(1, min(1000, (int) ($action[2] ?? '1')));
        }
        $library = $this->media?->search($query, $page);
        $linkQuery = mb_substr($this->request->formValue($id . '__linkQuery'), 0, 100);
        $linkPage = max(1, min(1000, (int) ($this->request->formValue($id . '__linkPage') ?: '1')));
        if ($action[0] === 'link-page' && ($action[1] ?? '') === $id) {
            $linkPage = max(1, min(1000, (int) ($action[2] ?? '1')));
        }
        $targets = $field->targetTypes === [] ? null : $this->content?->searchReferences($field, $linkQuery, $linkPage);
        $linkOptions = ['' => $t->text('composition.no_link')];
        foreach ($targets->records ?? [] as $target) {
            $token = (new \Utsuri\PortableCms\Conventional\Content\RecordReference($target->typeId, $target->id))->token();
            $linkOptions[$token] = \Utsuri\PortableCms\Conventional\Content\RecordLabels::label($target, $this->profile) . ' · ' . $this->profile->type($target->typeId)->singularLabel;
        }
        foreach ($value['rows'] as $row) {
            if (($row['id'] ?? '') !== $active) {
                continue;
            }
            $prefix = $id . '__' . $active;
            $markup .= '<details class="admin-row-menu"><summary>' . $t->html('composition.row_settings') . '</summary><div class="admin-composition-settings">' . $this->length($prefix . '__gap', $t->text('composition.row_column_gap'), $row['gap'] ?? null, true)
                . $this->length($prefix . '__minHeight', $t->text('composition.min_height'), $row['minHeight'] ?? null) . '</div></details>';
            $total = array_sum(array_map(static fn(array $column): float => is_numeric($column['share'] ?? null) ? (float) $column['share'] : 1, $row['columns']));
            $tabs = '';
            foreach ($row['columns'] as $index => $column) {
                $tabs .= '<button type="button" data-column-tab="' . $index . '" aria-pressed="false">' . $t->html('composition.column', ['position' => (string) ($index + 1)]) . '</button>';
            }
            $markup .= '<div class="admin-segmented admin-composition-tabs" role="group" aria-label="' . $t->html('composition.column_tabs') . '" data-column-tabs hidden>' . $tabs . '</div><div class="admin-composition-columns">';
            foreach ($row['columns'] as $index => $column) {
                $prefix = $id . '__' . $active . '__c' . $index;
                $options = ['' => $t->text('composition.blank_option')];
                foreach ($library->items ?? [] as $media) {
                    if ($media->status === 'ready') {
                        $options['media:' . $media->id] = $media->name;
                    }
                }
                $content = is_array($column['content'] ?? null) ? $column['content'] : [];
                $source = ($content['kind'] ?? '') === 'media' ? 'media:' . ($content['id'] ?? '') : (($content['kind'] ?? '') === '' ? '' : $content['kind'] . ':' . ($content['field'] ?? ''));
                $selectedMedia = ($content['kind'] ?? '') === 'media' ? $this->media?->find($content['id'] ?? '') : null;
                if ($selectedMedia !== null) {
                    $options[$source] = $selectedMedia->name;
                }
                foreach ($this->type->fields as $sourceField) {
                    if ($sourceField->type === 'rich_text') {
                        $options['rich_text:' . $sourceField->id] = $t->text('composition.source.text', ['field' => $sourceField->label]);
                    }
                    if ($sourceField->type === 'video_reference' && ($field->settings['videoEnabled'] ?? false) === true) {
                        $options['video:' . $sourceField->id] = $t->text('composition.source.video', ['field' => $sourceField->label]);
                    }
                    if (in_array($sourceField->id, $field->settings['blockFields'] ?? [], true)) {
                        $options['block:' . $sourceField->id] = $t->text('composition.source.block', ['field' => $sourceField->label]);
                    }
                }
                $columnId = $column['id'] ?? $active . '-c' . ($index + 1);
                $columnName = ['position' => (string) ($index + 1)];
                $share = $total > 0 && is_numeric($column['share'] ?? null) ? round(100 * (float) $column['share'] / $total, 1) : 0;
                $markup .= '<fieldset class="admin-composition-column" data-column-panel="' . $index . '"><legend>' . $t->html('composition.column', $columnName) . '</legend>'
                    . ($selectedMedia === null ? '' : ReferenceControls::thumbnail($selectedMedia))
                    . $this->select($prefix . '__source', $t->text('composition.asset'), $source, $options)
                    . $this->imageDetails($prefix, $content, $linkOptions, $field)
                    . (($content['kind'] ?? '') === 'rich_text' ? '<a href="#field-' . Html::attribute($content['field']) . '">' . $t->html('composition.edit_text') . '</a>' : '')
                    . '<div class="admin-composition-settings">' . self::number($prefix . '__share', $t->text('composition.share'), $column['share'] ?? 1, '0.01', '1000')
                    . '<p class="admin-meta">' . $t->html('composition.share_result', ['percent' => (string) $share]) . '</p></div>'
                    . '<details><summary>' . $t->html('composition.fine_tune') . '</summary><div class="admin-composition-settings">'
                    . self::number($prefix . '__width', $t->text('composition.asset_width'), $column['width'] ?? 100, '1', '100');
                $anchors = [];
                foreach (Composition::ANCHORS as $anchor) {
                    $anchors[$anchor] = $t->has('composition.anchor.' . str_replace('-', '_', $anchor)) ? $t->text('composition.anchor.' . str_replace('-', '_', $anchor)) : $anchor;
                }
                $markup .= $this->select($prefix . '__anchor', $t->text('composition.anchor'), $column['anchor'] ?? 'top-left', $anchors);
                $roles = ['' => $t->text('composition.inherit_typography')];
                foreach ($this->registry?->document()['typography'] ?? [] as $role) {
                    $roles[$role['id']] = $role['label'];
                }
                $markup .= $this->select($prefix . '__roleId', $t->text('composition.text_role'), $column['roleId'] ?? '', $roles);
                foreach (['top', 'right', 'bottom', 'left'] as $edge) {
                    $markup .= $this->length($prefix . '__' . $edge, $t->text('composition.padding_' . $edge), $column['padding'][$edge] ?? null);
                }
                $markup .= '</div></details><div class="admin-group-actions">'
                    . ($index > 0 ? self::button('left-column:' . $id . ':' . $columnId, '← ' . $t->text('composition.move'), label: $t->text('composition.column_left', $columnName)) : '')
                    . ($index + 1 < count($row['columns']) ? self::button('right-column:' . $id . ':' . $columnId, $t->text('composition.move') . ' →', label: $t->text('composition.column_right', $columnName)) : '')
                    . (count($row['columns']) > 1 ? self::button('remove-column:' . $id . ':' . $columnId, $t->text('group.remove'), label: $t->text('composition.column_remove', $columnName), class: 'admin-button--quiet') : '') . '</div></fieldset>';
            }
            $markup .= '</div>' . self::button('add-column:' . $id . ':' . $active, '+ ' . $t->text('composition.add_column'), class: 'admin-button--dashed');
        }
        if ($field->targetTypes !== []) {
            $markup .= '<details><summary>' . $t->html('composition.find_link_targets') . '</summary><label>' . $t->html('composition.find_by_name') . '<input type="search" maxlength="100" name="' . Html::attribute($id . '__linkQuery') . '" value="' . Html::attribute($linkQuery) . '"></label>'
                . '<input type="hidden" name="' . Html::attribute($id . '__linkPage') . '" value="' . $linkPage . '">' . self::button('link-page:' . $id . ':1', $t->text('composition.find_link_targets'))
                . ($linkPage > 1 ? self::button('link-page:' . $id . ':' . ($linkPage - 1), $t->text('records.pages.previous')) : '')
                . ($targets?->hasNext === true ? self::button('link-page:' . $id . ':' . ($linkPage + 1), $t->text('records.pages.next')) : '') . '</details>';
        }
        $markup .= '<details><summary>' . $t->html('composition.find_images') . '</summary><label>' . $t->html('composition.find_by_name') . '<input type="search" maxlength="100" name="' . Html::attribute($id . '__query') . '" value="' . Html::attribute($query) . '"></label>'
            . '<input type="hidden" name="' . Html::attribute($id . '__page') . '" value="' . $page . '">' . self::button('media-page:' . $id . ':1', $t->text('composition.find_images')) . '<ul class="admin-picker__results">';
        foreach ($library->items ?? [] as $media) {
            $markup .= '<li>' . ReferenceControls::thumbnail($media) . '<span>' . Html::text($media->name) . '</span></li>';
        }
        $markup .= '</ul>' . ($page > 1 ? self::button('media-page:' . $id . ':' . ($page - 1), $t->text('composition.previous_images')) : '')
            . ($library?->hasNext === true ? self::button('media-page:' . $id . ':' . ($page + 1), $t->text('composition.next_images')) : '') . '</details></div>';
        $requested = $this->request->formValue($id . '__viewport');
        $selectedWidth = in_array($requested, ['390', '768', '1440'], true) ? $requested : '1440';
        $devices = '';
        foreach (['390' => 'phone', '768' => 'tablet', '1440' => 'desktop'] as $width => $device) {
            $devices .= '<label><input type="radio" name="' . Html::attribute($id . '__viewport') . '" value="' . $width . '"' . ((string) $width === $selectedWidth ? ' checked' : '') . '>'
                . $t->html('composition.device.' . $device) . '</label>';
        }
        $localeOptions = [];
        foreach ($this->profile->localeDefinitions as $locale) {
            $localeOptions[$locale->id] = $locale->label;
        }
        return $markup . '<section class="admin-composition-preview" aria-label="' . $t->html('composition.preview_label') . '"><h3>' . $t->html('composition.preview') . '</h3><p class="admin-help">' . $t->html('composition.preview_help') . '</p>'
            . '<div class="admin-composition-preview__controls"><fieldset class="admin-composition-devices"><legend class="admin-visually-hidden">' . $t->html('composition.preview_width') . '</legend><div class="admin-segmented">' . $devices . '</div></fieldset>'
            . $this->select($id . '__locale', $t->text('composition.preview_language'), $this->request->formValue($id . '__locale') ?: $this->profile->defaultLocale, $localeOptions) . '</div>'
            . '<input type="hidden" name="' . Html::attribute($id . '__sequence') . '" value="0">'
            . '<button type="submit" name="_composition_preview" value="' . Html::attribute($id) . '" formtarget="composition-' . Html::attribute($id) . '" formnovalidate class="admin-button--small" data-composition-preview-button>' . $t->html('composition.update_preview') . '</button>'
            . '<button type="submit" name="_composition_preview" value="' . Html::attribute($id) . '" formtarget="_blank" formnovalidate class="admin-button--small admin-button--quiet">' . $t->html('composition.full_size') . '</button>'
            . '<p class="admin-meta" role="status" data-composition-status>' . $t->html('composition.preview_idle') . '</p>'
            . '<div class="admin-composition-stage" data-composition-stage><div class="admin-composition-frame-box" data-composition-box><iframe sandbox="allow-same-origin" name="composition-' . Html::attribute($id) . '" title="' . $t->html('composition.frame', ['field' => $field->label]) . '" data-composition-frame></iframe></div>'
            . '<span class="admin-composition-scale" data-composition-scale hidden></span></div></section>'
            . '</div></details><input type="hidden" name="' . Html::attribute($id . '__end') . '" value="1"></fieldset>';
    }

    /** @param array<int, mixed> $rows */
    private function summary(array $rows): string
    {
        $t = $this->t;
        $schematic = '';
        // A sketch of the first rows only, so the largest layouts keep their form budget.
        foreach (array_slice($rows, 0, 8) as $row) {
            if (is_array($row)) {
                $schematic .= '<span class="admin-layout-summary__row">' . $this->cells($row, false) . '</span>';
            }
        }
        return '<div class="admin-layout-summary"><p class="admin-layout-summary__count">'
            . ($rows === [] ? $t->html('composition.summary.empty') : $t->html('composition.summary.rows', ['count' => count($rows)])) . '</p>'
            . ($schematic === '' ? '' : '<span class="admin-layout-summary__schematic" aria-hidden="true">' . $schematic . '</span>') . '</div>';
    }
    private function length(string $name, string $label, mixed $raw, bool $inherit = false): string
    {
        $t = $this->t;
        $value = is_array($raw) ? $raw : ['unit' => $inherit ? '' : 'px', 'value' => 0];
        $units = ($inherit ? ['' => $t->text('composition.unit.inherit')] : []) + ['px' => 'px', 'rem' => 'rem', 'vw' => 'vw', 'vh' => 'vh', 'token' => $t->text('composition.unit.token')];
        $tokens = implode(', ', array_column($this->registry?->document()['spacing'] ?? [], 'id'));
        $unit = is_scalar($value['unit'] ?? null) ? (string) $value['unit'] : '';
        if (!array_key_exists($unit, $units)) {
            $units[$unit] = $t->text('composition.unavailable');
        }
        $options = '';
        foreach ($units as $id => $text) {
            $options .= '<option value="' . Html::attribute($id) . '"' . ($id === $unit ? ' selected' : '') . '>' . Html::text($text) . '</option>';
        }
        return '<div class="admin-composition-length"><label for="' . Html::attribute($name . '__value') . '">' . Html::text($label) . '</label>'
            . '<div class="admin-input-group"><input type="text" id="' . Html::attribute($name . '__value') . '" name="' . Html::attribute($name . '__value') . '" value="'
            . Html::attribute(is_scalar($value['value'] ?? null) ? (string) $value['value'] : '') . '" maxlength="80" inputmode="decimal">'
            . '<label class="admin-visually-hidden" for="' . Html::attribute($name . '__unit') . '">' . $t->html('composition.unit_label', ['label' => $label]) . '</label>'
            . '<select id="' . Html::attribute($name . '__unit') . '" name="' . Html::attribute($name . '__unit') . '">' . $options . '</select></div>'
            . ($tokens === '' ? '' : '<small class="admin-meta">' . $t->html('composition.named_spaces', ['tokens' => $tokens]) . '</small>') . '</div>';
    }

    /** @param array<string, mixed> $row */
    private function cells(array $row, bool $labels = true): string
    {
        $t = $this->t;
        $cells = '';
        foreach (is_array($row['columns'] ?? null) ? $row['columns'] : [] as $column) {
            $content = is_array($column['content'] ?? null) ? $column['content'] : [];
            $kind = is_string($content['kind'] ?? null) ? $content['kind'] : '';
            $label = $t->text('composition.cell.blank');
            if ($kind === 'media') {
                $label = $this->media?->find(is_string($content['id'] ?? null) ? $content['id'] : '')->name ?? $t->text('composition.cell.image');
            } elseif ($kind !== '') {
                $label = ucfirst(str_replace('_', ' ', $kind));
                foreach ($this->type->fields as $source) {
                    if ($source->id === ($content['field'] ?? null)) {
                        $label = $t->has('composition.cell.' . $kind) ? $t->text('composition.cell.' . $kind, ['field' => $source->label]) : ucfirst($kind) . ' · ' . $source->label;
                    }
                }
            }
            $share = is_numeric($column['share'] ?? null) ? (string) $column['share'] : '1';
            $caption = is_array($content['captions'] ?? null) ? ($content['captions'][$this->profile->defaultLocale] ?? '') : '';
            $linked = isset($content['link']) ? $this->t->text('composition.linked') : '';
            if (isset($content['link'])) {
                try {
                    $reference = \Utsuri\PortableCms\Conventional\Content\RecordReference::from($content['link']);
                    $target = $this->profile->type($reference->typeId) === null ? null : $this->content?->find($reference->typeId, $reference->recordId);
                    $linked .= ' · ' . ($target === null ? $this->t->text('composition.unavailable') : \Utsuri\PortableCms\Conventional\Content\RecordLabels::label($target, $this->profile));
                } catch (\InvalidArgumentException) {
                    $linked .= ' · ' . $this->t->text('composition.unavailable');
                }
            }
            $cells .= '<span class="admin-composition-cell admin-composition-cell--' . ($kind === '' ? 'blank' : ($kind === 'media' ? 'media' : 'text')) . '" data-share="' . Html::attribute($share) . '">'
                . ($labels ? '<strong>' . Html::text($label) . '</strong><span>' . Html::text(implode(' · ', array_filter([$share, $linked, is_string($caption) ? $caption : '']))) . '</span>' : '') . '</span>';
        }
        return $cells;
    }

    /** @param array<string, mixed> $source
     * @param array<string, string> $options
     */
    private function imageDetails(string $prefix, array $source, array $options, FieldDefinition $field): string
    {
        if (($field->settings['formatVersion'] ?? 1) !== 2) {
            return '<p class="admin-help">' . $this->t->html('composition.details_not_enabled') . '</p>';
        }
        $markup = '<details><summary>' . $this->t->html('composition.image_details') . '</summary>';
        if ($field->targetTypes !== []) {
            $token = '';
            $warning = '';
            if (isset($source['link'])) {
                try {
                    $reference = \Utsuri\PortableCms\Conventional\Content\RecordReference::from($source['link']);
                    $token = $reference->token();
                    $allowed = in_array($reference->typeId, $field->targetTypes, true);
                    $target = $allowed ? $this->content?->find($reference->typeId, $reference->recordId) : null;
                    $options[$token] = $target === null ? $this->t->text('composition.unavailable') : \Utsuri\PortableCms\Conventional\Content\RecordLabels::label($target, $this->profile) . ' · ' . $this->profile->type($target->typeId)->singularLabel;
                    if ($target === null || $target->workflowState !== 'published') {
                        $warning = '<p class="admin-field__error">' . $this->t->html('composition.link_unavailable') . '</p>';
                    }
                } catch (\InvalidArgumentException) {
                    $warning = '<p class="admin-field__error">' . $this->t->html('composition.link_invalid') . '</p>';
                }
            }
            if ($warning !== '') {
                $markup = str_replace('<details>', '<details open>', $markup);
            }
            $markup .= $this->select($prefix . '__link', $this->t->text('composition.image_link'), $token, $options) . $warning;
        }
        foreach ($this->profile->localeDefinitions as $locale) {
            $caption = is_array($source['captions'] ?? null) ? ($source['captions'][$locale->id] ?? '') : '';
            $markup .= '<label lang="' . Html::attribute($locale->id) . '">' . $this->t->html('composition.image_caption', ['locale' => $locale->label])
                . '<input type="text" maxlength="500" name="' . Html::attribute($prefix . '__caption__' . $locale->id) . '" value="' . Html::attribute(is_string($caption) ? $caption : '') . '"></label>';
        }
        return $markup . '</details>';
    }

    /** @param array<string|int, string> $options */
    private function select(string $name, string $label, mixed $value, array $options): string
    {
        $value = is_scalar($value) ? (string) $value : '';
        $markup = '<label>' . Html::text($label) . '<select name="' . Html::attribute($name) . '">';
        if (!array_key_exists($value, $options)) {
            $options[$value] = $this->t->text('composition.unavailable');
        }
        foreach ($options as $id => $text) {
            $markup .= '<option value="' . Html::attribute((string) $id) . '"' . ((string) $id === (string) $value ? ' selected' : '') . '>' . Html::text($text) . '</option>';
        }
        return $markup . '</select></label>';
    }

    private static function number(string $name, string $label, mixed $value, string $min, string $max): string
    {
        return '<label>' . Html::text($label) . '<input type="number" name="' . Html::attribute($name) . '" value="' . Html::attribute(is_scalar($value) ? (string) $value : '') . '" min="' . $min . '" max="' . $max . '" step="0.01"></label>';
    }

    private static function button(string $action, string $text, bool $current = false, ?string $label = null, string $class = ''): string
    {
        return '<button type="submit" name="_composition_action" value="' . Html::attribute($action) . '" formnovalidate' . ($current ? ' aria-current="true"' : '')
            . ($label === null ? '' : ' aria-label="' . Html::attribute($label) . '"') . ($class === '' ? '' : ' class="' . Html::attribute($class) . '"') . '>' . Html::text($text) . '</button>';
    }
}
