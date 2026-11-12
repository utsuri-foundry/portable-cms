<?php

declare(strict_types=1);

return [
    'design.error.font_faces' => 'Una familia necesita de 1 a 8 variantes con nombre, estilo redondo o cursiva y pesos entre 100 y 900.',
    'design.error.font_overlap' => 'Las variantes del mismo estilo no pueden tener intervalos de peso superpuestos.',
    'design.font.face_summary' => '{label} · {style} · peso {min}-{max}',
    'design.font.normal' => 'Redonda',
    'design.font.italic' => 'Cursiva',
    'design.font.new_family' => 'Familia nueva',
    'design.font.family' => 'Añadir esta variante a',
    'design.font.family_help' => 'Para una familia nueva, indica su ID y nombre debajo. Para una familia existente, deja esos dos campos vacíos.',
    'design.font.face_id' => 'ID de variante (único en esta familia)',
    'design.font.face_label' => 'Nombre de la variante',
    'design.font.style' => 'Estilo',
    'design.font.weight_min' => 'Peso mínimo',
    'design.font.weight_max' => 'Peso máximo',
    'design.font.face_help' => 'Indica el estilo real y los pesos admitidos por el archivo. Una variante estática tiene el mismo mínimo y máximo, por ejemplo 400. Añadir variantes convierte las declaraciones antiguas en familias; las vistas previas anteriores no cambian.',
];
