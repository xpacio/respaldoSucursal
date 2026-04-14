<?php
// Permisos page
$pageTitle = 'GIS - Permisos';
$activePage = 'permisos';
$pageHeader = 'Permisos / Home';
$pagePretitle = 'Gestión';

require_once __DIR__ . '/ui/header.php';
?>

<!-- Page Header with Client Selector -->
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
                    <select class="form-select form-select-sm w-auto" id="cliente-select">
                        <option value="">Seleccionar cliente...</option>
                    </select>
                    <button class="btn btn-primary btn-sm" id="btn-analizar" disabled>Analizar</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Page Content -->
<div class="row">
    <!-- Panel 1: Carpetas del Home -->
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Carpetas Home</h3>
                <span class="badge bg-azure-lt" id="folder-count">0</span>
            </div>
            <div class="card-table table-responsive" style="max-height: 500px; overflow-y: auto;">
                <table class="table table-vcenter table-sm">
                    <thead><tr><th>Carpeta</th><th>Mode</th><th>Owner</th><th>Perms</th><th>Status</th></tr></thead>
                    <tbody id="carpetas-tbody">
                        <tr><td colspan="6" class="text-center text-muted">Seleccione cliente</td></tr>
                </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Panel 2 y 3: Vertical -->
    <div class="col-lg-7">
        <!-- Panel 2: Archivos de carpeta -->
        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title">
                    <span class="badge bg-azure-lt me-1" id="panel-badge">-</span>
                    <span id="panel-title">Archivos</span>
                </h3>
            </div>
            <div class="card-table table-responsive" style="max-height: 300px; overflow-y: auto;">
                <table class="table table-vcenter table-sm">
                    <thead><tr><th>Nombre</th><th>Tipo</th><th>Actual</th><th>Objetivo</th><th>Owner</th><th>Tamaño</th></tr></thead>
                    <tbody id="archivos-tbody">
                        <tr><td colspan="6" class="text-center text-muted">Seleccione carpeta</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Panel 3: Subdirectorio -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">
                    <span class="badge bg-orange-lt me-1" id="subdir-badge">-</span>
                    <span id="subdir-title">Subcarpeta</span>
                </h3>
            </div>
            <div class="card-table table-responsive" style="max-height: 300px; overflow-y: auto;">
                <table class="table table-vcenter table-sm">
                    <thead><tr><th>Nombre</th><th>Tipo</th><th>Actual</th><th>Objetivo</th><th>Owner</th><th>Tamaño</th></tr></thead>
                    <tbody id="subdir-tbody">
                        <tr><td colspan="6" class="text-center text-muted">Click carpeta en panel 2</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="card-footer">
                <button class="btn btn-success btn-sm" id="btn-normalizar" disabled>Normalizar</button>
            </div>
        </div>
    </div>
</div>

<script type="module">
import { api } from './js/apiService.js';
import { ui } from './js/ui.js';

// Permisos-specific JavaScript

let currentClientId = null;
let currentFolder = null;
let permisosData = {};

async function init() {
    const data = await api.getClientes();
    const select = document.getElementById('cliente-select');
    select.innerHTML = '<option value="">Seleccionar cliente...</option>';
    (data.clientes || []).forEach(c => {
        const opt = document.createElement('option');
        opt.value = c.rbfid;
        opt.textContent = c.rbfid + ' - ' + (c.emp || '') + '/' + (c.plaza || '');
        select.appendChild(opt);
    });
    
    // Event listeners (replaces inline onclick/onchange)
    document.getElementById('cliente-select').addEventListener('change', loadCliente);
    document.getElementById('btn-analizar').addEventListener('click', analizarHome);
    document.getElementById('btn-normalizar').addEventListener('click', normalizarCarpeta);
    
    // Event delegation for dynamic rows
    document.getElementById('carpetas-tbody').addEventListener('click', (e) => {
        const row = e.target.closest('tr[data-action="select-folder"]');
        if (row) seleccionarCarpeta(row.dataset.path);
    });
    document.getElementById('archivos-tbody').addEventListener('click', (e) => {
        const row = e.target.closest('tr[data-action="select-file"]');
        if (row) seleccionarArchivo(row.dataset.name);
    });
}

