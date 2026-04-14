/**
 * Event Listeners Module
 * Centralizes ALL event listeners for the project
 */

import { 
    toggleMount, 
    deleteOverlay,
    agregarOverlay,
    syncOverlays
} from './overlayManager.js';
import {
    toggleEnabled,
    toggleSsh,
    toggleKeyDownload,
    descargarKey,
    regenerarKey,
    guardarCliente,
    deleteClienteFromModal,
    setupOverlayModalListeners,
    setupClientModalListeners
} from './clientHandlers.js';
import { setupFilterListeners } from './searchFilter.js';
import {
    habilitarSshVista,
    deshabilitarSshVista,
    resetSistema,
    habilitarClientesVista,
    deshabilitarClientesVista,
    habilitarKeyDownloadVista,
    deshabilitarKeyDownloadVista,
    regenerarClavesVista,
    descargarClavesVista
} from './clientView.js';
import {
    limpiarCautivos,
    regenerarCautivos,
    importarClientes,
    exportarClientes
} from './clientActions.js';

/**
 * Initialize all event listeners
 */
export function initEventListeners() {
    setupFilterListeners();
    setupOverlayModalListeners();
    setupClientModalListeners();
    setupGlobalActions();
}

/**
 * Global Event Delegation and Header Actions
 */
function setupGlobalActions() {
    // 1. Table Actions (Event Delegation)
    document.getElementById('clientes-tbody')?.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-action]');
        if (!btn) return;
        
        e.stopPropagation();
        const action = btn.dataset.action;
        const id = btn.dataset.id;
        
        console.log(`Action triggered: ${action} for ${id}`);
        
        switch (action) {
            case 'toggle-enabled': await toggleEnabled(id); break;
            case 'toggle-ssh': await toggleSsh(id); break;
            case 'toggle-key-download': await toggleKeyDownload(id); break;
            case 'regenerar-key': await regenerarKey(id); break;
            case 'descargar-key': await descargarKey(id); break;
        }
    });

    // 2. Global / Header Actions (Delegation on document or specific container)
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-action]');
        if (!btn || btn.closest('#clientes-tbody')) return; // Table body handled above
        
        // Prevent dropdown from closing for action buttons
        if (btn.closest('.dropdown-menu')) {
            e.preventDefault();
            e.stopPropagation();
        }
        
        const action = btn.dataset.action;
        console.log('Global action triggered:', action);
        
        const { clientId, overlayId, origen, id } = btn.dataset;
        
        switch (action) {
            // Batch ESTADO
            case 'batch-enable-estado': await habilitarClientesVista(); break;
            case 'batch-disable-estado': await deshabilitarClientesVista(); break;
            // Batch SSH
            case 'batch-enable-ssh': await habilitarSshVista(); break;
            case 'batch-disable-ssh': await deshabilitarSshVista(); break;
            // Batch KEY
            case 'batch-enable-key-download': await habilitarKeyDownloadVista(); break;
            case 'batch-disable-key-download': await deshabilitarKeyDownloadVista(); break;
            // Batch CLAVES
            case 'batch-regen-keys': await regenerarClavesVista(); break;
            case 'batch-download-keys': await descargarClavesVista(); break;
            // Otros
            case 'reset-sistema': await resetSistema(); break;
            case 'delete-cliente': await deleteClienteFromModal(id); break;
            case 'toggle-mount': await toggleMount(clientId, overlayId, origen); break;
            case 'delete-overlay': await deleteOverlay(clientId, overlayId, origen); break;
            case 'regenerar-cautivos': await regenerarCautivos(); break;
            case 'limpiar-cautivos': await limpiarCautivos(); break;
            case 'importar-clientes':
                document.getElementById('btn-open-import-modal')?.click();
                break;
            case 'exportar-clientes': await exportarClientes(); break;
        }
    });

    // 3. Form Submissions
    document.getElementById('clientForm')?.addEventListener('submit', guardarCliente);
    
    // Import Form
    document.getElementById('importForm')?.addEventListener('submit', importarClientes);

    // 4. Overlay Modal Actions
    document.getElementById('add-overlay-btn')?.addEventListener('click', (e) => {
        agregarOverlay();
    });

    document.getElementById('sync-btn')?.addEventListener('click', (e) => {
        syncOverlays();
    });

    // Cleanup legacy onclicks
    document.querySelector('button[onclick*="resetSistema"]')?.removeAttribute('onclick');
    document.getElementById('save-client-btn')?.removeAttribute('onclick');
}
