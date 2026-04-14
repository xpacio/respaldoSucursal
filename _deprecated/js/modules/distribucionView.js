import { api, ICONS } from '../apiService.js';
import { AutocompleteInput } from './autocomplete.js';
import { debounce } from '../utils/debounce.js';
import { toast } from '../utils/toast.js';
import { formatSize } from '../utils/formatSize.js';
import { LOADING } from '../utils/loading.js';

let currentDistId = null;
let cachedDistIds = [];
let debouncedLoad = null;
let cachedTipos = [];

function showModalAlert(type, title, description = '') {
    const modalBody = document.querySelector('#editModal .modal-body');
    if (!modalBody) return;
    
    const existing = modalBody.querySelector('.dist-alert');
    if (existing) existing.remove();

    const alertDiv = document.createElement('div');
    alertDiv.className = `dist-alert alert alert-${type} mb-3`;
    
    const icons = {
        success: '<svg class="icon alert-icon"><use href="#icon-check"></use></svg>',
        danger: '<svg class="icon alert-icon"><use href="#icon-alert-circle"></use></svg>',
        warning: '<svg class="icon alert-icon"><use href="#icon-alert-triangle"></use></svg>',
        info: '<svg class="icon alert-icon"><use href="#icon-info-circle"></use></svg>'
    };

    alertDiv.innerHTML = `
        <div class="d-flex align-items-start gap-2">
            ${icons[type] || icons.info}
            <div>
                <h4 class="alert-heading mb-1">${title}</h4>
                ${description ? `<div class="text-muted small">${description}</div>` : ''}
            </div>
        </div>
    `;

    const form = modalBody.querySelector('form');
    if (form) {
        form.insertBefore(alertDiv, form.firstChild);
    } else {
        modalBody.insertBefore(alertDiv, modalBody.firstChild);
    }
}

// ─── Lista principal ───

async function loadDistribuciones() {
    const tbody = document.getElementById('dist-tbody');
    tbody.innerHTML = 'xx';

    const nombre = document.getElementById('filter-nombre')?.value?.trim() || '';
    const tipo = document.getElementById('filter-tipo')?.value?.trim() || '';
    const plaza = document.getElementById('filter-plaza')?.value || '';
    const origen = document.getElementById('filter-origen')?.value?.trim() || '';

    const filters = {};
    if (nombre) filters.nombre = nombre;
    if (tipo) filters.tipo = tipo;
    if (plaza) filters.plaza = plaza;
    if (origen) filters.origen = origen;

    const limit8 = document.getElementById('limit-8');
    if (limit8 && limit8.checked) filters.limit = 8;

    const data = await api.distribucion({ action: 'list', ...filters });
    if (!data.ok) { tbody.innerHTML = '<tr><td colspan="8" class="text-danger">Error</td></tr>'; return; }

    cachedDistIds = data.distribuciones.map(d => d.id);

    if (data.distribuciones.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-muted text-center p-3">Sin distribuciones</td></tr>';
        return;
    }

    tbody.innerHTML = data.distribuciones.map(d => `
        <tr>
            <td><strong>${d.nombre}</strong></td>
            <td>${d.tipo}</td>
            <td>${d.plaza}</td>
            <td><small class="text-muted">${d.src_path}</small></td>
            
            
            <td><span class="badge bg-blue-lt">${d.total_clientes || 0}</span></td>
            <td class="text-center">
                <div class="btn-actions">
                    <button class="mx-1 btn btn-action bg-teal-lt" data-bs-toggle="modal" data-bs-target="#clientesModal" data-id="${d.id}" title="Clientes">
                        <svg class="icon"><use href="#icon-store"></use></svg>
                    </button>
                    <button class="mx-1 btn btn-action bg-blue-lt" data-detail="${d.id}" title="Detalle">
                        <svg class="icon"><use href="#icon-ajustes"></use></svg>
                    </button>
                    <button class="mx-1 btn btn-action bg-yellow-lt" data-bs-toggle="modal" data-bs-target="#editModal" data-edit="${d.id}" title="Editar">
                        <svg class="icon"><use href="#icon-pencil"></use></svg>
                    </button>
                    <button class="mx-1 btn btn-action bg-red-lt" data-del="${d.id}" title="Eliminar">
                        <svg class="icon icon-sm"><use href="#icon-trash"></use></svg>
                    </button>
                </div>
            </td>
        </tr>
    `).join('');

    tbody.querySelectorAll('[data-detail]').forEach(btn => {
        btn.addEventListener('click', () => openDetail(parseInt(btn.dataset.detail)));
    });
    tbody.querySelectorAll('[data-edit]').forEach(btn => {
        btn.addEventListener('click', () => openEdit(parseInt(btn.dataset.edit)));
    });
    tbody.querySelectorAll('[data-del]').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!confirm('¿Eliminar esta distribución?')) return;
            await api.distribucion({ action: 'delete', id: parseInt(btn.dataset.del) });
            toast('Eliminada');
            loadDistribuciones();
        });
    });
}

