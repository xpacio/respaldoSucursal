/**
 * Client View Module
 * Handles specific UI interactions for the Clientes view, 
 * including batch operations and row updates.
 */

import { api, ICONS } from '../apiService.js';
import { ui } from '../ui.js';
import { indicator } from './indicator.js';
import { getClientes, getCliente, updateCliente } from './clientStore.js';
import { loadClientes } from './searchFilter.js';
import { renderClientRow } from './clientRenderer.js';

/**
 * Update SSH icon and row styling
 * @param {string} rbfid - Client ID
 * @param {string} status - 'pending', 'success', 'error'
 * @param {string} action - 'enable' or 'disable'
 */
export function updateSshIconAndRow(rbfid, status, action = 'disable') {
    // 1. Actualizar el estado en clientesCache si es éxito
    if (status === 'success') {
        const cliente = getCliente(rbfid);
        if (cliente) {
            cliente.ssh_enabled = (action === 'enable');
        }
    }

    // 2. Encontrar la fila de la tabla correspondiente al rbfid
    const rows = document.querySelectorAll('#clientes-tbody tr');
    for (const row of rows) {
        // Look for the ID in the first cell
        const idCell = row.querySelector('td:first-child strong');
        if (idCell && idCell.textContent.trim() === rbfid) {
            // 3. Actualizar la clase de la fila según el estado
            row.classList.remove('bg-yellow-lt', 'badge-blink', 'bg-green-lt', 'bg-red-lt');
            
            if (status === 'pending') {
                row.classList.add('bg-yellow-lt', 'badge-blink');
            } else if (status === 'success') {
                row.classList.add('bg-green-lt');
                
                // 4. Actualizar el icono de SSH según la acción
                const sshTd = row.querySelectorAll('td')[3];
                const sshButton = sshTd ? sshTd.querySelector('.btn-action') : null;
                if (sshButton) {
                    if (action === 'enable') {
                        sshButton.innerHTML = ICONS.key;
                        sshButton.className = 'btn btn-action bg-teal-lt';
                        sshButton.title = 'SSH: Habilitado';
                    } else {
                        sshButton.innerHTML = ICONS.keyOff;
                        sshButton.className = 'btn btn-action bg-pink-lt';
                        sshButton.title = 'SSH: Deshabilitado';
                    }
                }
            } else if (status === 'error') {
                row.classList.add('bg-red-lt');
            }
            
            // Deshabilitar el botón individual durante la operación global
            const sshTd = row.querySelectorAll('td')[3];
            const sshButton = sshTd ? sshTd.querySelector('.btn-action') : null;
            if (sshButton) {
                sshButton.style.pointerEvents = (status === 'pending') ? 'none' : '';
                sshButton.style.opacity = (status === 'pending') ? '0.5' : '';
            }
            
            break;
        }
    }
}

/**
 * Enable all SSH buttons in the table
 */
export function enableAllSshButtons() {
    const sshButtons = document.querySelectorAll('#clientes-tbody .btn-action');
    sshButtons.forEach(btn => {
        btn.style.pointerEvents = '';
        btn.style.opacity = '';
    });
}

/**
 * Batch disable SSH for filtered clients
 */
