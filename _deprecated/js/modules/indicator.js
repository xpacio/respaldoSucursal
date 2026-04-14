/**
 * Indicator Module
 * Simple API for controlling header indicators (API status + Job indicators)
 * 
 * Usage:
 *   import { indicator } from './indicator.js';
 *   
 *   // API indicator (always shows request activity)
 *   indicator.api.start();       // Show API icon (blue)
 *   indicator.api.done();        // Show success (green, hide immediately)
 *   indicator.api.error();       // Show error (red, hide after 500ms)
 *   
 *   // Job indicators (each job gets its own nav-item)
 *   indicator.job.add('reset_123', 'system_reset', 'pending');
 *   indicator.job.update('reset_123', 'running');
 *   indicator.job.update('reset_123', 'completed');
 *   indicator.job.update('reset_123', 'failed');
 *   indicator.job.update('reset_123', 'stuck');
 */

const API_COLORS = {
    loading: '#0d6efd',   // Blue
    done:    '#2fb344',   // Green
    error:   '#dc3545'    // Red
};

const JOB_COLORS = {
    pending: '#fd7e14',  // Orange
    active:    '#0ca678',  // Teal/Green
    success:   '#2fb344',  // Green
    error:     '#dc3545',   // Red
    stuck:     '#dc3545'    // Red (stuck = error)
};

const JOB_STATUS_COLORS = {
    pending: '#fd7e14',    // Orange
    running: '#0ca678',   // Teal
    completed: '#2fb344',  // Green
    failed: '#dc3545',    // Red
    stuck: '#dc3545'      // Red
};

const NOTIFICATION_ICONS = {
    idle: `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-list-search"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><path d="M11 15a4 4 0 1 0 8 0a4 4 0 1 0 -8 0"></path><path d="M18.5 18.5l2.5 2.5"></path><path d="M4 6h16"></path><path d="M4 12h4"></path><path d="M4 18h4"></path></svg>`,
    
    loading: `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-loader-2"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><path d="M12 3a9 9 0 1 0 9 9"></path></svg>`,
    
    pending: `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-clock"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><path d="M12 12v-2a2 2 0 1 1 4 0v2"></path><path d="M12 22v-6"></path><path d="M12 6v6"></path><circle cx="12" cy="12" r="10"></circle></svg>`,
    
    running: `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-player-play"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><path d="M7 4v16"></path><path d="M7 12v10"></path><path d="M17 8v8"></path><path d="M19 4v16"></path></svg>`,
    
    completed: `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-check"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><path d="M5 12l5 5l10 -10"></path></svg>`,
    
    failed: `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-x"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><path d="M18 6L6 18"></path><path d="M6 6l12 12"></path></svg>`,
    
    stuck: `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-code-circle"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M10 14l-2 -2l2 -2" /><path d="M14 10l2 2l-2 2" /><path d="M3 12a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /></svg>`
};

const PROMPT_SVG = `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-prompt"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 7l5 5l-5 5"/><path d="M13 17l6 0"/></svg>`;

const JOB_ICONS = {
    idle: PROMPT_SVG,
    pending: PROMPT_SVG,
    running: PROMPT_SVG,
    completed: PROMPT_SVG,
    failed: PROMPT_SVG,
    stuck: PROMPT_SVG
};

export const JOB_LABELS = {
    'system_reset': 'Reset Sistema',
    'limpiar_cautivos': 'Limpiar Cautivos',
    'regenerar_cautivos': 'Regenerar Clientes',
    'batch_ssh_enable': 'Habilitar SSH',
    'batch_ssh_disable': 'Deshabilitar SSH',
    'batch_enable': 'Habilitar Clientes',
    'batch_disable': 'Deshabilitar Clientes',
    'batch_key_dl_enable': 'Habilitar Clave',
    'batch_key_dl_disable': 'Deshabilitar Clave',
    'batch_regen': 'Regenerar Claves',
    'batch_dl': 'Descargar Claves',
    'distribuir_batch': 'Distribuir (vista)',
    'distribuir_all': 'Distribuir (todas)',
    'default': 'Job'
};

// Track active job indicators
const activeJobIndicators = new Map();

function getJobsContainer() {
    return document.getElementById('jobs-container');
}

function updateJobsIndicator(state = 'idle', count = 0) {
    const container = getJobsContainer();
    if (!container) return;
    
    // Remove existing idle indicator
    let indicator = container.querySelector('.jobs-status-indicator');
    
    // When idle or no jobs, remove the indicator entirely
    if (state === 'idle' || count === 0) {
        if (indicator) {
            indicator.remove();
        }
        return;
    }
    
    // For active states, show count
    if (!indicator) {
        indicator = document.createElement('div');
        indicator.className = 'jobs-status-indicator nav-item ms-2';
        container.appendChild(indicator);
    }
    
    const colorMap = {
        'active': '#0ca678',
        'stuck': '#dc3545',
        'success': '#2fb344',
        'error': '#dc3545'
    };
    
    const labelMap = {
        'active': `${count} job(s) activo(s)`,
        'stuck': `${count} job(s) atascado(s)`,
        'success': 'Job completado',
        'error': 'Job con errores'
    };
    
    indicator.innerHTML = `
        <span class="d-flex align-items-center" title="${labelMap[state]}">
            <span class="d-inline-block" style="color: ${colorMap[state]}">${JOB_ICONS.pending}</span>
        </span>
    `;
}

function getJobLabel(jobType) {
    return JOB_LABELS[jobType] || JOB_LABELS['default'];
}

function getApiContainer() {
    return document.getElementById('api-status-icon');
}

function setApiIcon(color) {
    const container = getApiContainer();
    if (!container) return;
    
    container.style.color = color;
    container.classList.remove('d-none');
    container.style.opacity = '1';
}

