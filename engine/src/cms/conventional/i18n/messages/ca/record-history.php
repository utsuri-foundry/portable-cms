<?php

declare(strict_types=1);

return [
    'history.title' => 'Historial de revisions',
    'history.help' => 'Conserva un historial limitat per a cada registre. Els canvis actuals i la versió publicada es conserven sempre, a més d’aquest límit.',
    'history.limit' => 'Revisions històriques per registre',
    'history.policy' => 'Per defecte: 50. Tria entre 10 i 500. Les revisions històriques més antigues s’eliminen després de desar o de confirmar una reducció. Les previsualitzacions congelades i les publicacions conserven còpies pròpies.',
    'history.save' => 'Revisa el límit',
    'history.saved' => 'S’ha desat el límit de revisions.',
    'history.confirm' => 'Conservar {limit} revisions històriques per registre eliminaria {count} revisions de tot el web. Se n’exclouen les versions actuals i publicades.',
    'history.backup' => 'L’historial eliminat no es pot recuperar d’una exportació de contingut posterior. Conserva una còpia de seguretat verificada de la instal·lació abans de continuar.',
    'history.accept' => 'Confirmo aquest límit i l’eliminació de les revisions antigues.',
    'history.apply' => 'Aplica el límit',
    'history.cancel' => 'Cancel·la',
    'history.pending' => 'La neteja de l’historial està pendent. Cada petició tracta un registre i elimina com a màxim 100 revisions. Pots sortir d’aquesta pàgina i reprendre-la més tard; els lots completats es conserven.',
    'history.continue' => 'Continua la neteja',
    'history.capacity' => '{count, number, integer} revisions conservades d’una capacitat d’exportació mesurada de {capacity, number, integer}. El total inclou les versions actuals i publicades.',
    'history.warning' => 'L’historial s’apropa a la capacitat d’exportació mesurada o la supera. Revisa el límit i prepara una exportació completa verificada. També s’apliquen altres límits de quantitat i mida.',
    'history.invalid' => 'Tria un límit enter entre 10 i 500.',
    'history.changed' => 'La configuració o el nombre de revisions per eliminar ha canviat. Revisa el límit i torna a confirmar el recompte actual.',
    'history.forbidden' => 'Només un administrador pot canviar l’historial de revisions.',
    'history.preview_missing' => '{count, number, integer} revisions d’origen ja no es conserven. Aquesta previsualització congelada i les publicacions que se’n deriven mantenen el contingut exacte.',
];
