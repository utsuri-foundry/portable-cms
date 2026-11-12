<?php

declare(strict_types=1);

return [
    'preflight.issue.record' => '{type} "{title}": {reason}',
    'preflight.issue.route' => 'Pàgina {route}: {reason}',
    'preflight.issue.setting' => '{setting}: {reason}',
    'preflight.issue.website' => 'Lloc web: {reason}',
    'preflight.issue.field' => '{field}: {reason}',
    'preflight.issue.field_locale' => '{field} en {locale}: {reason}',
    'preflight.reason.missing_setting' => 'Cal incloure exactament un registre d’aquest tipus en la previsualització.',
    'preflight.reason.required_value' => 'Cal afegir el valor obligatori.',
    'preflight.reason.missing_translation' => 'Cal afegir la traducció que falta.',
    'preflight.reason.relationship' => 'Cal seleccionar un registre disponible d’un tipus permès i incloure’l en aquesta previsualització.',
    'preflight.reason.sharing_image' => 'Cal seleccionar una imatge per compartir compatible (JPEG, PNG o WebP).',
    'preflight.reason.duplicate_route' => 'Cal triar una adreça de pàgina única.',
    'preflight.reason.invalid_route' => 'Cal corregir l’adreça de la pàgina.',
    'preflight.reason.invalid_content' => 'Cal corregir el contingut desat.',
    'preflight.reason.profile_error' => 'El perfil no ha pogut generar aquesta pàgina. Cal demanar al desenvolupador del perfil que revisi la referència de diagnòstic.',
    'preflight.reference' => 'Referència de diagnòstic: {reference}',
];
