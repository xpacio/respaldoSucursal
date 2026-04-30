<?php declare(strict_types=1);
namespace App\Web;

require_once __DIR__ . '/shared_server.php';
use App\DB;
use App\Config;

class AdminUI {
    private DB $db;
    private string $action;
    private string $target;
    private string $login_error;


    public function __construct() {
        if (session_status() === PHP_SESSION_NONE) session_start();

        // Manejo de Logout
        if (isset($_GET['logout'])) {
            session_destroy();
            header("Location: /");
            exit;
        }

        // Manejo de Login
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_btn'])) {
            $user = $_POST['user'] ?? '';
            $pass = $_POST['pass'] ?? '';
            // Credenciales: mover a variables de entorno o gestor de secretos en producción
            if ($user === 'admin' && $pass === 'admin123') {
                $_SESSION['admin_auth'] = true;
                header("Location: /");
                exit;
            } else {
                $this->login_error = "Credenciales inválidas";
            }
        }

        $this->db = new DB(Config::getDb());
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $parts = explode('/', trim($uri, '/'));
        $this->action = $parts[0] ?: 'dashboard';
        $this->target = $parts[1] ?? '';

        // Acción de truncar tabla (Solo usuarios autenticados)
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['truncate_table']) && ($_SESSION['admin_auth'] ?? false)) {
            $tbl = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['truncate_table']);
            $this->db->exec("TRUNCATE TABLE $tbl RESTART IDENTITY CASCADE");
            header("Location: /table/$tbl");
            exit;
        }
        
        // Guardar servicio (Solo usuarios autenticados)
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_service']) && ($_SESSION['admin_auth'] ?? false)) {
            $id = (int)($_POST['service_id'] ?? 0);
            $name = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['name'] ?? '');
            $type = preg_replace('/[^a-zA-Z]/', '', $_POST['type'] ?? 'sync');
            $files = $_POST['files'] ?? '';
            $direction = $_POST['direction'] ?? 'upload';
            $temp = $_POST['temp'] ?? '%tmp%/respaldoSucursal/{service}';
            $dest = $_POST['dest'] ?? '/srv/qbck/{emp}/{plaza}/{rbfid}';
            $source = $_POST['source'] ?? '{base}';
            $recursive = isset($_POST['recursive']) ? 't' : 'f';
            $exclude = $_POST['exclude'] ?? '';
            $maxage = ($_POST['maxage'] ?? '') !== '' ? (int)$_POST['maxage'] : null;
            $description = $_POST['description'] ?? '';
            $defaultConfig = '{}';
            $defaultFrequency = 300;
            $enabled = isset($_POST['enabled']) ? 'true' : 'false';
            
            if ($id > 0) {
                $this->db->exec("UPDATE services SET name=:n, type=:t, files=:f, direction=:d, temp=:temp, dest=:dest, source=:source, recursive=:r, exclude=:e, maxage=:m, description=:desc, enabled=:en WHERE id=:id", 
                    [':id'=>$id, ':n'=>$name, ':t'=>$type, ':f'=>$files, ':d'=>$direction, ':temp'=>$temp, ':dest'=>$dest, ':source'=>$source, ':r'=>$recursive, ':e'=>$exclude, ':m'=>$maxage, ':desc'=>$description, ':en'=>$enabled]);
            } else {
                $this->db->exec("INSERT INTO services (name, type, files, direction, temp, dest, source, recursive, exclude, maxage, description, default_config, default_frequency_seconds, enabled) VALUES (:n, :t, :f, :d, :temp, :dest, :source, :r, :e, :m, :desc, :cfg, :freq, :en)", 
                    [':n'=>$name, ':t'=>$type, ':f'=>$files, ':d'=>$direction, ':temp'=>$temp, ':dest'=>$dest, ':source'=>$source, ':r'=>$recursive, ':e'=>$exclude, ':m'=>$maxage, ':desc'=>$description, ':cfg'=>$defaultConfig, ':freq'=>$defaultFrequency, ':en'=>$enabled]);
            }
            header("Location: /services");
            exit;
        }
    }

    private function renderLogin(): void {
        ?>
        <div class="page-center">
            <div class="page-body">
                <div class="container-tight">
                    <div class="card card-md">
                        <div class="card-body">
                            <h2 class="card-title text-center mb-4">Acceso Admin</h2>
                            <form method="post">
                                <div class="mb-3">
                                    <label class="form-label">Usuario</label>
                                    <input type="text" name="user" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Contraseña</label>
                                    <input type="password" name="pass" class="form-control" required>
                                </div>
                                <button type="submit" name="login_btn" class="btn btn-primary w-100">Entrar</button>
                                <?php if (isset($this->login_error)): ?>
                                <div class="alert alert-danger mt-3"><?= $this->login_error ?></div>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function render(): void {
        ?>
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <title>Admin Servidor</title>
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.4.0/dist/css/tabler.min.css" />
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.4.0/dist/css/tabler-icons.min.css" />
        </head>
        <body>
            <div class="page-wrapper">
            <header class="navbar navbar-expand-md d-print-none">
                <div class="container-xl">
                    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbar-menu">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                    <h1 class="navbar-brand navbar-brand-autodark">
                        Administración Web
                    </h1>
                    <?php if ($_SESSION['admin_auth'] ?? false): ?>
                    <div class="navbar-nav flex-row-order-md-last">
                        <a href="/" class="nav-link">Tablas</a>
                        <a href="/services" class="nav-link">Servicios</a>
                        <a href="/logs" class="nav-link">Logs</a>
                        <a href="/?logout=1" class="nav-link">
			    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-door-exit"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M13 12v.01" /><path d="M3 21h18" /><path d="M5 21v-16a2 2 0 0 1 2 -2h7.5m2.5 10.5v7.5" /><path d="M14 7h7m-3 -3l3 3l-3 3" /></svg>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </header>

            <div class="page-body">
                <div class="container-xl">
                <?php
                if (!($_SESSION['admin_auth'] ?? false)) {
                    $this->renderLogin();
                } elseif ($this->action === 'table') {
                    $this->viewTable($this->target);
                } elseif ($this->action === 'logs') {
                    $this->viewLogs();
                } else {
                    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
                    $uriParts = explode('/', trim($uri, '/'));
                    $serviceId = ($this->action === 'services' && $this->target === 'edit' && isset($uriParts[2])) ? (int)$uriParts[2] : 0;
                    
                    if ($this->action === 'services' && $serviceId > 0) {
                        $this->editService($serviceId);
                    } elseif ($this->action === 'services' && $this->target === 'new') {
                        $this->editService(0);
                    } elseif ($this->action === 'services') {
                        $this->viewServices();
                    } else {
                        $this->dashboard();
                    }
                }
                ?>
                </div>
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/@tabler/core@1.4.0/dist/js/tabler.min.js"></script>
        </body>
        </html>
        <?php
    }

    private function dashboard(): void {
        $tables = $this->db->qa("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE' ORDER BY table_name");
        echo "<h3>Base de Datos</h3>";
        echo "<div class='row row-cards'>";
        foreach ($tables as $t) {
            $name = $t['table_name'];
            echo "<div class='col-sm-6 col-lg-3'>";
            echo "<a href='/table/$name' class='card card-link'>";
            echo "<div class='card-body'>";
            echo "<div class='h1 m-0'><svg xmlns='http://www.w3.org/2000/svg' class='icon icon-lg' width='24' height='24' viewBox='0 0 24 24' stroke-width='2' stroke='currentColor' fill='none' stroke-linecap='round' stroke-linejoin='round'><rect x='3' y='3' width='18' height='18' rx='2' ry='2'/><line x1='3' y1='9' x2='21' y2='9'/><line x1='9' y1='21' x2='9' y2='9'/></svg> $name</div>";
            echo "</div></a></div>";
        }
        echo "</div>";
    }

    private function viewTable(string $name): void {
        $name = preg_replace('/[^a-zA-Z0-9_]/', '', $name);
        $cols = $this->db->qa("SELECT column_name FROM information_schema.columns WHERE table_name = :t", [':t' => $name]);
        $order = "1";
        foreach ($cols as $c) if (in_array($c['column_name'], ['updated_at', 'id', 'created_at', 'completed_at'])) { $order = $c['column_name']; break; }
        
        $data = $this->db->qa("SELECT * FROM $name ORDER BY $order DESC LIMIT 100");

        echo "<div class='d-flex justify-content-between align-items-center mb-3'>";
        echo "<h3 class='m-0'>$name</h3>";
        echo "<div>";
        echo "<a href='/' class='btn btn-secondary me-2'>Regresar</a>";
        echo "<form method='post' class='d-inline' onsubmit=\"return confirm('¿Estás seguro de truncar la tabla $name? Esta acción borrará todos los registros.')\">";
        echo "<input type='hidden' name='truncate_table' value='$name'>";
        echo "<button type='submit' class='btn btn-danger'>Truncar Tabla</button>";
        echo "</form>";
        echo "</div></div>";
        
        echo "<div class='card'><div class='table-responsive'><table class='table table-vcenter card-table table-striped'>";
        if (!empty($data)) {
            echo "<thead><tr>";
            foreach (array_keys($data[0]) as $h) echo "<th>$h</th>";
            echo "</tr></thead><tbody>";
            foreach ($data as $row) {
                echo "<tr>";
                foreach ($row as $v) echo "<td>" . (is_array($v) || is_object($v) ? json_encode($v) : htmlspecialchars((string)$v)) . "</td>";
                echo "</tr>";
            }
            echo "</tbody>";
        } else { echo "<tr><td>Sin registros disponibles</td></tr>"; }
        echo "</table></div></div>";
    }

    private function viewLogs(): void {
        echo "<h3>Monitoreo de Logs</h3>";

        $logSources = [
            'Syslog' => "tail -n 30 /var/log/syslog | cut -d' ' -f1- | sort -r",
            'Lighttpd' => "tail -n 30 /var/log/lighttpd/access.log | sort -r",
            'PHP-FPM' => "tail -n 30 /var/log/php8.4-fpm.log | cut -d' ' -f1- | sort -r",
            'PostgreSQL' => "tail -n 30 /var/log/postgresql/postgresql-16-main.log | cut -d' ' -f4- | sort -r"
        ];

        echo '<div class="card">';
        echo '<div class="card-header"><ul class="nav nav-tabs card-header-tabs" data-bs-toggle="tabs">';
        
        $first = true;
        $tabIds = [];
        foreach ($logSources as $title => $cmd) {
            $tabId = 'log-' . strtolower(str_replace([' ', '-', '-'], '', $title));
            $tabIds[$title] = $tabId;
            $activeClass = $first ? 'active' : '';
            echo '<li class="nav-item"><a href="#' . $tabId . '" class="nav-link ' . $activeClass . '" data-bs-toggle="tab">' . $title . '</a></li>';
            $first = false;
        }
        
        echo '</ul></div>';
        echo '<div class="card-body"><div class="tab-content">';
        
        $first = true;
        foreach ($logSources as $title => $cmd) {
            $tabId = $tabIds[$title];
            $activeClass = $first ? 'active show' : '';
            $logContent = htmlspecialchars(@shell_exec($cmd) ?: "Sin registros o error de permisos ($cmd).");
            echo '<div class="tab-pane ' . $activeClass . '" id="' . $tabId . '"><pre class="m-0">' . $logContent . '</pre></div>';
            $first = false;
        }
        
        echo '</div></div></div>';
    }

private function viewServices(): void {
        ?>
        <div class="page-header d-print-none" aria-label="Page header">
          <div class="container-xl">
            <div class="row g-2 align-items-center">
              <div class="col">
                <div class="page-pretitle">Services</div>
                <h2 class="page-title">Services</h2>
              </div>
              <div class="col-auto ms-auto d-print-none">
                <div class="btn-list">
                  <a href="/services/new" class="btn btn-primary d-none d-sm-inline-block">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-2">
                      <path d="M12 5l0 14"></path>
                      <path d="M5 12l14 0"></path>
                    </svg>
                    Create new service
                  </a>
                  <a href="/services/new" class="btn btn-primary d-sm-none btn-icon" aria-label="Create new service">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-2">
                      <path d="M12 5l0 14"></path>
                      <path d="M5 12l14 0"></path>
                    </svg>
                  </a>
                </div>
              </div>
            </div>
          </div>
        </div>
        <?php
        
        echo "<div class='card mt-3'><table class='table table-striped mb-0'>";
        echo "<thead><tr><th>ID</th><th>Nombre</th><th>Tipo</th><th>Direction</th><th>Archivos</th><th>MaxAge</th><th>Exclude</th><th>Recursive</th><th>Enabled</th><th>Acciones</th></tr></thead>";
        echo "<tbody>";
        $services = $this->db->qa("SELECT * FROM services ORDER BY name");
        foreach ($services as $s) {
            $filesStr = trim($s['files'] ?? '');
            $fileCount = $filesStr === '' ? 0 : count(explode(',', $filesStr));
            $maxage = $s['maxage'] ?? '-';
            $exclude = $s['exclude'] ?? '';
            
echo "<tr>";
            echo "<td>{$s['id']}</td>";
            echo "<td><strong>{$s['name']}</strong></td>";
            echo "<td>" . $this->iconType($s['type']) . "</td>";
            echo "<td>" . $this->iconDirection($s['direction']) . "</td>";
            echo "<td>{$fileCount}</td>";
            echo "<td>" . ($maxage !== '-' ? $maxage : "-") . "</td>";
            echo "<td><small>" . htmlspecialchars(substr($exclude, 0, 30)) . "</small></td>";
            echo "<td>" . $this->iconRecursive($s['recursive']) . "</td>";
            echo "<td>" . $this->iconEnabled($s['enabled']) . "</td>";
            echo "<td><a href='/services/edit/{$s['id']}' class='btn btn-sm btn-outline-primary'>Editar</a></td>";
            echo "</tr>";
        }
        echo "</tbody></table></div>";
    }

    private function iconType(?string $type): string {
        $type = $type ?? 'sync';
        $icons = [
            'sync' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler text-info"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4" /><path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4" /></svg>',
            'monitor' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler text-danger"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M3 5a1 1 0 0 1 1 -1h16a1 1 0 0 1 1 1v10a1 1 0 0 1 -1 1h-16a1 1 0 0 1 -1 -1l0 -10" /><path d="M7 20h10" /><path d="M9 16v4" /><path d="M15 16v4" /><path d="M7 10h2l2 3l2 -6l1 3h3" /></svg>',
            'download' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler text-warning"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M15 12h3.586a1 1 0 0 1 .707 1.707l-6.586 6.586a1 1 0 0 1 -1.414 0l-6.586 -6.586a1 1 0 0 1 .707 -1.707h3.586v-3h6v3" /><path d="M15 3h-6" /><path d="M15 6h-6" /></svg>',
            'upload' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler text-success"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M9 12h-3.586a1 1 0 0 1 -.707 -1.707l6.586 -6.586a1 1 0 0 1 1.414 0l6.586 6.586a1 1 0 0 1 -.707 1.707h-3.586v3h-6v-3" /><path d="M9 21h6" /><path d="M9 18h6" /></svg>',
        ];
        $icon = $icons[$type] ?? '<span class="text-muted">?</span>';
        return "$icon <span class='ms-1'>{$type}</span>";
    }

    private function iconDirection(?string $direction): string {
        $direction = $direction ?? 'upload';
        $icons = [
            'upload' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler text-success"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M5 3h1a1 1 0 0 1 1 1v2h3v-2a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v2h3v-2a1 1 0 0 1 1 -1h1a1 1 0 0 1 1 1v4.394a2 2 0 0 1 -.336 1.11l-1.328 1.992a2 2 0 0 0 -.336 1.11v7.394a1 1 0 0 1 -1 1h-10a1 1 0 0 1 -1 -1v-7.394a2 2 0 0 0 -.336 -1.11l-1.328 -1.992a2 2 0 0 1 -.336 -1.11v-4.394a1 1 0 0 1 1 -1" /><path d="M10 21v-5a2 2 0 1 1 4 0v5" /></svg>',
            'download' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler text-warning"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M15 12h3.586a1 1 0 0 1 .707 1.707l-6.586 6.586a1 1 0 0 1 -1.414 0l-6.586 -6.586a1 1 0 0 1 .707 -1.707h3.586v-3h6v3" /><path d="M15 3h-6" /><path d="M15 6h-6" /></svg>',
        ];
        $icon = $icons[$direction] ?? '<span class="text-muted">?</span>';
        return "$icon <span class='ms-1'>{$direction}</span>";
    }

    private function iconRecursive(?bool $recursive): string {
        if ($recursive === true) {
            return '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler text-primary"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M9 3h3l2 2h5a2 2 0 0 1 2 2v7a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-9a2 2 0 0 1 2 -2" /><path d="M17 16v2a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-9a2 2 0 0 1 2 -2h2" /></svg>';
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler text-muted"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M5 4h4l3 3h7a2 2 0 0 1 2 2v8a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-11a2 2 0 0 1 2 -2" /></svg>';
    }

    private function iconEnabled(?bool $enabled): string {
        if ($enabled === true) {
            return '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler text-success"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M3 12a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M9 12l2 2l4 -4" /></svg>';
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler text-danger"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M3 12a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M9 10h.01" /><path d="M15 10h.01" /><path d="M9.5 15.5a3.5 3.5 0 0 0 5 0" /></svg>';
    }

    private function editService(int $id): void {
        $service = ['id'=>0, 'name'=>'', 'type'=>'sync', 'files'=>'', 'direction'=>'upload', 'temp'=>'%tmp%/respaldoSucursal/{service}', 'dest'=>'/srv/qbck/{emp}/{plaza}/{rbfid}', 'source'=>'{base}', 'recursive'=>false, 'exclude'=>'', 'maxage'=>null, 'description'=>'', 'enabled'=>true];
        if ($id > 0) {
            $row = $this->db->q("SELECT * FROM services WHERE id=:id", [':id'=>$id]);
            if ($row) $service = array_merge($service, $row);
        }
        
        echo "<h3>" . ($id > 0 ? "Editar" : "Nuevo") . " Servicio</h3>";
        echo "<div class='card mb-3'><div class='card-body'>";
        echo "<form method='post'>";
        echo "<input type='hidden' name='save_service' value='1'>";
        echo "<input type='hidden' name='service_id' value='{$service['id']}'>";
        
        echo "<div class='row mb-3'>";
        echo "<div class='col-md-4'><label class='form-label'>Nombre</label><input type='text' name='name' class='form-control' value='" . htmlspecialchars($service['name']) . "' required></div>";
        echo "<div class='col-md-4'><label class='form-label'>Tipo</label><select name='type' class='form-select'>
            <option value='sync'" . ($service['type']==='sync'?' selected':'') . ">Sync</option>
            <option value='transfer'" . ($service['type']==='transfer'?' selected':'') . ">Transfer</option>
            <option value='command'" . ($service['type']==='command'?' selected':'') . ">Command</option>
            <option value='monitor'" . ($service['type']==='monitor'?' selected':'') . ">Monitor</option>
        </select></div>";
        echo "<div class='col-md-4'><label class='form-label'>Direction</label><select name='direction' class='form-select'>
            <option value='upload'" . ($service['direction']==='upload'?' selected':'') . ">Upload (Cliente → Servidor)</option>
            <option value='download'" . ($service['direction']==='download'?' selected':'') . ">Download (Servidor → Cliente)</option>
        </select></div>";
        echo "</div>";
        
        echo "<div class='mb-3'><label class='form-label'>Description</label><input type='text' name='description' class='form-control' value='" . htmlspecialchars($service['description'] ?? '') . "' placeholder='Descripción del servicio'></div>";
        
        echo "<div class='mb-3'><label class='form-label'>Files (separados por coma)</label><input type='text' name='files' class='form-control' value='" . htmlspecialchars($service['files'] ?? '') . "' placeholder='VENTA.DBF,*.DBF,carpeta/*'></div>";
        
        echo "<div class='row mb-3'>";
        echo "<div class='col-md-6'><label class='form-label'>Source (carpeta origen)</label><input type='text' name='source' class='form-control' value='" . htmlspecialchars($service['source'] ?? '{base}') . "' placeholder='{base}'></div>";
        echo "<div class='col-md-6'><label class='form-label'>Temp (carpeta temporal)</label><input type='text' name='temp' class='form-control' value='" . htmlspecialchars($service['temp'] ?? '%tmp%/respaldoSucursal/{service}') . "' placeholder='%tmp%/respaldoSucursal/{service}'></div>";
        echo "</div>";
        
        echo "<div class='mb-3'><label class='form-label'>Dest (carpeta destino)</label><input type='text' name='dest' class='form-control' value='" . htmlspecialchars($service['dest'] ?? '/srv/qbck/{emp}/{plaza}/{rbfid}') . "'></div>";
        
        echo "<div class='row mb-3'>";
        echo "<div class='col-md-4'><div class='form-check'><input type='checkbox' name='recursive' class='form-check-input' id='recursive'" . ($service['recursive']===true || $service['recursive']==='t'?' checked':'') . "><label class='form-check-label' for='recursive'>Recursive</label></div>";
        echo "<div class='form-check'><input type='checkbox' name='enabled' class='form-check-input' id='enabled'" . ($service['enabled']===true || $service['enabled']==='t'?' checked':'') . "><label class='form-check-label' for='enabled'>Enabled</label></div></div>";
        echo "<div class='col-md-4'><label class='form-label'>Exclude (máscaras)</label><input type='text' name='exclude' class='form-control' value='" . htmlspecialchars($service['exclude'] ?? '') . "' placeholder='*.log,*2025*'></div>";
        echo "<div class='col-md-4'><label class='form-label'>MaxAge (días)</label><input type='number' name='maxage' class='form-control' value='" . htmlspecialchars($service['maxage'] ?? '') . "' placeholder='30'></div>";
        echo "</div>";
        
        echo "<div class='mb-3'>";
        echo "<button type='submit' class='btn btn-primary'>Guardar</button>";
        echo "<a href='/services' class='btn btn-secondary ms-2'>Cancelar</a>";
        echo "</div>";
        echo "</form></div></div>";
    }

}

try { (new AdminUI())->render(); } 
catch (\Throwable $e) { echo "<div class='error padding'>Error Fatal: " . $e->getMessage() . "</div>"; }
