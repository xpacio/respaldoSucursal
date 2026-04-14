<?php
// Logs page
$pageTitle = 'GIS - Logs';
$activePage = 'logs';
$pageHeader = 'Logs / Ejecuciones';
$pagePretitle = 'Sistema';
$extraCss = '@media (max-width: 768px) { #navbar-menu { display: block !important; } .navbar-toggler { display: none !important; } #navbar-menu .navbar-nav { flex-direction: row !important; } }';

require_once __DIR__ . '/ui/header.php';
?>

<!-- Page Header with Limit Selector -->
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
                <div class="d-flex gap-2 align-items-center">
                    <select class="form-select form-select-sm w-auto" id="limit-select">
                        <option value="10" selected>Últimos 10</option>
                        <option value="20">Últimos 20</option>
                        <option value="50">Últimos 50</option>
                        <option value="100">Últimos 100</option>
                    </select>
                    <button class="btn btn-outline-secondary btn-sm" data-action="load-logs">Actualizar</button>
                    <button class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#purgeModal">
                        <svg class="icon"><use href="#icon-trash"></use></svg>
                        Purge
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Page Content -->
<div class="row">
    <!-- Panel 1: Executions -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Ejecuciones</h3>
                <span class="badge bg-azure-lt" id="exec-count">0</span>
            </div>
            <div class="card-table table-responsive" style="max-height: 500px; overflow-y: auto;">
                <table class="table table-vcenter table-sm">
                    <thead><tr><th>Cliente</th><th>Acción</th><th>Estado</th><th>Inicio</th></tr></thead>
                    <tbody id="logs-tbody">
                        <tr><td colspan="4" class="py-4 px-5"><div class="progress progress-sm"><div class="progress-bar progress-bar-indeterminate" style="width:100%; background:#0d6efd"></div></div></td></tr>
                    </tbody>
                </table>
            </div>
    	</div>
    </div>
    <!-- Panel 2: Execution Steps -->
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">
                    <span class="text-orange me-1" id="exec-badge">-</span>
                    <span id="exec-title">Pasos</span>
                </h3>
            </div>
            <div class="card-table table-responsive" style="max-height: 500px; overflow-y: auto;">
                <table class="table table-vcenter table-sm">
                    <thead><tr><th class="w-1">Código</th><th class="w-70">Mensaje</th><th class="w-20">Fecha</th></tr></thead>
                    <tbody id="steps-tbody">
                        <tr><td colspan="3" class="text-center text-muted">Seleccione ejecución</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
/* Mejoras para mensajes largos en logs */
#steps-tbody td:nth-child(2) {
    word-wrap: break-word;
    white-space: normal;
    max-width: none;
    min-width: 300px;
}

.table-sm td {
    padding: 0.3rem 0.5rem;
    font-size: 0.875rem;
}

.w-1 { width: 1%; }
.w-80 { width: 80%; }
</style>

<script type="module">
import { api } from './js/apiService.js';
import { ui } from './js/ui.js';

// Logs-specific JavaScript

let selectedExecution = null;
let currentLogs = [];

async function loadLogs() {
    const limit = document.getElementById('limit-select').value;
    try {
        const data = await api.getLogs(limit);
        console.log("Logs data:", data);
        currentLogs = data.logs || [];

        document.getElementById('exec-count').textContent = currentLogs.length;
    
        // Reset panel 2
        document.getElementById('exec-badge').textContent = '-';
        document.getElementById('exec-title').textContent = 'Pasos';
        document.getElementById('steps-tbody').innerHTML = '<tr><td colspan="3" class="text-center text-muted">Seleccione ejecución</td></tr>';

        if (currentLogs.length === 0) {
            document.getElementById('logs-tbody').innerHTML = 
                '<tr><td colspan="4" class="text-center text-muted">Sin logs</td></tr>';
            return;
        }

        const html = currentLogs.map(log => {
            const statusClass = log.status === 'success' ? 'bg-success-lt' : 
                               log.status === 'failed' ? 'bg-danger-lt' : 'bg-warning-lt';
            const started = new Date(log.started_at).toLocaleString();
            const isSelected = selectedExecution === log.id;
            
            return `<tr class="${isSelected ? 'table-active' : ''}" data-action="select-execution" data-id="${log.id}" style="cursor:pointer">
                <td><strong>${log.rbfid}</strong></td>
                <td>${log.action}</td>
                <td><span class="badge ${statusClass}">${log.status}</span></td>
                <td><span class="text-xs text-muted">${started}</span></td>
            </tr>`;
        }).join('');

        document.getElementById('logs-tbody').innerHTML = html;
    } catch (error) {
        console.error('Error loading logs:', error);
        document.getElementById('logs-tbody').innerHTML = 
            '<tr><td colspan="4" class="text-center text-danger">Error al cargar logs</td></tr>';
        ui.showErrorToast('Error al cargar logs: ' + error.message);
    }
}

