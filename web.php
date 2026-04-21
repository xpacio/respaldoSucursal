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
        $this->db = new DB(Config::getDb());
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $parts = explode('/', trim($uri, '/'));
        $this->action = $parts[0] ?: 'dashboard';
        $this->target = $parts[1] ?? '';
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
                    <a href="/" class="button transparent">Tablas</a>
                    <a href="/logs" class="button transparent">Logs</a>
                </nav>
            </header>

            <main class="responsive">
                <?php
                if ($this->action === 'table') $this->viewTable($this->target);
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
        $logs = [
            'Servidor (Syslog)' => 'grep "respaldo-sucursal" /var/log/syslog | tail -n 50',
            'Web (Lighttpd Access)' => 'tail -n 50 /var/log/lighttpd/access.log'
        ];
        foreach ($logs as $title => $cmd) {
            echo "<article class='border padding margin-bottom'>";
            echo "<h6>$title</h6>";
            echo "<pre class='scroll' style='max-height:300px; font-size:0.75rem; background:#1e1e1e; color:#00ff00; padding:15px; border-radius:4px;'>";
            echo htmlspecialchars(@shell_exec($cmd) ?: "Log no disponible (revisa permisos de lectura en /var/log/).");
            echo "</pre></article>";
        }
    }
}

try { (new AdminUI())->render(); } 
catch (\Throwable $e) { echo "<div class='error padding'>Error Fatal: " . $e->getMessage() . "</div>"; }