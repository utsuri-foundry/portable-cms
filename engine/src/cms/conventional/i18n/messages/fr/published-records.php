<?php

declare(strict_types=1);

return [
    'state.published_changes' => 'Prêt, avec des modifications',
    'workflow.publish' => 'Marquer comme prêt',
    'workflow.publish_changes' => 'Marquer les modifications comme prêtes',
    'workflow.unpublish' => 'Revenir au brouillon',
    'workflow.discard' => 'Abandonner les modifications',
    'workflow.discard_help' => 'Restaure le contenu prêt dans une nouvelle révision. Les révisions abandonnées restent dans l’historique.',
    'workflow.discard_confirm' => 'Je confirme l’abandon des modifications faites depuis que ce contenu a été marqué comme prêt.',
    'workflow.published_revision' => 'Révision prête : {revision}.',
    'workflow.pending' => 'Ces modifications sont enregistrées pour révision. Marquez-les comme prêtes pour les inclure dans la prochaine publication.',
    'workflow.changed_at' => 'Dernière modification :',
    'preview.pending_publication' => '{count, plural, one {# entrée contient des modifications pas encore marquées comme prêtes.} other {# entrées contiennent des modifications pas encore marquées comme prêtes.}}',
];
