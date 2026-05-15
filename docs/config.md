# Configuración

## Archivo config.json (Cliente)

Configuración local del cliente. Se genera automáticamente con `php cli.php scan`.

```json
{
  "url": "http://respaldosucursal.servicios.care",
  "locations": [
    {
      "rbfid": "roton",
      "base": "C:\\pvsi",
      "work": "C:\\pvsi\\quickbck"
    }
  ],
  "watch_files": [
    "AJTFLU.DBF", "VENTA.DBF", ...
  ],
  "files_version": "0bb4b79b"
}
```

| Campo | Descripción |
|---|---|
| `url` | URL del servidor central |
| `locations[]` | Instalaciones PVSI detectadas |
| `locations[].rbfid` | Identificador único de sucursal |
| `locations[].base` | Directorio raíz de la instalación |
| `locations[].work` | Directorio de trabajo (quickbck) |
| `watch_files` | Lista de archivos a monitorear |
| `files_version` | MD5 hash de la lista (para detectar cambios) |

## Base de Datos PostgreSQL (Servidor)

Base: `sync` — Puerto: `5432` — Usuario: `postgres`

### Tablas Principales

#### `services`
Catálogo de servicios disponibles.

| Columna | Tipo | Descripción |
|---|---|---|
| id | serial | PK |
| name | text | Nombre único del servicio |
| type | text | upload / download / backup |
| direction | text | upload / download |
| description | text | Descripción |
| files | text | Lista separada por comas |
| source | text | Template directorio origen |
| dest | text | Template directorio destino |
| temp | text | Template directorio temporal |
| recursive | boolean | Subdirectorios |
| enabled | boolean | Activo |
| exclude | text | Patrones exclusión |
| maxage | int | Edad máxima en días |
| frequency_seconds | int | Intervalo predeterminado |

#### `service_config`
Configuración por cliente + servicio (sobrescribe `services`).

| Columna | Tipo | Descripción |
|---|---|---|
| id | serial | PK |
| client_rbfid | text | FK a clients |
| service_id | int | FK a services |
| config | jsonb | Config personalizada |
| enabled | boolean | Activo para este cliente |
| next_execution | timestamp | Próxima ejecución programada |
| created_at | timestamp | Creación |
| updated_at | timestamp | Última modificación |

#### `clients`
Sucursales registradas.

| Columna | Tipo | Descripción |
|---|---|---|
| rbfid | text | PK, identificador |
| emp | text | Empresa |
| plaza | text | Plaza |
| razon_social | text | Razón social |
| tipo | text | sucursal / bodega |
| enabled | boolean | Activo |
| token | text | Token de autenticación |

#### `file_chunks`
Estado de chunks por archivo.

| Columna | Tipo | Descripción |
|---|---|---|
| id | serial | PK |
| rbfid | text | Cliente |
| file_name | text | Nombre archivo |
| chunk_index | int | Índice del chunk |
| chunk_hash | text | Hash del chunk |
| status | text | pending / received / verified |
| created_at | timestamp | Creación |

#### `service_history`
Historial de ejecuciones.

| Columna | Tipo | Descripción |
|---|---|---|
| id | serial | PK |
| client_rbfid | text | Cliente |
| service_name | text | Servicio |
| status | text | success / partial / failed |
| results | jsonb | Resultados detallados |
| execution_time_ms | int | Duración |
| executed_at | timestamp | Ejecución |

#### `service_health`
Estado del agente (heartbeat).

| Columna | Tipo | Descripción |
|---|---|---|
| rbfid | text | PK |
| last_heartbeat | timestamp | Último latido |
| orchestrator_status | text | running / stopped |
| services_running | int | Servicios activos |
| system_info | jsonb | Info del sistema |

### Templates de Rutas

Los campos `source`, `dest`, `temp` pueden usar placeholders:

| Placeholder | Reemplazo |
|---|---|
| `{base}` | Directorio raíz de la instalación |
| `{service}` | Nombre del servicio |
| `%tmp%` | Directorio temporal del sistema |
| `{emp}` | Empresa (servidor) |
| `{plaza}` | Plaza (servidor) |
| `{rbfid}` | RBFID (servidor) |

Ejemplos:
- `{base}` → `C:\pvsi`
- `%tmp%/respaldoSucursal/{service}` → `C:\Users\...\AppData\Local\Temp\respaldoSucursal\descargaVales`
- `/srv/qbck/{emp}/{plaza}/{rbfid}` → `/srv/qbck/tst/tst02/roton`

## Detección de Ubicaciones

El cliente escanea:
- **Windows**: Unidades C: a H:, busca `{unidad}:\pvsi`
- **Linux**: Directorios `/mnt`, `/media`, `/srv`

Identifica la sucursal por:
1. Archivo `{base}\.rbfid` — contiene el RBFID
2. Archivo `{base}\rbf\rbf.ini` — línea `_SUC=ROTON`
