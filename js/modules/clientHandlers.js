/**
 * Client Handlers - User action handlers for client operations
 * Reads from store, calls API, updates store
 * No direct DOM manipulation (except form reads)
 */
import { api } from '../apiService.js';
import { ui } from '../ui.js';
import { getCliente, updateCliente } from './clientStore.js';
import { loadClientes } from './searchFilter.js';
import { renderClientRow } from './clientRenderer.js';
import { loadMounts } from './overlayManager.js';

/**
 * Toggle client enabled status
 * @param {string} id - Client ID
 */
export async function toggleEnabled(id) {
    const c = getCliente(id);
    if (!c) return;
    try {
        const result = c.enabled ? await api.disableCliente(id) : await api.enableCliente(id);
        if (result.error) {
            ui.showErrorToast('Error: ' + result.error, id, 'CLIENT-TOGGLE');
        } else {
            updateCliente(id, { enabled: result.enabled });
            renderClientRow(getCliente(id));
            ui.showSuccess(result.enabled ? 'Cliente habilitado' : 'Cliente deshabilitado', id, 'CLIENT-TOGGLE');
        }
    } catch (e) {
        ui.showErrorToast('Error: ' + e.message, id, 'CLIENT-TOGGLE');
    }
}

/**
 * Toggle SSH for a client
 * @param {string} id - Client ID
 */
export async function toggleSsh(id) {
    const c = getCliente(id);
    if (!c) return;

    try {
        const result = c.ssh_enabled ? await api.disableSsh(id) : await api.enableSsh(id);

        if (result.error) {
            ui.showErrorToast('Error: ' + result.error, id, 'SSH-TOGGLE');
        } else {
            updateCliente(id, { ssh_enabled: result.ssh_enabled });
            renderClientRow(getCliente(id));
            ui.showSuccess(result.ssh_enabled ? 'SSH habilitado' : 'SSH deshabilitado', id, 'SSH-TOGGLE');
        }
    } catch (e) {
        ui.showErrorToast('Error: ' + e.message, id, 'SSH-TOGGLE');
    }
}

/**
 * Toggle key download status for a client
 * @param {string} clientId - Client ID
 */
export async function toggleKeyDownload(clientId) {
    const id = clientId || document.getElementById('cliente-id').value;
    if (!id) return;
    
    const rbfid = id;
    const actionType = 'KEY-DL';
    
    try {
        const statusData = await api.getKeyDownloadStatus(id);
        if (statusData.error) {
            ui.showErrorToast('Error: ' + statusData.error, rbfid, actionType);
            return;
        }
        
        const enabled = statusData.key_download_enabled === true || statusData.key_download_enabled === 't';
        const downloaded = statusData.key_downloaded_at !== null && statusData.key_downloaded_at !== undefined;
        
        let result;
        if (enabled && !downloaded) {
            result = await api.disableKeyDownload(id);
        } else if (!enabled && !downloaded) {
            result = await api.enableKeyDownload(id);
        } else if (downloaded) {
            if (!confirm('¿Resetear descarga? Permitirá al cliente descargar nuevamente.')) {
                return;
            }
            result = await api.resetKeyDownload(id);
        }
        
        if (result?.error) {
            ui.showErrorToast('Error: ' + result.error, rbfid, actionType);
        } else {
            updateCliente(rbfid, {
                key_download_enabled: result.key_download_enabled,
                key_downloaded_at: result.key_downloaded_at
            });
            renderClientRow(getCliente(rbfid));
            ui.showSuccess('Estado de descarga actualizado', rbfid, actionType);
        }
        
    } catch (e) {
        ui.showErrorToast('Error: ' + e.message, rbfid, actionType);
    }
}

/**
 * Download SSH key for a client
 * @param {string} clientId - Client ID
 */
export async function descargarKey(clientId) {
    const id = clientId || document.getElementById('cliente-id').value;
    if (!id) {
        ui.showErrorToast('No se proporcionó ID para descargar', id || '-', 'SSH-DL');
        return;
    }
    
    const rbfid = id;
    const actionType = 'SSH-DL';
    
    ui.showSuccess('Iniciando descarga de clave...', rbfid, actionType);
    
    try {
        const response = await fetch(`/api/cliente/${id}/ssh/key`, {
            headers: {
                'Authorization': 'Bearer ' + localStorage.getItem('token')
            }
        });
        
        if (!response.ok) {
            const errorText = await response.text();
            throw new Error(`HTTP ${response.status}: ${errorText}`);
        }
        
        const base64Key = await response.text();
        
        const privateKey = atob(base64Key);
        const blob = new Blob([privateKey], { type: 'text/plain' });
        const filename = `${id}.key`;
        
        const urlBlob = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = urlBlob;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(urlBlob);
        
        ui.showSuccess('Descarga completada para ' + id, rbfid, actionType);
        
    } catch (e) {
        console.error('Error en descargarKey:', e);
        ui.showErrorToast('Error al descargar clave: ' + e.message, rbfid, actionType);
    }
}

/**
 * Regenerate SSH key for a client
 * @param {string} clientId - Client ID
 */
export async function regenerarKey(clientId) {
    const id = clientId || document.getElementById('cliente-id').value;
    if (!id) return;
    if (!confirm('¿Regenerar claves? Las anteriores dejarán de funcionar.')) return;
    const rbfid = id;
    const actionType = 'SSH-REGEN';
    
    try {
        const result = await api.regenKey(id);
        if (result.error) {
            ui.showErrorToast('Error: ' + result.error, rbfid, actionType);
        } else {
            await descargarKey(id);
            await loadClientes();
            ui.showSuccess('Claves regeneradas y descargadas.', rbfid, actionType);
        }
    } catch (e) {
        console.error('Error en regenerarKey:', e);
        ui.showErrorToast('Error al regenerar clave: ' + e.message, rbfid, actionType);
    }
}

