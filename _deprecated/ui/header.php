<?php
// /var/www/sync/ui/header.php
// Common header with navbar - pass $activePage to highlight current menu item

$activePage = $activePage ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'GIS' ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.4.0/dist/css/tabler.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.4.0/dist/css/tabler-flags.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; }
        <?= $extraCss ?? '' ?>
    </style>
    
    <!-- Common JavaScript will be loaded via module imports in each page -->
</head>
<body>
<?php require_once __DIR__ . '/icons.php'; ?>

<?php if (!isset($hideNavbar) || !$hideNavbar): ?>
<div class="navbar navbar-expand sticky-top">
    <div class="container">
        <div class="col">
            <ul class="navbar-nav ms-auto gap-2 align-items-center">
                <div class="nav-item<?= $activePage === 'clientes' ? ' active' : '' ?>">
                    <a href="/clientes" class="nav-link">Clientes</a>
                </div>
                <div class="nav-item<?= $activePage === 'plantillas' ? ' active' : '' ?>">
                    <a href="/plantillas" class="nav-link">Plantillas</a>
                </div>
                <div class="nav-item<?= $activePage === 'distribucion' ? ' active' : '' ?>">
                    <a href="/distribucion" class="nav-link">Distribución</a>
                </div>
                <div class="nav-item dropdown">
                    <a href="#" class="nav-link dropdown-toggle<?= in_array($activePage, ['dashboard', 'logs', 'permisos', 'agente', 'sheditor']) ? ' active' : '' ?>" data-bs-toggle="dropdown">Herramientas</a>
                    <div class="dropdown-menu">
                        <a class="dropdown-item<?= $activePage === 'dashboard' ? ' active' : '' ?>" href="/dashboard">Dashboard</a>
                        <a class="dropdown-item<?= $activePage === 'logs' ? ' active' : '' ?>" href="/logs">Logs</a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item<?= $activePage === 'permisos' ? ' active' : '' ?>" href="/permisos">Permisos</a>
                        <a class="dropdown-item<?= $activePage === 'agente' ? ' active' : '' ?>" href="/agente">Agente</a>
                        <a class="dropdown-item<?= $activePage === 'sheditor' ? ' active' : '' ?>" href="/sheditor">Sh Editor</a>
                    </div>
                </div>
                <div class="nav-item">
                    <a href="/logout" class="nav-link text-danger">Salir</a>
                </div>
            </ul>
        </div>
        <div class="col col-md-auto">
            <ul class="navbar-nav ms-auto gap-2 align-items-center">
                <!-- API Status Icon (fijo) -->
                <div class="nav-item d-none d-md-flex">
                    <span id="api-status-icon" class="d-none" style="color: #0d6efd;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-api"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 13h5" /><path d="M12 16v-8h3a2 2 0 0 1 2 2v1a2 2 0 0 1 -2 2h-3" /><path d="M20 8v8" /><path d="M9 16v-5.5a2.5 2.5 0 0 0 -5 0v5.5" /></svg>
                    </span>
                </div>
                <!-- Jobs Container (nav-items dinámicos se crean aquí) -->
                <div id="jobs-container" class="d-none d-md-flex"></div>
                <!-- Notifications Button (Extrema Derecha) -->
                <div class="nav-item d-none d-md-flex">
                    <a href="#" class="nav-link px-0 rounded p-1" id="notification-btn" data-bs-toggle="modal" data-bs-target="#notificationsModal" tabindex="-1" aria-label="Show notifications" style="background: #e9ecef;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="icon icon-1">
                            <path d="M10 5a2 2 0 1 1 4 0a7 7 0 0 1 4 6v3a4 4 0 0 0 2 3h-16a4 4 0 0 0 2 -3v-3a7 7 0 0 1 4 -6"></path>
                            <path d="M9 17v1a3 3 0 0 0 6 0v-1"></path>
                        </svg>
                    </a>
                </div>
            </ul>
        </div>
    </div>
</div>

<!-- Notifications Modal -->
<div class="modal modal-blur fade" id="notificationsModal" tabindex="-1" role="dialog" aria-modal="true">
    <div class="modal-dialog modal-xl modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Notificaciones</h5>
                <div class="d-flex gap-2 align-items-center">
                    <input type="text" class="form-control form-control-sm" id="log-filter" placeholder="Filtrar logs..." style="width: 200px;">
                    <span id="notification-count" class="badge bg-primary rounded-pill">0</span>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </div>
            <div class="modal-body p-0">
                <div class="list-group list-group-flush list-group-hoverable" id="notification-list" style="max-height: 70vh; overflow-y: auto;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-link link-secondary" id="clear-notifications-btn">Limpiar notificaciones</button>
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<!-- Page Body -->
<div class="page-body">
    <div class="container-fluid">
    <!-- <div class="container-xl"> -->
