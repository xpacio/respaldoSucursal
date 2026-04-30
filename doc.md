# Documentación de la tabla `service_config`

## Formato de Versión
El sistema usa versiones con formato: `X.YMMDDc`
- `X`: Versión mayor (0=inicial, 1, 2, etc.)
- `Y`: Ǘltimo dígito del año (6=2026, 7=2027, etc.)
- `MM`: Mes (01-12)
- `DD`: Día (01-31)
- `c`: Letra del commit (a-z, donde 'a' es el primer commit del día)

**Ejemplo:** `0.60428a`
- Versión mayor: 0
- Año: 6 → 2026
- Mes: 04 (abril)
- Día: 28
- Commit: 'a' (primer commit del día)

## Propósito
Almacena la configuración **específica por cliente y por servicio** del sistema de respaldo de sucursales, permitiendo sobrescribir los valores por defecto definidos en la tabla `services` para casos particulares de cada cliente.

## Acceso al servidor
```bash
psql -U postgres -d sync
```

## Estructura real (PostgreSQL)
```sql
                            Table "public.service_config"
      Column       |            Type             | Nullable |   Default   
-------------------+-----------------------------+----------+-------------
 client_rbfid      | character varying(5)        | not null | 
 service_id        | integer                     | not null | 
 frequency_seconds | integer                     |          | 300
 config            | jsonb                       |          | '{}'::jsonb
 enabled           | boolean                     |          | true
 last_execution    | timestamp without time zone |          | 
 next_execution    | timestamp without time zone |          | 

Indexes:
    "client_services_pkey" PRIMARY KEY, btree (client_rbfid, service_id)
Foreign-key constraints:
    "client_services_client_rbfid_fkey" FOREIGN KEY (client_rbfid) REFERENCES clients(rbfid)
    "client_services_service_id_fkey" FOREIGN KEY (service_id) REFERENCES services(id)
```

| Columna | Tipo | Default | Descripción |
|---------|------|---------|-------------|
| `client_rbfid` | VARCHAR(5) | - | Identificador del cliente (FK a `clients.rbfid`) |
| `service_id` | INT | - | ID del servicio (FK a `services.id`) |
| `frequency_seconds` | INT | 300 | Frecuencia de ejecución (sobrescribe `services.default_frequency_seconds`) |
| `config` | JSONB | `{}` | Configuración personalizada del cliente |
| `enabled` | BOOLEAN | true | Indica si el servicio está habilitado |
| `last_execution` | TIMESTAMP | - | Última ejecución realizada |
| `next_execution` | TIMESTAMP | - | Próxima ejecución programada |

**Clave primaria:** `(client_rbfid, service_id)` — garantiza que no haya duplicados.

## Relaciones
- **`services`**: Unión por `service_config.service_id = services.id` para obtener la configuración base del servicio.
- **`clients`**: Vinculación por `client_rbfid` para asociar la configuración a un cliente específico.

## Uso en el código
- **`srv.php`**:
  - `schedule()`: Obtiene servicios habilitados y verifica horarios (`línea 484`)
  - `listServices()`: Lista servicios configurados para un cliente (`línea 535`)
  - `updateNextExecution()`: Actualiza tiempos de ejecución (`línea 545`)
  - `serviceConfig()`: Entrega configuración final (base + cliente) para un servicio (`línea 603`)
  - `downloadList()`: Configuración para servicios de descarga (`línea 647`)
- **`cli.php`**: Solicita configuración de servicio al servidor vía endpoint `service_config` (`línea 120`)

## Análisis del contenido actual
**Tabla truncada** — sin registros actualmente.

```sql
-- Estado actual
SELECT COUNT(*) FROM service_config;
-- Resultado: 0 (tabla vacía)

-- Antes del truncate: 642 registros, todos con config vacía {}
-- 641 clientes con servicio "respaldo" + 1 con "testRespaldo"
```

**Nota:** En su estado actual (todos los registros con `config = {}`), la tabla funcionaba como un "enable/disable" por cliente-servicio usando la columna `enabled`. Como no había personalizaciones reales, su utilidad era limitada. El sistema puede operar usando solo `services` con sus valores por defecto hasta que se necesite configuración personalizada por cliente.

## Cambios implementados (Opción C)

El sistema ahora funciona con **fallback a `services`** cuando `service_config` está vacío:

1. **`schedule()`**: Si no hay registros en `service_config` para el cliente, usa `services` directamente
2. **`listServices()`**: Muestra servicios habilitados desde `services` si no hay personalizaciones
3. **`serviceConfig()`**: Entrega configuración de `services` por defecto, solo consulta `service_config` si existe personalización
4. **`downloadList()`**: Igual, usa `services` si no hay entrada en `service_config`
5. **`updateNextExecution()`**: Solo actualiza `service_config` si existe el registro, sino omite (usa valores por defecto)

**Ventaja**: No requiere registros vacíos en `service_config`. La tabla solo se usa para casos especiales (frecuencia diferente, configuración personalizada por cliente).

## ¿Debe tener datos repetidos?
**No**. La clave primaria `(client_rbfid, service_id)` lo impide a nivel de BD.

## Flujo de configuración
1. `services` define valores por defecto (archivos, dirección, rutas base, etc.)
2. `service_config` ajusta estos valores por cliente (ej. frecuencia personalizada)
3. Al solicitar configuración, el sistema prioriza `service_config` sobre `services`
