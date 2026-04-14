// UI Helper Functions - Reusable DOM manipulation for tables, loading states, etc.

// StatusBar colors
const STATUS_COLORS = {
    loading: '#0d6efd',   // azul - indeterminate
    done:    '#2fb344',   // verde - 100%
    pending: '#f59f00',   // amarillo - X%
    error:   '#d63384',   // rojo - 100%
};

/**
 * Unified status bar (progress bar with state/colors)
 * @param {HTMLElement} container - element to render into
 * @param {string} state - 'loading' | 'done' | 'pending' | 'error'
 * @param {number|null} percent - 0-100 for pending, null for others
 * @param {string|null} label - optional text below bar
 */
function statusBar(container, state, percent = null, label = null) {
    const color = STATUS_COLORS[state] || STATUS_COLORS.loading;
    const isIndeterminate = state === 'loading';
    const width = state === 'loading' ? 100 : (state === 'pending' ? (percent || 50) : 100);
    const cls = isIndeterminate ? 'progress-bar progress-bar-indeterminate' : 'progress-bar';

    container.innerHTML = `
        <div class="progress progress-sm">
            <div class="${cls}" style="width:${width}%; background:${color}"></div>
        </div>
        ${label ? `<small class="text-muted mt-1">${label}</small>` : ''}
    `;
    container.style.display = '';
}

