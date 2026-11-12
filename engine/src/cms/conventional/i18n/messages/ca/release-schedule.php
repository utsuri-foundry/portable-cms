<?php

declare(strict_types=1);

return [
    'schedule.when' => 'Publicar',
    'schedule.now' => 'Ara',
    'schedule.later' => 'En una data i hora',
    'schedule.date' => 'Data i hora ({zone})',
    'schedule.interval' => 'El procés de treball començarà la publicació en la primera execució a partir d’aquesta hora. L’inici real depèn de l’interval configurat.',
    'schedule.move' => 'Canvia la data de publicació',
    'schedule.cancel' => 'Cancel·la la publicació programada',
    'schedule.stale' => 'S’ha publicat una altra versió. Cal una nova aprovació. Revisa la previsualització i aprova-la de nou respecte de la versió actual.',
    'schedule.included' => 'Publicació programada {id}: {time}',
    'schedule.frozen' => 'Aquesta publicació conserva la previsualització aprovada. Les modificacions posteriors no la canvien. També pot retirar contingut.',
    'schedule.error.invalid' => 'Introdueix una data i hora vàlides en el fus horari indicat. Les hores omeses pel canvi d’hora no estan disponibles.',
    'schedule.error.ambiguous' => 'Aquesta hora es repeteix durant un canvi d’hora. Tria una hora sense ambigüitat.',
    'schedule.error.range' => 'Tria una hora futura com a màxim d’aquí a un any.',
    'publication.status.scheduled' => 'Programada',
    'publication.status.needs_approval' => 'Cal una nova aprovació',
    'publication.status.cancelled' => 'Cancel·lada',
];
