<?php

declare(strict_types=1);

return [
    'workflow.delete' => 'Supprimer le contenu archivé',
    'workflow.delete_help' => 'La suppression retire ce contenu et son historique éditorial. Les aperçus et publications existants restent inchangés. Les contenus référencés ou possédant des médias, téléversements ou tâches d’IA ne peuvent pas être supprimés ; conservez-les archivés.',
    'workflow.delete_confirm' => 'Je confirme la suppression définitive de ce contenu et de son historique.',
    'workflow.delete_blocked' => 'Seuls les contenus archivés de collection sans références, médias propres, téléversements ni tâches d’IA peuvent être supprimés.',
    'workflow.save' => 'Enregistrer les modifications',
    'workflow.change' => 'Changer le statut',
    'workflow.help' => 'Les publications utilisent la version prête de chaque contenu. Enregistrez les modifications pour les revoir, puis marquez-les comme prêtes. Rien n’atteint le site public avant l’approbation d’une publication.',
    'workflow.state' => 'Nouveau statut',
    'workflow.confirm' => 'Je confirme ce changement de statut du contenu enregistré.',
    'workflow.submit' => 'Confirmer le changement',
    'workflow.failed' => 'Statut inchangé',
    'workflow.conflict' => 'Le contenu a changé. Vérifiez la révision enregistrée actuelle avant de confirmer à nouveau.',
    'workflow.invalid' => 'Vérifiez le contenu enregistré avant de le marquer comme prêt.',
    'workflow.current' => 'Statut actuel : {state}. Révision enregistrée : {revision}.',
    'workflow.back' => 'Retour au contenu',
    'workflow.required' => '{field} est obligatoire dans toutes les langues du site pour que le contenu soit prêt.',
    'workflow.relationship' => 'Marquez d’abord les contenus référencés comme prêts ou supprimez la référence.',
    'workflow.media' => 'Les médias sélectionnés doivent avoir fini d’être traités. Les médias non pris en charge ne peuvent pas être inclus.',
    'preview.mode.drafts' => 'Inclut les brouillons enregistrés. Vérification uniquement ; publication interdite.',
    'preview.include_preparation' => 'Inclure les langues en préparation : {languages} (vérification uniquement)',
    'preview.mode.preparation' => 'Inclut des langues en préparation. Vérification uniquement ; publication interdite.',
    'preview.mode.published' => 'Contenu prêt uniquement ; c’est ce qu’une publication met en ligne.',
];