/**
 * Save client (create or update)
 * @param {Event} e - Form submit event
 */
export async function guardarCliente(e) {
    if (e) e.preventDefault();
    
    const rbfid = document.getElementById('rbfid').value;
    const emp = document.getElementById('emp').value;
    const plaza = document.getElementById('plaza').value;
    const enabled = document.getElementById('enabled').value === 'true';
    const existingId = document.getElementById('cliente-id').value;
    
    try {
        let result;
        if (existingId) {
            result = await api.actualizarCliente(rbfid, emp, plaza, enabled);
        } else {
            result = await api.crearCliente(rbfid, emp, plaza, enabled);
        }
        
        if (result.error) {
            ui.showErrorToast('Error: ' + result.error, rbfid, 'CLIENT-SAVE');
        } else {
            // Close modal
            const modal = document.getElementById('clientModal');
            if (modal) {
                const closeBtn = modal.querySelector('.btn-close');
                if (closeBtn) closeBtn.click();
            }
            
            // Reload list for new clients
            if (!existingId) {
                await loadClientes();
            }
            
            // Handle overlay warning
            if (result.template_warning) {
                ui.showWarning('Cliente ' + rbfid + ' creado, pero overlays fallaron: ' + result.template_warning, rbfid, 'CLIENT-SAVE');
                setTimeout(() => {
                    const row = document.querySelector(`tr[data-client-row="${rbfid}"]`);
                    if (row) row.classList.add('bg-yellow-lt');
                }, 300);
            } else {
                ui.showSuccess(existingId ? 'Cliente actualizado' : 'Cliente creado', rbfid, 'CLIENT-SAVE');
                
                if (existingId) {
                    const updated = updateCliente(rbfid, { emp, plaza, enabled });
                    if (updated) {
                        renderClientRow(getCliente(rbfid));
                    } else {
                        await loadClientes();
                    }
                }
            }
        }
    } catch (e) {
        ui.showErrorToast('Error: ' + e.message, rbfid, 'CLIENT-SAVE');
    }
}

/**
 * Delete client from modal
 * @param {string} id - Client ID
 */
export async function deleteClienteFromModal(id) {
    if (!id) return;
    if (!confirm(`¿Está seguro de eliminar permanentemente al cliente ${id}?\n\nEsta acción:\n1. Desmontará todos los overlays\n2. Eliminará el usuario Linux\n3. Eliminará las claves SSH\n4. Eliminará el directorio home\n5. Eliminará el registro de la base de datos\n\n¿Continuar?`)) {
        return;
    }
    
    const btn = document.querySelector('[data-action="delete-cliente"]');
    const originalText = btn ? btn.textContent : 'Eliminar';
    
    try {
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Eliminando...';
        }
        
        const result = await api.deleteCliente(id, 'hard');
        if (!result.ok) {
            throw new Error(result.error || 'Error al eliminar cliente');
        }
        
        ui.showSuccess(`Cliente ${id} eliminado`);
        document.getElementById('clientModal').querySelector('.btn-close').click();
        await loadClientes();
    } catch (e) {
        ui.showErrorToast('Error: ' + e.message, id, 'CLIENT-DELETE');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.textContent = originalText;
        }
    }
}

/**
 * Populate client modal with data
 * @param {string} id - Client ID (empty for new)
 */
export function populateClientModal(id) {
    const cliente = id ? getCliente(id) : null;
    
    document.getElementById('cliente-id').value = id || '';
    document.getElementById('rbfid').value = cliente?.rbfid || '';
    document.getElementById('emp').value = cliente?.emp || '';
    document.getElementById('plaza').value = cliente?.plaza || '';
    document.getElementById('enabled').value = cliente?.enabled ? 'true' : 'false';
    
    const title = document.getElementById('clientModalTitle');
    if (title) title.textContent = id ? 'Editar Cliente' : 'Nuevo Cliente';
    
    const deleteBtn = document.getElementById('modalActions');
    if (deleteBtn) {
        if (id) {
            deleteBtn.innerHTML = `<button type="button" class="btn btn-danger" data-action="delete-cliente" data-id="${id}">Eliminar</button>`;
        } else {
            deleteBtn.innerHTML = '';
        }
    }
    
    // Focus first input
    document.getElementById('rbfid')?.focus();
}

/**
 * Set up client modal event listeners
 */
export function setupClientModalListeners() {
    const modal = document.getElementById('clientModal');
    if (!modal) return;
    
    modal.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        const clientId = button?.getAttribute('data-client-id') || '';
        populateClientModal(clientId);
    });
}

/**
 * Set up overlay modal event listeners
 */
export function setupOverlayModalListeners() {
    const overlaysModal = document.getElementById('overlaysModal');
    if (!overlaysModal) return;
    
    overlaysModal.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        const clientId = button?.getAttribute('data-client-id');
        if (clientId) {
            window.currentClientId = clientId;
            
            const badge = document.getElementById('modal-cliente-badge');
            if (badge) badge.textContent = clientId;
            
            // Enable buttons
            const addBtn = document.getElementById('add-overlay-btn');
            const syncBtn = document.getElementById('sync-btn');
            if (addBtn) addBtn.disabled = false;
            if (syncBtn) syncBtn.disabled = false;
            
            loadMounts();
        }
    });
}
