/**
 * Client Store - Single owner of client data state
 * Eliminates window.clientesCache coupling across modules
 */
import { api } from '../apiService.js';
import { ui } from '../ui.js';

// Private state - only this module can mutate
let clientesCache = [];
let clienteActual = null;
let listeners = [];

/**
 * Get all clients from cache
 * @returns {Array}
 */
export function getClientes() {
    return clientesCache;
}

/**
 * Get a single client by rbfid
 * @param {string} rbfid
 * @returns {Object|undefined}
 */
export function getCliente(rbfid) {
    return clientesCache.find(c => c.rbfid === rbfid);
}

/**
 * Get index of a client in cache
 * @param {string} rbfid
 * @returns {number}
 */
export function getClienteIndex(rbfid) {
    return clientesCache.findIndex(c => c.rbfid === rbfid);
}

/**
 * Get currently selected client
 * @returns {string|null}
 */
export function getClienteActual() {
    return clienteActual;
}

/**
 * Set clients cache (replaces entire array)
 * @param {Array} data
 */
export function setClientes(data) {
    clientesCache = data || [];
    notifyListeners();
}

/**
 * Update a single client's fields
 * @param {string} rbfid
 * @param {Object} fields - fields to merge
 * @returns {boolean} true if client was found and updated
 */
export function updateCliente(rbfid, fields) {
    const i = clientesCache.findIndex(c => c.rbfid === rbfid);
    if (i !== -1) {
        clientesCache[i] = { ...clientesCache[i], ...fields };
        notifyListeners();
        return true;
    }
    return false;
}

/**
 * Add a new client to cache (prepend)
 * @param {Object} cliente
 */
export function addCliente(cliente) {
    clientesCache.unshift(cliente);
    notifyListeners();
}

/**
 * Remove a client from cache
 * @param {string} rbfid
 * @returns {boolean}
 */
export function removeCliente(rbfid) {
    const i = clientesCache.findIndex(c => c.rbfid === rbfid);
    if (i !== -1) {
        clientesCache.splice(i, 1);
        notifyListeners();
        return true;
    }
    return false;
}

/**
 * Set currently selected client
 * @param {string|null} rbfid
 */
export function setClienteActual(rbfid) {
    clienteActual = rbfid;
}

/**
 * Register a listener that fires when clients change
 * @param {Function} fn
 */
export function onClientesChange(fn) {
    listeners.push(fn);
    return () => {
        listeners = listeners.filter(l => l !== fn);
    };
}

function notifyListeners() {
    listeners.forEach(fn => fn());
}

/**
 * Legacy window global for backward compatibility during transition
 * @deprecated Use getClientes() instead
 */
export function syncToWindow() {
    window.clientesCache = clientesCache;
    window.clienteActual = clienteActual;
}

/**
 * Load clients from API
 * Reads filter values from DOM and updates store
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
        
        if (data.error) {
            ui.showErrorToast('Error: ' + data.error, '-', 'LOAD');
            return;
        }
        
        setClientes(data.clientes || []);
        syncToWindow(); // backward compat during transition
        
        if (loadingBar) {
            loadingBar.style.display = 'none';
        }
    } catch(e) {
        console.error('Error cargando clientes:', e);
        if (loadingBar) {
            ui.showError(loadingBar, 'Error al cargar clientes: ' + (e.message || 'Error desconocido'));
        }
    }
}

/**
 * Load filter options (empresas and plazas)
 * Keeps in searchFilter.js as it's filter-specific, not store
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
 * Initialize debounced load
 */
let debouncedLoad = null;
export function initDebouncedLoad(loadFn, wait = 300) {
    let timerId;
    debouncedLoad = () => {
        clearTimeout(timerId);
        timerId = setTimeout(loadFn, wait);
    };
}

/**
 * Trigger debounced load (call this on filter changes)
 */
export function triggerDebouncedLoad() {
    if (debouncedLoad) {
        debouncedLoad();
    }
}
