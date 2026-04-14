/**
 * Overlay Management Module
 * Handles overlay toggling, deletion, synchronization, and adding new overlays
 */

import { api, ICONS } from '../apiService.js';
import { ui } from '../ui.js';

/**
 * Update overlay row styling in the modal
 * @param {string|number} overlayId - Overlay ID
 * @param {string} status - 'pending', 'success', 'error'
 * @param {string} action - 'enable' or 'disable' or 'delete'
 */
function updateOverlayRow(overlayId, status, action) {
    // Find the row with this overlay ID
    const rows = document.querySelectorAll('#mounts-tbody tr');
    for (const row of rows) {
        const overlayCell = row.querySelector('td:first-child strong');
        if (overlayCell && overlayCell.textContent.trim()) {
            // Find the button for this overlay
            const btn = row.querySelector(`[data-overlay-id="${overlayId}"]`);
            if (btn) {
                // Update row class
                row.classList.remove('bg-yellow-lt', 'badge-blink', 'bg-green-lt', 'bg-red-lt');
                
                if (status === 'pending') {
                    row.classList.add('bg-yellow-lt', 'badge-blink');
                } else if (status === 'success') {
                    row.classList.add('bg-green-lt');
                    // Update button icon and class
                    if (action === 'enable') {
                        btn.className = 'btn btn-action bg-teal-lt';
                        btn.innerHTML = ICONS.switchClose;
                        btn.title = 'Montado - Click para desmontar';
                    } else if (action === 'disable') {
                        btn.className = 'btn btn-action bg-pink-lt';
                        btn.innerHTML = ICONS.switchOpen;
                        btn.title = 'Desmontado - Click para montar';
                    }
                } else if (status === 'error') {
                    row.classList.add('bg-red-lt');
                }
                break;
            }
        }
    }
}

/**
 * Toggle mount/unmount of an overlay
 * @param {string} clientId - Client ID
 * @param {string|number} overlayId - Overlay ID
 * @param {string} origen - 'plantilla' or 'cliente'
 */
export async function toggleMount(clientId, overlayId, origen) {
    try {
        // Get current overlay data to check if it's mounted
        const data = await api.getMounts(clientId);
        const overlays = data.overlays || [];
        const overlay = overlays.find(o => o.overlay_id == overlayId);
        
        if (!overlay) {
            ui.showErrorToast('Overlay no encontrado', clientId, 'OVERLAY-TOGGLE');
            return;
        }
        
        const isMounted = overlay.mounted === true || overlay.mounted === 't';
        
        // Update UI to pending state
        updateOverlayRow(overlayId, 'pending', isMounted ? 'disable' : 'enable');
        
        // enableOverlay/disableOverlay manejan ambas fuentes (plantilla y directo)
        let result;
        if (isMounted) {
            result = await api.disableOverlay(clientId, overlayId);
        } else {
            result = await api.enableOverlay(clientId, overlayId);
        }
        
        if (result.error) {
            updateOverlayRow(overlayId, 'error');
            ui.showErrorToast('Error: ' + result.error, clientId, 'OVERLAY-TOGGLE');
        } else if (result.ok === false) {
            updateOverlayRow(overlayId, 'error');
            ui.showErrorToast(result.message || 'Error desconocido', clientId, 'OVERLAY-TOGGLE');
        } else {
            updateOverlayRow(overlayId, 'success', isMounted ? 'disable' : 'enable');
            ui.showSuccess('Estado del overlay actualizado', clientId, 'OVERLAY-TOGGLE');
        }
    } catch (e) {
        updateOverlayRow(overlayId, 'error');
        ui.showErrorToast('Error: ' + e.message, clientId, 'OVERLAY-TOGGLE');
    }
}

/**
 * Delete an overlay
 * @param {string} clientId - Client ID
 * @param {string|number} overlayId - Overlay ID
 * @param {string} origen - 'plantilla' or 'cliente' (not used directly but kept for compatibility)
 */
export async function deleteOverlay(clientId, overlayId, origen) {
    if (!confirm('¿Eliminar overlay?')) return;
    
    // Update UI to pending state
    updateOverlayRow(overlayId, 'pending', 'delete');
    
    try {
        const result = await api.deleteOverlayById(clientId, overlayId);
        if (result.error) {
            updateOverlayRow(overlayId, 'error');
            ui.showErrorToast('Error: ' + result.error, clientId, 'OVERLAY-DELETE');
        } else if (result.success || result.ok) {
            // Remove the row from the table
            const rows = document.querySelectorAll('#mounts-tbody tr');
            for (const row of rows) {
                const btn = row.querySelector(`[data-overlay-id="${overlayId}"]`);
                if (btn) {
                    row.remove();
                    break;
                }
            }
            ui.showSuccess('Overlay eliminado', clientId, 'OVERLAY-DELETE');
        } else {
            updateOverlayRow(overlayId, 'error');
            ui.showErrorToast('Error al eliminar overlay', clientId, 'OVERLAY-DELETE');
        }
    } catch (e) {
        updateOverlayRow(overlayId, 'error');
        ui.showErrorToast('Error: ' + e.message, clientId, 'OVERLAY-DELETE');
    }
}

/**
 * Synchronize overlays (align with template state)
 * @param {string} clientId - Client ID (optional, uses currentClientId if not provided)
 */
export async function syncOverlays(clientId = null) {
    const currentClientId = clientId || window.currentClientId;
    if (!currentClientId) {
        ui.showErrorToast('Seleccione un cliente primero', '-', 'SYNC');
        return;
    }

    const rbfid = currentClientId;
    const actionType = 'SYNC';

    try {
        const result = await api.syncOverlays(currentClientId);
        if (result.error) {
            ui.showErrorToast('Error: ' + result.error, rbfid, actionType);
        } else {
            ui.showSuccess(result.summary || 'Sincronización completada', rbfid, actionType);
            // Reload mounts to reflect changes
            await loadMounts();
        }
    } catch (e) {
        ui.showErrorToast('Error: ' + e.message, rbfid, actionType);
    }
}

