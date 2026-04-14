<?php
// Dashboard page
$pageTitle = 'GIS - Dashboard';
$activePage = 'dashboard';
$pageHeader = 'Dashboard';
$pagePretitle = 'Overview';

require_once __DIR__ . '/ui/header.php';
?>

<!-- Page Content -->
<!-- Stats Cards -->
<div class="row row-deck row-cards">
    <div class="col-sm-6 col-lg-3">
        <div class="card">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="subheader">Total Clientes</div>
                </div>
                <div class="h1 mb-3" id="stat-total">-</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="subheader">Habilitados</div>
                </div>
                <div class="h1 mb-3 text-success" id="stat-enabled">-</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="subheader">Deshabilitados</div>
                </div>
                <div class="h1 mb-3 text-danger" id="stat-disabled">-</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="subheader">Última Sincronización</div>
                </div>
                <div class="h1 mb-3" id="stat-last-sync">-</div>
            </div>
        </div>
    </div>
</div>

<!-- Recent Activity -->
<div class="row mt-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Actividad Reciente</h3>
            </div>
            <div class="card-table table-responsive">
                <table class="table table-vcenter">
                    <thead>
                        <tr>
                            <th>Cliente</th>
                            <th>Acción</th>
                            <th>Estado</th>
                            <th>Fecha</th>
                        </tr>
                    </thead>
                    <tbody id="recent-activity">
                        <tr><td colspan="4" class="py-4 px-5"><div class="progress progress-sm"><div class="progress-bar progress-bar-indeterminate" style="width:100%; background:#0d6efd"></div></div></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script type="module">
import { api } from './js/apiService.js';
// Dashboard-specific JavaScript

async function loadStats() {
    try {
        const data = await api.getClientes();
        const clientes = data.clientes || [];
        const enabled = clientes.filter(c => c.enabled).length;
        const disabled = clientes.filter(c => !c.enabled).length;
        
        document.getElementById('stat-total').textContent = clientes.length;
        document.getElementById('stat-enabled').textContent = enabled;
        document.getElementById('stat-disabled').textContent = disabled;
    } catch(e) {
        console.error(e);
    }
}

async function loadRecentActivity() {
    try {
        const data = await api.getLogs(10);
        const logs = data.logs || [];
        
        if (logs.length === 0) {
            document.getElementById('recent-activity').innerHTML = 
                '<tr><td colspan="4" class="text-center text-muted">Sin actividad reciente</td></tr>';
            return;
        }
        
        const html = logs.map(log => {
            const statusClass = log.status === 'success' ? 'text-success' : 
                               log.status === 'failed' ? 'text-danger' : 'text-muted';
            return `
                <tr>
                    <td><strong>${log.rbfid}</strong></td>
                    <td>${log.action}</td>
                    <td><span class="${statusClass}">${log.status}</span></td>
                    <td>${new Date(log.started_at).toLocaleString()}</td>
                </tr>
            `;
        }).join('');
        
        document.getElementById('recent-activity').innerHTML = html;
    } catch(e) {
        console.error(e);
    }
}

// Load data on page load
loadStats();
loadRecentActivity();
</script>

<?php require_once __DIR__ . '/ui/footer.php'; ?>
