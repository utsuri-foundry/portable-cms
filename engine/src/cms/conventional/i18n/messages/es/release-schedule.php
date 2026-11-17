<?php

declare(strict_types=1);

return [
    'schedule.when' => 'Cuándo',
    'schedule.now' => 'Ahora',
    'schedule.later' => 'En una fecha y hora',
    'schedule.date' => 'Fecha y hora ({zone})',
    'schedule.for' => 'Programada para {time}',
    'schedule.help' => 'Publicar tarda unos minutos. Una publicación programada empieza pocos minutos después de la hora elegida.',
    'schedule.move' => 'Cambiar la fecha',
    'schedule.cancel' => 'Cancelar',
    'schedule.stale' => 'Se publicó otra versión desde que se aprobó. Apruébala de nuevo para publicarla.',
    'schedule.included' => 'Publicación programada: {time}',
    'schedule.frozen' => 'Esta publicación conserva la versión aprobada. Las ediciones posteriores no la cambian.',
    'schedule.error.invalid' => 'Introduce una fecha y hora válidas en la zona horaria indicada. Las horas omitidas por el cambio de hora no están disponibles.',
    'schedule.error.ambiguous' => 'Esta hora se repite durante un cambio de hora. Elige una hora sin ambigüedad.',
    'schedule.error.range' => 'Elige una hora futura como máximo dentro de un año.',
    'publication.status.scheduled' => 'Programada',
    'publication.status.needs_approval' => 'Necesita una nueva aprobación',
    'publication.status.cancelled' => 'Cancelada',
];
