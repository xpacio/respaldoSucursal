// Icon constants
export const ICONS = {
    switchOpen: '<svg class="icon"><use href="#icon-switch-open"></use></svg>',
    switchClose: '<svg class="icon"><use href="#icon-switch-closed"></use></svg>',
    key: '<svg class="icon"><use href="#icon-key"></use></svg>',
    keyOff: '<svg class="icon"><use href="#icon-key-off"></use></svg>',
    plug: '<svg class="icon"><use href="#icon-plug"></use></svg>',
    plugOff: '<svg class="icon"><use href="#icon-plug-off"></use></svg>',
    cancel: '<svg class="icon"><use href="#icon-cancel"></use></svg>',
    circleKey: '<svg class="icon"><use href="#icon-circle-key"></use></svg>',
    keyPlus: '<svg class="icon"><use href="#icon-key-plus"></use></svg>',
    download: '<svg class="icon"><use href="#icon-download"></use></svg>',
    userEdit: '<svg class="icon"><use href="#icon-user-edit"></use></svg>',
    stack2: '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#000000" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-stack-2"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 4l-8 4l8 4l8 -4l-8 -4" /><path d="M4 12l8 4l8 -4" /><path d="M4 16l8 4l8 -4" /></svg>'
};

// Import ui for notifications
import { ui } from './ui.js';
import { indicator } from './modules/indicator.js';

// API Service - Todo via POST con {action, ...params}
async function handleResponse(response) {
    if (!response.ok) {
        if (response.status === 401) {
            window.location.href = '/login';
        }
        let message = `HTTP ${response.status}: ${response.statusText}`;
        try {
            const body = await response.json();
            if (body.error) message = body.error;
        } catch (e) {}
        throw new Error(message);
    }
    const text = await response.text();
    if (!text) return { ok: true };
    try {
        return JSON.parse(text);
    } catch (e) {
        return { ok: false, error: 'Respuesta del servidor no es JSON válido' };
    }
}