/**
 * Create a new job indicator (nav-item)
 */
function createJobNav(jobId, jobType, step = '', stuck = false) {
    const container = getJobsContainer();
    if (!container) return null;
    
    const label = getJobLabel(jobType);
    const title = step ? `${label}: ${step}` : (stuck ? `${label}: ATASCADO` : label);
    
    const navItem = document.createElement('div');
    navItem.className = 'nav-item';
    navItem.id = `job-nav-${jobId}`;
    navItem.innerHTML = `
        <span class="d-flex align-items-center" title="${title}">
            <span class="d-inline-block me-1">${JOB_ICONS.pending}</span>
        </span>
    `;
    
    const svg = navItem.querySelector('svg');
    if (svg) svg.style.color = stuck ? JOB_COLORS.error : JOB_COLORS.active;
    
    container.appendChild(navItem);
    activeJobIndicators.set(jobId, { navItem, jobType, step, stuck });
    
    // Update jobs indicator
    updateJobsIndicator('active', activeJobIndicators.size);
    
    return navItem;
}

/**
 * Update job indicator state
 */
function updateJobNav(jobId, state, step = '') {
    const jobData = activeJobIndicators.get(jobId);
    if (!jobData) return;
    
    const { navItem, jobType } = jobData;
    const label = getJobLabel(jobType);
    const title = step ? `${label}: ${step}` : label;
    
    // Update title
    const span = navItem.querySelector('span[title]');
    if (span) span.setAttribute('title', title);
    
    // Update icon color (icon stays the same, only color changes)
    const iconContainer = navItem.querySelector('.d-inline-block');
    if (!iconContainer) return;
    
    const svg = iconContainer.querySelector('svg');
    if (svg) {
        if (state === 'completed') {
            svg.style.color = JOB_COLORS.success;
        } else if (state === 'failed' || state === 'stuck') {
            svg.style.color = JOB_COLORS.error;
        } else if (state === 'running') {
            svg.style.color = JOB_COLORS.active;
        }
    }
    
    // Update jobs indicator based on state
    if (state === 'completed') {
        updateJobsIndicator('success', activeJobIndicators.size);
    } else if (state === 'failed' || state === 'stuck') {
        updateJobsIndicator('error', activeJobIndicators.size);
    } else if (state === 'running') {
        updateJobsIndicator('active', activeJobIndicators.size);
    }
    
    jobData.step = step;
}

/**
 * Remove job indicator (with delay for completed/failed)
 */
function removeJobNav(jobId, delayMs = 2000) {
    setTimeout(() => {
        const jobData = activeJobIndicators.get(jobId);
        if (!jobData) return;
        
        jobData.navItem.remove();
        activeJobIndicators.delete(jobId);
        
        // Update jobs indicator if no more active jobs
        if (activeJobIndicators.size === 0) {
            updateJobsIndicator('idle', 0);
        } else {
            // Check if any remaining jobs are stuck
            let anyStuck = false;
            for (const [, data] of activeJobIndicators) {
                if (data.stuck) {
                    anyStuck = true;
                    break;
                }
            }
            updateJobsIndicator(anyStuck ? 'error' : 'active', activeJobIndicators.size);
        }
    }, delayMs);
}

// Public API
export const indicator = {
    /**
     * API indicator - shows when any API call is made
     */
    api: {
        /** Show API icon in blue (loading) */
        start() {
            setApiIcon(API_COLORS.loading);
        },
        
        /** Show API icon in green and hide immediately */
        done() {
            setApiIcon(API_COLORS.done);
            const container = getApiContainer();
            if (container) {
                container.classList.add('d-none');
            }
        },
        
        /** Show API icon in red and hide after 500ms */
        error() {
            setApiIcon(API_COLORS.error);
            setTimeout(() => {
                const container = getApiContainer();
                if (container) container.classList.add('d-none');
            }, 500);
        }
    },
    
    /**
     * Job indicators - each job gets its own nav-item
     */
    job: {
        /** 
         * Add a new job indicator
         * @param {string} jobId - Unique job ID
         * @param {string} jobType - Type of job (system_reset, etc.)
         * @param {string} step - Current step (optional)
         */
        add(jobId, jobType, step = '') {
            createJobNav(jobId, jobType, step);
        },
        
        /**
         * Update existing job indicator state
         * @param {string} jobId - Job ID
         * @param {string} state - 'running', 'completed', 'failed'
         * @param {string} step - Current step (optional)
         */
        update(jobId, state, step = '') {
            updateJobNav(jobId, state, step);
            
            // Auto-remove after delay for completed/failed
            if (state === 'completed') {
                removeJobNav(jobId, 5000);
            } else if (state === 'failed') {
                removeJobNav(jobId, 5000);
            }
        },
        
        /**
         * Remove job indicator immediately
         * @param {string} jobId - Job ID
         */
        remove(jobId) {
            const jobData = activeJobIndicators.get(jobId);
            if (jobData) {
                jobData.navItem.remove();
                activeJobIndicators.delete(jobId);
            }
        },
        
        /**
         * Check if job indicator exists
         * @param {string} jobId - Job ID
         * @returns {boolean}
         */
        exists(jobId) {
            return activeJobIndicators.has(jobId);
        },
        
        /**
         * Get count of active job indicators
         * @returns {number}
         */
        count() {
            return activeJobIndicators.size;
        },
        
        /**
         * Update jobs container indicator
         * @param {string} state - 'idle', 'active', 'stuck', 'success', 'error'
         * @param {number} count - number of active jobs
         */
        updateJobsState(state, count) {
            updateJobsIndicator(state, count);
        }
    }
};

export default indicator;
