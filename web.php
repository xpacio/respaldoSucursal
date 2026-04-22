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
            // Credenciales básicas (puedes moverlas a Config más adelante)
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
            $type = preg_replace('/[^a-zA-Z]/', '', $_POST['type'] ?? 'upload');
            $files = $_POST['files'] ?? '';
            $direction = $_POST['direction'] ?? 'upload';
            $client_temp = $_POST['client_temp'] ?? '%tmp%/respaldoSucursal/{service}';
            $server_dest = $_POST['server_dest'] ?? '/srv/qbck/{emp}/{plaza}/{rbfid}';
            $client_source = $_POST['client_source'] ?? '{base}';
            $recursive = isset($_POST['recursive']) ? 'true' : 'false';
            $exclude = $_POST['exclude'] ?? '';
            $maxage = (int)($_POST['maxage'] ?? 0) ?: 'NULL';
            
            if ($id > 0) {
                $this->db->exec("UPDATE services SET name=:n, type=:t, files=:f, direction=:d, client_temp=:ct, server_dest=:sd, client_source=:cs, recursive=:r, exclude=:e, maxage=:m WHERE id=:id", 
                    [':id'=>$id, ':n'=>$name, ':t'=>$type, ':f'=>$files, ':d'=>$direction, ':ct'=>$client_temp, ':sd'=>$server_dest, ':cs'=>$client_source, ':r'=>$recursive, ':e'=>$exclude, ':m'=>$maxage]);
            } else {
                $this->db->exec("INSERT INTO services (name, type, files, direction, client_temp, server_dest, client_source, recursive, exclude, maxage) VALUES (:n, :t, :f, :d, :ct, :sd, :cs, :r, :e, :m)", 
                    [':n'=>$name, ':t'=>$type, ':f'=>$files, ':d'=>$direction, ':ct'=>$client_temp, ':sd'=>$server_dest, ':cs'=>$client_source, ':r'=>$recursive, ':e'=>$exclude, ':m'=>$maxage]);
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
                            <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M14 8v-2a2 2 0 0 0 -2 -2h-7a2 2 0 0 0 -2 2v7a2 2 0 0 0 2 2h7"/><path d="M9 12h12"/><path d="M12 15l3 -3"/><path d="M21 12a9 9 0 1 1 -18 0 9 9 0 0 1 18 0z"/></svg>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </header>

            <div class="page-body">
                <div class="container-xl">
                <?php
                if (!($_SESSION['admin_auth'] ?? false)) $this->renderLogin();
                elseif ($this->action === 'table') $this->viewTable($this->target);
                elseif ($this->action === 'logs') $this->viewLogs();
                elseif ($this->action === 'services' && in_array($this->target, ['new', 'edit'])) $this->editService((int)explode('/', $this->target)[1] ?? 0);
                elseif ($this->action === 'services') $this->viewServices();
                else $this->dashboard();
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
        echo "<h3>Servicios</h3>";
        echo "<div class='mb-3'>";
        echo "<a href='/services/new' class='btn btn-primary'>Nuevo Servicio</a>";
        echo "</div>";
        
        $services = $this->db->qa("SELECT * FROM services ORDER BY name");
        
        echo "<div class='card'><table class='table table-striped mb-0'>";
        echo "<thead><tr><th>ID</th><th>Nombre</th><th>Tipo</th><th>Files</th><th>Direction</th><th>Recursive</th><th>Exclude</th><th>MaxAge</th><th>client_temp</th><th>server_dest</th><th>client_source</th><th>Acciones</th></tr></thead>";
        echo "<tbody>";
        foreach ($services as $s) {
            echo "<tr>";
            echo "<td>{$s['id']}</td>";
            echo "<td><strong>{$s['name']}</strong></td>";
            echo "<td>{$s['type']}</td>";
            echo "<td><small>" . htmlspecialchars(substr($s['files'] ?? '', 0, 50)) . "</small></td>";
            echo "<td>{$s['direction']}</td>";
            echo "<td>" . ($s['recursive'] ? 'Sí' : 'No') . "</td>";
            echo "<td><small>" . htmlspecialchars($s['exclude'] ?? '') . "</small></td>";
            echo "<td>" . ($s['maxage'] ?? '-') . "</td>";
            echo "<td><small>" . htmlspecialchars($s['client_temp'] ?? '') . "</small></td>";
            echo "<td><small>" . htmlspecialchars($s['server_dest'] ?? '') . "</small></td>";
            echo "<td><small>" . htmlspecialchars($s['client_source'] ?? '') . "</small></td>";
            echo "<td><a href='/services/edit/{$s['id']}' class='btn btn-sm btn-outline-primary'>Editar</a></td>";
            echo "</tr>";
        }
        echo "</tbody></table></div>";
    }

    private function editService(int $id): void {
        $service = ['id'=>0, 'name'=>'', 'type'=>'upload', 'files'=>'', 'direction'=>'upload', 'client_temp'=>'%tmp%/respaldoSucursal/{service}', 'server_dest'=>'/srv/qbck/{emp}/{plaza}/{rbfid}', 'client_source'=>'{base}', 'recursive'=>'f', 'exclude'=>'', 'maxage'=>null];
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
        echo "<div class='col-md-6'><label class='form-label'>Nombre</label><input type='text' name='name' class='form-control' value='" . htmlspecialchars($service['name']) . "' required></div>";
        echo "<div class='col-md-6'><label class='form-label'>Tipo</label><select name='type' class='form-select'><option value='upload'" . ($service['type']==='upload'?' selected':'') . ">Upload</option><option value='download'" . ($service['type']==='download'?' selected':'') . ">Download</option></select></div>";
        echo "</div>";
        
        echo "<div class='mb-3'><label class='form-label'>Files (separados por coma)</label><input type='text' name='files' class='form-control' value='" . htmlspecialchars($service['files'] ?? '') . "' placeholder='VENTA.DBF,*.DBF,carpeta/*'></div>";
        
        echo "<div class='row mb-3'>";
        echo "<div class='col-md-6'><label class='form-label'>Direction</label><select name='direction' class='form-select'><option value='upload'" . ($service['direction']==='upload'?' selected':'') . ">Upload</option><option value='download'" . ($service['direction']==='download'?' selected':'') . ">Download</option></select></div>";
        echo "<div class='col-md-6'><label class='form-label'>Client Source</label><input type='text' name='client_source' class='form-control' value='" . htmlspecialchars($service['client_source'] ?? '{base}') . "' placeholder='{base}'></div>";
        echo "</div>";
        
        echo "<div class='mb-3'><label class='form-label'>Client Temp</label><input type='text' name='client_temp' class='form-control' value='" . htmlspecialchars($service['client_temp'] ?? '%tmp%/respaldoSucursal/{service}') . "' placeholder='%tmp%/respaldoSucursal/{service}'></div>";
        
        echo "<div class='mb-3'><label class='form-label'>Server Dest</label><input type='text' name='server_dest' class='form-control' value='" . htmlspecialchars($service['server_dest'] ?? '/srv/qbck/{emp}/{plaza}/{rbfid}') . "'></div>";
        
        echo "<div class='row mb-3'>";
        echo "<div class='col-md-4'><div class='form-check'><input type='checkbox' name='recursive' class='form-check-input' id='recursive'" . ($service['recursive']==='t'|| $service['recursive']===true?' checked':'') . "><label class='form-check-label' for='recursive'>Recursive</label></div></div>";
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