export const ui = {
    // Unified status bar (exported)
    statusBar,

    // Show empty state in a container
    showEmpty: (container, message) => {
        container.innerHTML = `
            <tr>
                <td colspan="100%" class="text-center text-muted py-4">
                    ${message || 'No hay datos disponibles'}
                </td>
            </tr>
        `;
    },

    // Show loading state in a table body (statusBar)
    showLoading: (tbody) => {
        tbody.innerHTML = `
            <tr>
                <td colspan="100%" class="py-4 px-5">
                    <div class="progress progress-sm">
                        <div class="progress-bar progress-bar-indeterminate" style="width:100%; background:${STATUS_COLORS.loading}"></div>
                    </div>
                </td>
            </tr>
        `;
    },

    // Show error state in a table body (statusBar rojo)
    showError: (tbody, message) => {
        tbody.innerHTML = `
            <tr>
                <td colspan="100%" class="py-4 px-5">
                    <div class="progress progress-sm">
                        <div class="progress-bar" style="width:100%; background:${STATUS_COLORS.error}"></div>
                    </div>
                    <small class="text-danger mt-1 d-block text-center">${message || 'Error al cargar los datos'}</small>
                </td>
            </tr>
        `;
    },

    // Notification Dropdown System
    _notifications: [],
    _maxNotifications: 25,

    // Initialize notification system
    initNotifications: () => {
        // Inject CSS styles if not already present
        if (!document.getElementById('notification-styles')) {
            const style = document.createElement('style');
            style.id = 'notification-styles';
            style.textContent = `
                .notification-item {
                    display: flex;
                    align-items: center;
                    padding: 8px 12px;
                    border-bottom: 1px solid rgba(0,0,0,0.05);
                    transition: background 0.2s;
                }
                
                .notification-item:hover {
                    background: rgba(0,0,0,0.02);
                }

                .notification-item .status-dot {
                    width: 8px;
                    height: 8px;
                    border-radius: 50%;
                    margin-right: 10px;
                    flex-shrink: 0;
                }
                
                .notification-item .status-dot.bg-red { background-color: #e74c3c; }
                .notification-item .status-dot.bg-green { background-color: #2ecc71; }
                
                .notification-item .content {
                    flex-grow: 1;
                    min-width: 0; /* Allow truncation */
                }

                .notification-item .header {
                    font-size: 12px;
                    font-weight: 600;
                    color: #333;
                    margin-bottom: 2px;
                }

                .notification-item .message {
                    font-size: 12px;
                    color: #666;
                    white-space: nowrap;
                    overflow: hidden;
                    text-overflow: ellipsis;
                }

                .notification-item .timestamp {
                    font-size: 10px;
                    color: #999;
                    margin-left: 8px;
                    flex-shrink: 0;
                }

                .badge-blink {
                    animation: blink 1s infinite;
                }

                @keyframes blink {
                    0%, 50% { opacity: 1; }
                    51%, 100% { opacity: 0.3; }
                }
            `;
            document.head.appendChild(style);
        }

        // Add event listener to clear notifications when clicking "Clear" button in modal
        const clearBtn = document.getElementById('clear-notifications-btn');
        if (clearBtn) {
            clearBtn.addEventListener('click', (e) => {
                e.preventDefault();
                // Clear all notifications
                ui._notifications = [];
                ui.renderNotifications();
                // Close the modal
                const modal = document.getElementById('notificationsModal');
                if (modal) {
                    const bsModal = bootstrap.Modal.getInstance(modal);
                    if (bsModal) bsModal.hide();
                }
            });
        }
    },

    // Render notifications to the dropdown
    renderNotifications: () => {
        const container = document.getElementById('notification-list');
        const countBadge = document.getElementById('notification-count');
        const notificationBtn = document.getElementById('notification-btn');
        
        if (!container) return;

        container.innerHTML = '';

        const hasErrors = ui._notifications.some(n => n.type === 'danger');
        const hasNotifications = ui._notifications.length > 0;

        ui._notifications.slice().reverse().forEach(notif => {
            const item = document.createElement('div');
            item.className = 'notification-item';
            
            const statusClass = notif.type === 'danger' ? 'bg-red' : notif.type === 'warning' ? 'bg-yellow' : 'bg-green';
            
            // Format: Dot + [RBFID] Message + Timestamp (compact, one line)
            item.innerHTML = `
                <span class="status-dot ${statusClass}"></span>
                <div class="content">
                    <div class="message">[${notif.rbfid}] ${notif.message}</div>
                </div>
                <span class="timestamp">${notif.timestamp}</span>
            `;
            
            container.appendChild(item);
        });

        // Update notification count badge
        if (countBadge) {
            countBadge.textContent = ui._notifications.length;
            countBadge.style.display = hasNotifications ? '' : 'none';
        }

        // Update notification button background
        if (notificationBtn) {
            if (hasNotifications) {
                notificationBtn.style.background = hasErrors ? '#ffe3e3' : '#d3f9d8';  // bg-pink-lt / bg-green-lt
            } else {
                notificationBtn.style.background = '#e9ecef';  // gray-100
            }
        }
    },

    // Add notification message
    addNotification: (message, type = 'info', rbfid = '-', actionType = '', duration = 15000) => {
        ui.initNotifications();

        const timestamp = new Date().toLocaleTimeString('es-ES', { hour12: false });
        
        // Ensure rbfid is never undefined or null
        if (rbfid === undefined || rbfid === null) {
            rbfid = '-';
        }
        
        const notification = {
            id: Date.now(),
            message: message,
            type: type,
            rbfid: rbfid,
            actionType: actionType,
            timestamp: timestamp
        };

        ui._notifications.push(notification);

        // Limit to maxNotifications
        if (ui._notifications.length > ui._maxNotifications) {
            ui._notifications.shift(); // Remove oldest
        }

        // Render immediately (time real sync)
        ui.renderNotifications();
    },

    // Show success message
    showSuccess: (message, rbfid = '-', actionType = '', duration = 15000) => {
        return ui.addNotification(message, 'success', rbfid, actionType, duration);
    },
    showWarning: (message, rbfid = '-', actionType = '', duration = 20000) => {
        return ui.addNotification(message, 'warning', rbfid, actionType, duration);
    },
    showErrorToast: (message, rbfid = '-', actionType = '', duration = 15000) => {
        ui.addNotification(message, 'danger', rbfid, actionType, duration);
    },

    // Format date for display
    formatDate: (dateString) => {
        if (!dateString) return 'Nunca';
        try {
            return new Date(dateString).toLocaleString();
        } catch (e) {
            return dateString;
        }
    }
};