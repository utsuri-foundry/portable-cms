<?php

declare(strict_types=1);

return [
    'workflow.delete' => 'Elimina la entrada archivada',
    'workflow.delete_help' => 'Eliminar suprime la entrada y su historial de revisiones. Las vistas previas y publicaciones existentes no cambian. Las entradas con referencias entrantes, medios propios, subidas o tareas de IA no se pueden eliminar; mantenlas archivadas.',
    'workflow.delete_confirm' => 'Confirmo la eliminación permanente de esta entrada y su historial de revisiones.',
    'workflow.delete_blocked' => 'Solo se pueden eliminar entradas archivadas de colección sin referencias, medios propios, subidas ni tareas de IA.',
    'workflow.save' => 'Guardar cambios',
    'workflow.change' => 'Cambiar estado',
    'workflow.help' => 'Las versiones del sitio usan la revisión publicada. Guarda las ediciones para revisarlas y publica los cambios cuando estén listos. Publicar no modifica de inmediato el sitio en línea.',
    'workflow.state' => 'Nuevo estado',
    'workflow.confirm' => 'Confirmo este cambio de estado del registro guardado.',
    'workflow.submit' => 'Confirmar cambio de estado',
    'workflow.failed' => 'Estado no cambiado',
    'workflow.conflict' => 'El registro ha cambiado. Revisa la revisión guardada actual antes de confirmar de nuevo.',
    'workflow.invalid' => 'Revisa el contenido guardado antes de publicar.',
    'workflow.current' => 'Estado actual: {state}. Revisión guardada: {revision}.',
    'workflow.back' => 'Volver al registro',
    'workflow.required' => '{field} es obligatorio en todos los idiomas del sitio antes de publicar.',
    'workflow.relationship' => 'Publica primero los registros referenciados o elimina la referencia.',
    'workflow.media' => 'Los medios seleccionados deben estar listos. Los medios no compatibles no pueden publicarse.',
    'preview.include_drafts' => 'Incluir borradores guardados y cambios no publicados (solo revisión)',
    'preview.mode.drafts' => 'Incluye borradores guardados. Solo revisión; no puede publicarse en el sitio.',
    'preview.include_preparation' => 'Incluir los idiomas en preparación: {languages} (solo revisión)',
    'preview.mode.preparation' => 'Incluye idiomas en preparación. Solo revisión; no puede publicarse en el sitio.',
    'preview.mode.published' => 'Solo contenido publicado; apto para las comprobaciones de publicación.',
];