async function seleccionarEjecucion(id) {
    selectedExecution = id;
    
    // Update row selection visually using data-id
    document.querySelectorAll('#logs-tbody tr').forEach(row => {
        const rowId = row.dataset.id;
        row.classList.toggle('table-active', rowId === id);
    });
    
    const log = currentLogs.find(l => l.id === id);
    if (log) {
        document.getElementById('exec-badge').textContent = log.rbfid;
        document.getElementById('exec-title').textContent = log.action;
        
        document.getElementById('steps-tbody').innerHTML = '<tr><td colspan="3" class="py-4 px-5"><div class="progress progress-sm"><div class="progress-bar progress-bar-indeterminate" style="width:100%; background:#0d6efd"></div></div></td></tr>';
        
        try {
            const data = await api.getEjecucion(id);
            renderPasos(data.pasos || []);
        } catch (e) {
            document.getElementById('steps-tbody').innerHTML = '<tr><td colspan="3" class="text-center text-danger">Error: ' + e.message + '</td></tr>';
        }
    }
}

function renderPasos(pasos) {
    if (pasos.length === 0) {
        document.getElementById('steps-tbody').innerHTML = '<tr><td colspan="3" class="text-center text-muted">Sin pasos</td></tr>';
        return;
    }
    
    const html = pasos.map(paso => {
        let codeClass = '';
        let codeBadge = 'bg-secondary-lt';
        
        if (paso.step_code === 9999) {
            codeClass = 'text-danger';
            codeBadge = 'bg-danger-lt';
        } else if (paso.step_code >= 9000) {
            codeClass = 'text-success';
            codeBadge = 'bg-success-lt';
        } else if (paso.step_code >= 8000) {
            codeClass = 'text-warning';
            codeBadge = 'bg-warning-lt';
        } else if (paso.step_code >= 1000) {
            codeClass = 'text-info';
        }
        
        const fecha = paso.created_at ? new Date(paso.created_at).toLocaleString() : '-';
        
        return `<tr>
            <td><span class="badge ${codeBadge} ${codeClass}">${paso.step_code}</span></td>
            <td><span class="${codeClass}">${paso.step_message}</span></td>
            <td><span class="text-xs text-muted">${fecha}</span></td>
        </tr>`;
    }).join('');
    
    document.getElementById('steps-tbody').innerHTML = html;
}

// Event delegation for all actions
document.addEventListener('DOMContentLoaded', function() {
    loadLogs();
    
    // Handle limit-select change
    const limitSelect = document.getElementById('limit-select');
    if (limitSelect) {
        limitSelect.addEventListener('change', loadLogs);
    }
    
    // Handle all click events with data-action
    document.addEventListener('click', async (e) => {
        const target = e.target.closest('[data-action]');
        if (!target) return;
        
        const action = target.dataset.action;
        const id = target.dataset.id;
        
        try {
            switch (action) {
                case 'load-logs':
                    await loadLogs();
                    break;
                case 'select-execution':
                    await seleccionarEjecucion(id);
                    break;
                case 'purge-logs':
                    await handlePurgeLogs();
                    break;
            }
        } catch (error) {
            console.error('Error handling action:', action, error);
            ui.showErrorToast(error.message);
        }
    });
    
    // Log when purge modal is opened
    const purgeModal = document.getElementById('purgeModal');
    if (purgeModal) {
        purgeModal.addEventListener('show.bs.modal', function() {
            api.logUIAction('open-purge-modal', { page: 'logs' });
        });
    }
});

