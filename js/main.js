/**
 * Main Entry Point for Clientes Module
 * Initializes all modules and sets up event listeners
 */

import { ui } from './ui.js';
import { 
    initDebouncedLoad, 
    loadClientes, 
    loadOptions
} from './modules/searchFilter.js';
import { loadMounts } from './modules/overlayManager.js';
import { AutocompleteInput } from './modules/autocomplete.js';
import { startJobPolling } from './modules/jobTracker.js';
import { initEventListeners } from './modules/eventListeners.js';
import { syncToWindow } from './modules/clientStore.js';

/**
 * Initialize application
 */
function init() {
    // Initialize debounced load function
    initDebouncedLoad(loadClientes);
    
    // Initialize notification system
    ui.initNotifications();
    ui.renderNotifications();
    
    // Start job polling (every 5 seconds)
    startJobPolling(5000);
    
    // Load initial data
    loadClientes();
    loadOptions();
    
    // Set up event listeners
    initEventListeners();
    
    // Initialize Autocomplete inputs
    new AutocompleteInput('emp', { table: 'clients', column: 'emp' });
    new AutocompleteInput('plaza', { table: 'clients', column: 'plaza' });
    
    // Initial sync to window for legacy compatibility
    syncToWindow();
    
    console.log('Clientes module initialized');
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
