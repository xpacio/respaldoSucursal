/**
 * Job Tracker Module
 * Handles polling of active jobs across all pages
 */

import { api } from '../apiService.js';
import { ui } from '../ui.js';
import { indicator, JOB_LABELS } from './indicator.js';

let activeJobs = new Map();
let pollingInterval = null;
let isPolling = false;

// Listen for job-started events from frontend
window.addEventListener('job-started', (e) => {
    const jobId = e.detail?.job_id;
    const jobType = e.detail?.job_type;
    const step = e.detail?.step || '';
    
    if (jobId && jobType) {
        indicator.job.add(jobId, jobType, step);
    }
});

window.addEventListener('job-completed', (e) => {
    const jobId = e.detail?.job_id;
    const success = e.detail?.success !== false;
    
    if (jobId) {
        indicator.job.update(jobId, success ? 'completed' : 'failed');
    }
});

function getJobLabel(type) {
    return JOB_LABELS[type] || JOB_LABELS['default'];
}

function isJobStuck(job) {
    if (job.state !== 'running') return false;
    if (!job.updated_at) return false;
    
    const updated = new Date(job.updated_at);
    const now = new Date();
    const diffMs = now - updated;
    const diffMinutes = diffMs / 1000 / 60;
    
    return diffMinutes > 10;
}

export async function checkActiveJobs() {
    if (isPolling) return;
    isPolling = true;
    
    try {
        // Poll both job systems in parallel
        const [adminResult, workerResult] = await Promise.all([
            api.adminJobsActive().catch(() => ({ ok: false })),
            api.jobList('running', 50).catch(() => ({ ok: false, jobs: [] }))
        ]);
        
        const allJobs = [];
        
        // System A: procesos_desacoplados (AdminService)
        if (adminResult.ok && adminResult.jobs) {
            for (const job of adminResult.jobs) {
                allJobs.push({
                    job_id: job.job_id,
                    job_type: job.job_type,
                    state: job.state,
                    current_step: job.current_step || '',
                    updated_at: job.updated_at,
                    source: 'admin'
                });
            }
        }
        
        // System B: jobs (BackendWorker)
        if (workerResult.ok && workerResult.jobs) {
            for (const job of workerResult.jobs) {
                allJobs.push({
                    job_id: job.id,
                    job_type: job.action,
                    state: job.status === 'queued' ? 'pending' : 'running',
                    current_step: job.progress_total > 0 
                        ? `${job.progress_current}/${job.progress_total}` 
                        : '',
                    updated_at: job.started_at || job.created_at,
                    source: 'worker'
                });
            }
        }
        
        if (allJobs.length === 0) {
            // Clean up any active job indicators
            for (const [jobId] of activeJobs) {
                activeJobs.delete(jobId);
            }
            if (activeJobs.size === 0) {
                indicator.job.updateJobsState?.('idle', 0);
            }
            return;
        }
        
        const currentJobs = new Map();
        
        for (const job of allJobs) {
            currentJobs.set(job.job_id, job);
            
            const stuck = isJobStuck(job);
            
            // New job - add indicator
            if (!activeJobs.has(job.job_id)) {
                activeJobs.set(job.job_id, job);
                indicator.job.add(job.job_id, job.job_type, job.current_step, stuck);
                if (stuck) {
                    ui.showErrorToast(`Job atascado: ${getJobLabel(job.job_type)}`, job.job_id, 'JOB-STUCK');
                } else {
                    ui.showSuccess(`Job iniciado: ${getJobLabel(job.job_type)}`, job.job_id, 'JOB-' + job.job_type);
                }
            }
            
            // Update step if changed
            const existingJob = activeJobs.get(job.job_id);
            if (job.state === 'running' && job.current_step && job.current_step !== existingJob.current_step) {
                indicator.job.update(job.job_id, stuck ? 'stuck' : 'running', job.current_step);
                existingJob.current_step = job.current_step;
                existingJob.updated_at = job.updated_at;
            }
            
            // Check if job became stuck
            if (job.state === 'running' && stuck && !existingJob.stuck) {
                indicator.job.update(job.job_id, 'stuck', job.current_step);
                existingJob.stuck = true;
                ui.showErrorToast(`Job atascado: ${getJobLabel(job.job_type)} - Sin progreso en >10 min`, job.job_id, 'JOB-STUCK');
            }
        }
        
        // Remove jobs that no longer exist or are completed
        for (const [jobId, job] of activeJobs) {
            if (!currentJobs.has(jobId)) {
                indicator.job.update(jobId, 'completed');
                activeJobs.delete(jobId);
            }
        }
        
    } catch (e) {
        console.error('Error checking active jobs:', e);
    } finally {
        isPolling = false;
    }
}

export function startJobPolling(intervalMs = 5000) {
    if (pollingInterval) return;
    
    checkActiveJobs();
    pollingInterval = setInterval(checkActiveJobs, intervalMs);
}

export function stopJobPolling() {
    if (pollingInterval) {
        clearInterval(pollingInterval);
        pollingInterval = null;
    }
}

export function hasActiveJobs() {
    return activeJobs.size > 0;
}