// ─── Crear/Editar ───

async function loadTiposSelect() {
    const select = document.getElementById('edit-tipo');
    if (!select) return;

    const data = await api.distribucion({ action: 'tipos' });
    if (!data.ok) return;

    cachedTipos = data.tipos || [];
    const current = select.value;
    select.innerHTML = '<option value="">Seleccionar perfil...</option>';
    cachedTipos.forEach(t => {
        const opt = document.createElement('option');
        opt.value = t.tipo;
        opt.textContent = t.tipo;
        select.appendChild(opt);
    });
    if (current) select.value = current;
}

function updatePerfilPreview(tipo) {
    const preview = document.getElementById('perfil-preview');
    const perfil = cachedTipos.find(t => t.tipo === tipo);
    if (perfil) {
        document.getElementById('preview-files').textContent = perfil.files;
        document.getElementById('preview-dst').textContent = perfil.dst_template;
        preview.classList.remove('d-none');
    } else {
        preview.classList.add('d-none');
    }
}

export async function openEdit(id = null) {
    document.getElementById('edit-id').value = id || '';
    document.getElementById('editModalTitle').textContent = id ? 'Editar Distribución' : 'Nueva Distribución';
    document.querySelector('#editModal .dist-alert')?.remove();

    await loadTiposSelect();

    if (id) {
        const data = await api.distribucion({ action: 'get', id });
        if (data.ok) {
            const d = data.distribucion;
            document.getElementById('edit-nombre').value = d.nombre || '';
            document.getElementById('edit-tipo').value = d.tipo || '';
            document.getElementById('edit-plaza').value = d.plaza || '';
            document.getElementById('edit-src').value = d.src_path || '';
            updatePerfilPreview(d.tipo);
        }
    } else {
        document.getElementById('edit-nombre').value = '';
        document.getElementById('edit-tipo').value = '';
        document.getElementById('edit-plaza').value = '';
        document.getElementById('edit-src').value = '';
        updatePerfilPreview('');
    }
}

async function saveDist(e) {
    e.preventDefault();
    
    const id = document.getElementById('edit-id').value;
    const action = id ? 'update' : 'create';
    
    let srcPath = document.getElementById('edit-src').value.trim();
    if (srcPath && !srcPath.startsWith('/')) {
        srcPath = '/srv/' + srcPath;
    }

    const result = await api.distribucion({
        action,
        id: id ? parseInt(id) : undefined,
        nombre: document.getElementById('edit-nombre').value.trim(),
        tipo: document.getElementById('edit-tipo').value,
        plaza: document.getElementById('edit-plaza').value.trim(),
        src_path: srcPath
    });

    if (result.ok) {
        showModalAlert('success', id ? '¡Actualizada!' : '¡Creada!', id ? 'Distribución actualizada correctamente' : 'Distribución creada correctamente');
        setTimeout(() => {
            document.querySelector('#editModal .btn-close').click();
            loadDistribuciones();
            loadOptions();
        }, 1000);
    } else {
        showModalAlert('danger', 'Error', result.error || 'No se pudo guardar la distribución');
    }
}

// ─── Detalle ───

async function openDetail(id) {
    currentDistId = id;
    const data = await api.distribucion({ action: 'get', id });
    if (!data.ok) return;

    document.getElementById('detailModalTitle').textContent = `${data.distribucion.tipo} ${data.distribucion.plaza}`;
    document.getElementById('btn-open-detail-modal').click();

    loadClientes(data.distribucion);
}

