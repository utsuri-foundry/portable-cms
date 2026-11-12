<?php

declare(strict_types=1);

return [
    'history.title' => 'Historique des révisions',
    'history.help' => 'Conservez un historique limité par fiche. Les modifications actuelles et la version publiée sont toujours conservées, en plus de cette limite.',
    'history.limit' => 'Révisions historiques par fiche',
    'history.policy' => 'Par défaut : 50. Choisissez entre 10 et 500. Les révisions les plus anciennes sont supprimées après un enregistrement ou une réduction confirmée. Les aperçus figés et les publications gardent leurs propres copies.',
    'history.save' => 'Examiner la limite',
    'history.saved' => 'Limite de révisions enregistrée.',
    'history.confirm' => 'Conserver {limit} révisions historiques par fiche supprimerait {count} révisions sur tout le site. Les versions actuelles et publiées sont exclues.',
    'history.backup' => 'Un export ultérieur ne permet pas de récupérer l’historique supprimé. Conservez une sauvegarde vérifiée de l’installation avant de continuer.',
    'history.accept' => 'Je confirme cette limite et la suppression des anciennes révisions.',
    'history.apply' => 'Appliquer la limite',
    'history.cancel' => 'Annuler',
    'history.pending' => 'Le nettoyage est en attente. Chaque requête traite une fiche et supprime au maximum 100 révisions. Vous pouvez quitter cette page et reprendre plus tard ; les lots terminés sont conservés.',
    'history.continue' => 'Continuer le nettoyage',
    'history.capacity' => '{count, number, integer} révisions conservées sur une capacité d’export mesurée de {capacity, number, integer}. Ce total inclut les versions actuelles et publiées.',
    'history.warning' => 'L’historique approche ou dépasse la capacité d’export mesurée. Examinez la limite et préparez un export complet vérifié. Les autres limites de quantité et de taille s’appliquent aussi.',
    'history.invalid' => 'Choisissez une limite entière entre 10 et 500.',
    'history.changed' => 'Les paramètres ou le nombre de suppressions ont changé. Examinez la limite et confirmez à nouveau le décompte actuel.',
    'history.forbidden' => 'Seul un administrateur peut modifier l’historique des révisions.',
    'history.preview_missing' => '{count, number, integer} révisions sources ne sont plus conservées. Cet aperçu figé et les publications associées gardent leur contenu exact.',
];
