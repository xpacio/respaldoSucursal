# API REST del Servidor

Todas las rutas bajo `/api/{action}/{rbfid}`. Método: **POST**. Content-Type: `application/json`.

## Autenticación

Headers requeridos (excepto `health` y `download`):

| Header | Descripción |
|---|---|
| `X-RBFID` | Identificador de sucursal |
| `X-TOTP-Token` | Token dinámico: `base64(strrev(xxh3(floor(ts/100) + rbfid)))` |
| `X-Timestamp` | Timestamp UNIX actual |

## Endpoints

### `health` — Health Check

Sin autenticación. Retorna estado del servidor.

```
GET /api/health
→ {"ok": true, "status": "healthy"}
```

### `init` — Init/Registro

Registra o verifica cliente.

```
→ {"ok": true, "enabled": true, "client": {...}}
```

### `register` — Registro Forzado

Activa un cliente.

```
→ {"ok": true}
```

### `sync` — Sincronización de Archivos

Compara archivos del cliente contra el servidor. Endpoint principal.

**Request:**
```json
{
  "service": "descargaVales",
  "files": [{
    "filename": "AJTFLU.DBF",
    "hash_completo": "base64...",
    "chunk_hashes": ["hash1", "hash2", ...],
    "mtime": 1234567890,
    "size": 1048576
  }]
}
```

**Response (cuando hay chunks pendientes):**
```json
{
  "ok": true,
  "needs_upload": [0, 2, 5],
  "file_changes": [{
    "file": "AJTFLU.DBF",
    "hash": "...",
    "dest": "/srv/qbck/...",
    "old_size": 1000,
    "new_size": 2000,
    "diff_bytes": 1000,
    "growth_pct": 100,
    "old_size_fmt": "1000 B",
    "new_size_fmt": "2.0 KB",
    "diff_fmt": "1.0 KB",
    "time_diff_fmt": "9 seconds"
  }]
}
```

### `upload` — Subida de Chunk

**Request:**
```json
{
  "service": "descargaVales",
  "filename": "AJTFLU.DBF",
  "chunk_index": 0,
  "chunk_hash": "base64...",
  "data": "base64...",
  "size": 1048576,
  "compressed": true
}
```

**Response:**
```json
{"ok": true, "next_chunk": 1}
```

### `finalize` — Finalizar Archivo

Activa el ensamblado de chunks en el servidor. Requiere verificación de hash.

### `missing` — Reportar Archivos Faltantes

```json
{
  "service": "descargaVales",
  "missing_files": ["CANOTA.DBF", "VENTA.FPT"]
}
```

### `schedule` — Obtener Servicios Pendientes

Usado por el orquestador. Retorna servicios con `next_execution <= NOW`.

```
→ {"ok": true, "services": ["descargaVales", "backupDiario"]}
```

### `service_config` — Configuración de Servicio

```json
{
  "name": "descargaVales",
  "type": "upload",
  "direction": "download",
  "files": ["VALES.DBF", "VALPEN.DBF"],
  "source": "{base}",
  "dest": "{base}",
  "temp": "%tmp%/respaldoSucursal/{service}",
  "recursive": false,
  "exclude": "*.tmp,*.log",
  "maxage": 30,
  "frequency_seconds": 300
}
```

### `service_result` — Resultado de Ejecución

```json
{
  "service": "descargaVales",
  "status": "success",
  "results": {"sync_ok": ["AJTFLU.DBF"], "sync_missing": []},
  "execution_time_ms": 1234
}
```

### `heartbeat` — Latido del Cliente

```json
{
  "status": "running",
  "system_info": {"cycle_start": "10:30:00", "service": "descargaVales"}
}
```

### `list_services` — Listar Servicios

Retorna todos los servicios disponibles con frecuencia y última ejecución.

### `download_list` — Lista de Archivos para Download

```json
{
  "service": "descargaVales",
  "files": [{"filename": "VALES.DBF", "size": 1000, "hash": "...", "mtime": 123}]
}
```

### `download_file` — Descargar Chunk

```
→ {"ok": true, "data": "base64...", "chunk_hash": "..."}
```

## Formatos

### Hash xxh3

El hash xxh3 de 64 bits se codifica como:

1. Obtener 8 bytes little-endian de XXH3_64bits()
2. Revertir el orden de los bytes
3. Codificar en base64 estándar
4. Eliminar padding `=`

### Chunk Size Adaptativo

| Tamaño Archivo | Chunk Size |
|---|---|
| < 1 MB | 64 KB |
| < 10 MB | 256 KB |
| >= 10 MB | 1 MB |
