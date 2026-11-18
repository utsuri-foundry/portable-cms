<?php

declare(strict_types=1);

return [
    'media.text.title' => 'Description',
    'media.text.help' => 'Dites ce que montre l’image, dans chaque langue du site, pour les personnes qui ne peuvent pas la voir et pour les moteurs de recherche. Une langue vide utilise le texte en {locale}. 500 caractères maximum chacune. Les changements s’appliquent partout où l’image est utilisée.',
    'media.text.decorative' => 'Image décorative, sans description nécessaire',
    'media.text.decorative_help' => 'Pour les images qui n’ajoutent rien au texte, comme un fond ou un séparateur. Les lecteurs d’écran les passent.',
    'media.text.alternative' => 'Description',
    'media.text.more' => 'Légende et crédit',
    'media.text.caption' => 'Légende',
    'media.text.credit' => 'Crédit',
    'media.text.invalid' => 'Utilisez du texte brut de 500 caractères maximum pour chaque description, légende et crédit.',
    'media.upload.title' => 'Importer',
    'media.upload.rule' => 'Images jusqu’à {limit} Mo.',
    'media.upload.rule_video' => 'Images et vidéos courtes jusqu’à {limit} Mo.',
    'media.upload.owner' => 'Appartient à',
    'media.upload.owner_help' => 'D’où viennent les fichiers. Toutes les pages peuvent les utiliser dans tous les cas.',
    'media.upload.website' => 'Le site web',
    'media.upload.files' => 'Fichiers',
    'media.upload.submit' => 'Importer les fichiers',
    'preflight.issue.media_text' => 'La page {route} affiche l’image {id} sans son texte alternatif rédigé ni le choix décoratif en {locale}.',
    'workflow.media_text' => '{field} nécessite un texte alternatif rédigé ou le choix décoratif en {locale}.',
];
