/**
 * Search and Filter Module
 * Handles client search filtering and debounced loading
 * Uses clientStore for state management and clientRenderer for display
 */

import { ui } from '../ui.js';
import { renderClientes } from './clientRenderer.js';
import { debounce } from '../utils/debounce.js';
import { api } from '../apiService.js';
import { setClientes, syncToWindow } from './clientStore.js';

/**
 * Debounced load function (300ms delay)
 */
let debouncedLoad = null;

/**
 * Initialize debounced load
 * @param {Function} loadFunc - Function to call when debouncing
 */
export function initDebouncedLoad(loadFunc) {
    debouncedLoad = debounce(loadFunc, 300);
    return debouncedLoad;
}

/**
 * Trigger debounced load (call this on filter changes)
 */
export function filterClientes() {
    if (debouncedLoad) {
        debouncedLoad();
    }
}

/**
 * Load clients with current search filters
 */
export async function loadClientes() {
    const loadingBar = document.getElementById('loading-bar');
    if (loadingBar) {
        ui.showLoading(loadingBar);
    }
    
    try {
        const searchInput = document.getElementById('search');
        const empSelect = document.getElementById('filter-emp');
        const plazaSelect = document.getElementById('filter-plaza');
        const enabledSelect = document.getElementById('filter-enabled');
        
        if (!searchInput) {
            console.error("Element 'search' not found");
            return;
        }
        
        const rbfid = searchInput.value.trim();
        const emp = empSelect ? empSelect.value : '';
        const plaza = plazaSelect ? plazaSelect.value : '';
        const enabled = enabledSelect ? enabledSelect.value : '';
        const limit8 = document.getElementById('limit-8');
        const limit = (limit8 && limit8.checked) ? 8 : null;
        
        const params = {
            rbfid: rbfid || null,
            emp: emp || null,
            plaza: plaza || null,
            enabled: enabled || null,
            limit: limit
        };
        
        const data = await api.getClientes(params);
        
        // Update store (not window.clientesCache directly)
        setClientes(data.clientes || []);
        syncToWindow(); // backward compat during transition
        
        if (loadingBar) {
            loadingBar.style.display = 'none';
        }
        
        renderClientes();
    } catch(e) {
        console.error('Error cargando clientes:', e);
        if (loadingBar) {
            ui.showError(loadingBar, 'Error al cargar clientes: ' + (e.message || 'Error desconocido'));
        }
    }
}

/**
 * Load filter options (empresas and plazas)
 */
export async function loadOptions() {
    try {
        const data = await api.getClientesOptions();

        const empSelect = document.getElementById('filter-emp');
        const plazaSelect = document.getElementById('filter-plaza');

        if (empSelect && data?.emp?.length) {
            empSelect.innerHTML = '<option value="">emp</option>';
            data.emp.forEach(val => {
                const opt = document.createElement('option');
                opt.value = val;
                opt.textContent = val;
                empSelect.appendChild(opt);
            });
        }

        if (plazaSelect && data?.plaza?.length) {
            plazaSelect.innerHTML = '<option value="">plaza</option>';
            data.plaza.forEach(val => {
                const opt = document.createElement('option');
                opt.value = val;
                opt.textContent = val;
                plazaSelect.appendChild(opt);
            });
        }
    } catch (e) {
        console.error('Error cargando opciones:', e);
    }
}

/**
 * Set up event listeners for search and filters
 */
export function setupFilterListeners() {
    const searchInput = document.getElementById('search');
    if (searchInput) {
        searchInput.addEventListener('input', filterClientes);
    }
    
    const empSelect = document.getElementById('filter-emp');
    if (empSelect) {
        empSelect.addEventListener('change', filterClientes);
    }
    
    const plazaSelect = document.getElementById('filter-plaza');
    if (plazaSelect) {
        plazaSelect.addEventListener('change', filterClientes);
    }
    
    const enabledSelect = document.getElementById('filter-enabled');
    if (enabledSelect) {
        enabledSelect.addEventListener('change', filterClientes);
    }
    
    const limit8Select = document.getElementById('limit-8');
    if (limit8Select) {
        limit8Select.addEventListener('change', filterClientes);
    }
}
