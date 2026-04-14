<?php
$pageTitle = 'GIS - Distribución';
$activePage = 'distribucion';
$pageHeader = 'Distribución';
$pagePretitle = 'Distribución de Archivos';

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
                <button class="btn bg-teal text-white" id="btn-nuevo-perfil" data-bs-toggle="modal" data-bs-target="#perfilModal">
                    <svg class="icon mx-1"><use href="#icon-plus"></use></svg>Perfil
                </button>
                <button class="btn bg-blue text-white" id="btn-nueva-dist" data-bs-toggle="modal" data-bs-target="#editModal">
                    <svg class="icon mx-1"><use href="#icon-plus"></use></svg>Distribución
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Tabla de distribuciones -->
<div class="card">
    <div class="card-header p-2">
        <div class="row w-100">
            <div class="col-md-2">
                <input type="text" class="form-control" placeholder="Nombre..." id="filter-nombre">
            </div>
            <div class="col-md-2">
                <select class="form-select" id="filter-tipo">
                    <option value="">tipo*</option>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select" id="filter-plaza">
                    <option value="">plaza*</option>
                </select>
            </div>
            <div class="col-md-2">
                <input type="text" class="form-control" placeholder="Origen..." id="filter-origen">
            </div>
            <div class="col-md-4 d-flex align-items-center gap-2">
                <div class="btn-actions">
                    <button class="mx-1 btn btn-action bg-blue-lt" id="btn-exportar" title="Exportar">
                        <svg class="icon"><use href="#icon-download"></use></svg>
                    </button>
                    <button class="mx-1 btn btn-action bg-blue-lt" id="btn-importar" title="Importar" data-bs-toggle="modal" data-bs-target="#importModal">
                        <svg class="icon"><use href="#icon-upload"></use></svg>
                    </button>
                    <button class="mx-1 btn btn-action bg-purple-lt" id="btn-copiar-vista" title="Ejecutar vista">
                        <svg class="icon"><use href="#icon-player-play"></use></svg>
                    </button>
                    <button class="mx-1 btn btn-action bg-orange-lt" id="btn-copiar-todas" title="Ejecutar todas">
                        <svg class="icon"><use href="#icon-playstation-x"></use></svg>
                    </button>
                </div>
                    <label class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" id="limit-8" checked />
                        <span class="form-check-label">Límite 8</span>
                    </label>
            </div>





        </div>
    </div>
    <div class="card-body p-0 card-body-scrollable">
        <table class="table table-vcenter">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Perfil</th>
                    <th>Plaza</th>
                    <th>Origen</th>
                    <th>Clientes</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody id="dist-tbody">
                <div class="progress progress-sm"><div class="progress-bar progress-bar-indeterminate bg-blue-lt"></div></div>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Crear/Editar Distribución -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-blue-lt">
        <h5 class="modal-title" id="editModalTitle">Nueva Distribución</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="distForm">
            <input type="hidden" id="edit-id">
            <div class="mb-3">
                <label class="form-label">Nombre</label>
                <input type="text" class="form-control" id="edit-nombre" placeholder="Listas Chapala" autocomplete="off" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Perfil</label>
                <select class="form-select" id="edit-tipo">
                    <option value="">Seleccionar perfil...</option>
                </select>
            </div>
            <div id="perfil-preview" class="mb-3 d-none">
                <div class="card card-sm bg-body-tertiary">
                    <div class="card-body py-2 px-3">
                        <div class="row">
                            <div class="col-auto">
                                <small class="text-muted">Archivos:</small>
                                <div class="font-monospace small" id="preview-files"></div>
                            </div>
                            <div class="col-auto">
                                <small class="text-muted">Destino:</small>
                                <div class="font-monospace small" id="preview-dst"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Plaza</label>
                <div class="position-relative">
                    <input type="text" class="form-control" id="edit-plaza" placeholder="CHAPALA" autocomplete="off">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Ruta origen</label>
                <input type="text" class="form-control" id="edit-src" placeholder="/srv/precios/CHAPALA/ENVIAR/">
            </div>
        </form>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">
                                        <svg class="icon mx-1"><use href="#icon-cancel"></use></svg>
        </button>
        <button type="submit" class="btn btn-primary" id="btn-save-dist" form="distForm">


                            <svg class="icon mx-1"><use href="#icon-save"></use></svg>


        </button>

      </div>
    </div>
  </div>
</div>

