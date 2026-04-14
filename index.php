<?php
/**
 * GIS API v3 - Entry Point
 * 
 * Convención: /{resource} → route_{resource}() en routes/{resource}.php
 * Autoloader para clases, require solo para config/auth.
 */

// CORS headers
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header("Access-Control-Allow-Origin: $origin");
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, X-User-Id, X-TOTP-Token, Content-Type');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// 1. Autoloader + dependencias con funciones standalone
require_once __DIR__ . '/shared/autoload.php';
$config = require __DIR__ . '/shared/Config/config.php';
require_once __DIR__ . '/shared/auth.php';
require_once __DIR__ . '/shared/Router.php';

// 2. Inicializar dependencias
$db = new Database($config['db']);
$logger = LoggerFactory::createFromConfig($config, $db);
$services = ServiceFactory::createServices($config, $db, $logger);

$system = $services['system'];
$clientService = $services['clientService'];
$userService = new UserService($db, $logger, $system);
$sshService = new SshService($db, $logger, $system);
$mountService = new MountService($db, $logger, $system);
$adminService = new AdminService($db, $logger, $system);

// 3. Inicializar Router
$router = Router::getInstance();
$router->setDependencies($db, $logger, $system, $clientService, $userService, $sshService, $mountService, $services['overlaySyncService'], $adminService);
$router->clientManager = ClientManager::getInstance($db, $logger, $system);

// 4. Obtener path actual
$path = isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : '/';
if (empty($path) || $path === '/') {
    $path = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($path, PHP_URL_PATH);
}
$isApiRequest = (strpos($path, '/api/') === 0 || strpos($path, '/api') === 0);
$path = preg_replace('#^/api/index\.php#', '', $path);
$path = preg_replace('#^/api#', '', $path);
if (empty($path)) $path = '/';

