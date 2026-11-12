<?php

declare(strict_types=1);

return [
    'workflow.delete' => 'Supprimer le contenu archivé',
    'workflow.delete_help' => 'La suppression retire ce contenu et son historique éditorial. Les aperçus et publications existants restent inchangés. Les contenus référencés ou possédant des médias, téléversements ou tâches d’IA ne peuvent pas être supprimés ; conservez-les archivés.',
    'workflow.delete_confirm' => 'Je confirme la suppression définitive de ce contenu et de son historique.',
    'workflow.delete_blocked' => 'Seuls les contenus archivés de collection sans références, médias propres, téléversements ni tâches d’IA peuvent être supprimés.',
    'workflow.save' => 'Enregistrer les modifications',
    'workflow.change' => 'Changer le statut',
    'workflow.help' => 'Les versions du site utilisent la révision publiée. Enregistrez les modifications pour les revoir, puis publiez-les lorsqu’elles sont prêtes. Publier ne modifie pas immédiatement le site en ligne.',
    'workflow.state' => 'Nouveau statut',
    'workflow.confirm' => 'Je confirme ce changement de statut du contenu enregistré.',
    'workflow.submit' => 'Confirmer le changement',
    'workflow.failed' => 'Statut inchangé',
    'workflow.conflict' => 'Le contenu a changé. Vérifiez la révision enregistrée actuelle avant de confirmer à nouveau.',
    'workflow.invalid' => 'Vérifiez le contenu enregistré avant de publier.',
    'workflow.current' => 'Statut actuel : {state}. Révision enregistrée : {revision}.',
    'workflow.back' => 'Retour au contenu',
    'workflow.required' => '{field} est obligatoire dans toutes les langues du site avant publication.',
    'workflow.relationship' => 'Publiez d’abord les contenus référencés ou supprimez la référence.',
    'workflow.media' => 'Les médias sélectionnés doivent être prêts. Les médias non pris en charge ne peuvent pas être publiés.',
    'preview.include_drafts' => 'Inclure les brouillons enregistrés et les modifications non publiées (révision uniquement)',
    'preview.mode.drafts' => 'Inclut les brouillons enregistrés. Vérification uniquement ; publication interdite.',
    'preview.include_preparation' => 'Inclure les langues en préparation : {languages} (vérification uniquement)',
    'preview.mode.preparation' => 'Inclut des langues en préparation. Vérification uniquement ; publication interdite.',
    'preview.mode.published' => 'Contenu publié uniquement ; admissible aux contrôles de publication.',
];