export async function deshabilitarSshVista() {
    console.log("Iniciando deshabilitarSshVista");
    if (!confirm('¿Deshabilitar SSH para los clientes filtrados?')) return;

    // Filter clients WITH SSH enabled (to disable them)
    const clientes = getClientes().filter(c =>
        c.ssh_enabled === true || c.ssh_enabled === 't' || c.ssh_enabled === '1' || c.ssh_enabled === 1
    );

    if (clientes.length === 0) {
        ui.showWarning('No hay clientes para deshabilitar', '-', 'SSH-VIEW');
        return;
    }
    
    console.log(`Clientes a deshabilitar: ${clientes.length}/${getClientes().length}`);
    
    const confirmMsg = `¿Deshabilitar SSH para ${clientes.length} cliente(s)?`;
    if (!confirm(confirmMsg)) return;
    
    const totalIgnorados = getClientes().length - clientes.length;
    
    if (clientes.length === 0) {
        ui.showErrorToast(totalIgnorados > 0 
            ? `Todos los clientes de la vista ya están deshabilitados (${totalIgnorados} clientes)` 
            : 'No hay clientes en la vista actual', '-', 'SSH-VISTA');
        return;
    }

    const btnHabilitar = document.getElementById('btn-habilitar-ssh-vista');
    const btnDeshabilitar = document.getElementById('btn-deshabilitar-ssh-vista');
    
    if (btnDeshabilitar) {
        btnDeshabilitar.disabled = true;
        btnDeshabilitar.dataset.originalText = btnDeshabilitar.innerText;
    }
    if (btnHabilitar) btnHabilitar.disabled = true;
    
    // Deshabilitar todos los botones individuales
    document.querySelectorAll('#clientes-tbody .btn-action').forEach(btn => {
        btn.style.pointerEvents = 'none';
        btn.style.opacity = '0.5';
    });

    let successCount = 0;
    let errorCount = 0;
    const total = clientes.length;

    // Generate unique job ID for this batch
    const batchJobId = 'batch_ssh_disable_' + Date.now();

    // Show job indicator at start
    indicator.job.add(batchJobId, 'batch_ssh_disable', `${total} clientes`);

    for (let i = 0; i < total; i++) {
        const rbfid = clientes[i].rbfid;
        if (btnDeshabilitar) btnDeshabilitar.innerText = `Procesando ${i + 1}/${total}...`;
        
        updateSshIconAndRow(rbfid, 'pending', 'disable');
        
        try {
            const result = await api.disableSsh(rbfid);
            if (result.error) throw new Error(result.error);
            
            updateSshIconAndRow(rbfid, 'success', 'disable');
            ui.showSuccess(`SSH deshabilitado para ${rbfid}`, rbfid, 'SSH-VISTA');
            successCount++;
        } catch (e) {
            updateSshIconAndRow(rbfid, 'error', 'disable');
            ui.showErrorToast(`Error deshabilitando SSH para ${rbfid}: ${e.message}`, rbfid, 'SSH-VISTA');
            errorCount++;
        }
        await new Promise(resolve => setTimeout(resolve, 100));
    }

    // Show job indicator at end
    indicator.job.update(batchJobId, errorCount > 0 ? 'failed' : 'completed');

    if (btnDeshabilitar) {
        btnDeshabilitar.disabled = false;
        btnDeshabilitar.innerText = btnDeshabilitar.dataset.originalText || 'Deshabilitar SSH en vista';
    }
    if (btnHabilitar) btnHabilitar.disabled = false;
    
    enableAllSshButtons();
    ui.showSuccess(`SSH deshabilitado: ${successCount} éxito, ${errorCount} errores`, '-', 'SSH-VISTA-RESUMEN');
}

/**
 * Batch enable SSH for filtered clients
 */