/**
 * Add a new overlay
 * @param {string} clientId - Client ID (optional, uses currentClientId if not provided)
 */
export async function agregarOverlay(clientId = null) {
    const currentClientId = clientId || window.currentClientId;
    if (!currentClientId) {
        ui.showErrorToast('Seleccione un cliente primero', '-', 'OVERLAY-ADD');
        return;
    }
    
    const src = document.getElementById('overlay-src').value.trim();
    const dst = document.getElementById('overlay-dst').value.trim();
    const mode = document.querySelector('input[name="overlay-mode"]:checked').value;
    const perms = document.querySelector('input[name="overlay-dst-perms"]:checked').value;
    
    if (!src || !dst) {
        ui.showErrorToast('Complete los campos Origen y Destino', currentClientId, 'OVERLAY-ADD');
        return;
    }
    
    try {
        const result = await api.addOverlay(currentClientId, src, dst, mode, perms);
        if (result.error) {
            ui.showErrorToast('Error: ' + result.error, currentClientId, 'OVERLAY-ADD');
        } else {
            ui.showSuccess('Overlay agregado', currentClientId, 'OVERLAY-ADD');
            document.getElementById('overlay-src').value = '';
            document.getElementById('overlay-dst').value = '';
            // Reload mounts to reflect changes
            await loadMounts();
        }
    } catch (e) {
        ui.showErrorToast('Error: ' + e.message, currentClientId, 'OVERLAY-ADD');
    }
}

/**
 * Load overlays for current client
 * @param {string} clientId - Client ID (optional, uses currentClientId if not provided)
 */
export async function loadMounts(clientId = null) {
    const currentClientId = clientId || window.currentClientId;
    if (!currentClientId) return;
    
    ui.showLoading(document.getElementById('mounts-tbody'));
    try {
        const data = await api.getMounts(currentClientId);
        const overlays = data.overlays || [];
        
        document.getElementById('overlay-count').textContent = overlays.length + ' overlays';
        
        if (overlays.length === 0) {
            document.getElementById('mounts-tbody').innerHTML = 
                '<tr><td colspan="6" class="text-center text-muted">Sin overlays para este cliente</td></tr>';
            return;
        }
        
        let hasDesviaciones = false;
        const html = overlays.map(o => {
            const mounted = o.mounted === true || o.mounted === 't';
            const lastSync = o.last_sync ? new Date(o.last_sync).toLocaleString() : 'Nunca';
            const isPlantilla = o.origen === 'plantilla';
            const isDesviado = o.is_desviado === true || o.is_desviado === 't' || o.is_desviado === 'true';
            
            if (isDesviado) hasDesviaciones = true;
            
            const rowClass = isDesviado ? 'bg-yellow-lt' : '';
            
            return `
                <tr class="${rowClass}">
                    <td><strong>${o.overlay_dst}</strong></td>
                    <td><code class="text-xs">${o.overlay_src}</code></td>
                    <td><span class="text-uppercase badge ${o.mode === 'rw' ? 'bg-pink-lt' : 'bg-teal-lt'}">${o.mode}</span></td>
                    <td><span class="badge ${isPlantilla ? 'bg-blue-lt' : 'bg-secondary-lt'}">${isPlantilla ? 'Plantilla' : 'Cliente'}</span></td>
                    <td><span class="text-muted text-xs">${lastSync}</span></td>
                    <td class="text-center">
                        <div class="btn-actions">
                            <div 
                                class="btn btn-action ${mounted ? 'bg-teal-lt' : 'bg-pink-lt'}" 
                                data-action="toggle-mount"
                                data-client-id="${currentClientId}"
                                data-overlay-id="${o.overlay_id}"
                                data-origen="${o.origen}"
                                title="${mounted ? 'Montado - Click para desmontar' : 'Desmontado - Click para montar'}">
                                ${mounted ? ICONS.switchClose : ICONS.switchOpen}
                            </div>
                            ${o.origen === 'cliente' ? `
                            <div 
                                class="btn btn-action bg-red-lt" 
                                data-action="delete-overlay"
                                data-client-id="${currentClientId}"
                                data-overlay-id="${o.overlay_id}"
                                data-origen="${o.origen}"
                                title="Eliminar overlay">
                                ${ICONS.cancel}
                            </div>
                            ` : ''}
                        </div>
                    </td>
                </tr>
            `;
        }).join('');
        
        document.getElementById('mounts-tbody').innerHTML = html;
        
        // Re-attach event listeners for the new rows
        attachOverlayEventListeners();
        
        // Mostrar/ocultar botón "Alinear"
        const syncBtn = document.getElementById('sync-btn');
        if (syncBtn) {
            syncBtn.style.display = hasDesviaciones ? 'inline-block' : 'none';
        }
    } catch (e) {
        ui.showError(document.getElementById('mounts-tbody'), e.message || 'Error al cargar');
    }
}

/**
 * Attach event listeners to overlay action buttons
 */
export function attachOverlayEventListeners() {
    // Toggle mount buttons
    document.querySelectorAll('[data-toggle-mount]').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            e.stopPropagation();
            const { clientId, overlayId, origen } = e.currentTarget.dataset;
            await toggleMount(clientId, overlayId, origen);
        });
    });
    
    // Delete overlay buttons
    document.querySelectorAll('[data-delete-overlay]').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            e.stopPropagation();
            const { clientId, overlayId, origen } = e.currentTarget.dataset;
            await deleteOverlay(clientId, overlayId, origen);
        });
    });
}
