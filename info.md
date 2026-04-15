# Sistema de Respaldo de Sucursales

## Arquitectura General

```
┌─────────────────────────────────────────────────────────────────┐
│                         SERVIDOR                                │
│                    (respaldosucursal.servicios.care)           │
├─────────────────────────────────────────────────────────────────┤
│  index.php (entry point)                                        │
│    └─> Router (dispatch por convención)                          │
│           └─> routes/ar.php (handlers de acciones)              │
│                  ├─ init       → obtener timestamp + habilitar │
│                  ├─ register   → registrar cliente en DB        │
│                  ├─ config     → lista de archivos a respaldar │
│                  ├─ sync       → sincronizar hashes            │
│                  ├─ upload     → recibir chunks de archivos    │
│                  └─ ...                                     │
│                                                                  │
│  Database (PostgreSQL)                                          │
│    ├─ clients          → información de sucursales              │
│    ├─ ar_clients       → clientes AR registrados                │
│    ├─ ar_files         → archivos por sucursal                 │
│    └─ ar_file_hashes   → chunks de cada archivo                 │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│                      CLIENTE CLI (PHP)                          │
│                        (cli.php + cli/Client.php)               │
├─────────────────────────────────────────────────────────────────┤
│  Cliente:                                                         │
│    ├─ Escanea disco buscando rbf/rbf.ini → detecta sucursal    │
│    ├─ Carga config.json (server_url + locations)               │
│    ├─ register()    → registra en servidor                       │
│    ├─ fullHashCheck() → sync completo periódico                │
│    └─ runLoop()    → detecta cambios y sincroniza              │
│                                                                  │
│  Servicios:                                                       │
│    ├─ HttpClient         → peticiones HTTP al servidor         │
│    ├─ RegistrationService → fetch timestamp + generar TOTP     │
│    └─ SyncService        → upload de archivos por chunks       │
└─────────────────────────────────────────────────────────────────┘
```

## Principios de Diseño

### 1. Routing por Convención

El Router sigue el patrón **resource-based routing**:
- URL `/` → procesa acción del body JSON directamente
- Body contiene `action` que determina el handler

```php
// index.php
$router->dispatch($path);

// routes/ar.php
function route_ar(Router $r, string $resource): void {
    $body = $r->getBody();
    $action = $body['action'] ?? '';
    switch ($action) {
        case 'init':     route_ar_init($r, $body); break;
        case 'register': route_ar_register($r, $body); break;
        case 'sync':     route_ar_sync($r, $body); break;
        // ...
    }
}
```

### 2. Separación de Responsabilidades

| Componente | Responsabilidad |
|------------|-----------------|
| `Router` | Dispatch de requests, parsing de JSON, respuestas |
| `ArCore` | Lógica de negocio AR (registro, sync, upload) |
| `Database` | Abstracción de BD (fetch, execute, insert) |
| `HttpClient` | Comunicación HTTP con servidor |
| `RegistrationService` | Obtención de timestamp y generación de tokens |

### 3. Transferencia por Chunks

Archivos se transfieren en chunks con tamaño adaptativo:

| Tamaño archivo | chunkSize | Chunks (ejemplo) |
|---------------|-----------|------------------|
| < 1 MB | 64 KB | 1-16 |
| 1-10 MB | 64 KB | 16-160 |
| 10-100 MB | 256 KB | 40-400 |
| > 100 MB | 1 MB | 100-1024 |

```
Cliente                           Servidor
   │                                  │
   │──── POST / (sync) ─────────────>│ Envía hash completo + hashes de chunks
   │<──── needs_upload: [{chunks}] ──│ Indica qué chunks faltan
   │                                  │
   │──── POST / (upload chunk 0) ───>│ Envía chunk en Base64 + hash
   │──── POST / (upload chunk 1) ───>│
   │   ...                            │
   │<──── ok + chunk ─────────────────│
```

### 4. Autenticación con TOTP

Token generado localmente basado en timestamp + rbfid:

```php
// Cliente
$seed = substr($timestamp, 0, -2);  // sin últimos 2 dígitos
$token = hash('xxh3', $seed . $rbfid); // en base64

// Servidor valida:
$expected = hash('xxh3', $seed . $rbfid);
if ($token !== $expected) throw new Exception('Token inválido');
```

## Estructura de Archivos

```
/
├── index.php                    # Entry point API
├── cli.php                      # Entry point cliente CLI
├── config.json                  # Config del cliente
│
├── api/
│   └── routes/
│       ├── ar.php               # Handlers AR (register, sync, upload...)
│       └── heartbeat.php       # Handlers heartbeat (legacy)
│
├── cli/
│   ├── Client.php               # Orquestador del cliente
│   ├── HttpClient.php           # Cliente HTTP
│   ├── Location.php             # Modelo de ubicación
│   ├── Chunk.php                # Lógica de chunks
│   └── SyncService.php         # Servicio de sincronización
│
├── shared/
│   ├── Router.php               # Router HTTP
│   ├── Logger.php               # Logging (archivo + stdout)
│   ├── Hash.php                 # Utilidades de hash
│   ├── Constants.php            # Constantes globales
│   ├── Config.php               # Persistencia de config
│   ├── TotpValidator.php        # Validación TOTP
│   │
│   └── Services/
│       ├── RegistrationService.php
│       ├── ConfigService.php
│       └── SyncService.php
│
└── logs/
    └── ar-YYYY-MM-DD.log
```