export async function habilitarSshVista() {
    console.log("Iniciando habilitarSshVista");
    if (!confirm('¿Habilitar SSH para los clientes filtrados?')) return;

    // Robust boolean check (f, false, 0, null)
    const clientes = getClientes().filter(c => 
        c.ssh_enabled !== true && c.ssh_enabled !== 't' && c.ssh_enabled !== '1' && c.ssh_enabled !== 1
    );
    
    if (clientes.length === 0) {
        ui.showWarning('No hay clientes para habilitar', '-', 'SSH-VIEW');
        return;
    }
    
    console.log(`Clientes a habilitar: ${clientes.length}/${getClientes().length}`);
    
    const confirmMsg = `¿Habilitar SSH para ${clientes.length} cliente(s)?`;
    if (!confirm(confirmMsg)) return;
    
    const totalIgnorados = getClientes().length - clientes.length;
    
    if (clientes.length === 0) {
        ui.showErrorToast(totalIgnorados > 0 
            ? `Todos los clientes de la vista ya están habilitados (${totalIgnorados} clientes)` 
            : 'No hay clientes en la vista actual', '-', 'SSH-VISTA');
        return;
    }

    const btnHabilitar = document.getElementById('btn-habilitar-ssh-vista');
    const btnDeshabilitar = document.getElementById('btn-deshabilitar-ssh-vista');
    
    if (btnHabilitar) {
        btnHabilitar.disabled = true;
        btnHabilitar.dataset.originalText = btnHabilitar.innerText;
    }
    if (btnDeshabilitar) btnDeshabilitar.disabled = true;
    
    document.querySelectorAll('#clientes-tbody .btn-action').forEach(btn => {
        btn.style.pointerEvents = 'none';
        btn.style.opacity = '0.5';
    });

    let successCount = 0;
    let errorCount = 0;
    const total = clientes.length;

    // Generate unique job ID for this batch
    const batchJobId = 'batch_ssh_enable_' + Date.now();

    // Show job indicator at start
    indicator.job.add(batchJobId, 'batch_ssh_enable', `${total} clientes`);

    for (let i = 0; i < total; i++) {
        const rbfid = clientes[i].rbfid;
        if (btnHabilitar) btnHabilitar.innerText = `Procesando ${i + 1}/${total}...`;
        
        updateSshIconAndRow(rbfid, 'pending', 'enable');
        
        try {
            const result = await api.enableSsh(rbfid);
            if (result.error) throw new Error(result.error);
            
            updateSshIconAndRow(rbfid, 'success', 'enable');
            ui.showSuccess(`SSH habilitado para ${rbfid}`, rbfid, 'SSH-VISTA');
            successCount++;
        } catch (e) {
            updateSshIconAndRow(rbfid, 'error', 'enable');
            ui.showErrorToast(`Error habilitando SSH para ${rbfid}: ${e.message}`, rbfid, 'SSH-VISTA');
            errorCount++;
        }
        await new Promise(resolve => setTimeout(resolve, 100));
    }

    // Show job indicator at end
    indicator.job.update(batchJobId, errorCount > 0 ? 'failed' : 'completed');

    if (btnHabilitar) {
        btnHabilitar.disabled = false;
        btnHabilitar.innerText = btnHabilitar.dataset.originalText || 'Habilitar SSH en vista';
    }
    if (btnDeshabilitar) btnDeshabilitar.disabled = false;
    
    enableAllSshButtons();
    ui.showSuccess(`SSH habilitado: ${successCount} éxito, ${errorCount} errores`, '-', 'SSH-VISTA-RESUMEN');
}

/**
 * Reset completo del sistema (asincrónico con polling)
 * 1. Desmontar todos los overlays (force)
 * 2. Eliminar TODOS los usuarios Linux (_xxxxx)
 * 3. Eliminar TODOS los homes residuales (/home/xxxxx)
 * 4. Limpiar BD (overlays, executions, clientes)
 */