async function loadCliente() {
    currentClientId = document.getElementById('cliente-select').value;
    document.getElementById('btn-analizar').disabled = !currentClientId;
    
    if (!currentClientId) {
        document.getElementById('carpetas-tbody').innerHTML = '<tr><td colspan="6" class="text-center text-muted">Seleccione cliente</td></tr>';
        return;
    }
    
    document.getElementById('carpetas-tbody').innerHTML = '<tr><td colspan="6" class="py-4 px-5"><div class="progress progress-sm"><div class="progress-bar progress-bar-indeterminate" style="width:100%; background:#0d6efd"></div></div></td></tr>';
    try {
        const data = await api.getPermisos(currentClientId);
        permisosData = data.permisos || { carpetas: [], archivos: [] };
        renderCarpetas(permisosData.carpetas);
    } catch(e) {
        document.getElementById('carpetas-tbody').innerHTML = '<tr><td colspan="6" class="text-center text-danger">Error: ' + (e.message || 'Error al cargar') + '</td></tr>';
    }
}

function renderCarpetas(carpetas) {
    document.getElementById('folder-count').textContent = carpetas.length;
    
    if (carpetas.length === 0) {
        document.getElementById('carpetas-tbody').innerHTML = '<tr><td colspan="6" class="text-center text-muted">Sin carpetas</td></tr>';
        return;
    }
    
    document.getElementById('carpetas-tbody').innerHTML = carpetas.map(c => `
        <tr data-action="select-folder" data-path="${c.path}" style="cursor:pointer" class="${currentFolder === c.path ? 'table-active' : ''}">
            <td>${c.path}</td>
            <td>${c.mode}</td>
            <td>${c.owner}</td>
            <td>${c.perms}</td>
            <td><span class="badge ${c.status === 'ok' ? 'bg-success' : 'bg-warning'}">${c.status}</span></td>
        </tr>
    `).join('');
}

function seleccionarCarpeta(path) {
    currentFolder = path;
    renderCarpetas(permisosData.carpetas);
    
    document.getElementById('panel-badge').textContent = currentClientId;
    document.getElementById('panel-title').textContent = path;
    
    const archivos = permisosData.archivos[path] || [];
    renderArchivos(archivos);
}

function renderArchivos(archivos) {
    if (archivos.length === 0) {
        document.getElementById('archivos-tbody').innerHTML = '<tr><td colspan="6" class="text-center text-muted">Sin archivos</td></tr>';
        return;
    }
    
    document.getElementById('archivos-tbody').innerHTML = archivos.map(a => `
        <tr data-action="select-file" data-name="${a.name}" style="cursor:pointer">
            <td>${a.name}</td>
            <td>${a.type}</td>
            <td>${a.perms}</td>
            <td>${a.perms}</td>
            <td>${a.owner}</td>
            <td>${a.size || 'N/A'}</td>
        </tr>
    `).join('');
}

function seleccionarArchivo(name) {
    document.getElementById('subdir-badge').textContent = name;
    document.getElementById('subdir-title').textContent = 'Detalle';
    
    const archivos = permisosData.archivos[currentFolder] || [];
    const archivo = archivos.find(a => a.name === name);
    
    if (archivo) {
        document.getElementById('subdir-tbody').innerHTML = `
            <tr>
                <td colspan="6">
                    <pre>${JSON.stringify(archivo, null, 2)}</pre>
                </td>
            </tr>
        `;
        document.getElementById('btn-normalizar').disabled = false;
    }
}

async function analizarHome() {
    if (!currentClientId) return;
    loadCliente();
}

async function normalizarCarpeta() {
    if (!currentClientId || !currentFolder) return;
    
    try {
        // Implementar normalización según API
        alert('Función de normalización no implementada');
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

document.addEventListener('DOMContentLoaded', init);
</script>

<?php require_once __DIR__ . '/ui/footer.php'; ?>
