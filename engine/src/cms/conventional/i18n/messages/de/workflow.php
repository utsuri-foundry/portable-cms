<?php

declare(strict_types=1);

return [
    'workflow.delete' => 'Archivierten Eintrag löschen',
    'workflow.delete_help' => 'Das Löschen entfernt den Eintrag und seinen Bearbeitungsverlauf. Vorhandene Vorschauen und Veröffentlichungen bleiben unverändert. Einträge mit eingehenden Verweisen, eigenen Medien, Uploads oder KI-Aufgaben können nicht gelöscht werden; lassen Sie sie archiviert.',
    'workflow.delete_confirm' => 'Ich bestätige das endgültige Löschen dieses Eintrags und seines Bearbeitungsverlaufs.',
    'workflow.delete_blocked' => 'Nur archivierte Sammlungseinträge ohne Verweise, eigene Medien, Uploads oder KI-Aufgaben können gelöscht werden.',
    'workflow.save' => 'Änderungen speichern',
    'workflow.change' => 'Status ändern',
    'workflow.help' => 'Veröffentlichungen verwenden die bereite Version jedes Eintrags. Speichern Sie Änderungen zur Prüfung und markieren Sie sie als bereit. Nichts erreicht die öffentliche Website, bis eine Veröffentlichung freigegeben wird.',
    'workflow.state' => 'Neuer Status',
    'workflow.confirm' => 'Ich bestätige diese Statusänderung des gespeicherten Eintrags.',
    'workflow.submit' => 'Statusänderung bestätigen',
    'workflow.failed' => 'Status nicht geändert',
    'workflow.conflict' => 'Der Eintrag wurde geändert. Prüfen Sie die aktuelle gespeicherte Revision vor der erneuten Bestätigung.',
    'workflow.invalid' => 'Prüfen Sie den gespeicherten Inhalt, bevor Sie ihn als bereit markieren.',
    'workflow.current' => 'Aktueller Status: {state}. Gespeicherte Revision: {revision}.',
    'workflow.back' => 'Zurück zum Eintrag',
    'workflow.required' => '{field} ist in jeder Website-Sprache erforderlich, damit der Eintrag bereit ist.',
    'workflow.relationship' => 'Markieren Sie zuerst referenzierte Einträge als bereit oder entfernen Sie den Verweis.',
    'workflow.media' => 'Ausgewählte Medien müssen fertig verarbeitet sein. Nicht unterstützte Medien können nicht aufgenommen werden.',
    'preview.include_drafts' => 'Gespeicherte Entwürfe und noch nicht als bereit markierte Änderungen einschließen (nur zur Prüfung)',
    'preview.mode.drafts' => 'Enthält gespeicherte Entwürfe. Nur Prüfung; keine Veröffentlichung möglich.',
    'preview.include_preparation' => 'Sprachen in Vorbereitung einbeziehen: {languages} (nur Prüfung)',
    'preview.mode.preparation' => 'Enthält Sprachen in Vorbereitung. Nur Prüfung; keine Veröffentlichung möglich.',
    'preview.mode.published' => 'Nur bereite Inhalte; das ist, was eine Veröffentlichung online stellt.',
];
