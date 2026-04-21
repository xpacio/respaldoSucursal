<?php declare(strict_types=1);
namespace App\Web;

require_once __DIR__ . '/shared_server.php';
use App\DB;
use App\Config;

class AdminUI {
    private DB $db;
    private string $action;
    private string $target;

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
    }

    private function renderLogin(): void {
        ?>
        <div class="middle-align center-align" style="height:80vh">
            <form method="post" class="padding border shadow" style="width:300px">
                <h5 class="center-align">Acceso Admin</h5>
                <div class="field label border"> <input type="text" name="user" required> <label>Usuario</label> </div>
                <div class="field label border"> <input type="password" name="pass" required> <label>Contraseña</label> </div>
                <button class="extend" name="login_btn" type="submit">Entrar</button>
                <?php if (isset($this->login_error)) echo "<p class='error-text center-align'>{$this->login_error}</p>"; ?>
            </form>
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
            <link href="https://cdn.jsdelivr.net/npm/beercss@3.5.1/dist/cdn/beer.min.css" rel="stylesheet">
            <script type="module" src="https://cdn.jsdelivr.net/npm/beercss@3.5.1/dist/cdn/beer.min.js"></script>
            <style>body { background-color: #f8f9fa; } main { padding-top: 2rem; }</style>
        </head>
        <body>
            <header class="primary shadow">
                <nav>
                    <button class="circle transparent"><i>menu</i></button>
                    <h5 class="max">Administración Web</h5>
                    <?php if ($_SESSION['admin_auth'] ?? false): ?>
                    <a href="/" class="button transparent">Tablas</a>
                    <a href="/logs" class="button transparent">Logs</a>
                    <a href="/?logout=1" class="button transparent"><i>logout</i></a>
                    <?php endif; ?>
                </nav>
            </header>

            <main class="responsive">
                <?php
                if (!($_SESSION['admin_auth'] ?? false)) $this->renderLogin();
                elseif ($this->action === 'table') $this->viewTable($this->target);
                elseif ($this->action === 'logs') $this->viewLogs();
                else $this->dashboard();
                ?>
            </main>
        </body>
        </html>
        <?php
    }

    private function dashboard(): void {
        $tables = $this->db->qa("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE' ORDER BY table_name");
        echo "<h4>Base de Datos</h4><div class='grid'>";
        foreach ($tables as $t) {
            $name = $t['table_name'];
            echo "<div class='s12 m4 l3'><a href='/table/$name' class='card padding border center-align middle-align'>
                    <i class='extra'>table_chart</i><div class='max'>$name</div>
                  </a></div>";
        }
        echo "</div>";
    }

    private function viewTable(string $name): void {
        $name = preg_replace('/[^a-zA-Z0-9_]/', '', $name);
        $cols = $this->db->qa("SELECT column_name FROM information_schema.columns WHERE table_name = :t", [':t' => $name]);
        $order = "1";
        // Intentar ordenar por campos de tiempo o ID descendente
        foreach ($cols as $c) if (in_array($c['column_name'], ['updated_at', 'id', 'created_at', 'completed_at'])) { $order = $c['column_name']; break; }
        
        $data = $this->db->qa("SELECT * FROM $name ORDER BY $order DESC LIMIT 100");

        echo "<div class='row'><h5>$name</h5><div class='max'></div><a href='/' class='button border'>Regresar</a></div>";
        echo "<div class='scroll overflow border'><table class='padding'>";
        if (!empty($data)) {
            echo "<thead><tr>";
            foreach (array_keys($data[0]) as $h) echo "<th>$h</th>";
            echo "</tr></thead><tbody>";
            foreach ($data as $row) {
                echo "<tr>";
                foreach ($row as $v) echo "<td>" . (is_array($v) || is_object($v) ? json_encode($v) : $v) . "</td>";
                echo "</tr>";
            }
            echo "</tbody>";
        } else { echo "<tr><td>Sin registros disponibles</td></tr>"; }
        echo "</table></div>";
    }

    private function viewLogs(): void {
        echo "<h4>Monitoreo de Logs</h4>";
        echo "<div class='grid'>";
        $logs = [
            'Servidor (Syslog)' => "tail -n 100 /var/log/syslog | sort -r",
            'Web (Lighttpd Access)' => 'tail -n 100 /var/log/lighttpd/access.log | sort -r',
            'PHP-FPM (8.4)' => 'tail -n 100 /var/log/php8.4-fpm.log | sort -r',
            'Base de Datos (PostgreSQL)' => 'tail -n 100 /var/log/postgresql/postgresql-16-main.log | sort -r'
        ];
        foreach ($logs as $title => $cmd) {
            echo "<div class='s12 m6'><article class='border padding margin-bottom shadow'>";
            echo "<h6>$title</h6>";
            echo "<pre class='scroll' style='max-height:450px; font-size:0.7rem; background:#121212; color:#00ff41; padding:10px; border-radius:4px; overflow:auto; border:1px solid #333;'>";
            echo htmlspecialchars(@shell_exec($cmd) ?: "Sin registros o error de permisos ($cmd).");
            echo "</pre></article></div>";
        }
        echo "</div>";
    }
}

try { (new AdminUI())->render(); } 
catch (\Throwable $e) { echo "<div class='error padding'>Error Fatal: " . $e->getMessage() . "</div>"; }