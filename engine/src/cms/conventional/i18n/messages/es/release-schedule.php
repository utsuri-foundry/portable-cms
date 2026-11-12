<?php

declare(strict_types=1);

return [
    'schedule.when' => 'Publicar',
    'schedule.now' => 'Ahora',
    'schedule.later' => 'En una fecha y hora',
    'schedule.date' => 'Fecha y hora ({zone})',
    'schedule.interval' => 'El proceso de trabajo iniciará la publicación en su primera ejecución a partir de esa hora. El inicio real depende del intervalo configurado.',
    'schedule.move' => 'Cambiar la fecha de publicación',
    'schedule.cancel' => 'Cancelar la publicación programada',
    'schedule.stale' => 'Se ha publicado otra versión. Hace falta una nueva aprobación. Revisa la vista previa y apruébala de nuevo respecto a la versión actual.',
    'schedule.included' => 'Publicación programada {id}: {time}',
    'schedule.frozen' => 'Esta publicación conserva la vista previa aprobada. Los cambios posteriores no la modifican. También puede retirar contenido.',
    'schedule.error.invalid' => 'Introduce una fecha y hora válidas en la zona horaria indicada. Las horas omitidas por el cambio de hora no están disponibles.',
    'schedule.error.ambiguous' => 'Esta hora se repite durante un cambio de hora. Elige una hora sin ambigüedad.',
    'schedule.error.range' => 'Elige una hora futura como máximo dentro de un año.',
    'publication.status.scheduled' => 'Programada',
    'publication.status.needs_approval' => 'Necesita una nueva aprobación',
    'publication.status.cancelled' => 'Cancelada',
];
