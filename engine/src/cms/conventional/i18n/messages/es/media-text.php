<?php

declare(strict_types=1);

return [
    'media.text.help' => 'Describe la imagen en cada idioma del sitio. Los campos vacíos usan el texto escrito en {locale}. Las alternativas, los pies y los créditos admiten texto plano de hasta 500 caracteres. Se necesita una alternativa o marcar la imagen como decorativa antes de publicar. Los cambios afectan a todos los usos de la imagen.',
    'media.text.decorative' => 'Imagen decorativa (alternativa vacía)',
    'media.text.alternative' => 'Texto alternativo',
    'media.text.caption' => 'Pie (opcional)',
    'media.text.credit' => 'Crédito (opcional)',
    'media.text.save' => 'Guardar detalles',
    'media.text.edit' => 'Editar detalles de la imagen',
    'media.text.invalid' => 'Usa texto plano de hasta 500 caracteres para cada alternativa, pie y crédito.',
    'media.upload.title' => 'Subir imágenes',
    'media.upload.help' => 'Elige el sitio web o un registro guardado para conservar las imágenes. Estarán disponibles en toda la biblioteca; seleccionarlas en el contenido es un paso separado.',
    'media.upload.owner' => 'Conservar las imágenes con',
    'media.upload.files' => 'Imágenes (hasta ocho)',
    'preflight.issue.media_text' => 'La página {route} muestra la imagen {id} sin su texto alternativo escrito ni la marca decorativa en {locale}.',
    'workflow.media_text' => '{field} necesita un texto alternativo escrito o la marca decorativa en {locale}.',
];