export async function resetSistema() {
    const confirmed = confirm(
        '¿Reiniciar estado del sistema?\n\n' +
        'Esta acción ejecutará:\n' +
        '1. Desmontar todos los overlays\n' +
        '2. Eliminar TODOS los usuarios Linux\n' +
        '3. Eliminar TODOS los homes residuales\n' +
        '4. Limpiar base de datos\n\n' +
        '¿Continuar?'
    );
    
    if (!confirmed) return;

    const btn = document.querySelector('[data-action="reset-sistema"]');
    const originalText = btn ? btn.textContent : 'Reset Sistema';
    
    try {
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Ejecutando...';
        }

        ui.showLoading(document.getElementById('loading-bar'));

        // Iniciar reset asincrónico
        const startResult = await api.adminResetStart();
        
        if (!startResult.ok) {
            throw new Error(startResult.error || 'No se pudo iniciar el reset');
        }

        const jobId = startResult.job_id;
        let pollingInterval = null;

        // Show job indicator at start
        indicator.job.add(jobId, 'system_reset', 'iniciando');

        // Polling cada 5 segundos
        const pollPromise = new Promise((resolve, reject) => {
            pollingInterval = setInterval(async () => {
                try {
                    const status = await api.adminResetStatus(jobId);
                    
                    if (!status.ok) {
                        clearInterval(pollingInterval);
                        reject(new Error(status.error || 'Error consultando estado'));
                        return;
                    }

                    updateProgressUI(status);

                    // Update job indicator with current step
                    if (status.current_step) {
                        indicator.job.update(jobId, 'running', status.current_step);
                    }

                    if (status.state === 'completed') {
                        clearInterval(pollingInterval);
                        resolve(status);
                    } else if (status.state === 'failed') {
                        clearInterval(pollingInterval);
                        reject(new Error(status.error_message || 'Reset falló'));
                    }
                } catch (e) {
                    clearInterval(pollingInterval);
                    reject(e);
                }
            }, 5000);
        });

        const status = await pollPromise;
        
        // Mostrar resultado
        const results = status.results || {};
        let summary = 'Reset completado:\n';
        
        if (results.unmount?.ok) {
            summary += '✓ Overlays desmontados\n';
        } else if (results.unmount) {
            summary += '⚠ Overlays desmontados con advertencias\n';
        }
        
        if (results.cleanup_all_users?.ok) {
            summary += `✓ ${results.cleanup_all_users.total_deleted} usuarios Linux eliminados\n`;
            if (results.cleanup_all_users.total_errors > 0) {
                summary += `⚠ ${results.cleanup_all_users.total_errors} errores\n`;
            }
        }
        
        if (results.cleanup_all_homes?.ok) {
            summary += `✓ ${results.cleanup_all_homes.total_deleted} homes eliminados\n`;
            if (results.cleanup_all_homes.total_errors > 0) {
                summary += `⚠ ${results.cleanup_all_homes.total_errors} errores\n`;
            }
        }
        
        if (results.db_cleanup?.ok) {
            summary += `✓ BD limpiada\n`;
        } else if (results.db_cleanup) {
            summary += `⚠ BD: ${results.db_cleanup.error || 'error'}\n`;
        }
        
        if (results.delete_clients?.ok) {
            summary += `✓ ${results.delete_clients.clients_deleted} clientes eliminados de la BD\n`;
        } else if (results.delete_clients) {
            summary += `⚠ BD clientes: ${results.delete_clients.error || 'error'}\n`;
        }
        
        ui.showSuccess(summary, '-', 'RESET-SYSTEM');
        
        // Show job indicator at end
        indicator.job.update(jobId, 'completed');
        
        const { loadClientes: reload } = await import('./searchFilter.js');
        await reload();
        
    } catch (e) {
        ui.showErrorToast('Error en reset: ' + e.message, '-', 'RESET-ERROR');
        // Show job indicator error
        indicator.job.update(jobId, 'failed');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.textContent = originalText;
        }
    }
}

function updateProgressUI(status) {
    const loadingBar = document.getElementById('loading-bar');
    if (loadingBar) {
        const stepNames = {
            'unmount': 'Desmontando overlays',
            'cleanup_all_users': 'Eliminando usuarios Linux',
            'cleanup_all_homes': 'Eliminando homes residuales',
            'cleanup_db_state': 'Limpiando tablas de BD',
            'delete_clients': 'Eliminando clientes'
        };
        const label = stepNames[status.current_step] || status.current_step || 'Procesando...';
        ui.statusBar(loadingBar, 'loading', null, label);
    }
}

// ═══════════════════════════════════════════════════════════════
// BATCH OPERATIONS — ESTADO
// ═══════════════════════════════════════════════════════════════

function isClienteEnabled(c) {
    return c.enabled === true || c.enabled === 't' || c.enabled === '1' || c.enabled === 1;
}

function isClienteDisabled(c) {
    return !isClienteEnabled(c);
}

