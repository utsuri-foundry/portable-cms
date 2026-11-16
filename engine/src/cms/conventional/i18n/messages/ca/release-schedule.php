<?php

declare(strict_types=1);

return [
    'schedule.when' => 'Quan',
    'schedule.now' => 'Ara',
    'schedule.later' => 'En una data i hora',
    'schedule.date' => 'Data i hora ({zone})',
    'schedule.for' => 'Programada per a {time}',
    'schedule.help' => 'Publicar triga uns minuts. Una publicació programada comença pocs minuts després de l’hora triada.',
    'schedule.move' => 'Canvia la data',
    'schedule.cancel' => 'Cancel·la',
    'schedule.stale' => 'S’ha publicat una altra versió des que es va aprovar. Aprova-la de nou per publicar-la.',
    'schedule.included' => 'Publicació programada: {time}',
    'schedule.frozen' => 'Aquesta publicació conserva la versió aprovada. Les edicions posteriors no la canvien.',
    'schedule.error.invalid' => 'Introdueix una data i hora vàlides en el fus horari indicat. Les hores omeses pel canvi d’hora no estan disponibles.',
    'schedule.error.ambiguous' => 'Aquesta hora es repeteix durant un canvi d’hora. Tria una hora sense ambigüitat.',
    'schedule.error.range' => 'Tria una hora futura com a màxim d’aquí a un any.',
    'publication.status.scheduled' => 'Programada',
    'publication.status.needs_approval' => 'Cal una nova aprovació',
    'publication.status.cancelled' => 'Cancel·lada',
];
