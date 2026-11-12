<?php

declare(strict_types=1);

return [
    'history.title' => 'Historial de revisiones',
    'history.help' => 'Conserva un historial limitado por registro. Los cambios actuales y la versión publicada se conservan siempre, además de este límite.',
    'history.limit' => 'Revisiones históricas por registro',
    'history.policy' => 'Por defecto: 50. Elige entre 10 y 500. Las revisiones históricas más antiguas se eliminan al guardar o confirmar una reducción. Las vistas previas congeladas y las publicaciones conservan copias propias.',
    'history.save' => 'Revisar límite',
    'history.saved' => 'Límite de revisiones guardado.',
    'history.confirm' => 'Conservar {limit} revisiones históricas por registro eliminaría {count} revisiones de todo el sitio. Se excluyen las versiones actuales y publicadas.',
    'history.backup' => 'El historial eliminado no se puede recuperar de una exportación posterior. Conserva una copia de seguridad verificada de la instalación antes de continuar.',
    'history.accept' => 'Confirmo este límite y la eliminación de revisiones antiguas.',
    'history.apply' => 'Aplicar límite',
    'history.cancel' => 'Cancelar',
    'history.pending' => 'La limpieza del historial está pendiente. Cada petición trata un registro y elimina como máximo 100 revisiones. Puedes salir y continuar más tarde; los lotes completados se conservan.',
    'history.continue' => 'Continuar limpieza',
    'history.capacity' => '{count, number, integer} revisiones conservadas de una capacidad de exportación medida de {capacity, number, integer}. El total incluye las versiones actuales y publicadas.',
    'history.warning' => 'El historial se acerca a la capacidad de exportación medida o la supera. Revisa el límite y prepara una exportación completa verificada. También se aplican otros límites de cantidad y tamaño.',
    'history.invalid' => 'Elige un límite entero entre 10 y 500.',
    'history.changed' => 'La configuración o el número de revisiones que se eliminarían ha cambiado. Revisa el límite y confirma de nuevo el recuento actual.',
    'history.forbidden' => 'Solo un administrador puede cambiar el historial de revisiones.',
    'history.preview_missing' => '{count, number, integer} revisiones de origen ya no se conservan. Esta vista previa congelada y sus publicaciones mantienen el contenido exacto.',
];
