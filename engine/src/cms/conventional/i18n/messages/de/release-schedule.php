<?php

declare(strict_types=1);

return [
    'schedule.when' => 'Veröffentlichen',
    'schedule.now' => 'Jetzt',
    'schedule.later' => 'Zu einem Datum und einer Uhrzeit',
    'schedule.date' => 'Datum und Uhrzeit ({zone})',
    'schedule.interval' => 'Der Hintergrundprozess beginnt die Veröffentlichung beim ersten Lauf ab dieser Uhrzeit. Der tatsächliche Beginn hängt vom eingestellten Intervall ab.',
    'schedule.move' => 'Geplante Veröffentlichung verschieben',
    'schedule.cancel' => 'Geplante Veröffentlichung abbrechen',
    'schedule.stale' => 'Eine andere Version wurde veröffentlicht. Eine neue Freigabe ist nötig. Prüfen Sie die Vorschau und geben Sie sie erneut gegenüber der aktuellen Version frei.',
    'schedule.included' => 'Geplante Veröffentlichung {id}: {time}',
    'schedule.frozen' => 'Diese Veröffentlichung behält ihre freigegebene Vorschau. Spätere Änderungen verändern sie nicht. Sie kann auch Inhalte entfernen.',
    'schedule.error.invalid' => 'Geben Sie ein gültiges Datum und eine Uhrzeit in der angegebenen Zeitzone ein. Bei der Zeitumstellung übersprungene Uhrzeiten sind nicht verfügbar.',
    'schedule.error.ambiguous' => 'Diese Uhrzeit kommt bei der Zeitumstellung zweimal vor. Wählen Sie eine eindeutige Uhrzeit.',
    'schedule.error.range' => 'Wählen Sie eine zukünftige Uhrzeit innerhalb eines Jahres.',
    'publication.status.scheduled' => 'Geplant',
    'publication.status.needs_approval' => 'Neue Freigabe erforderlich',
    'publication.status.cancelled' => 'Abgebrochen',
];
