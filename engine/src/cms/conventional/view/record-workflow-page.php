<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\View;

use Utsuri\PortableCms\Conventional\Content\ProfileManifest;
use Utsuri\PortableCms\Conventional\Content\RecordSnapshot;
use Utsuri\PortableCms\Conventional\Content\RecordTypeDefinition;
use Utsuri\PortableCms\Conventional\Http\Html;
use Utsuri\PortableCms\Conventional\I18n\Message;
use Utsuri\PortableCms\Conventional\I18n\Translator;

final class RecordWorkflowPage
{
    public static function controls(RecordSnapshot $record, Translator $t, ?string $selected = null, bool $allowDelete = false): string
    {
        $options = '';
        foreach (['draft', 'published', 'archived'] as $state) {
            $options .= '<option value="' . $state . '"' . ($state === ($selected ?? $record->workflowState) ? ' selected' : '') . '>' . Html::text(AdminShell::stateLabel($state, $t)) . '</option>';
        }
        $publish = $record->workflowState !== 'archived'
            ? '<button type="submit" form="record-publish-form" data-publish-changes' . ($record->workflowState === 'published' && !$record->hasUnpublishedChanges() ? ' hidden' : '') . '>' . $t->html($record->workflowState === 'published' ? 'workflow.publish_changes' : 'workflow.publish') . '</button>' : '';
        $discard = $record->workflowState === 'published' ? '<details class="admin-rail-card" data-keep-discard' . ($record->hasUnpublishedChanges() ? '' : ' hidden') . '><summary>' . $t->html('workflow.discard') . '</summary><p>' . $t->html('workflow.discard_help') . '</p><label><input type="checkbox" form="record-discard-form" name="_confirmed" value="1" required> ' . $t->html('workflow.discard_confirm') . '</label><button form="record-discard-form" type="submit">' . $t->html('workflow.discard') . '</button></details>' : '';
        return $publish . $discard . '<details class="admin-rail-card"' . ($selected === null ? '' : ' open') . '><summary>' . $t->html('workflow.change') . '</summary><p class="admin-meta">' . $t->html('workflow.help') . '</p>'
            . '<label>' . $t->html('workflow.state') . '<select form="record-status-form" name="_workflow_state">' . $options . '</select></label>'
            . '<label><input form="record-status-form" type="checkbox" name="_confirmed" value="1" required> ' . $t->html('workflow.confirm') . '</label>'
            . '<button form="record-status-form" type="submit">' . $t->html('workflow.submit') . '</button></details>'
            . ($allowDelete && $record->workflowState === 'archived' ? '<details class="admin-rail-card"><summary>' . $t->html('workflow.delete') . '</summary><p>' . $t->html('workflow.delete_help') . '</p><label><input form="record-delete-form" type="checkbox" name="_confirmed" value="1" required> ' . $t->html('workflow.delete_confirm') . '</label><button form="record-delete-form" type="submit">' . $t->html('workflow.delete') . '</button></details>' : '');
    }

    public static function form(RecordSnapshot $record, string $csrf): string
    {
        $intent = '<input type="hidden" name="_edit_sequence" value="' . $record->editSequence . '"><input type="hidden" name="_csrf" value="' . Html::attribute($csrf) . '"><input type="hidden" name="_revision" value="' . $record->revisionNumber . '"><input type="hidden" name="_workflow_state" value="published">';
        $path = '/records/' . $record->typeId . '/' . $record->id . '/status';
        $publish = $record->workflowState !== 'archived' ? '<form id="record-publish-form" method="post" action="' . Html::url($path) . '">' . $intent . '<input type="hidden" name="_confirmed" value="1"></form>' : '';
        $discard = $record->workflowState === 'published' ? '<form id="record-discard-form" method="post" action="' . Html::url($path) . '">' . $intent . '<input type="hidden" name="_workflow_action" value="discard"></form>' : '';
        return $publish . $discard . '<form id="record-status-form" method="post" action="' . Html::url('/records/' . $record->typeId . '/' . $record->id . '/status') . '">'
            . '<input type="hidden" name="_edit_sequence" value="' . $record->editSequence . '"><input type="hidden" name="_csrf" value="' . Html::attribute($csrf) . '"><input type="hidden" name="_revision" value="' . $record->revisionNumber . '"></form>'
            . ($record->workflowState === 'archived' ? '<form id="record-delete-form" method="post" action="' . Html::url('/records/' . $record->typeId . '/' . $record->id . '/status') . '"><input type="hidden" name="_edit_sequence" value="' . $record->editSequence . '"><input type="hidden" name="_csrf" value="' . Html::attribute($csrf) . '"><input type="hidden" name="_revision" value="' . $record->revisionNumber . '"><input type="hidden" name="_workflow_action" value="delete"><input type="hidden" name="_workflow_state" value="archived"></form>' : '');
    }

    /** @param array<string, Message> $errors */
    public static function failure(ProfileManifest $profile, RecordTypeDefinition $type, RecordSnapshot $record, string $csrf, string $selected, array $errors, bool $conflict, ?AdminChrome $chrome): string
    {
        $t = AdminShell::translator($chrome);
        $items = '';
        foreach ($errors as $message) {
            $items .= '<li>' . Html::text($t->message($message)) . '</li>';
        }
        $path = '/records/' . $type->id . '/' . $record->id;
        $body = AdminShell::header($t->text('workflow.failed'))
            . '<section class="admin-notice admin-notice--danger" role="alert"><p>' . $t->html($conflict ? 'workflow.conflict' : 'workflow.invalid') . '</p><ul>' . $items . '</ul></section>'
            . '<p>' . $t->html('workflow.current', ['state' => AdminShell::stateLabel($record->workflowState, $t), 'revision' => (string) $record->revisionNumber]) . '</p>'
            . self::controls($record, $t, $selected) . self::form($record, $csrf)
            . '<p><a class="admin-button" href="' . Html::url($path) . '">' . $t->html('workflow.back') . '</a></p>';
        return AdminShell::application($t->text('workflow.failed'), TrustedHtml::fromEngineMarkup($body), $profile, $type->id, 'standard', $chrome);
    }
}
