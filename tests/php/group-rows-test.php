<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Content\AdministrationLabels;
use Utsuri\PortableCms\Conventional\Content\EditorialControls;
use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Http\Request;
use Utsuri\PortableCms\Conventional\I18n\Translator;
use Utsuri\PortableCms\Conventional\View\GroupControls;

/**
 * The reference profile with extra project fields, loaded through the schema and manifest checks.
 *
 * @param list<array<string, mixed>> $fields
 */
function groupRowsProfile(array $fields): ProfileManifest
{
    // Objects stay objects, so empty settings keep their JSON shape.
    $document = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/profiles/reference-studio/profile.json'), false, 64, JSON_THROW_ON_ERROR);
    foreach ($document->recordTypes as $type) {
        if ($type->id === 'project') {
            $type->fields = [...$type->fields, ...json_decode(json_encode($fields, JSON_THROW_ON_ERROR), false, 64, JSON_THROW_ON_ERROR)];
        }
    }
    $path = tempnam(sys_get_temp_dir(), 'group-rows-profile-');
    try {
        file_put_contents($path, json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return ProfileManifest::load($path);
    } finally {
        unlink($path);
    }
}

/**
 * @param list<array<string, mixed>> $children
 * @param array<string, mixed> $settings
 * @return array<string, mixed>
 */
function groupRowsField(string $id, array $children, array $settings = []): array
{
    return ['id' => $id, 'label' => 'Crew', 'type' => 'repeatable_group', 'localized' => false, 'requiredOnDraft' => false, 'requiredOnPublish' => false, 'settings' => ['maximumItems' => 3, 'fields' => $children, ...$settings]];
}

/** @return array<string, mixed> */
function groupRowsChild(string $id, string $type, bool $localized = false, bool $required = false): array
{
    $settings = match ($type) {
        'short_text', 'multiline_text', 'web_address' => ['maximumLength' => 200],
        'integer' => ['minimum' => 0, 'maximum' => 9999],
        default => new \stdClass(),
    };
    return ['id' => $id, 'label' => ucfirst($id), 'type' => $type, 'localized' => $localized, 'requiredOnDraft' => $required, 'requiredOnPublish' => $required, 'settings' => $settings];
}

$tests['short repeatable groups choose rows by default and profiles override within bounds'] = static function (): void {
    $profile = groupRowsProfile([
        groupRowsField('crew', [groupRowsChild('role', 'short_text', true), groupRowsChild('names', 'short_text')], ['itemLabel' => 'crew credit']),
        groupRowsField('notes', [groupRowsChild('note', 'multiline_text')]),
        groupRowsField('cardsCrew', [groupRowsChild('role', 'short_text'), groupRowsChild('year', 'integer')], ['presentation' => 'cards']),
        groupRowsField('wide', [groupRowsChild('a', 'short_text'), groupRowsChild('b', 'short_text'), groupRowsChild('c', 'boolean'), groupRowsChild('d', 'integer')], ['presentation' => 'rows']),
        groupRowsField('four', [groupRowsChild('a', 'short_text'), groupRowsChild('b', 'short_text'), groupRowsChild('c', 'short_text'), groupRowsChild('d', 'short_text')]),
    ]);
    $type = $profile->type('project');
    $fields = array_column($type->fields, null, 'id');
    assertSameValue(
        [true, false, false, true, false, true],
        [$fields['crew']->rows(), $fields['notes']->rows(), $fields['cardsCrew']->rows(), $fields['wide']->rows(), $fields['four']->rows(), $fields['credits']->rows()],
        'rows apply to at most three short inline fields unless the profile chooses',
    );
    assertSameValue(['crew credit', null], [$fields['crew']->itemLabel(), $fields['notes']->itemLabel()], 'the item label is optional');
    foreach ([
        'unknown presentation' => groupRowsField('bad', [groupRowsChild('a', 'short_text')], ['presentation' => 'table']),
        'rows of long text' => groupRowsField('bad', [groupRowsChild('a', 'multiline_text')], ['presentation' => 'rows']),
        'rows of five fields' => groupRowsField('bad', array_map(static fn(string $id): array => groupRowsChild($id, 'short_text'), ['a', 'b', 'c', 'd', 'e']), ['presentation' => 'rows']),
        'empty item label' => groupRowsField('bad', [groupRowsChild('a', 'short_text')], ['itemLabel' => '']),
        'item label control character' => groupRowsField('bad', [groupRowsChild('a', 'short_text')], ['itemLabel' => "crew\ncredit"]),
    ] as $case => $field) {
        try {
            groupRowsProfile([$field]);
            assertTrueValue(false, $case . ' must be rejected');
        } catch (\InvalidArgumentException) {
        }
    }
};

$tests['group rows align named columns with one native menu per row and read back as one line'] = static function (): void {
    $profile = groupRowsProfile([groupRowsField('crew', [groupRowsChild('role', 'short_text', true), groupRowsChild('names', 'short_text')], ['itemLabel' => 'crew credit'])]);
    $type = $profile->type('project');
    $field = array_column($type->fields, null, 'id')['crew'];
    $first = str_repeat('a', 32);
    $second = str_repeat('b', 32);
    $value = [
        ['_itemId' => $first, 'role' => ['ca' => 'Direcció', 'es' => 'Dirección', 'en' => 'Direction'], 'names' => 'Ana <b>Barragán</b>'],
        ['_itemId' => $second, 'role' => ['ca' => 'So', 'es' => 'Sonido', 'en' => 'Sound'], 'names' => 'Pau Riera'],
    ];
    $html = GroupControls::field($field, json_encode($value, JSON_THROW_ON_ERROR), $profile, static fn(): string => 'unused', null, '', Translator::english());
    assertSameValue(1, substr_count($html, 'admin-group-rows__head'), 'column names appear once');
    assertSameValue(2, substr_count($html, 'data-group-menu'), 'one actions menu per row');
    assertTrueValue(str_contains($html, '<span>Role <span class="admin-locale-tag" data-locale-tag hidden></span></span><span>Names</span>'), 'a translated column carries the language tag');
    assertTrueValue(str_contains($html, 'Ana &lt;b&gt;Barragán&lt;/b&gt;</textarea>') && !str_contains($html, '<b>Barragán'), 'values are escaped text');
    $role = EditorialControls::childName('crew_i' . $first, 'role');
    assertTrueValue(str_contains($html, '<div class="admin-group-cell__locale" data-locale-panel="es" lang="es"><label class="admin-group-cell__label" for="field-' . $role . '-es">'), 'a translated child keeps one control per language');
    assertSameValue(2, substr_count($html, '<div class="admin-group-cell" data-locale-group="Crew: Role" '), 'each row groups the languages of its translated cell for coverage');
    assertSameValue(2, substr_count($html, '<div class="admin-group-cell" data-field="crew.'), 'untranslated cells are not language groups');
    assertTrueValue(str_contains($html, 'data-field="crew.' . $second . '.names" data-field-label="Crew · Item 2 · Names"'), 'a child names its group, current position and field, but addresses the stable identity');
    assertSameValue(4, substr_count($html, 'data-field-item data-field-match="text"'), 'short text row children can be edited in place');
    assertTrueValue(str_contains($html, '<span class="admin-group-cell__name">Role</span> <span class="admin-group-cell__language">Español</span><span class="admin-visually-hidden" data-group-text=", row {position}">, row 1</span>'), 'every input names its column, language and row');
    assertTrueValue(str_contains($html, 'value="up:crew::' . $first . '" formnovalidate aria-label="Move up, row 1 of Crew" data-group-label="Move up, row {position} of Crew" disabled'), 'the first row cannot move up but keeps the action');
    assertTrueValue(str_contains($html, 'value="down:crew::' . $second . '" formnovalidate aria-label="Move down, row 2 of Crew" data-group-label="Move down, row {position} of Crew" disabled'), 'the last row cannot move down');
    assertTrueValue(str_contains($html, 'value="insert:crew::' . $first . '"') && !str_contains($html, 'value="insert:crew::' . $first . '" formnovalidate aria-label="Insert below, row 1 of Crew" data-group-label="Insert below, row {position} of Crew" disabled'), 'rows can be inserted below the maximum');
    assertTrueValue(str_contains($html, '>+ Add crew credit</button><span class="admin-group-count">2 items</span>'), 'the add button names one item beside the count');
    assertTrueValue(str_contains($html, 'data-group-grip aria-hidden="true" hidden'), 'grips wait for the reordering island');
    $full = GroupControls::field($field, json_encode([...$value, ['_itemId' => str_repeat('c', 32), 'names' => 'Iris']], JSON_THROW_ON_ERROR), $profile, static fn(): string => 'unused', null, '', Translator::english());
    assertTrueValue(!str_contains($full, 'add:crew:') && str_contains($full, '3 items') && str_contains($full, 'aria-label="Insert below, row 1 of Crew" data-group-label="Insert below, row {position} of Crew" disabled'), 'a full list offers no add or insert');

    $names = EditorialControls::childName('crew_i' . $first, 'names');
    $form = ['_editorial_end' => '1', '_present_crew' => '1', 'crew_items' => [$second, $first], $names => "Ana Barragán,\r\n  Joan Puig", $role . '__ca' => "Direcció\n", $role . '__es' => 'Dirección', $role . '__en' => 'Direction'];
    $input = EditorialControls::groupInput(new Request('POST', '/', form: $form), $field, $profile);
    assertSameValue([$second, $first], array_column($input, '_itemId'), 'the posted row order is the saved order');
    assertSameValue(['Ana Barragán, Joan Puig', ['ca' => 'Direcció ', 'es' => 'Dirección', 'en' => 'Direction']], [$input[1]['names'], $input[1]['role']], 'wrapped short texts stay on one line');

    $inserted = EditorialControls::apply(new Request('POST', '/', form: ['_editor_action' => 'insert:crew::' . $first]), $type, $profile, ['crew' => json_encode($value, JSON_THROW_ON_ERROR)], new IdentityTestRandom());
    $ids = array_column(EditorialControls::structured($inserted['crew']), '_itemId');
    assertSameValue(3, count($ids), 'insert adds one row');
    assertSameValue([$first, $second], [$ids[0], $ids[2]], 'the new row sits below the chosen one');
    $capped = EditorialControls::apply(new Request('POST', '/', form: ['_editor_action' => 'insert:crew::' . $first]), $type, $profile, $inserted, new IdentityTestRandom());
    assertSameValue($ids, array_column(EditorialControls::structured($capped['crew']), '_itemId'), 'insert respects the maximum');
};

$tests['card groups fold to their first values and stay open when they need attention'] = static function (): void {
    $profile = groupRowsProfile([groupRowsField('notes', [groupRowsChild('heading', 'short_text', false, true), groupRowsChild('note', 'multiline_text')], ['maximumItems' => 8])]);
    $field = array_column($profile->type('project')->fields, null, 'id')['notes'];
    $render = static fn(mixed $value, ?string $error = null): string => GroupControls::field($field, json_encode($value, JSON_THROW_ON_ERROR), $profile, static fn(): string => '<div class="admin-field">child</div>', $error, '', Translator::english());
    $html = $render([
        ['_itemId' => str_repeat('a', 32), 'heading' => 'Opening', 'note' => "First\n  line of a note"],
        ['_itemId' => str_repeat('b', 32), 'heading' => '', 'note' => 'Missing its heading'],
        ['_itemId' => str_repeat('c', 32)],
    ]);
    assertTrueValue(str_contains($html, '<details class="admin-group-card__details"><summary>Opening · First line of a note</summary>'), 'a complete item folds to its first values');
    assertTrueValue(str_contains($html, 'data-field="notes.' . str_repeat('a', 32) . '.heading" data-field-label="Crew · Item 1 · Heading"'), 'a card child has the same identity link as a row child');
    assertTrueValue(str_contains($html, '<details class="admin-group-card__details" open><summary>Missing its heading</summary>'), 'an item missing a required value stays open');
    assertTrueValue(str_contains($html, '<details class="admin-group-card__details" open><summary>Item 3</summary>'), 'an empty item stays open under its position');
    assertTrueValue(str_contains($html, '>+ Add to Crew</button><span class="admin-group-count">3 items</span>'), 'without an item label the button names the group');
    assertSameValue(3, substr_count($render([['_itemId' => str_repeat('a', 32), 'heading' => 'A'], ['_itemId' => str_repeat('b', 32), 'heading' => 'B'], ['_itemId' => str_repeat('c', 32), 'heading' => 'C']], 'Crew needs attention.'), ' open>'), 'a group error opens every item');
};

$tests['administration labels translate group item labels and reject unknown items'] = static function (): void {
    $profile = groupRowsProfile([groupRowsField('crew', [groupRowsChild('role', 'short_text')], ['itemLabel' => 'crew credit'])]);
    $labels = AdministrationLabels::fromDocument(['formatVersion' => 1, 'profileId' => 'reference-studio', 'languages' => ['ca' => ['items' => ['project.crew' => 'crèdit']]]], $profile);
    $field = array_column($profile->type('project')->fields, null, 'id')['crew'];
    assertSameValue(['crèdit', 'crew credit'], [$labels->field($field, 'ca', 'project.crew')->itemLabel(), $labels->field($field, 'es', 'project.crew')->itemLabel()], 'item labels follow the interface language');
    foreach (['project.crew.role', 'project.title', 'project.missing'] as $path) {
        try {
            AdministrationLabels::fromDocument(['formatVersion' => 1, 'profileId' => 'reference-studio', 'languages' => ['ca' => ['items' => [$path => 'element']]]], $profile);
            assertTrueValue(false, $path . ' has no item label to translate');
        } catch (\InvalidArgumentException) {
        }
    }
};
