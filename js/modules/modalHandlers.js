/**
 * Modal Handlers Module (REFACTORED)
 * 
 * All functions have been moved to:
 * - clientHandlers.js (toggleEnabled, toggleSsh, guardarCliente, etc.)
 * - clientRenderer.js (renderClientes, renderClientRow)
 * 
 * This file re-exports for backward compatibility.
 * TODO: Remove this file after verifying all imports are updated.
 */

// Re-export from clientHandlers
export {
    toggleEnabled,
    toggleSsh,
    toggleKeyDownload,
    descargarKey,
    regenerarKey,
    guardarCliente,
    deleteClienteFromModal,
    populateClientModal,
    setupOverlayModalListeners,
    setupClientModalListeners
} from './clientHandlers.js';

// Re-export from clientRenderer
export {
    renderClientes,
    renderClientRow,
    getKeyDownloadClass,
    getKeyDownloadTitle,
    highlightClienteRow
} from './clientRenderer.js';