<!-- Modal: Detalle Distribución -->
<button id="btn-open-detail-modal" data-bs-toggle="modal" data-bs-target="#detailModal" style="display:none;"></button>
<div class="modal fade" id="detailModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="detailModalTitle">Detalle</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="d-flex gap-2 mb-3 flex-wrap align-items-center">
            <button class="btn btn-outline-primary" id="btn-evaluar">
                <svg class="icon mx-1"><use href="#icon-check"></use></svg>Evaluar
            </button>
            <button class="btn btn-primary" id="btn-copiar">
                <svg class="icon mx-1"><use href="#icon-player-play"></use></svg>Ejecutar Copia
            </button>
            <div id="status-message" class="flex-grow-1"></div>
        </div>

        <!-- Tabs -->
        <ul class="nav nav-tabs mb-3" id="detailTabs">
            <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-clientes">Clientes</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-versiones">Versiones</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-errores">Errores</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-ejecuciones">Ejecuciones</a></li>
        </ul>

        <div class="tab-content">
            <div class="tab-pane active show" id="tab-clientes">
                <div id="clientes-list" style="max-height:300px;overflow-y:auto;"></div>
            </div>
            <div class="tab-pane" id="tab-versiones">
                <div id="versiones-list" class="list-group list-group-flush" style="max-height:300px;overflow-y:auto;"></div>
            </div>
            <div class="tab-pane" id="tab-errores">
                <div id="errores-list" class="list-group list-group-flush" style="max-height:300px;overflow-y:auto;"></div>
            </div>
            <div class="tab-pane" id="tab-ejecuciones">
                <div id="ejecuciones-list" class="list-group list-group-flush" style="max-height:300px;overflow-y:auto;"></div>
            </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Perfiles (Tipos) -->
<div class="modal fade" id="perfilModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-green-lt">
        <h5 class="modal-title" id="perfilModalTitle">Perfiles de Distribución</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <!-- Formulario de perfil -->
        <form id="perfilForm" class="mb-3">
            <input type="hidden" id="perfil-edit-mode" value="">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">Perfil</label>
                    <input type="text" class="form-control" id="perfil-tipo" placeholder="listas" autocomplete="off" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Archivos (separados por coma)</label>
                    <input type="text" class="form-control" id="perfil-files" placeholder="LISTA.CDX,LISTA.DBF" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Plantilla destino</label>
                    <input type="text" class="form-control" id="perfil-dst" placeholder="/home/{rbfid}/lista/" required>
                </div>
                <div class="col-md-2">
                    <div class="d-flex gap-1">
                        <button type="submit" class="btn btn-primary flex-fill" id="btn-save-perfil">
                            <svg class="icon mx-1"><use href="#icon-save"></use></svg>
                        </button>
                        <button type="button" class="btn btn-secondary d-none" id="btn-cancel-perfil">
                            <svg class="icon mx-1"><use href="#icon-cancel"></use></svg>
                        </button>
                    </div>
                </div>
            </div>
        </form>

        <!-- Tabla de perfiles existentes -->
        <table class="table table-vcenter">
            <thead>
                <tr>
                    <th>Perfil</th>
                    <th>Archivos</th>
                    <th>Destino</th><th class="text-center">Acciones</th></tr>
            </thead>
            <tbody id="perfiles-tbody">
                <tr><td colspan="4" class="text-muted text-center p-3">Cargando...</td></tr>
            </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Importar Distribuciones -->
<div class="modal fade" id="importModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Importar Distribuciones</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <p class="text-muted small mb-0">Una distribución por línea. Formato: <code>tipo,nombre,plaza,ruta_origen</code> | <code># comentario</code></p>
          <div class="d-flex align-items-center gap-2">
            <div class="form-check form-switch mb-0">
              <input class="form-check-input" type="checkbox" id="scan-incluidas">
              <label class="form-check-label small" for="scan-incluidas">Incluidas</label>
            </div>
            <button class="btn btn-sm btn-outline-secondary" id="btn-scan-precios">
              <svg class="icon me-1"><use href="#icon-refresh"></use></svg>Escanear
            </button>
          </div>
        </div>
        <textarea class="form-control font-monospace" id="import-texto" rows="10"
          placeholder="lista,CHAPALA,guada,/srv/precios/CHAPALA/ENVIAR&#10;lista,VALLARTA,valla,/srv/precios/VALLARTA/ENVIAR"></textarea>
        <div class="form-check mt-2">
          <input class="form-check-input" type="checkbox" id="import-truncar">
          <label class="form-check-label text-danger" for="import-truncar">Truncar distribuciones antes de importar</label>
        </div>
        <div id="import-resultado" class="mt-3"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary" id="btn-ejecutar-import">
          <svg class="icon mx-1"><use href="#icon-upload"></use></svg>Importar
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Clientes de Distribución -->
<div class="modal fade" id="clientesModal" tabindex="-1" aria-hidden="true" data-bs-focus="false">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="clientesModalTitle">Clientes</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="clientes-modal-list" style="max-height:400px;overflow-y:auto;"></div>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/ui/footer.php'; ?>

<script type="module">
console.log('[DEBUG] Cargando distribucionView...');
import { distView, openEdit } from './js/modules/distribucionView.js';
console.log('[DEBUG] Módulo cargado, inicializando...');
distView.init();
console.log('[DEBUG] distView.init() completado');

document.getElementById('editModal').addEventListener('show.bs.modal', function(event) {
    const button = event.relatedTarget;
    const editId = button?.dataset?.edit;
    console.log('[DEBUG] Modal abierto, editId:', editId);
    if (editId) {
        openEdit(parseInt(editId));
    } else {
        openEdit();
    }
});
</script>
