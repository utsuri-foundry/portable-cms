<?php

declare(strict_types=1);

return [
    'export.capacity.details' => 'Límits d’exportació i restauració',
    'export.capacity.legacy' => 'Descàrrega al navegador (v1): fins a {records, number, integer} registres, {revisions, number, integer} versions conservades, {relationships, number, integer} relacions actuals i {media, number, integer} registres multimèdia, dins de {mib} MiB de JSON.',
    'export.capacity.chunked' => 'Paquet privat en fragments (v2), preparat per l’administrador de la instal·lació: fins a {records, number, integer} registres, {revisions, number, integer} versions conservades, {relationships, number, integer} relacions actuals i {media, number, integer} registres multimèdia, dins de {mib} MiB de fragments de contingut.',
    'export.capacity.scope' => 'Els totals inclouen tota la instal·lació: esborranys, contingut arxivat, termes i configuració. Cada versió desada conté el cos complet. Tots dos processos admeten fins a {derivatives, number, integer} derivats. Els límits de quantitat i mida s’apliquen alhora; una exportació massa gran es rebutja, no es retalla ni se’n purga l’historial.',
    'export.capacity.restore' => 'La restauració té comprovacions pròpies de fitxers, mida, durada i allotjament. V1 necessita memòria PHP lliure d’almenys 64 MiB o setze vegades la mida del JSON, el valor més gran. El nivell v2 mesurat requereix un procés PHP CLI qualificat de 256 MiB; el nombre de registres no qualifica l’allotjament per si sol. La preparació incompleta s’ha de reprendre, no és una restauració acabada.',
    'export.capacity.bytes' => 'El JSON no inclou originals privats, derivats ni fonts pujades: un operador també n’ha de preparar els fitxers. El paquet de contingut exclou comptes, secrets, sessions, historial d’auditoria antic, recuperació de tasques i activació de publicacions. Conserveu una còpia verificada independent de la instal·lació.',
    'export.capacity.exceeded' => 'Aquest web supera almenys un límit admès de quantitat o de mida. No s’ha eliminat res. Demaneu a l’administrador que revisi la capacitat i utilitzi un procés d’exportació qualificat abans de continuar ampliant el web.',
    'preflight.issue.capacity' => 'Aquest web supera un límit de mida o de nombre de registres de la previsualització. No s’ha suprimit cap contingut. Demaneu a l’administrador que revisi la capacitat de previsualització i d’exportació completa abans de construir el web.',
    'export.capacity.counts' => 'Aquest web té {records} registres i {revisions} versions desades, inclosos els esborranys i el contingut arxivat.',
    'export.capacity.warning' => 'Aquest web s’apropa a un límit admès de contingut, historial o mida de les pàgines actuals. Prepareu una exportació completa verificada i reviseu la capacitat abans d’afegir contingut. L’historial segueix el límit de conservació configurat.',
    'export.capacity.package' => 'Per exportar tot aquest web, feu servir el procés privat d’exportació en fragments. Demaneu a l’administrador de la instal·lació que executi l’eina inclosa de preparació del paquet. S’hi inclouen totes les versions conservades, sense descartar l’historial.',
];