// 5. Login
if ($path === '/login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        header('Location: /login?error=empty');
        exit;
    }
    
    try {
        $user = $db->fetchOne("SELECT id, username, password, nombre FROM users WHERE username = :username", [':username' => $username]);
        
        if ($user && password_verify($password, $user['password'])) {
            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+1 week'));
            
            $db->execute("UPDATE users SET login_token = :token, token_expires_at = :expires WHERE id = :id", [':token' => $token, ':expires' => $expiresAt, ':id' => $user['id']]);
            
            setcookie('auth_token', $token, [
                'expires' => strtotime('+1 week'),
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
            
            header('Location: /dashboard');
            exit;
        }
    } catch (Exception $e) {
        error_log('Login error: ' . $e->getMessage());
    }
    
    header('Location: /login?error=invalid');
    exit;
}

if ($path === '/login') {
    require __DIR__ . '/login.php';
    exit;
}

// Logout
if ($path === '/logout') {
    $token = $_COOKIE['auth_token'] ?? '';
    if ($token) {
        $db->execute("UPDATE users SET login_token = NULL, token_expires_at = NULL WHERE login_token = :token", [':token' => $token]);
    }
    setcookie('auth_token', '', ['expires' => time() - 3600, 'path' => '/']);
    header('Location: /login');
    exit;
}

// 6. Verificar autenticación para páginas UI
function isLoggedIn(Database $db): bool {
    $token = $_COOKIE['auth_token'] ?? '';
    if (empty($token)) return false;

    $user = $db->fetchOne(
        "SELECT id FROM users WHERE login_token = :token AND (token_expires_at IS NULL OR token_expires_at > NOW())",
        [':token' => $token]
    );
    if (!$user) return false;

    $db->execute("UPDATE users SET token_expires_at = :expires WHERE login_token = :token", [
        ':expires' => date('Y-m-d H:i:s', strtotime('+1 hour')),
        ':token' => $token
    ]);
    setcookie('auth_token', $token, [
        'expires' => strtotime('+1 hour'),
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    return true;
}

// Redirección raíz
if ($path === '/' || $path === '') {
    header('Location: /dashboard');
    exit;
}

// Páginas UI protegidas (solo para requests no-API)
$protectedPages = ['dashboard', 'clientes', 'plantillas', 'permisos', 'logs', 'agente', 'sheditor', 'distribucion'];
$isAuthenticated = isLoggedIn($db);
if (!$isApiRequest && in_array(ltrim($path, '/'), $protectedPages) && !$isAuthenticated) {
    header('Location: /login');
    exit;
}

if (!$isApiRequest) {
    foreach ($protectedPages as $page) {
        if ($path === "/$page" || $path === "/$page.php") {
            require __DIR__ . "/$page.php";
            exit;
        }
    }
}

// 7. Endpoint público: descarga de clave SSH (sin auth)
if (preg_match('#^/public/cliente/([^/]+)/ssh/key$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $clientId = $m[1];
    
    $client = $db->fetchOne("
        SELECT rbfid, private_key, enabled, key_download_enabled, key_downloaded_at 
        FROM clients WHERE rbfid = :rbfid
    ", [':rbfid' => $clientId]);
    
    if (!$client) { http_response_code(404); echo 'Cliente no encontrado'; exit; }
    if ($client['enabled'] !== true && $client['enabled'] !== 't') { http_response_code(403); echo 'Cliente deshabilitado'; exit; }
    if ($client['key_download_enabled'] !== true && $client['key_download_enabled'] !== 't') { http_response_code(403); echo 'Descarga no habilitada'; exit; }
    if (empty($client['private_key'])) { http_response_code(404); echo 'Clave no disponible'; exit; }
    if ($client['key_downloaded_at']) { http_response_code(410); echo 'Clave ya fue descargada.'; exit; }
    
    $base64Key = base64_encode($client['private_key']);
    $md5Hash = md5($base64Key);
    $md4Suffix = strtoupper(substr($md5Hash, -4));
    
    $db->execute("UPDATE clients SET key_download_enabled = false, key_downloaded_at = NOW() WHERE rbfid = :rbfid", [':rbfid' => $clientId]);
    
    header('MD4: ' . $md4Suffix);
    header('Content-Type: text/plain');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    echo $base64Key;
    exit;
}

// 8. Endpoints publicos sin auth (TOTP o sin token)
if (preg_match('#^/heartbeat#', $path)) {
    require_once __DIR__ . '/api/routes/heartbeat.php';
    route_heartbeat($router, 'heartbeat');
}

if (preg_match('#^/ar/download$#', $path) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    require_once __DIR__ . '/api/routes/ar.php';
    route_ar_download($router, []);
    exit;
}

if (preg_match('#^/ar(/|$)#', $path)) {
    require_once __DIR__ . '/api/routes/ar.php';
    route_ar($router, 'ar');
    exit;
}

// 9. Autenticación para API
$currentUser = apiAuthRequire();
$logger->startExecution('api', '[API] ' . $_SERVER['REQUEST_METHOD'] . ' ' . $path);
$logger->enterContext(1, 0, 0, "API Request");

// 9b. Endpoint autenticado: descarga de clave SSH por ID en URL
if (preg_match('#^/cliente/([^/]+)/ssh/key$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $clientId = $m[1];

    $client = $db->fetchOne("
        SELECT rbfid, private_key, enabled
        FROM clients WHERE rbfid = :rbfid
    ", [':rbfid' => $clientId]);

    if (!$client) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Cliente no encontrado']);
        exit;
    }
    if ($client['enabled'] !== true && $client['enabled'] !== 't') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Cliente deshabilitado']);
        exit;
    }
    if (empty($client['private_key'])) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Clave no disponible']);
        exit;
    }

    $base64Key = base64_encode($client['private_key']);
    $md4Suffix = strtoupper(substr(md5($base64Key), -4));

    header('MD4: ' . $md4Suffix);
    header('Content-Type: text/plain');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo $base64Key;
    exit;
}

// 10. Dispatch (convención: /{resource} → route_{resource}())
$router->dispatch($path);