export async function habilitarClientesVista() {
    if (!confirm('¿Habilitar clientes en la vista actual?')) return;

    const clientes = getClientes().filter(isClienteDisabled);
    if (clientes.length === 0) {
        ui.showWarning('Todos los clientes ya están habilitados', '-', 'ESTADO-VIEW');
        return;
    }

    const confirmMsg = `¿Habilitar ${clientes.length} cliente(s)?\nEsto habilitará el usuario Linux, SSH y montará overlays.`;
    if (!confirm(confirmMsg)) return;

    disableAllTableButtons();
    let successCount = 0;
    let errorCount = 0;
    const total = clientes.length;
    const batchJobId = 'batch_enable_' + Date.now();

    indicator.job.add(batchJobId, 'batch_enable', `${total} clientes`);

    for (let i = 0; i < total; i++) {
        const c = clientes[i];
        updateEstadoRow(c.rbfid, 'pending');
        
        try {
            const result = await api.enableCliente(c.rbfid);
            if (result.error) throw new Error(result.error);
            
            updateCliente(c.rbfid, { enabled: true, ssh_enabled: true });
            renderClientRow(getCliente(c.rbfid));
            ui.showSuccess(`Cliente ${c.rbfid} habilitado`, c.rbfid, 'ESTADO-VISTA');
            successCount++;
        } catch (e) {
            updateEstadoRow(c.rbfid, 'error');
            ui.showErrorToast(`Error: ${c.rbfid} - ${e.message}`, c.rbfid, 'ESTADO-VISTA');
            errorCount++;
        }
        await new Promise(resolve => setTimeout(resolve, 200));
    }

    indicator.job.update(batchJobId, errorCount > 0 ? 'failed' : 'completed');
    enableAllTableButtons();
    ui.showSuccess(`Clientes habilitados: ${successCount} éxito, ${errorCount} errores`, '-', 'ESTADO-VISTA-RESUMEN');
}

export async function deshabilitarClientesVista() {
    if (!confirm('¿Deshabilitar clientes en la vista actual?')) return;

    const clientes = getClientes().filter(isClienteEnabled);
    if (clientes.length === 0) {
        ui.showWarning('Todos los clientes ya están deshabilitados', '-', 'ESTADO-VIEW');
        return;
    }

    const confirmMsg = `¿Deshabilitar ${clientes.length} cliente(s)?\nEsto desmontará overlays y deshabilitará SSH.`;
    if (!confirm(confirmMsg)) return;

    disableAllTableButtons();
    let successCount = 0;
    let errorCount = 0;
    const total = clientes.length;
    const batchJobId = 'batch_disable_' + Date.now();

    indicator.job.add(batchJobId, 'batch_disable', `${total} clientes`);

    for (let i = 0; i < total; i++) {
        const c = clientes[i];
        updateEstadoRow(c.rbfid, 'pending');
        
        try {
            const result = await api.disableCliente(c.rbfid);
            if (result.error) throw new Error(result.error);
            
            updateCliente(c.rbfid, { enabled: false, ssh_enabled: false });
            renderClientRow(getCliente(c.rbfid));
            ui.showSuccess(`Cliente ${c.rbfid} deshabilitado`, c.rbfid, 'ESTADO-VISTA');
            successCount++;
        } catch (e) {
            updateEstadoRow(c.rbfid, 'error');
            ui.showErrorToast(`Error: ${c.rbfid} - ${e.message}`, c.rbfid, 'ESTADO-VISTA');
            errorCount++;
        }
        await new Promise(resolve => setTimeout(resolve, 200));
    }

    indicator.job.update(batchJobId, errorCount > 0 ? 'failed' : 'completed');
    enableAllTableButtons();
    ui.showSuccess(`Clientes deshabilitados: ${successCount} éxito, ${errorCount} errores`, '-', 'ESTADO-VISTA-RESUMEN');
}

// ═══════════════════════════════════════════════════════════════
// BATCH OPERATIONS — KEY DOWNLOAD
// ═══════════════════════════════════════════════════════════════

function isKeyDownloadEnabled(c) {
    return c.key_download_enabled === true || c.key_download_enabled === 't';
}

function isKeyDownloadDisabled(c) {
    return !isKeyDownloadEnabled(c);
}