async function loadClientes(dist, containerId = 'clientes-list') {
    const list = document.getElementById(containerId);
    list.innerHTML = LOADING;

    const plaza = dist.plaza;
    const id = dist.id;
    const data = await api.distribucion({ action: 'clientes_plaza', plaza, id });
    
    if (!data.ok) {
        list.innerHTML = '<div class="text-danger text-center p-3">Error: ' + (data.error || 'Desconocido') + '</div>';
        return;
    }

    if (data.clientes.length === 0) {
        list.innerHTML = `<div class="text-muted text-center p-3">No hay clientes en plaza "${plaza}"</div>`;
        return;
    }

    list.innerHTML = `<div class="form-selectgroup">` + 
        data.clientes.map(c => `
            <label class="form-selectgroup-item">
                <input type="checkbox" class="form-selectgroup-input" 
                       data-rbfid="${c.rbfid}" 
                       ${c.in_dist ? 'checked' : ''}>
                <span class="form-selectgroup-label">${c.rbfid}</span>
            </label>
        `).join('') + 
        `</div>`;

    list.querySelectorAll('[data-rbfid]').forEach(checkbox => {
        checkbox.addEventListener('change', async (e) => {
            const rbfid = e.target.dataset.rbfid;
            const action = e.target.checked ? 'add_cliente' : 'remove_cliente';
            const result = await api.distribucion({ action, id, rbfid });
            if (result.ok) {
                toast(e.target.checked ? 'Cliente agregado' : 'Cliente removido');
                loadDistribuciones();
            } else {
                e.target.checked = !e.target.checked;
                toast(result.error || 'Error', 'danger');
            }
        });
    });
}

async function openClientesModal(id) {
    const data = await api.distribucion({ action: 'get', id });
    if (!data.ok) return;

    const d = data.distribucion;
    document.getElementById('clientesModalTitle').textContent = `Clientes — ${d.nombre} (${d.plaza})`;
    loadClientes(d, 'clientes-modal-list');
    new bootstrap.Modal(document.getElementById('clientesModal')).show();
}

async function loadVersiones(id) {
    const list = document.getElementById('versiones-list');
    list.innerHTML = LOADING;

    const data = await api.distribucion({ action: 'versiones', id });
    if (!data.ok) return;

    if (data.versiones.length === 0) {
        list.innerHTML = '<div class="text-muted text-center p-3">Sin versiones</div>';
        return;
    }

    list.innerHTML = data.versiones.map(v => `
        <div class="list-group-item d-flex justify-content-between align-items-center py-1 px-2">
            <span>${v.nombre_archivo}</span>
            <span class="badge bg-blue-lt">v${v.version}</span>
            <code class="text-muted" style="font-size:0.75em">${v.xxh3.substring(0, 12)}...</code>
            <small class="text-muted">${formatSize(v.peso)}</small>
            <small class="text-muted">${v.fecha}</small>
        </div>
    `).join('');
}

async function loadErrores(id) {
    const list = document.getElementById('errores-list');
    list.innerHTML = LOADING;

    const data = await api.distribucion({ action: 'errores', id });
    if (!data.ok) return;

    if (data.errores.length === 0) {
        list.innerHTML = '<div class="text-success text-center p-3">✅ Sin errores</div>';
        return;
    }

    list.innerHTML = data.errores.map(e => `
        <div class="list-group-item d-flex justify-content-between align-items-center py-1 px-2">
            <span><strong>${e.cliente_rbfid}</strong></span>
            <small class="text-muted">${e.mensaje || 'Error ' + e.exit_code}</small>
            <small class="text-muted">${e.dst_path}</small>
            ${!e.resuelto ? `<button class="btn btn-action bg-green-lt" data-resolve="${e.id}" title="Resolver">
                <svg class="icon icon-sm"><use href="#icon-circle-check"></use></svg>
            </button>` : '<span class="badge bg-green-lt">Resuelto</span>'}
        </div>
    `).join('');

    list.querySelectorAll('[data-resolve]').forEach(btn => {
        btn.addEventListener('click', async () => {
            await api.distribucion({ action: 'resolver', error_id: parseInt(btn.dataset.resolve) });
            toast('Resuelto');
            loadErrores(id);
        });
    });
}

