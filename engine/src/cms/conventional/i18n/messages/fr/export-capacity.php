<?php

declare(strict_types=1);

return [
    'export.capacity.details' => 'Limites d’export et de restauration',
    'export.capacity.legacy' => 'Téléchargement dans le navigateur (v1) : jusqu’à {records, number, integer} fiches, {revisions, number, integer} versions conservées, {relationships, number, integer} relations actuelles et {media, number, integer} fiches média, dans {mib} MiB de JSON.',
    'export.capacity.chunked' => 'Paquet privé par fragments (v2), préparé par l’administrateur de l’installation : jusqu’à {records, number, integer} fiches, {revisions, number, integer} versions conservées, {relationships, number, integer} relations actuelles et {media, number, integer} fiches média, dans {mib} MiB de fragments de contenu.',
    'export.capacity.scope' => 'Ces totaux couvrent toute l’installation, dont les brouillons, archives, termes et réglages. Chaque version enregistrée contient son corps complet. Les deux voies acceptent jusqu’à {derivatives, number, integer} dérivés. Toutes les limites de quantité et de taille s’appliquent ensemble ; un export trop volumineux est refusé, jamais tronqué ni purgé de son historique.',
    'export.capacity.restore' => 'La restauration vérifie séparément les fichiers, tailles, durées et capacités de l’hébergement. V1 exige au moins 64 MiB de mémoire PHP libre ou seize fois la taille du JSON, la valeur la plus élevée. Le niveau v2 mesuré nécessite un processus PHP CLI qualifié de 256 MiB ; le nombre de fiches ne qualifie pas l’hébergement à lui seul. Une préparation incomplète doit être reprise, ce n’est pas une restauration terminée.',
    'export.capacity.bytes' => 'Le JSON exclut les originaux privés, dérivés et polices téléversées : un opérateur doit aussi empaqueter ces fichiers. Un paquet de contenu exclut comptes, secrets, sessions, ancien historique d’audit, reprise des tâches et activation des publications. Conservez une sauvegarde vérifiée indépendante de l’installation.',
    'export.capacity.exceeded' => 'Ce site dépasse au moins une limite prise en charge de quantité ou de taille. Rien n’a été supprimé. Demandez à l’administrateur de vérifier la capacité et d’utiliser un processus d’export qualifié avant de poursuivre la croissance du site.',
    'preflight.issue.capacity' => 'Ce site dépasse une limite de taille ou de nombre de fiches de l’aperçu. Aucun contenu n’a été supprimé. Demandez à l’administrateur de vérifier la capacité des aperçus et de l’export complet avant la génération.',
    'export.capacity.counts' => 'Ce site contient {records} fiches et {revisions} versions enregistrées, y compris les brouillons et le contenu archivé.',
    'export.capacity.warning' => 'Ce site approche une limite prise en charge de contenu, d’historique ou de taille des pages actuelles. Préparez un export complet vérifié et examinez la capacité avant d’ajouter du contenu. L’historique suit la limite de conservation configurée.',
    'export.capacity.package' => 'Utilisez le processus privé d’export par fragments pour exporter entièrement ce site. Demandez à l’administrateur de l’installation d’exécuter l’outil fourni de préparation du paquet. Toutes les versions conservées sont incluses, sans suppression de l’historique.',
];
