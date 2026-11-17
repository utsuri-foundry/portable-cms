<?php

declare(strict_types=1);

return [
    'schedule.when' => 'Wann',
    'schedule.now' => 'Jetzt',
    'schedule.later' => 'Zu einem Datum und einer Uhrzeit',
    'schedule.date' => 'Datum und Uhrzeit ({zone})',
    'schedule.for' => 'Geplant für {time}',
    'schedule.help' => 'Veröffentlichen dauert einige Minuten. Eine geplante Veröffentlichung beginnt wenige Minuten nach ihrer Uhrzeit.',
    'schedule.move' => 'Verschieben',
    'schedule.cancel' => 'Abbrechen',
    'schedule.stale' => 'Seit der Freigabe wurde eine andere Version veröffentlicht. Geben Sie sie erneut frei, um sie zu veröffentlichen.',
    'schedule.included' => 'Geplante Veröffentlichung: {time}',
    'schedule.frozen' => 'Diese Veröffentlichung behält die freigegebene Version. Spätere Änderungen verändern sie nicht.',
    'schedule.error.invalid' => 'Geben Sie ein gültiges Datum und eine Uhrzeit in der angegebenen Zeitzone ein. Bei der Zeitumstellung übersprungene Uhrzeiten sind nicht verfügbar.',
    'schedule.error.ambiguous' => 'Diese Uhrzeit kommt bei der Zeitumstellung zweimal vor. Wählen Sie eine eindeutige Uhrzeit.',
    'schedule.error.range' => 'Wählen Sie eine zukünftige Uhrzeit innerhalb eines Jahres.',
    'publication.status.scheduled' => 'Geplant',
    'publication.status.needs_approval' => 'Neue Freigabe erforderlich',
    'publication.status.cancelled' => 'Abgebrochen',
];
