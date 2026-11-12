<?php

declare(strict_types=1);

return [
    'history.title' => 'Versionsverlauf',
    'history.help' => 'Für jeden Datensatz wird ein begrenzter Verlauf aufbewahrt. Aktuelle Änderungen und die veröffentlichte Version bleiben zusätzlich zu dieser Grenze immer erhalten.',
    'history.limit' => 'Historische Versionen je Datensatz',
    'history.policy' => 'Standard: 50. Wählen Sie 10 bis 500. Die ältesten historischen Versionen werden nach dem Speichern oder einer bestätigten Senkung entfernt. Eingefrorene Vorschauen und Veröffentlichungen behalten eigene Kopien.',
    'history.save' => 'Grenze prüfen',
    'history.saved' => 'Versionsgrenze gespeichert.',
    'history.confirm' => 'Bei {limit} historischen Versionen je Datensatz würden insgesamt {count} Versionen dieser Website entfernt. Aktuelle und veröffentlichte Versionen sind ausgenommen.',
    'history.backup' => 'Entfernter Verlauf lässt sich aus einem späteren Inhaltsexport nicht wiederherstellen. Bewahren Sie vor dem Fortfahren eine geprüfte Installationssicherung auf.',
    'history.accept' => 'Ich bestätige diese Grenze und das Entfernen älterer Versionen.',
    'history.apply' => 'Grenze anwenden',
    'history.cancel' => 'Abbrechen',
    'history.pending' => 'Die Bereinigung steht aus. Jede Anfrage bearbeitet einen Datensatz und entfernt höchstens 100 Versionen. Sie können die Seite verlassen und später fortfahren; abgeschlossene Schritte bleiben erhalten.',
    'history.continue' => 'Bereinigung fortsetzen',
    'history.capacity' => '{count, number, integer} aufbewahrte Versionen bei einer gemessenen Exportkapazität von {capacity, number, integer}. Aktuelle und veröffentlichte Versionen zählen mit.',
    'history.warning' => 'Der Verlauf nähert sich der gemessenen Exportkapazität oder überschreitet sie. Prüfen Sie die Grenze und erstellen Sie einen geprüften vollständigen Export. Weitere Mengen- und Bytegrenzen gelten ebenfalls.',
    'history.invalid' => 'Wählen Sie eine ganze Zahl zwischen 10 und 500.',
    'history.changed' => 'Die Einstellungen oder die Anzahl der zu entfernenden Versionen haben sich geändert. Prüfen und bestätigen Sie die aktuellen Angaben erneut.',
    'history.forbidden' => 'Nur Administratoren dürfen den Versionsverlauf ändern.',
    'history.preview_missing' => '{count, number, integer} Quellversionen werden nicht mehr aufbewahrt. Diese eingefrorene Vorschau und ihre Veröffentlichungen behalten den genauen Inhalt.',
];
