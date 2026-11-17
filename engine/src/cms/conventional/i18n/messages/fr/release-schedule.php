<?php

declare(strict_types=1);

return [
    'schedule.when' => 'Quand',
    'schedule.now' => 'Maintenant',
    'schedule.later' => 'À une date et une heure',
    'schedule.date' => 'Date et heure ({zone})',
    'schedule.for' => 'Programmée pour {time}',
    'schedule.help' => 'Publier prend quelques minutes. Une publication programmée démarre dans les minutes qui suivent l’heure choisie.',
    'schedule.move' => 'Déplacer',
    'schedule.cancel' => 'Annuler',
    'schedule.stale' => 'Une autre version a été publiée depuis son approbation. Approuvez-la à nouveau pour la publier.',
    'schedule.included' => 'Publication programmée : {time}',
    'schedule.frozen' => 'Cette publication conserve la version approuvée. Les modifications ultérieures ne la changent pas.',
    'schedule.error.invalid' => 'Saisissez une date et une heure valides dans le fuseau indiqué. Les heures sautées lors du changement d’heure sont indisponibles.',
    'schedule.error.ambiguous' => 'Cette heure se répète lors du changement d’heure. Choisissez une heure sans ambiguïté.',
    'schedule.error.range' => 'Choisissez une heure future dans un délai maximal d’un an.',
    'publication.status.scheduled' => 'Programmée',
    'publication.status.needs_approval' => 'Nouvelle approbation nécessaire',
    'publication.status.cancelled' => 'Annulée',
];
