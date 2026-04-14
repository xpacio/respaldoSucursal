<?php
// Clientes page
$pageTitle = 'GIS - Clientes';
$activePage = 'clientes';
$pageHeader = 'Clientes';
$pagePretitle = 'Gestión';

require_once __DIR__ . '/ui/header.php';
?>

<!-- Page Header with Reset Button -->
<div class="page-header d-print-none mb-3" aria-label="Page header">
    <div class="container-fluid">
        <div class="row g-2 align-items-center">
            <div class="col">
                <?php if (!empty($pagePretitle)): ?>
                <div class="page-pretitle"><?= htmlspecialchars($pagePretitle) ?></div>
                <?php endif; ?>
                <h2 class="page-title"><?= htmlspecialchars($pageHeader) ?></h2>
            </div>
            <div class="col-auto ms-auto d-print-none">
                <div class="btn-list">
                    <a href="#" class="btn btn-primary d-none d-sm-inline-block" data-bs-toggle="modal" data-bs-target="#clientModal">
                        <svg class="icon"><use href="#icon-plus"></use></svg>
                    </a>
                    <!-- Dropdowns de Acciones -->
                    <div class="dropdown">
                        <button class="btn dropdown-toggle" data-bs-toggle="dropdown">
                            Disable
                        </button>
                        <div class="dropdown-menu">
                            <button class="dropdown-item" data-action="batch-disable-estado">Clientes</button>
                            <button class="dropdown-item" data-action="batch-disable-key-download">Descarga de clave</button>
                            <button class="dropdown-item" data-action="batch-disable-ssh">SSH</button>
                        </div>
                    </div>
                    <div class="dropdown">
                        <button class="btn dropdown-toggle" data-bs-toggle="dropdown">
                            Enable
                        </button>
                        <div class="dropdown-menu">
                            <button class="dropdown-item" data-action="batch-enable-estado">Clientes</button>
                            <button class="dropdown-item" data-action="batch-enable-key-download">Descarga de clave</button>
                            <button class="dropdown-item" data-action="batch-enable-ssh">SSH</button>
                        </div>
                    </div>
                    <div class="dropdown">
                        <button class="btn dropdown-toggle" data-bs-toggle="dropdown">
                            Regen
                        </button>
                        <div class="dropdown-menu">
                            <button class="dropdown-item" data-action="batch-regen-keys">Claves</button>
                            <button class="dropdown-item" data-action="regenerar-cautivos">Clientes</button>
                        </div>
                    </div>
                    <div class="dropdown">
                        <button class="btn dropdown-toggle" data-bs-toggle="dropdown">
                            Misc
                        </button>
                        <div class="dropdown-menu">
                            <button class="dropdown-item" data-action="batch-download-keys">Descargar claves</button>
                            <button class="dropdown-item" data-action="importar-clientes">Importar clientes</button>
                            <button class="dropdown-item" data-action="exportar-clientes">Exportar clientes</button>
                        </div>
                    </div>
                    <div class="dropdown">
                        <button class="btn dropdown-toggle" data-bs-toggle="dropdown">
                            System
                        </button>
                        <div class="dropdown-menu">
                            <button class="dropdown-item" data-action="limpiar-cautivos">Limpiar cautivos</button>
                            <button class="dropdown-item" data-action="reset-sistema">Reset Sistema</button>
                        </div>
                    </div>
                    
                    <a href="#" class="btn btn-primary btn-6 d-sm-none btn-icon" data-bs-toggle="modal" data-bs-target="#clientModal" aria-label="Create new report">
                        <svg class="icon icon-2"><use href="#icon-plus"></use></svg>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Page Content -->
<div class="row">
    <!-- Tabla de Clientes -->
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <div class="row w-100">
                    <div class="col-md-3">
                        <input type="text" class="form-control" placeholder="Buscar por ID..." id="search">
                    </div>
                    <div class="col-md-2">
                        <select class="form-select" id="filter-plaza">
                            <option value="">plaza*</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select class="form-select" id="filter-enabled">
                            <option value="">estado*</option>
                            <option value="enabled">Activos</option>
                            <option value="disabled">Inactivos</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select class="form-select" id="filter-emp">
                            <option value="">emp*</option>
                        </select>
                    </div>

                    <div class="col-md-2 d-flex align-items-center">
                        <label class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" id="limit-8" checked />
                            <span class="form-check-label">Límite 8</span>
                        </label>
                    </div>
                </div>
            </div>
            <div class="card-table table-responsive">
                <table class="table table-vcenter">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Empresa/Plaza</th>
                            <th>Estado</th>
                            <th>SSH</th>
                            <th>Key</th>
                            <th>Regen</th>
                            <th>DL</th>
                            <th>Mounts</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="clientes-tbody"></tbody>
                </table>
                <div id="loading-bar">
                    <div class="progress progress-sm">
                        <div class="progress-bar progress-bar-indeterminate"></div>
                    </div>
                </div>
                <div id="complete-bar" style="display: none;">
                    <div class="progress progress-sm">
                        <div class="progress-bar" style="width: 100%"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal for Create/Edit Client -->
