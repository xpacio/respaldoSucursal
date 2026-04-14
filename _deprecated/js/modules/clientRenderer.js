/**
 * Client Renderer - Pure DOM rendering for client rows
 * No business logic, no API calls - just data → HTML
 */
import { ICONS } from '../apiService.js';
import { ui } from '../ui.js';
import { getClientes, getClienteActual } from './clientStore.js';

/**
 * Get key download CSS class based on status
 * @param {Object} c - Client object
 */
export function getKeyDownloadClass(c) {
    const enabled = c.key_download_enabled === true || c.key_download_enabled === 't';
    const downloaded = c.key_downloaded_at !== null && c.key_downloaded_at !== undefined;
    
    if (!enabled && !downloaded) return 'bg-pink-lt';
    if (enabled && !downloaded) return 'bg-yellow-lt';
    return 'bg-teal-lt';
}

/**
 * Get key download title based on status
 * @param {Object} c - Client object
 */
export function getKeyDownloadTitle(c) {
    const enabled = c.key_download_enabled === true || c.key_download_enabled === 't';
    const downloaded = c.key_downloaded_at !== null && c.key_downloaded_at !== undefined;
    
    if (!enabled && !downloaded) return 'Descarga deshabilitada - Click para habilitar';
    if (enabled && !downloaded) return 'Descarga habilitada - Click para deshabilitar';
    return 'Ya descargado (' + ui.formatDate(c.key_downloaded_at) + ') - Click para resetear';
}

/**
 * Render a single client row
 * @param {Object} c - Client object
 */
export function renderClientRow(c) {
    const list = document.getElementById('clientes-tbody');
    if (!list) return;

    const rowHtml = `
        <td>
            <strong>${c.rbfid}</strong>
            ${c.last_sync ? `<br><small class="text-muted" style="font-size: 0.65rem;">Sinc: ${new Date(c.last_sync).toLocaleString()}</small>` : ''}
        </td>
        <td>${c.emp || ''}/${c.plaza || ''}</td>
        <td class="text-center">
            <div class="btn-actions">
                <div class="btn btn-action ${c.enabled ? 'bg-teal-lt' : 'bg-pink-lt'}" 
                     data-action="toggle-enabled"
                     data-id="${c.rbfid}" 
                     title="${c.enabled ? 'Activo - Click para desactivar' : 'Inactivo - Click para activar'}">
                    ${c.enabled ? ICONS.plug : ICONS.plugOff}
                </div>
            </div>
        </td>
        <td class="text-center">
            <div class="btn-actions">
                <div class="btn btn-action ${c.ssh_enabled ? 'bg-teal-lt' : 'bg-pink-lt'}" 
                     data-action="toggle-ssh"
                     data-id="${c.rbfid}" 
                     title="SSH: ${c.ssh_enabled ? 'Habilitado' : 'Deshabilitado'}">
                ${c.ssh_enabled ? ICONS.key : ICONS.keyOff}
                </div>
            </div>
        </td>
        <td class="text-center">
             <div class="btn-actions">
                 <div class="btn btn-action ${getKeyDownloadClass(c)}" 
                      data-action="toggle-key-download"
                      data-id="${c.rbfid}" 
                      title="${getKeyDownloadTitle(c)}">
                 ${ICONS.circleKey}
                 </div>
             </div>
        </td>
        <td class="text-center">
            <div class="btn-actions">
                <div class="btn btn-action bg-yellow-lt" 
                     data-action="regenerar-key"
                     data-id="${c.rbfid}" 
                     title="Regenerar claves SSH">
                ${ICONS.keyPlus}
                </div>
            </div>
        </td>
        <td class="text-center">
            <div class="btn-actions">
                <div class="btn btn-action bg-blue-lt" 
                     data-action="descargar-key"
                     data-id="${c.rbfid}" 
                     title="Descargar clave privada">
                ${ICONS.download}
                </div>
            </div>
        </td>
        <td class="text-center">
            <div class="btn-actions">
                <div class="btn btn-action bg-cyan-lt" 
                     data-bs-toggle="modal" 
                     data-bs-target="#overlaysModal"
                     data-client-id="${c.rbfid}"
                     title="Ver montajes">
                ${ICONS.stack2}
                </div>
            </div>
        </td>
        <td class="text-center">
            <div class="btn-actions">
                <button class="btn btn-action bg-purple-lt" 
                        data-bs-toggle="modal" 
                        data-bs-target="#clientModal"
                        data-client-id="${c.rbfid}"
                        title="Editar cliente">
                ${ICONS.userEdit}
                </button>
            </div>
        </td>
    `;

    // Check if row already exists
    let row = list.querySelector(`tr[data-client-row="${c.rbfid}"]`);
    const clienteActual = getClienteActual();
    
    if (row) {
        row.className = clienteActual === c.rbfid ? 'bg-primary-lt' : '';
        row.innerHTML = rowHtml;
    } else {
        const tr = document.createElement('tr');
        tr.setAttribute('data-client-row', c.rbfid);
        tr.className = clienteActual === c.rbfid ? 'bg-primary-lt' : '';
        tr.innerHTML = rowHtml;
        list.appendChild(tr);
    }
}

/**
 * Render clients list in table
 */
export function renderClientes() {
    const list = document.getElementById('clientes-tbody');
    if (!list) return;
    
    list.innerHTML = '';
    
    const clientes = getClientes();
    if (clientes.length === 0) {
        ui.showEmpty(list, 'No hay clientes');
        return;
    }
    
    clientes.forEach(c => renderClientRow(c));
}

/**
 * Update highlight on a specific client row
 * @param {string} rbfid 
 */
export function highlightClienteRow(rbfid) {
    const list = document.getElementById('clientes-tbody');
    if (!list) return;
    
    // Remove highlight from all rows
    list.querySelectorAll('tr').forEach(tr => {
        tr.classList.remove('bg-primary-lt');
    });
    
    // Add highlight to selected row
    if (rbfid) {
        const row = list.querySelector(`tr[data-client-row="${rbfid}"]`);
        if (row) row.classList.add('bg-primary-lt');
    }
}
