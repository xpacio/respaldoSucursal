/**
 * Toast notification utility
 * @param {string} msg - Message to display
 * @param {string} type - Bootstrap alert type: 'success', 'warning', 'danger', 'info'
 */
export function toast(msg, type = 'success') {
    const div = document.createElement('div');
    div.className = `alert alert-${type} alert-dismissible position-fixed top-0 end-0 m-3`;
    div.style.zIndex = '9999';
    div.innerHTML = `${msg}<button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;
    document.body.appendChild(div);
    setTimeout(() => div.remove(), 4000);
}
