<?php

declare(strict_types=1);

return [
    'design.error.font_faces' => 'A family needs 1-8 named faces, with normal or italic style and a weight range between 100 and 900.',
    'design.error.font_overlap' => 'Faces of the same style cannot have overlapping weight ranges.',
    'design.font.face_summary' => '{label} · {style} · weight {min}-{max}',
    'design.font.normal' => 'Roman',
    'design.font.italic' => 'Italic',
    'design.font.new_family' => 'New family',
    'design.font.family' => 'Add this face to',
    'design.font.family_help' => 'For a new family, enter its ID and label below. For an existing family, leave those two fields empty.',
    'design.font.face_id' => 'Face ID (unique in this family)',
    'design.font.face_label' => 'Face label',
    'design.font.style' => 'Style',
    'design.font.weight_min' => 'Minimum weight',
    'design.font.weight_max' => 'Maximum weight',
    'design.font.face_help' => 'Use the real style and supported weights of the file. A static face has the same minimum and maximum, for example 400. Adding faces upgrades legacy declarations to families; older previews stay unchanged.',
];