// Enviar POST a /{resource} con body JSON
function call(resource, data = {}) {
    indicator.api.start();
    
    return fetch(`/api/${resource}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify(data)
    }).then(handleResponse).then(result => {
        if (result.ok) {
            indicator.api.done();
        } else {
            indicator.api.error();
        }
        return result;
    }).catch(err => {
        indicator.api.error();
        throw err;
    });
}

export const api = {
    // ─── Distinct genérico ───
    distinct(table, column, q = '') { return call('root', { action: 'distinct', table, column, q }); },

    // ─── Clientes ───
    getClientes(params = {}) { return call('clientes', { action: 'search', ...params }); },
    getClientesOptions() { return call('clientes', { action: 'options' }); },
    crearCliente(id, emp, plaza, enabled) { return call('clientes', { action: 'create', id, emp, plaza, enabled }); },
    actualizarCliente(id, emp, plaza, enabled) { return call('clientes', { action: 'update', id, emp, plaza, enabled }); },
    deleteCliente(id, hard = true) { return call('clientes', { action: 'delete', id, hard }); },
    enableCliente(id) { return call('clientes', { action: 'enable', id }); },
    disableCliente(id) { return call('clientes', { action: 'disable', id }); },
    enableSsh(id) { return call('clientes', { action: 'ssh_enable', id }); },
    disableSsh(id) { return call('clientes', { action: 'ssh_disable', id }); },
    regenKey(id) { return call('clientes', { action: 'ssh_regen', id }); },
    enableKeyDownload(id) { return call('clientes', { action: 'enable_key_download', id }); },
    disableKeyDownload(id) { return call('clientes', { action: 'disable_key_download', id }); },
    resetKeyDownload(id) { return call('clientes', { action: 'reset_key_download', id }); },
    getKeyDownloadStatus(id) { return call('clientes', { action: 'get_key_download_status', id }); },
    addOverlay(id, src, dst, mode, perms) { return call('clientes', { action: 'add_overlay', id, src, dst, mode, dst_perms: perms }); },
    enableOverlay(id, overlayId) { return call('clientes', { action: 'overlay_enable', overlay_id: overlayId }); },
    disableOverlay(id, overlayId) { return call('clientes', { action: 'overlay_disable', overlay_id: overlayId }); },
    deleteOverlayById(id, overlayId) { return call('clientes', { action: 'overlay_delete', overlay_id: overlayId }); },
    getMounts(id) { return call('clientes', { action: 'get_mounts', id }); },
    syncOverlays(id) { return call('clientes', { action: 'sync_overlays', id }); },
    getPermisos(id) { return call('clientes', { action: 'permisos', id }); },
    getSSHKey(id) { return call('clientes', { action: 'get_ssh_key', id }); },

    // ─── Plantillas ───
    getPlantillas() { return call('plantillas', { action: 'list' }); },
    crearPlantilla(src, dst, mode, perms, auto) { return call('plantillas', { action: 'create', src, dst, mode, dst_perms: perms, auto_mount: auto ? 'true' : 'false' }); },
    actualizarPlantilla(id, src, dst, mode, perms, auto) { return call('plantillas', { action: 'edit', id, src, dst, mode, dst_perms: perms, auto_mount: auto ? 'true' : 'false' }); },
    eliminarPlantilla(id) { return call('plantillas', { action: 'delete', id }); },

    // ─── Logs ───
    getLogs(limit = 100) { return call('logs', { action: 'list', limit }); },
    getEjecucion(id) { return call('logs', { action: 'get', id }); },
    purgeLogs(olderThan) { return call('logs', { action: 'purge', olderThan }); },

    // ─── Admin ───
    adminResetStart() { return call('admin', { action: 'reset_start' }); },
    adminResetStatus(jobId) { return call('admin', { action: 'reset_status', job_id: jobId }); },
    adminJobsActive() { return call('admin', { action: 'jobs_active' }); },
    adminJobStatus(jobId) { return call('admin', { action: 'job_status', job_id: jobId }); },
    adminExecuteJob(jobId) { return call('admin', { action: 'execute_job', job_id: jobId }); },

    // ─── Agente ───
    agenteFiles(path = '') { return call('agente', { action: 'files', path }); },
    agenteFile(path) { return call('agente', { action: 'file', path }); },
    agenteSave(path, content) { return call('agente', { action: 'file_save', path, content }); },
    agenteCreateFile(path, content) { return call('agente', { action: 'file_create', path, content }); },
    agenteCreateDir(path) { return call('agente', { action: 'dir_create', path }); },
    agenteDelete(path) { return call('agente', { action: 'delete', path }); },
    agenteDownload(path) { return call('agente', { action: 'download', path }); },

    // ─── Sh Editor ───
    sheditor(data = {}) { return call('sheditor', data); },

    // ─── Distribución ───
    distribucion(data = {}) { return call('distribucion', data); },

    // ─── Jobs (BackendWorker) ───
    jobList(status = 'running', limit = 50) { return call('job', { action: 'list', status, limit }); },
    jobStatus(jobId) { return call('job', { action: 'status', job_id: jobId }); },

    // ─── Clientes (genérico) ───
    clientes(data = {}) { return call('clientes', data); },

    // ─── ZIG (Monitoreo y sync-v2) ───
    // Clientes conectados
    zigClients() { return call('zig', { action: 'clients' }); },
    zigClientDetail(id) { return call('zig', { action: 'client_detail', id }); },
    zigClientStats(id) { return call('zig', { action: 'client_stats', id }); },
    
    // Estadísticas
    zigStats() { return call('zig', { action: 'stats' }); },
    zigActivity(limit = 100) { return call('zig', { action: 'activity', limit }); },
    
    // Precios
    zigPriceCatalog(plaza) { return call('zig', { action: 'price_catalog', plaza }); },
    zigPriceScan(rootPath = '/srv/precios') { return call('zig', { action: 'price_scan', root_path: rootPath }); },
    zigPriceSync(plaza) { return call('zig', { action: 'price_sync', plaza }); },
    
    // Distribución
    zigDistribucionStatus() { return call('zig', { action: 'distribucion_status' }); },
    
    // Respaldos
    zigBackupStatus(rbfid) { return call('zig', { action: 'backup_status', rbfid }); },
    zigBackupHistory(rbfid, limit = 50) { return call('zig', { action: 'backup_history', rbfid, limit }); },
    zigBackupTrigger(rbfid) { return call('zig', { action: 'backup_trigger', rbfid }); },
    
    // Health
    zigHealth() { return call('zig', { action: 'health' }); },
};
