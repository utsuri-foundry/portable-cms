<?php

declare(strict_types=1);

return [
    'export.capacity.details' => 'Límites de exportación y restauración',
    'export.capacity.legacy' => 'Descarga en el navegador (v1): hasta {records, number, integer} registros, {revisions, number, integer} versiones conservadas, {relationships, number, integer} relaciones actuales y {media, number, integer} registros multimedia, dentro de {mib} MiB de JSON.',
    'export.capacity.chunked' => 'Paquete privado por fragmentos (v2), preparado por el administrador de la instalación: hasta {records, number, integer} registros, {revisions, number, integer} versiones conservadas, {relationships, number, integer} relaciones actuales y {media, number, integer} registros multimedia, dentro de {mib} MiB de fragmentos de contenido.',
    'export.capacity.scope' => 'Los totales abarcan toda la instalación: borradores, archivos, términos y configuración. Cada versión guardada contiene el cuerpo completo. Ambas vías admiten hasta {derivatives, number, integer} derivados. Los límites de cantidad y tamaño se aplican juntos; una exportación excesiva se rechaza, nunca se trunca ni se purga su historial.',
    'export.capacity.restore' => 'La restauración tiene comprobaciones propias de archivos, tamaño, duración y alojamiento. V1 necesita memoria PHP libre de al menos 64 MiB o dieciséis veces el tamaño del JSON, el valor mayor. El nivel v2 medido requiere un proceso PHP CLI cualificado de 256 MiB; el número de registros no cualifica por sí solo al alojamiento. La preparación incompleta debe reanudarse, no es una restauración terminada.',
    'export.capacity.bytes' => 'El JSON excluye originales privados, derivados y fuentes subidas: un operador también debe empaquetar esos archivos. El paquete de contenido excluye cuentas, secretos, sesiones, historial de auditoría antiguo, recuperación de tareas y activación de publicaciones. Conserve una copia verificada independiente de la instalación.',
    'export.capacity.exceeded' => 'Este sitio supera al menos un límite admitido de cantidad o tamaño. No se ha eliminado nada. Pida al administrador que revise la capacidad y utilice un proceso de exportación cualificado antes de seguir ampliando el sitio.',
    'preflight.issue.capacity' => 'Este sitio supera un límite de tamaño o de número de registros de la vista previa. No se ha eliminado contenido. Pida al administrador que revise la capacidad de vista previa y exportación completa antes de generar el sitio.',
    'export.capacity.counts' => 'Este sitio tiene {records} registros y {revisions} versiones guardadas, incluidos los borradores y el contenido archivado.',
    'export.capacity.warning' => 'Este sitio se acerca a un límite admitido de contenido, historial o tamaño de las páginas actuales. Prepare una exportación completa verificada y revise la capacidad antes de añadir contenido. El historial sigue el límite de conservación configurado.',
    'export.capacity.package' => 'Para exportar todo este sitio, use el proceso privado de exportación por fragmentos. Pida al administrador de la instalación que ejecute la herramienta incluida para preparar el paquete. Se incluyen todas las versiones conservadas, sin descartar historial.',
];
