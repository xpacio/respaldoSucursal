<?php
// Agente page
$pageTitle = 'GIS - Agente';
$activePage = 'agente';
$pageHeader = 'Agente';
$pagePretitle = 'Editor de Código';

require_once __DIR__ . '/ui/header.php';
?>

<!-- Page Header -->
<div class="page-header d-print-none mb-3">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <?php if (!empty($pagePretitle)): ?>
                <div class="page-pretitle"><?= htmlspecialchars($pagePretitle) ?></div>
                <?php endif; ?>
                <h2 class="page-title"><?= htmlspecialchars($pageHeader) ?></h2>
            </div>
            <div class="col-auto">
                <span id="current-path" class="text-muted me-3"></span>
            </div>
        </div>
    </div>
</div>

<!-- Page Content -->
<div class="row">
    <!-- Explorador de archivos -->
    <div class="col-md-3">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Archivos</h3>
                <div class="card-actions btn-actions">
                    <button id="btn-new-file" class="btn btn-sm btn-outline-primary" title="Nuevo archivo">
                        <svg class="icon"><use href="#icon-plus"></use></svg>
                    </button>
                    <button id="btn-new-dir" class="btn btn-sm btn-outline-primary" title="Nueva carpeta">
                        <svg class="icon"><use href="#icon-folder"></use></svg>
                    </button>
                    <button id="btn-refresh" class="btn btn-sm btn-outline-secondary" title="Actualizar">
                        <svg class="icon"><use href="#icon-refresh"></use></svg>
                    </button>
                </div>
            </div>
            <div class="card-body p-0">
                <div id="file-explorer" class="list-group list-group-flush" style="max-height: 600px; overflow-y: auto;">
                    <div class="p-3 px-5">
                        <div class="progress progress-sm"><div class="progress-bar progress-bar-indeterminate" style="width:100%; background:#0d6efd"></div></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Editor -->
    <div class="col-md-9">
        <div class="card">
            <div class="card-header">
                <div class="row w-100 align-items-center">
                    <div class="col">
                        <input type="text" id="file-path" class="form-control form-control-sm" placeholder="Seleccione un archivo" readonly>
                    </div>
                    <div class="col-auto">
                        <button id="btn-save" class="btn btn-sm btn-primary" disabled>
                            <svg class="icon me-1"><use href="#icon-save"></use></svg>
                            Guardar
                        </button>
                        <button id="btn-download" class="btn btn-sm btn-outline-secondary" disabled>
                            <svg class="icon me-1"><use href="#icon-download"></use></svg>
                            Descargar
                        </button>
                        <button id="btn-upload" class="btn btn-sm btn-outline-secondary">
                            <svg class="icon me-1"><use href="#icon-upload"></use></svg>
                            Subir
                        </button>
                        <button id="btn-delete" class="btn btn-sm btn-outline-danger" disabled>
                            <svg class="icon me-1"><use href="#icon-trash"></use></svg>
                            Eliminar
                        </button>
                    </div>
                </div>
            </div>
            <div class="card-body p-0">
                <div id="editor-area">
                    <div class="text-center p-5 text-muted">
                        <svg class="icon mb-2" style="width: 48px; height: 48px;"><use href="#icon-file"></use></svg>
                        <p>Seleccione un archivo para editar</p>
                    </div>
                </div>
                <textarea id="file-content" class="form-control" style="display: none; font-family: monospace; min-height: 500px; border: none; resize: vertical;" spellcheck="false"></textarea>
            </div>
            <div class="card-footer" id="editor-status" style="display: none;">
                <small class="text-muted">
                    <span id="file-info"></span>
                </small>
            </div>
        </div>
    </div>
</div>

<!-- Modal para nuevo archivo -->
<div class="modal modal-blur fade" id="newFileModal" tabindex="-1" style="display: none;" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Nuevo Archivo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Nombre del archivo</label>
                    <input type="text" id="new-file-name" class="form-control" placeholder="script.bat">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" id="btn-create-file" class="btn btn-primary">Crear</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal para nueva carpeta -->
<div class="modal modal-blur fade" id="newDirModal" tabindex="-1" style="display: none;" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Nueva Carpeta</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Nombre de la carpeta</label>
                    <input type="text" id="new-dir-name" class="form-control" placeholder="nueva_carpeta">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" id="btn-create-dir" class="btn btn-primary">Crear</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal para confirmar eliminación -->
<div class="modal modal-blur fade" id="deleteConfirmModal" tabindex="-1" style="display: none;" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirmar Eliminación</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>¿Está seguro de eliminar <strong id="delete-item-name"></strong>?</p>
                <p class="text-danger">Esta acción no se puede deshacer.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" id="btn-confirm-delete" class="btn btn-danger">Eliminar</button>
            </div>
        </div>
    </div>
</div>

<!-- Upload Modal -->
<div class="modal modal-blur fade" id="uploadModal" tabindex="-1" style="display: none;" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Subir Archivo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Carpeta destino</label>
                    <input type="text" id="upload-path" class="form-control" placeholder="/" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label">Archivo</label>
                    <input type="file" id="upload-file" class="form-control">
                </div>
                <div class="form-hint">Archivos permitidos: .bat, .vbs, .txt, .cmd, .ps1, .exe</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" id="btn-do-upload" class="btn btn-primary">Subir</button>
            </div>
        </div>
    </div>
</div>

<!-- Toast container -->
<div id="toast-container" class="toast-container position-fixed bottom-0 end-0 p-3"></div>

<style>
.file-item { cursor: pointer; }
.file-item:hover { background-color: var(--tblr-light); }
.file-item.active { background-color: var(--tblr-primary-bg-subtle); }
.file-item.directory { font-weight: 500; }
.file-item.editable { }
.file-item:not(.editable) { color: var(--tblr-secondary); }
</style>

<script type="module">
import { AgenteView } from './js/modules/agenteView.js';
const agente = new AgenteView();
agente.init();
</script>

<?php require_once __DIR__ . '/ui/footer.php'; ?>
