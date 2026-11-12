<?php

declare(strict_types=1);

return [
    'workflow.delete' => 'Elimina l’entrada arxivada',
    'workflow.delete_help' => 'Eliminar suprimeix l’entrada i el seu historial de revisions. Les previsualitzacions i publicacions existents no canvien. Les entrades amb referències entrants, mitjans propis, pujades o tasques d’IA no es poden eliminar; mantén-les arxivades.',
    'workflow.delete_confirm' => 'Confirmo l’eliminació permanent d’aquesta entrada i del seu historial de revisions.',
    'workflow.delete_blocked' => 'Només es poden eliminar entrades arxivades de col·lecció sense referències, mitjans propis, pujades ni tasques d’IA.',
    'workflow.save' => 'Desar els canvis',
    'workflow.change' => 'Canvia l’estat',
    'workflow.help' => 'Les versions del web fan servir la revisió publicada. Desa les edicions per revisar-les i publica els canvis quan estiguin a punt. Publicar no modifica immediatament el web en línia.',
    'workflow.state' => 'Nou estat',
    'workflow.confirm' => 'Confirmo aquest canvi d’estat del registre desat.',
    'workflow.submit' => 'Confirma el canvi d’estat',
    'workflow.failed' => 'L’estat no s’ha canviat',
    'workflow.conflict' => 'El registre ha canviat. Revisa la revisió desada actual abans de tornar a confirmar.',
    'workflow.invalid' => 'Revisa el contingut desat abans de publicar.',
    'workflow.current' => 'Estat actual: {state}. Revisió desada: {revision}.',
    'workflow.back' => 'Torna al registre',
    'workflow.required' => '{field} és obligatori en totes les llengües del web abans de publicar.',
    'workflow.relationship' => 'Publica primer els registres referenciats o elimina la referència.',
    'workflow.media' => 'Els mitjans seleccionats han d’estar preparats. Els mitjans no compatibles no es poden publicar.',
    'preview.include_drafts' => 'Inclou els esborranys desats i els canvis no publicats (només revisió)',
    'preview.mode.drafts' => 'Inclou esborranys desats. Només revisió; no es pot publicar al web.',
    'preview.include_preparation' => 'Inclou les llengües en preparació: {languages} (només revisió)',
    'preview.mode.preparation' => 'Inclou llengües en preparació. Només revisió; no es pot publicar al web.',
    'preview.mode.published' => 'Només contingut publicat; apte per a les comprovacions de publicació.',
];
