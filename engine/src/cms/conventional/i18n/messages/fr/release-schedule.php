<?php

declare(strict_types=1);

return [
    'schedule.when' => 'Mettre en ligne',
    'schedule.now' => 'Maintenant',
    'schedule.later' => 'À une date et une heure',
    'schedule.date' => 'Date et heure ({zone})',
    'schedule.interval' => 'Le processus de travail commencera la publication lors de sa première exécution à partir de cette heure. Le début réel dépend de son intervalle configuré.',
    'schedule.move' => 'Déplacer la publication programmée',
    'schedule.cancel' => 'Annuler la publication programmée',
    'schedule.stale' => 'Une autre version a été publiée. Une nouvelle approbation est nécessaire. Vérifiez l’aperçu et approuvez-le à nouveau par rapport à la version actuelle.',
    'schedule.included' => 'Publication programmée {id} : {time}',
    'schedule.frozen' => 'Cette publication conserve son aperçu approuvé. Les modifications ultérieures ne le changent pas. Elle peut aussi retirer du contenu.',
    'schedule.error.invalid' => 'Saisissez une date et une heure valides dans le fuseau indiqué. Les heures sautées lors du changement d’heure sont indisponibles.',
    'schedule.error.ambiguous' => 'Cette heure se répète lors du changement d’heure. Choisissez une heure sans ambiguïté.',
    'schedule.error.range' => 'Choisissez une heure future dans un délai maximal d’un an.',
    'publication.status.scheduled' => 'Programmée',
    'publication.status.needs_approval' => 'Nouvelle approbation nécessaire',
    'publication.status.cancelled' => 'Annulée',
];