<div class="modal" id="clientModal" tabindex="-1">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="clientModalTitle">Nuevo Cliente</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="clientForm">
                    <input type="hidden" id="cliente-id">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Client ID</label>
                                <input type="text" class="form-control" id="rbfid" maxlength="5" pattern="[a-z0-9]{5}" required placeholder="abcde">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Empresa (EMP)</label>
                                <div class="position-relative">
                                    <input type="text" class="form-control" id="emp" maxlength="3" placeholder="ABC" autocomplete="off">
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Plaza</label>
                                <div class="position-relative">
                                    <input type="text" class="form-control" id="plaza" maxlength="5" placeholder="PLAZA" autocomplete="off">
                                </div>
                            </div>
                        </div>
                    </div>
                    <input type="hidden" id="enabled" value="true">
                </form>
            </div>
            <div class="modal-footer">
                <div class="me-auto" id="modalActions">
                    <!-- Delete button will be here -->
                </div>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="save-client-btn" form="clientForm">Guardar</button>
            </div>
        </div>
    </div>
</div>

<script type="module" src="./js/main.js"></script>

<!-- Modal de Overlays -->
<div class="modal fade" id="overlaysModal" tabindex="-1" aria-labelledby="overlaysModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-fullscreen">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="overlaysModalLabel">Overlays del Cliente <span class="badge bg-azure-lt" id="modal-cliente-badge">-</span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <!-- Formulario de Agregar Overlay -->
        <div class="card mb-4" id="add-overlay-form">
            <div class="card-header">
                <h3 class="card-title">Agregar Nuevo Overlay</h3>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-5">
                        <label class="form-label">Origen (Host)</label>
                        <div class="position-relative">
                            <input type="text" class="form-control" id="overlay-src" placeholder="/ruta/en/host" autocomplete="off">
                        </div>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">Destino (Cliente)</label>
                        <div class="position-relative">
                            <input type="text" class="form-control" id="overlay-dst" placeholder="nombre_directorio" autocomplete="off">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Modo</label>
                        <div class="form-selectgroup">
                            <label class="form-selectgroup-item">
                                <input type="radio" name="overlay-mode" value="ro" class="form-selectgroup-input" checked>
                                <span class="form-selectgroup-label">Lectura</span>
                            </label>
                            <label class="form-selectgroup-item">
                                <input type="radio" name="overlay-mode" value="rw" class="form-selectgroup-input">
                                <span class="form-selectgroup-label">Escritura</span>
                            </label>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Permisos Destino</label>
                        <div class="form-selectgroup">
                            <label class="form-selectgroup-item">
                                <input type="radio" name="overlay-dst-perms" value="default" class="form-selectgroup-input" checked>
                                <span class="form-selectgroup-label" title="root:root 0755">Predeterminado</span>
                            </label>
                            <label class="form-selectgroup-item">
                                <input type="radio" name="overlay-dst-perms" value="exclusive" class="form-selectgroup-input">
                                <span class="form-selectgroup-label" title="Exclusivo del cliente (0750)">Exclusivo</span>
                            </label>
                            <label class="form-selectgroup-item">
                                <input type="radio" name="overlay-dst-perms" value="group" class="form-selectgroup-input">
                                <span class="form-selectgroup-label" title="Compartido con grupo users (0750)">Grupo</span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-footer d-flex justify-content-end">
                <button class="btn btn-primary" id="add-overlay-btn" disabled>Agregar</button>
            </div>
        </div>

        <!-- Tabla de Overlays -->
        <div class="card">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <h3 class="card-title">
                        <span class="badge bg-azure-lt text-azure-lt-fg" id="overlay-count">0</span>
                        Overlays del Cliente
                    </h3>
                    <div>
                        <button class="btn btn-primary btn-sm" id="sync-btn" disabled>
                            <svg class="icon me-1"><use href="#icon-refresh"></use></svg>
                            Alinear
                        </button>
                    </div>
                </div>
            </div>
            <div class="card-table table-responsive">
                <table class="table table-vcenter">
                    <thead>
                        <tr>
                            <th>Destino</th>
                            <th>Origen</th>
                            <th>Modo</th>
                            <th>Tipo</th>
                            <th>Última Sinc.</th>
                            <th class="text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="mounts-tbody">
                        <tr><td colspan="6" class="text-center text-muted">Selecciona un cliente</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Importar Clientes -->
<button id="btn-open-import-modal" data-bs-toggle="modal" data-bs-target="#importModal" style="display:none;"></button>
<div class="modal fade" id="importModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Importar Clientes</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="importForm">
            <div class="mb-3">
                <label class="form-label">Formato: rbfid emp plaza (un cliente por línea)</label>
                <textarea class="form-control" id="import-textarea" rows="10" placeholder="roton siv xalap&#10;xicoc cha xalap&#10;test1 emp1 plaz1" style="font-family: monospace;" required></textarea>
                <div class="form-text">
                    <strong>rbfid:</strong> 5 caracteres alfanuméricos (ej: roton)<br>
                    <strong>emp:</strong> 3 caracteres (ej: siv)<br>
                    <strong>plaza:</strong> 5 caracteres (ej: xalap)
                </div>
            </div>
            <div id="import-errors" class="alert alert-danger d-none mb-3"></div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary" id="btn-importar" form="importForm">Importar</button>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/ui/footer.php'; ?>