## Patrones Clave

### Service Layer

Servicios puros sin estado, inyéctalos por constructor:

```php
class RegistrationService {
    private HttpClient $http;
    private string $serverUrl;

    public function __construct(HttpClient $http, string $serverUrl) {
        $this->http = $http;
        $this->serverUrl = $serverUrl;
    }

    public function fetchTimestamp(string $rbfid): int { ... }
    public function generateTotp(string $rbfid, int $timestamp): string { ... }
}
```

### Dependency Injection en Cliente

```php
class Client {
    private HttpClient $http;
    private RegistrationService $regService;
    private SyncService $syncService;

    public function __construct() {
        $this->http = new HttpClient();
        $this->regService = new RegistrationService($this->http, $this->serverUrl);
        $this->syncService = new SyncService($this->http, $this->regService);
    }

    public function setServerUrl(string $url): void {
        $this->serverUrl = $url;
        // Re-crear servicios con nueva URL
        $this->regService = new RegistrationService($this->http, $url);
        $this->syncService = new SyncService($this->http, $this->regService);
    }
}
```

### Repository Pattern (implícito)

La clase `Database` actúa como repository:

```php
class Database {
    public function fetchOne(string $sql, array $params = []): ?array { ... }
    public function fetchAll(string $sql, array $params = []): array { ... }
    public function execute(string $sql, array $params = []): void { ... }
    public function insert(string $sql, array $params = []): int { ... }
}
```

Uso:
```php
$client = $db->fetchOne(
    "SELECT rbfid, emp, plaza FROM clients WHERE rbfid = :rbfid",
    [':rbfid' => $rbfid]
);
```

## Resiliencia

### Retry con backoff (implícito en slots)

```php
$slots = getArSlots($db);
$rateDelay = $slots['available'] > 0 ? 3000 : 10000;
```

### Graceful Degradation

```php
try {
    $client->register();
} catch (Exception $e) {
    Logger::warn('Register fallo: ' . $e->getMessage() . ' — continuando');
}
// Continúa aunque register falle
```

### Validación defensiva

```php
$timestamp = $this->regService->fetchTimestamp($loc->rbfid);
if ($timestamp === 0) {
    Logger::err("No timestamp. Saltando.");
    return;
}
```

## Testabilidad

### Servicios testeables sin mocks de HTTP

Los servicios usan interfaces o clases concretas fácilmente mockeables:

```php
// Test con mock de HttpClient
$mockHttp = $this->createMock(HttpClient::class);
$mockHttp->method('post')->willReturn('{"ok":true,"timestamp":"123"}');

$regService = new RegistrationService($mockHttp, 'http://test/');
$ts = $regService->fetchTimestamp('roton');
$this->assertEquals(123, $ts);
```

### Logging configurable

```php
Logger::init('/path/to/logs', $verbose);
Logger::setQuiet(true);  // Para tests/API
```

## Configuración

### Cliente (config.json)

```json
{
    "server_url": "http://respaldosucursal.servicios.care",
    "locations": [
        {
            "rbfid": "roton",
            "base": "/srv/roton",
            "work": "/tmp/ar_work/roton/"
        }
    ]
}
```

### Servidor (variables de entorno)

```php
// shared/Config/config.php
$config = [
    'db' => [
        'host' => getenv('DB_HOST') ?: 'localhost',
        'port' => getenv('DB_PORT') ?: 5432,
        'database' => getenv('DB_NAME') ?: 'respaldo',
        'username' => getenv('DB_USER') ?: 'postgres',
        'password' => getenv('DB_PASS') ?: '',
    ]
];
```

## Endpoints API

| Action | Body | Response |
|--------|------|----------|
| `init` | `{rbfid}` | `{ok, rbfid, timestamp, enabled}` |
| `register` | `{rbfid}` | `{ok, rbfid}` |
| `config` | `{rbfid, files_version}` | `{ok, files_version, files[]?}` |
| `sync` | `{rbfid, totp_token, files[{filename, hash_completo, chunk_hashes[]}]}` | `{ok, needs_upload[], rate_delay}` |
| `upload` | `{rbfid, totp_token, filename, chunk_index, hash_xxh3, data}` | `{ok, file, chunk}` |

## Detección de Sucursales (Cliente)

1. Lee `config.json` si existe
2. Si no, escanea discos buscando `rbf/rbf.ini`
3. Valida con archivos testigo: `XCORTE.DBF`, `CANOTA.DBF`, `CAT_PROD.DBF`, `MASTER.DBF`
4. Guarda `config.json` para próxima ejecución

```php
// Estructura esperada en disco (búsqueda recursiva en /srv):
/srv
└── {pvsi_root}/
    └── rbf/
        └── rbf.ini   ← contiene _suc=roton
```