async function loadEjecuciones(id) {
    const list = document.getElementById('ejecuciones-list');
    list.innerHTML = LOADING;

    const data = await api.distribucion({ action: 'ejecuciones', id });
    if (!data.ok) return;

    if (data.ejecuciones.length === 0) {
        list.innerHTML = '<div class="text-muted text-center p-3">Sin ejecuciones</div>';
        return;
    }

    list.innerHTML = data.ejecuciones.map(e => `
        <div class="list-group-item d-flex justify-content-between align-items-center py-1 px-2">
            <span>v${e.version_actual}</span>
            <span class="badge ${e.fallidos > 0 ? 'bg-red-lt' : 'bg-green-lt'}">${e.exitosos}/${e.total_clientes}</span>
            <small class="text-muted">${formatSize(e.peso_total_origen)} origen</small>
            <small class="text-muted">${formatSize(e.peso_total_copiados)} copiados</small>
            <small class="text-muted">${e.started_at}</small>
        </div>
    `).join('');
}

async function evaluar() {
    if (!currentDistId) return;
    const data = await api.distribucion({ action: 'evaluar', id: currentDistId });
    if (data.ok) {
        toast('Evaluación completa');
        loadVersiones(currentDistId);
    } else {
        toast(data.error || 'Error', 'danger');
    }
}

function showStatus(type, message) {
    const el = document.getElementById('status-message');
    if (!el) return;
    el.innerHTML = `<div class="alert alert-${type} mb-0 py-1 px-2">${message}</div>`;
    setTimeout(() => { el.innerHTML = ''; }, 10000);
}

async function copiar() {
    if (!currentDistId) return;
    showStatus('info', 'Iniciando copia...');
    const data = await api.distribucion({ action: 'copiar', id: currentDistId });
    if (data.ok) {
        showStatus('success', `Copia completada: ${data.exitosos}/${data.total} exitosos`);
        loadEjecuciones(currentDistId);
        loadErrores(currentDistId);
    } else {
        showStatus('danger', data.error || 'Error al ejecutar copia');
    }
}

async function copiarVista() {
    if (cachedDistIds.length === 0) {
        toast('No hay distribuciones en vista', 'warning');
        return;
    }
    if (!confirm(`Ejecutar copia de ${cachedDistIds.length} distribución(es) en vista?`)) return;

    const data = await api.distribucion({ action: 'copiar_vista', ids: cachedDistIds });
    if (data.ok) {
        toast(`Job encolado: ${data.total} distribuciones`);
        window.dispatchEvent(new CustomEvent('job-started', {
            detail: { job_id: data.job_id, job_type: 'distribuir_batch' }
        }));
    } else {
        toast(data.error || 'Error', 'danger');
    }
}

async function copiarTodas() {
    if (!confirm('Ejecutar copia de TODAS las distribuciones activas?')) return;

    const data = await api.distribucion({ action: 'copiar_todas' });
    if (data.ok) {
        toast('Job encolado: todas las distribuciones');
        window.dispatchEvent(new CustomEvent('job-started', {
            detail: { job_id: data.job_id, job_type: 'distribuir_all' }
        }));
    } else {
        toast(data.error || 'Error', 'danger');
    }
}

// ─── Options (tipos y plazas) ───

async function loadOptions() {
    try {
        const data = await api.distribucion({ action: 'options' });
        if (!data.ok) return;

        const plazaSelect = document.getElementById('filter-plaza');
        if (plazaSelect && data.plazas?.length) {
            plazaSelect.innerHTML = '<option value="">plaza*</option>';
            data.plazas.forEach(val => {
                const opt = document.createElement('option');
                opt.value = val;
                opt.textContent = val;
                plazaSelect.appendChild(opt);
            });
        }

        const tipoSelect = document.getElementById('filter-tipo');
        if (tipoSelect && data.tipos?.length) {
            tipoSelect.innerHTML = '<option value="">tipo*</option>';
            data.tipos.forEach(val => {
                const opt = document.createElement('option');
                opt.value = val;
                opt.textContent = val;
                tipoSelect.appendChild(opt);
            });
        }
    } catch (e) {
        console.error('Error cargando opciones:', e);
    }
}

function filterDistribuciones() {
    if (debouncedLoad) {
        debouncedLoad();
    }
}

