/**
 * Global Entry Point - Loaded on all pages
 * Initializes shared functionality like job polling
 */

import { startJobPolling } from './modules/jobTracker.js';

// Start job polling for all pages (every 5 seconds)
startJobPolling(5000);

console.log('[Global] Job polling started');