export async function habilitarKeyDownloadVista() {
    if (!confirm('¿Habilitar descarga de clave para los clientes en vista?')) return;

    const clientes = getClientes().filter(isKeyDownloadDisabled);
    if (clientes.length === 0) {
        ui.showWarning('Todos los clientes ya tienen descarga habilitada', '-', 'KEY-VIEW');
        return;
    }

    const confirmMsg = `¿Habilitar descarga de clave para ${clientes.length} cliente(s)?`;
    if (!confirm(confirmMsg)) return;

    disableAllTableButtons();
    let successCount = 0;
    let errorCount = 0;
    const total = clientes.length;
    const batchJobId = 'batch_key_dl_enable_' + Date.now();

    indicator.job.add(batchJobId, 'batch_key_dl_enable', `${total} clientes`);

    for (let i = 0; i < total; i++) {
        const c = clientes[i];
        
        try {
            const result = await api.enableKeyDownload(c.rbfid);
            if (result.error) throw new Error(result.error);
            
            updateCliente(c.rbfid, { key_download_enabled: true });
            renderClientRow(getCliente(c.rbfid));
            ui.showSuccess(`Descarga habilitada para ${c.rbfid}`, c.rbfid, 'KEY-VISTA');
            successCount++;
        } catch (e) {
            ui.showErrorToast(`Error: ${c.rbfid} - ${e.message}`, c.rbfid, 'KEY-VISTA');
            errorCount++;
        }
        await new Promise(resolve => setTimeout(resolve, 100));
    }

    indicator.job.update(batchJobId, errorCount > 0 ? 'failed' : 'completed');
    enableAllTableButtons();
    ui.showSuccess(`Descarga habilitada: ${successCount} éxito, ${errorCount} errores`, '-', 'KEY-VISTA-RESUMEN');
}

export async function deshabilitarKeyDownloadVista() {
    if (!confirm('¿Deshabilitar descarga de clave para los clientes en vista?')) return;

    const clientes = getClientes().filter(isKeyDownloadEnabled);
    if (clientes.length === 0) {
        ui.showWarning('Todos los clientes ya tienen descarga deshabilitada', '-', 'KEY-VIEW');
        return;
    }

    const confirmMsg = `¿Deshabilitar descarga de clave para ${clientes.length} cliente(s)?`;
    if (!confirm(confirmMsg)) return;

    disableAllTableButtons();
    let successCount = 0;
    let errorCount = 0;
    const total = clientes.length;
    const batchJobId = 'batch_key_dl_disable_' + Date.now();

    indicator.job.add(batchJobId, 'batch_key_dl_disable', `${total} clientes`);

    for (let i = 0; i < total; i++) {
        const c = clientes[i];
        
        try {
            const result = await api.disableKeyDownload(c.rbfid);
            if (result.error) throw new Error(result.error);
            
            updateCliente(c.rbfid, { key_download_enabled: false });
            renderClientRow(getCliente(c.rbfid));
            ui.showSuccess(`Descarga deshabilitada para ${c.rbfid}`, c.rbfid, 'KEY-VISTA');
            successCount++;
        } catch (e) {
            ui.showErrorToast(`Error: ${c.rbfid} - ${e.message}`, c.rbfid, 'KEY-VISTA');
            errorCount++;
        }
        await new Promise(resolve => setTimeout(resolve, 100));
    }

    indicator.job.update(batchJobId, errorCount > 0 ? 'failed' : 'completed');
    enableAllTableButtons();
    ui.showSuccess(`Descarga deshabilitada: ${successCount} éxito, ${errorCount} errores`, '-', 'KEY-VISTA-RESUMEN');
}

// ═══════════════════════════════════════════════════════════════
// BATCH OPERATIONS — REGEN KEYS
// ═══════════════════════════════════════════════════════════════

export async function regenerarClavesVista() {
    const clientes = getClientes().filter(c => isClienteEnabled(c) && (c.ssh_enabled === true || c.ssh_enabled === 't'));
    if (clientes.length === 0) {
        ui.showWarning('No hay clientes habilitados con SSH activo', '-', 'REGEN-VIEW');
        return;
    }

    const confirmMsg = `¿Regenerar claves SSH para ${clientes.length} cliente(s)?\n\nLas claves anteriores dejarán de funcionar.\nNO se descargarán automáticamente.`;
    if (!confirm(confirmMsg)) return;

    disableAllTableButtons();
    let successCount = 0;
    let errorCount = 0;
    const total = clientes.length;
    const batchJobId = 'batch_regen_' + Date.now();

    indicator.job.add(batchJobId, 'batch_regen', `${total} clientes`);

    for (let i = 0; i < total; i++) {
        const c = clientes[i];
        
        try {
            const result = await api.regenKey(c.rbfid);
            if (result.error) throw new Error(result.error);
            
            updateCliente(c.rbfid, { key_download_enabled: true, key_downloaded_at: null });
            renderClientRow(getCliente(c.rbfid));
            ui.showSuccess(`Claves regeneradas para ${c.rbfid}`, c.rbfid, 'REGEN-VISTA');
            successCount++;
        } catch (e) {
            ui.showErrorToast(`Error: ${c.rbfid} - ${e.message}`, c.rbfid, 'REGEN-VISTA');
            errorCount++;
        }
        await new Promise(resolve => setTimeout(resolve, 200));
    }

    indicator.job.update(batchJobId, errorCount > 0 ? 'failed' : 'completed');
    enableAllTableButtons();
    ui.showSuccess(`Claves regeneradas: ${successCount} éxito, ${errorCount} errores`, '-', 'REGEN-VISTA-RESUMEN');
}