// Purge functionality
async function handlePurgeLogs() {
    const preset = document.querySelector('input[name="purge-preset"]:checked')?.value || '3d';
    const sourceType = document.getElementById('purgeSourceType')?.value || 'all';
    const rbfid = document.getElementById('purgeRbfid')?.value.trim() || '';
    
    let olderThan = '';
    const now = new Date();
    
    if (preset === 'all') {
        const tomorrow = new Date(now);
        tomorrow.setDate(now.getDate() + 1);
        olderThan = tomorrow.toISOString().split('T')[0];
    } else {
        const date = new Date(now);
        if (preset === '1d') date.setDate(now.getDate() - 1);
        else if (preset === '3d') date.setDate(now.getDate() - 3);
        else if (preset === '1w') date.setDate(now.getDate() - 7);
        else if (preset === '1m') date.setMonth(now.getMonth() - 1);
        olderThan = date.toISOString().split('T')[0];
    }

    if (!confirm(`¿Está seguro de purgar logs ${sourceType !== 'all' ? 'de fuente ' + sourceType + ' ' : ''}${rbfid ? 'del usuario ' + rbfid + ' ' : ''}anteriores a ${olderThan}? Esta acción no se puede deshacer.`)) {
        return;
    }
    
    const purgeBtn = document.getElementById('purgeConfirmBtn');
    const originalText = purgeBtn.innerHTML;
    purgeBtn.disabled = true;
    purgeBtn.innerHTML = '<div class="progress progress-sm" style="width:60px;height:3px;display:inline-block;vertical-align:middle"><div class="progress-bar progress-bar-indeterminate" style="background:#0d6efd"></div></div>';
    purgeBtn.disabled = true;
    
    try {
        const result = await api.purgeLogs(olderThan, sourceType, rbfid);
        if (result.ok) {
            alert('Logs purgados correctamente.');
            bootstrap.Modal.getInstance(document.getElementById('purgeModal')).hide();
            loadLogs();
        } else {
            alert('Error al purgar logs: ' + (result.error || 'Error desconocido'));
        }
    } catch (error) {
        alert('Error al purgar logs: ' + error.message);
    } finally {
        purgeBtn.innerHTML = originalText;
        purgeBtn.disabled = false;
    }
}
</script>

<!-- Purge Modal -->
<div class="modal modal-blur fade" id="purgeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Purgar Logs</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Purgar logs:</label>
                    <div class="form-selectgroup">
                        <label class="form-selectgroup-item">
                            <input type="radio" name="purge-preset" value="1d" class="form-selectgroup-input">
                            <span class="form-selectgroup-label">1 Día</span>
                        </label>
                        <label class="form-selectgroup-item">
                            <input type="radio" name="purge-preset" value="3d" class="form-selectgroup-input" checked>
                            <span class="form-selectgroup-label">3 Días</span>
                        </label>
                        <label class="form-selectgroup-item">
                            <input type="radio" name="purge-preset" value="1w" class="form-selectgroup-input">
                            <span class="form-selectgroup-label">1 Semana</span>
                        </label>
                        <label class="form-selectgroup-item">
                            <input type="radio" name="purge-preset" value="1m" class="form-selectgroup-input">
                            <span class="form-selectgroup-label">1 Mes</span>
                        </label>
                        <label class="form-selectgroup-item">
                            <input type="radio" name="purge-preset" value="all" class="form-selectgroup-input">
                            <span class="form-selectgroup-label">TODO</span>
                        </label>
                    </div>
                    <div class="form-text">Los logs anteriores al período seleccionado serán eliminados permanentemente.</div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Tipo de fuente:</label>
                    <select class="form-select" id="purgeSourceType">
                        <option value="all">Todos (UI y api)</option>
                        <option value="ui">Solo UI</option>
                        <option value="api">Solo api</option>
                        <option value="system">Solo Sistema</option>
                    </select>
                    <div class="form-text">Filtrar por origen de los logs.</div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Usuario (rbfid):</label>
                    <div class="position-relative">
                        <input type="text" class="form-control" id="purgeRbfid" placeholder="Ej: u1, api, guest, system" maxlength="5" autocomplete="off">
                    </div>
                    <div class="form-text">Dejar vacío para todos los usuarios. Máximo 5 caracteres.</div>
                </div>
                
                <div class="alert alert-warning">
                    <div class="d-flex">
                        <div>
                            <svg class="icon alert-icon"><use href="#icon-alert-triangle"></use></svg>
                        </div>
                        <div>
                            <h4 class="alert-title">¡Atención!</h4>
                            <div class="text-secondary">Esta acción eliminará permanentemente los logs seleccionados. No se puede deshacer.</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-danger" id="purgeConfirmBtn" data-action="purge-logs">Purgar Logs</button>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/ui/footer.php'; ?>

<script type="module">
import { AutocompleteInput } from './js/modules/autocomplete.js';
new AutocompleteInput('purgeRbfid', { table: 'clients', column: 'rbfid' });
</script>
