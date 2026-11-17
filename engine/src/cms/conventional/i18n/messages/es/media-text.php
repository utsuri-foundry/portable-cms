<?php

declare(strict_types=1);

return [
    'media.text.title' => 'Descripción',
    'media.text.help' => 'Explica qué muestra la imagen, en cada idioma del sitio, para quien no puede verla y para los buscadores. Un idioma vacío usa el texto en {locale}. Hasta 500 caracteres cada una. Los cambios se aplican a todos los usos de la imagen.',
    'media.text.decorative' => 'Imagen decorativa, no necesita descripción',
    'media.text.decorative_help' => 'Para imágenes que no aportan nada al texto, como un fondo o un separador. Los lectores de pantalla las omiten.',
    'media.text.alternative' => 'Descripción',
    'media.text.more' => 'Pie y crédito',
    'media.text.caption' => 'Pie',
    'media.text.credit' => 'Crédito',
    'media.text.edit' => 'Editar detalles de la imagen',
    'media.text.invalid' => 'Usa texto plano de hasta 500 caracteres para cada descripción, pie y crédito.',
    'media.upload.title' => 'Subir',
    'media.upload.rule' => 'Imágenes de hasta {limit} MB.',
    'media.upload.rule_video' => 'Imágenes y vídeos cortos de hasta {limit} MB.',
    'media.upload.owner' => 'Pertenece a',
    'media.upload.owner_help' => 'De dónde vienen los archivos. Todas las páginas pueden usarlos igualmente.',
    'media.upload.website' => 'El sitio web',
    'media.upload.files' => 'Archivos',
    'media.upload.submit' => 'Subir los archivos',
    'preflight.issue.media_text' => 'La página {route} muestra la imagen {id} sin su texto alternativo escrito ni la marca decorativa en {locale}.',
    'workflow.media_text' => '{field} necesita un texto alternativo escrito o la marca decorativa en {locale}.',
];