function setupFilterListeners() {
    const nombreInput = document.getElementById('filter-nombre');
    const tipoSelect = document.getElementById('filter-tipo');
    const plazaSelect = document.getElementById('filter-plaza');
    const origenInput = document.getElementById('filter-origen');
    const limit8 = document.getElementById('limit-8');

    if (nombreInput) nombreInput.addEventListener('input', filterDistribuciones);
    if (tipoSelect) tipoSelect.addEventListener('change', filterDistribuciones);
    if (plazaSelect) plazaSelect.addEventListener('change', filterDistribuciones);
    if (origenInput) origenInput.addEventListener('input', filterDistribuciones);
    if (limit8) limit8.addEventListener('change', filterDistribuciones);
}

// ─── Logs (usa modal de notificaciones del header) ───

let cachedLogs = [];

function renderLogs(logs) {
    const container = document.getElementById('notification-list');
    if (!container) return;

    const filterInput = document.getElementById('log-filter');
    const filter = filterInput?.value?.toLowerCase() || '';

    const filtered = filter 
        ? logs.filter(log => 
            (log.action || '').toLowerCase().includes(filter) ||
            (log.source_type || '').toLowerCase().includes(filter) ||
            (log.status || '').toLowerCase().includes(filter) ||
            (log.rbfid || '').toLowerCase().includes(filter)
        )
        : logs;

    if (filtered.length === 0) {
        container.innerHTML = '<div class="text-muted p-3">Sin resultados</div>';
        return;
    }

    const statusClass = {
        'running': 'bg-blue',
        'success': 'bg-green',
        'error': 'bg-red'
    };

    const sourceClass = {
        'api': 'bg-azure',
        'ui': 'bg-yellow',
        'sh': 'bg-purple',
        'db': 'bg-dark'
    };

    container.innerHTML = filtered.map(log => `
        <div class="list-group-item d-flex justify-content-between align-items-center py-2">
            <div class="d-flex align-items-center gap-2">
                <span class="badge ${sourceClass[log.source_type] || 'bg-secondary'}">${log.source_type || '-'}</span>
                <span class="text-truncate" style="max-width: 300px;">${log.action || '-'}</span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="badge ${statusClass[log.status] || 'bg-secondary'}">${log.status || '-'}</span>
                <small class="text-muted">${log.rbfid || '-'}</small>
                <small class="text-muted">${log.steps_count || 0} steps</small>
                <small class="text-muted">${log.started_at ? new Date(log.started_at).toLocaleString() : '-'}</small>
            </div>
        </div>
    `).join('');
}

async function loadLogs() {
    const container = document.getElementById('notification-list');
    const modalTitle = document.querySelector('#notificationsModal .modal-title');
    const clearBtn = document.getElementById('clear-notifications-btn');
    const filterInput = document.getElementById('log-filter');
    
    if (!container) {
        console.error('notification-list not found');
        return;
    }

    if (modalTitle) modalTitle.textContent = 'Logs del Sistema';
    if (clearBtn) clearBtn.style.display = 'none';

    //container.innerHTML = '';

    const data = await api.distribucion({ action: 'logs' });
    if (!data.ok) {
        container.innerHTML = '<div class="text-danger p-3">Error cargando logs</div>';
        return;
    }

    cachedLogs = data.logs || [];
    renderLogs(cachedLogs);

    if (filterInput) {
        filterInput.oninput = () => renderLogs(cachedLogs);
    }
}

// ─── Importar ───

async function importarDist() {
    const texto = document.getElementById('import-texto').value.trim();
    const truncar = document.getElementById('import-truncar').checked;
    const resultado = document.getElementById('import-resultado');

    if (!texto) {
        toast('Ingresa al menos una línea', 'warning');
        return;
    }

    resultado.innerHTML = LOADING;

    const data = await api.distribucion({ action: 'importar', texto, truncar });
    if (!data.ok) {
        resultado.innerHTML = `<div class="alert alert-danger py-2">${data.error || 'Error'}</div>`;
        return;
    }

    const type = data.errores > 0 ? 'warning' : 'success';
    let html = `<div class="alert alert-${type} py-2">`;
    html += `<strong>${data.exitosas}</strong> importada(s), <strong>${data.errores}</strong> error(es)`;
    if (data.detalles.length > 0) {
        html += `<ul class="mb-0 mt-1" style="max-height:200px;overflow-y:auto">`;
        data.detalles.forEach(d => {
            const icon = d.includes('importada') ? '✓' : '✗';
            html += `<li class="small">${icon} ${d}</li>`;
        });
        html += `</ul>`;
    }
    html += `</div>`;
    resultado.innerHTML = html;

    if (data.exitosas > 0) {
        loadDistribuciones();
        loadTiposSelect();
    }
}

