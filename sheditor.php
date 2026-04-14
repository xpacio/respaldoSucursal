<?php
$pageTitle = 'GIS - Sh Editor';
$activePage = 'sheditor';
$pageHeader = 'Sh Editor';
$pagePretitle = 'Editor de Scripts';

require_once __DIR__ . '/ui/header.php';
?>

<div class="page-header d-print-none mb-3">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <div class="page-pretitle"><?= htmlspecialchars($pagePretitle) ?></div>
                <h2 class="page-title"><?= htmlspecialchars($pageHeader) ?></h2>
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#diskModal" id="btn-open-disk">
                    <svg class="icon me-1"><use href="#icon-folder"></use></svg>Visor Disco
                </button>
                <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#manageModal" id="btn-open-manage">
                    <svg class="icon me-1"><use href="#icon-settings"></use></svg>Gestión
                </button>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Sidebar BD -->
    <div class="col-md-3">
        <div class="d-flex mb-2 gap-1">
            <button id="btn-new-file" class="btn btn-sm btn-outline-primary flex-fill" title="Nuevo archivo">
                <svg class="icon"><use href="#icon-plus"></use></svg> Nuevo
            </button>
            <button id="btn-refresh-bd" class="btn btn-sm btn-outline-secondary" title="Actualizar">
                <svg class="icon"><use href="#icon-refresh"></use></svg>
            </button>
        </div>
        <div id="bd-file-list" class="list-group list-group-flush" style="max-height:550px;overflow-y:auto;">
            <div class="p-3 px-5">
                <div class="progress progress-sm"><div class="progress-bar progress-bar-indeterminate" style="width:100%;background:#0d6efd"></div></div>
            </div>
        </div>
    </div>

    <!-- Editor -->
    <div class="col-md-9">
        <div class="mb-2">
            <input type="text" id="sh-path" class="form-control form-control-sm" placeholder="path/archivo.sh">
        </div>
        <textarea id="sh-content" class="form-control" style="font-family:monospace;min-height:300px;resize:vertical;" spellcheck="false" placeholder="Seleccione un archivo o cree uno nuevo..."></textarea>
        <div class="d-flex justify-content-between align-items-center mt-2">
            <small id="sh-md5" class="text-muted"></small>
            <div>
                <button id="btn-save" class="btn btn-sm btn-primary" disabled>
                    <svg class="icon me-1"><use href="#icon-device-floppy"></use></svg>Guardar
                </button>
                <button id="btn-delete-file" class="btn btn-sm btn-outline-danger" disabled>
                    <svg class="icon me-1"><use href="#icon-trash"></use></svg>Eliminar
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Visor de Disco -->
<div class="modal fade" id="diskModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Visor de Disco /srv/sh/</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="d-flex mb-2 gap-1">
            <button id="btn-refresh-disk" class="btn btn-sm btn-outline-secondary">
                <svg class="icon me-1"><use href="#icon-refresh"></use></svg>Actualizar
            </button>
            <button id="btn-show-orphans" class="btn btn-sm btn-outline-warning">
                <svg class="icon me-1"><use href="#icon-alert-triangle"></use></svg>Solo huérfanos
            </button>
            <button id="btn-import-all-orphans" class="btn btn-sm btn-outline-success" style="display:none;">
                Importar todos
            </button>
        </div>
        <div id="disk-list" class="list-group list-group-flush" style="max-height:400px;overflow-y:auto;"></div>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Gestión -->
<div class="modal fade" id="manageModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Gestión de archivos</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="d-flex gap-2 mb-3 flex-wrap">
            <button id="btn-verify-all" class="btn btn-sm btn-outline-primary">
                <svg class="icon me-1"><use href="#icon-check"></use></svg>Verificar todo MD5
            </button>
            <button id="btn-rebuild-all" class="btn btn-sm btn-outline-warning">
                <svg class="icon me-1"><use href="#icon-refresh"></use></svg>Reconstruir disco desde BD
            </button>
        </div>
        <div id="manage-results" class="list-group list-group-flush" style="max-height:400px;overflow-y:auto;">
            <p class="text-muted text-center p-3">Elija una acción arriba</p>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/ui/footer.php'; ?>

<script type="module">
import { shEditor } from './js/modules/shEditorView.js';
shEditor.init();
</script>
