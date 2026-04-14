/**
 * Client Actions Module
 * Business operations for clients (import, regenerate, cleanup)
 */

import { api } from '../apiService.js';
import { ui } from '../ui.js';
import { loadClientes } from './searchFilter.js';
import { indicator } from './indicator.js';

/**
 * Clear all captive clients from the table
 */
export async function limpiarCautivos() {
    console.log('limpiarCautivos called');
    const confirmed = confirm('¿Limpiar tabla clientes_cautivos?\n\nEsto eliminará todos los registros. Luego podrás usar "Importar clientes" para cargarlos de nuevo.');
    if (!confirmed) return;
    
    try {
        console.log('Calling API for limpiar_cautivos');
        const result = await api.clientes({ action: 'limpiar_cautivos' });
        console.log('API result:', result);
        
        if (result.ok) {
            ui.showSuccess('Clientes cautivos eliminados. Usa "Importar clientes" para cargarlos.');
        } else {
            ui.showErrorToast(result.error || 'Error al limpiar cautivos');
        }
    } catch (error) {
        console.error('Error en limpiarCautivos:', error);
        ui.showErrorToast('Error de conexión: ' + error.message);
    }
}

/**
 * Regenerate clients from captive table
 */
export async function regenerarCautivos() {
    const confirmed = confirm('¿Regenerar clientes desde cautivos?\n\nEsto recreará todos los clientes desde la tabla de respaldo.');
    if (!confirmed) return;
    
    try {
        const result = await api.clientes({ action: 'regenerar_cautivos' });
        
        if (result.ok) {
            ui.showSuccess('Job de regeneración iniciado. Puedes seguir trabajando, te notificaremos cuando termine.');
            // Show job indicator immediately with job_id from backend
            if (result.job_id) {
                indicator.job.add(result.job_id, 'regenerar_cautivos', 'iniciando');
                // Also dispatch event for jobTracker
                window.dispatchEvent(new CustomEvent('job-started', { 
                    detail: { job_id: result.job_id, job_type: 'regenerar_cautivos' } 
                }));
            }
        } else {
            ui.showErrorToast(result.error || 'Error al iniciar regeneración');
        }
    } catch (error) {
        ui.showErrorToast('Error de conexión: ' + error.message);
    }
}

/**
 * Import clients from textarea
 */
export async function importarClientes(e) {
    e.preventDefault();
    
    const textarea = document.getElementById('import-textarea');
    const errorsDiv = document.getElementById('import-errors');
    const btn = document.getElementById('btn-importar');
    
    const text = textarea.value.trim();
    if (!text) {
        errorsDiv.textContent = 'Debe proporcionar datos para importar';
        errorsDiv.classList.remove('d-none');
        return;
    }
    
    errorsDiv.classList.add('d-none');
    btn.disabled = true;
    btn.textContent = 'Importando...';
    
    try {
        const result = await api.clientes({ action: 'importar', clientes: text });
        
        if (result.ok) {
            const importCount = result.imported || 0;
            const skipCount = result.skipped || 0;
            
            if (importCount > 0) {
                ui.showSuccess(`Importados: ${importCount} clientes`);
            }
            if (skipCount > 0) {
                ui.showWarning(`Omitidos: ${skipCount} clientes`);
            }
            if (result.errors && result.errors.length > 0) {
                errorsDiv.innerHTML = result.errors.join('<br>');
                errorsDiv.classList.remove('d-none');
            }
            
            // Clear textarea on success
            if (importCount > 0) {
                textarea.value = '';
            }
            
            // Reload clients list
            loadClientes();
            
            // Close modal after delay
            setTimeout(() => {
                document.getElementById('importModal')?.querySelector('.btn-close')?.click();
            }, 1500);
        } else {
            errorsDiv.textContent = result.error || 'Error al importar clientes';
            if (result.errors) {
                errorsDiv.innerHTML = result.errors.join('<br>');
            }
            errorsDiv.classList.remove('d-none');
        }
    } catch (error) {
        errorsDiv.textContent = 'Error de conexión: ' + error.message;
        errorsDiv.classList.remove('d-none');
    } finally {
        btn.disabled = false;
        btn.textContent = 'Importar';
    }
}

/**
 * Export captive clients to TXT file
 */
export async function exportarClientes() {
    try {
        const result = await api.clientes({ action: 'exportar' });

        if (!result.ok || !result.texto) {
            ui.showErrorToast(result.error || 'No hay clientes para exportar');
            return;
        }

        const blob = new Blob([result.texto], { type: 'text/plain' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'clientes_cautivos.txt';
        a.click();
        URL.revokeObjectURL(url);

        ui.showSuccess(`Exportados: ${result.total} clientes`);
    } catch (error) {
        ui.showErrorToast('Error de conexión: ' + error.message);
    }
}