async function exportarDist() {
    const data = await api.distribucion({ action: 'exportar' });
    if (!data.ok) { toast(data.error || 'Error', 'danger'); return; }
    if (!data.texto) { toast('No hay distribuciones para exportar', 'warning'); return; }

    const blob = new Blob([data.texto + '\n'], { type: 'text/plain' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'distribuciones.txt';
    a.click();
    URL.revokeObjectURL(url);
    toast('Exportado');
}

async function scanPrecios() {
    const textarea = document.getElementById('import-texto');
    const btn = document.getElementById('btn-scan-precios');
    const incluidas = document.getElementById('scan-incluidas')?.checked ?? true;
    btn.disabled = true;
    btn.innerHTML = LOADING;

    const data = await api.distribucion({ action: 'scan_precios', incluidas });

    btn.disabled = false;
    btn.innerHTML = `<svg class="icon me-1"><use href="#icon-refresh"></use></svg>Escanear`;

    if (!data.ok) { toast(data.error || 'Error', 'danger'); return; }

    textarea.value = data.texto || '';
    toast('Escaneo completado');
}

// ─── Perfiles (tipos) ───

async function loadPerfiles() {
    const tbody = document.getElementById('perfiles-tbody');
    if (!tbody) return;
    tbody.innerHTML = LOADING;

    const data = await api.distribucion({ action: 'tipos' });
    if (!data.ok) { tbody.innerHTML = '<tr><td colspan="4" class="text-danger">Error</td></tr>'; return; }

    cachedTipos = data.tipos || [];

    if (data.tipos.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-muted text-center p-3">Sin perfiles</td></tr>';
        return;
    }

    tbody.innerHTML = data.tipos.map(p => `
        <tr>
            <td><strong>${p.tipo}</strong></td>
            <td><code class="small">${p.files}</code></td>
            <td><code class="small">${p.dst_template}</code></td>
            <td class="text-center">
                <div class="btn-actions">
                    <button class="btn btn-action bg-yellow-lt mx-1" data-edit-perfil="${p.tipo}" title="Editar">
                        <svg class="icon icon-sm"><use href="#icon-pencil"></use></svg>
                    </button>
                    <button class="btn btn-action bg-red-lt mx-1" data-del-perfil="${p.tipo}" title="Eliminar">
                        <svg class="icon icon-sm"><use href="#icon-trash"></use></svg>
                    </button>
                </div>
            </td>
        </tr>
    `).join('');

    tbody.querySelectorAll('[data-edit-perfil]').forEach(btn => {
        btn.addEventListener('click', () => editPerfil(btn.dataset.editPerfil));
    });
    tbody.querySelectorAll('[data-del-perfil]').forEach(btn => {
        btn.addEventListener('click', () => deletePerfil(btn.dataset.delPerfil));
    });
}

function editPerfil(tipo) {
    const perfil = cachedTipos.find(p => p.tipo === tipo);
    if (!perfil) return;

    document.getElementById('perfil-edit-mode').value = tipo;
    document.getElementById('perfil-tipo').value = perfil.tipo;
    document.getElementById('perfil-tipo').disabled = true;
    document.getElementById('perfil-files').value = perfil.files;
    document.getElementById('perfil-dst').value = perfil.dst_template;
    document.getElementById('btn-cancel-perfil').classList.remove('d-none');
}

function cancelEditPerfil() {
    document.getElementById('perfil-edit-mode').value = '';
    document.getElementById('perfil-tipo').value = '';
    document.getElementById('perfil-tipo').disabled = false;
    document.getElementById('perfil-files').value = '';
    document.getElementById('perfil-dst').value = '';
    document.getElementById('btn-cancel-perfil').classList.add('d-none');
}

async function savePerfil(e) {
    e.preventDefault();

    const editMode = document.getElementById('perfil-edit-mode').value;
    const tipo = document.getElementById('perfil-tipo').value.trim();
    const files = document.getElementById('perfil-files').value.trim();
    const dst = document.getElementById('perfil-dst').value.trim();

    if (!tipo || !files || !dst) {
        toast('Todos los campos son requeridos', 'warning');
        return;
    }

    const action = editMode ? 'update_tipo' : 'create_tipo';
    const result = await api.distribucion({ action, tipo, files, dst_template: dst });

    if (result.ok) {
        toast(editMode ? 'Perfil actualizado' : 'Perfil creado');
        cancelEditPerfil();
        loadPerfiles();
        loadTiposSelect();
    } else {
        toast(result.error || 'Error', 'danger');
    }
}

async function deletePerfil(tipo) {
    if (!confirm(`¿Eliminar el perfil "${tipo}"? Las distribuciones que lo usen se eliminarán en cascada.`)) return;

    const result = await api.distribucion({ action: 'delete_tipo', tipo });
    if (result.ok) {
        toast('Perfil eliminado');
        loadPerfiles();
        loadTiposSelect();
        loadDistribuciones();
    } else {
        toast(result.error || 'Error', 'danger');
    }
}

// ─── Init ───

export const distView = {
    init() {
        debouncedLoad = debounce(loadDistribuciones, 300);
        
        loadOptions();
        loadDistribuciones();
        loadTiposSelect();
        setupFilterListeners();

        // Autocomplete inputs (solo plaza, tipo ahora es select)
        new AutocompleteInput('edit-plaza', { table: 'distribucion', column: 'plaza' });

        // Edit modal
        document.getElementById('btn-nueva-dist').addEventListener('click', () => openEdit());
        document.getElementById('distForm').addEventListener('submit', saveDist);

        // Preview del perfil seleccionado
        document.getElementById('edit-tipo').addEventListener('change', (e) => {
            updatePerfilPreview(e.target.value);
        });

        // Perfil modal
        document.getElementById('perfilForm').addEventListener('submit', savePerfil);
        document.getElementById('btn-cancel-perfil').addEventListener('click', cancelEditPerfil);
        document.getElementById('perfilModal').addEventListener('show.bs.modal', () => {
            loadPerfiles();
        });

        // Import modal
        document.getElementById('btn-ejecutar-import').addEventListener('click', importarDist);
        document.getElementById('btn-exportar').addEventListener('click', exportarDist);
        document.getElementById('btn-scan-precios').addEventListener('click', scanPrecios);
        document.getElementById('importModal').addEventListener('hidden.bs.modal', () => {
            document.getElementById('import-texto').value = '';
            document.getElementById('import-resultado').innerHTML = '';
        });

        // Detail modal
        document.getElementById('btn-evaluar').addEventListener('click', evaluar);
        document.getElementById('btn-copiar').addEventListener('click', copiar);

        // Bulk actions
        document.getElementById('btn-copiar-vista').addEventListener('click', copiarVista);
        document.getElementById('btn-copiar-todas').addEventListener('click', copiarTodas);

        // Load tabs on show
        document.getElementById('detailModal').addEventListener('shown.bs.tab', (e) => {
            if (!currentDistId) return;
            const href = e.target.getAttribute('href');
            if (href === '#tab-versiones') loadVersiones(currentDistId);
            if (href === '#tab-errores') loadErrores(currentDistId);
            if (href === '#tab-ejecuciones') loadEjecuciones(currentDistId);
        });

        // Load logs when notifications modal opens from distribucion page
        const notifModal = document.getElementById('notificationsModal');
        if (notifModal) {
            notifModal.addEventListener('show.bs.modal', () => {
                loadLogs();
            });
        }

        // ClientesModal - load data on open
        const clientesModal = document.getElementById('clientesModal');
        if (clientesModal) {
            clientesModal.addEventListener('show.bs.modal', async (e) => {
                const btn = e.relatedTarget;
                const id = btn?.dataset?.id;
                if (!id) return;
                
                const data = await api.distribucion({ action: 'get', id: parseInt(id) });
                if (data.ok) {
                    document.getElementById('clientesModalTitle').textContent = `Clientes — ${data.distribucion.nombre} (${data.distribucion.plaza})`;
                    loadClientes(data.distribucion, 'clientes-modal-list');
                }
            });
        }
    }
};