// ═══════════════════════════════════════════════════════════════
// BATCH OPERATIONS — DOWNLOAD KEYS
// ═══════════════════════════════════════════════════════════════

async function downloadKeyForClient(id) {
    const token = localStorage.getItem('token');
    const response = await fetch(`/api/cliente/${id}/ssh/key`, {
        headers: { 'Authorization': 'Bearer ' + token }
    });
    
    if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${await response.text()}`);
    }
    
    const base64Key = await response.text();
    const privateKey = atob(base64Key);
    const blob = new Blob([privateKey], { type: 'text/plain' });
    const urlBlob = URL.createObjectURL(blob);
    
    const a = document.createElement('a');
    a.href = urlBlob;
    a.download = `${id}.key`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(urlBlob);
}

export async function descargarClavesVista() {
    const clientes = getClientes().filter(c =>
        (c.ssh_enabled === true || c.ssh_enabled === 't') &&
        (c.key_download_enabled === true || c.key_download_enabled === 't')
    );
    
    if (clientes.length === 0) {
        ui.showWarning('No hay clientes con SSH y descarga habilitada', '-', 'DL-VIEW');
        return;
    }

    const confirmMsg = `¿Descargar claves para ${clientes.length} cliente(s)?\n\nEl navegador descargará un archivo .key por cada cliente.`;
    if (!confirm(confirmMsg)) return;

    disableAllTableButtons();
    let successCount = 0;
    let errorCount = 0;
    const total = clientes.length;
    const batchJobId = 'batch_dl_' + Date.now();

    indicator.job.add(batchJobId, 'batch_dl', `${total} claves`);

    for (let i = 0; i < total; i++) {
        const c = clientes[i];
        
        try {
            await downloadKeyForClient(c.rbfid);
            
            updateCliente(c.rbfid, { key_downloaded_at: new Date().toISOString() });
            renderClientRow(getCliente(c.rbfid));
            ui.showSuccess(`Clave descargada: ${c.rbfid}`, c.rbfid, 'DL-VISTA');
            successCount++;
        } catch (e) {
            ui.showErrorToast(`Error descargando ${c.rbfid}: ${e.message}`, c.rbfid, 'DL-VISTA');
            errorCount++;
        }
        await new Promise(resolve => setTimeout(resolve, 500));
    }

    indicator.job.update(batchJobId, errorCount > 0 ? 'failed' : 'completed');
    enableAllTableButtons();
    ui.showSuccess(`Claves descargadas: ${successCount} éxito, ${errorCount} errores`, '-', 'DL-VISTA-RESUMEN');
}

// ═══════════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════════

function updateEstadoRow(rbfid, status) {
    const list = document.getElementById('clientes-tbody');
    if (!list) return;
    
    const row = list.querySelector(`tr[data-client-row="${rbfid}"]`);
    if (!row) return;
    
    row.classList.remove('bg-yellow-lt', 'bg-green-lt', 'bg-red-lt');
    
    if (status === 'pending') {
        row.classList.add('bg-yellow-lt');
    } else if (status === 'error') {
        row.classList.add('bg-red-lt');
    }
}

function disableAllTableButtons() {
    document.querySelectorAll('#clientes-tbody .btn-action').forEach(btn => {
        btn.style.pointerEvents = 'none';
        btn.style.opacity = '0.5';
    });
}

function enableAllTableButtons() {
    document.querySelectorAll('#clientes-tbody .btn-action').forEach(btn => {
        btn.style.pointerEvents = '';
        btn.style.opacity = '';
    });
}
