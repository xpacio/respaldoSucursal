# Sistema de Orquestación y Respaldo de Sucursales

Sistema multi-servicio para sucursales que evoluciona el respaldo tradicional a una plataforma de gestión remota (monitoreo, descargas y respaldos) coordinada desde un servidor central.

## 🚀 Características Principales

- **Arquitectura de Orquestador**: El cliente (`cli.php`) actúa como un agente que ejecuta tareas programadas dinámicamente.
- **Configuración Centralizada**: El servidor define qué servicios ejecuta cada sucursal y con qué frecuencia.
- **Detección automática** de ubicaciones en Windows (C:/ D:/) y Linux (/srv/)
- **4 copias de cada archivo**: origen, quickbck (cliente), temporal (servidor), destino final (servidor)
- **Sincronización incremental**: solo chunks modificados se transfieren
- **Persistencia de configuración**: lista de archivos guardada en `config.json`
- **Reporte de archivos faltantes**: archivos ausentes se marcan como 'missing' en BD
- **Normalización a mayúsculas**: archivos como `CANOTa.dbf` llegan como `CANOTA.DBF`
- **Autenticación TOTP**: tokens dinámicos basados en timestamp + rbfid
- **Health Checks & Métricas**: Reporte periódico del estado del sistema y uso de recursos (CPU/Disco).

## 📁 Arquitectura del Sistema

### Estructura de 4 Copias
```
1. ORIGEN:      /srv/roton/                    (archivos originales)
2. QUICKBCK:    /srv/roton/quickbck/           (copia de trabajo del cliente)
3. TEMPORAL:    /tmp/ar/roton/                 (ensamblaje de chunks en servidor)
4. DESTINO:     /srv/qbck/tst/tst02/roton/     (archivos finales respaldados)
```

### Componentes Principales
- **`cli.php`**: Cliente PHP que detecta ubicaciones y sincroniza archivos
- **`index.php`**: Servidor API que maneja registro, sincronización y upload
- **`shared.php`**: Constantes, clases de utilidad y configuración compartida
- **`config.json`**: Configuración persistente del cliente

## 🛠️ Instalación y Configuración

### Requisitos
- PHP 8.0+
- PostgreSQL
- Acceso a directorios `/srv/` (Linux) o `C:/`, `D:/` (Windows)

### Base de Datos
```sql
-- Tablas principales
CREATE TABLE clients (rbfid TEXT PRIMARY KEY, emp TEXT, plaza TEXT, enabled BOOLEAN);
CREATE TABLE ar_files (rbfid TEXT, file_name TEXT, status TEXT, hash_xxh3 TEXT, updated_at TIMESTAMP);
CREATE TABLE ar_file_hashes (rbfid TEXT, file_name TEXT, chunk_index INT, hash_xxh3 TEXT, status TEXT);
CREATE TABLE ar_global_files (file_name TEXT PRIMARY KEY, enabled BOOLEAN);
```

### Configuración del Cliente
El archivo `config.json` se crea automáticamente al ejecutar:
```bash
php cli.php discover
```

Ejemplo de `config.json` generado:
```json
{
    "locations": [
        {
            "rbfid": "roton",
            "base": "/srv/roton",
            "work": "/srv/roton/quickbck/"
        }
    ],
    "files_version": "0bb4b79b",
    "watch_files": ["AJTFLU.DBF", "ASISTE.DBF", ...]
}
```

## 🔄 Flujo de Operación

### 1. Descubrimiento de Ubicaciones
```bash
php cli.php discover
```
- Escanea discos buscando `rbf/rbf.ini` con patrón `_suc=`
- Crea `config.json` con ubicaciones detectadas
- Sincroniza archivos de base → work (quickbck)

### 2. Sincronización Continua
```bash
php cli.php
```
- Verifica lista de archivos cada **3600 segundos** (1 hora)
- Detecta cambios por tamaño o mtime
- Reporta archivos faltantes al servidor
- Sincroniza solo chunks modificados

### 3. Endpoints del Servidor
| Método | Endpoint | Descripción |
|--------|----------|-------------|
| POST | `/api/register/{rbfid}` | Registrar cliente |
| POST | `/api/config/{rbfid}` | Obtener lista de archivos |
| POST | `/api/sync/{rbfid}` | Sincronizar hashes |
| POST | `/api/upload/{rbfid}` | Subir chunks |
| POST | `/api/missing/{rbfid}` | Reportar archivos faltantes |

## 📊 Optimización de Transferencia

### Chunks Adaptativos
| Tamaño archivo | Tamaño chunk | Ejemplo |
|---------------|-------------|---------|
| < 1 MB | 64 KB | 1-16 chunks |
| 1-10 MB | 64 KB | 16-160 chunks |
| 10-100 MB | 256 KB | 40-400 chunks |
| > 100 MB | 1 MB | 100+ chunks |

### Patching Incremental
1. Servidor copia archivo existente de destino → temporal
2. Compara hashes de chunks individualmente
3. Solo chunks modificados se transfieren
4. Archivo final se ensambla y verifica

## 🔐 Autenticación TOTP

```php
// Generación de token
$seed = substr($timestamp, 0, -2);  // sin últimos 2 dígitos
$token = hash('xxh3', $seed . $rbfid);
$tokenBase64 = substr(base64_encode(strrev(hex2bin($token))), 0, 11);

// Validación en servidor
for ($d = -30; $d <= 30; $d++) {
    if (hash_equals($expectedToken, $receivedToken)) {
        return true; // Token válido
    }
}
```

## 🐛 Solución de Problemas

### Logs
- **Cliente**: `logs/ar-YYYY-MM-DD.log`
- **Servidor**: `logs/ar-YYYY-MM-DD.log`

### Comandos de Diagnóstico
```bash
# Verificar estado de archivos en BD
psql -U postgres -d sync -c "SELECT file_name, status FROM ar_files WHERE rbfid = 'roton';"

# Verificar chunks pendientes
psql -U postgres -d sync -c "SELECT file_name, COUNT(*) as pending FROM ar_file_hashes WHERE status='pending' GROUP BY file_name;"

# Probar conexión cliente-servidor
php cli.php discover --run-once
```

### Errores Comunes
1. **"Auth required"**: Verificar headers `X-RBFID` y `X-TOTP-Token`
2. **"File not found"**: Verificar permisos en directorios base y work
3. **"Hash mismatch"**: Verificar conversión base64 ↔ HEX en hashes

## 📈 Monitoreo

### Métricas Clave
- **Archivos sincronizados**: `SELECT COUNT(*) FROM ar_files WHERE status='completed'`
- **Archivos faltantes**: `SELECT COUNT(*) FROM ar_files WHERE status='missing'`
- **Chunks pendientes**: `SELECT COUNT(*) FROM ar_file_hashes WHERE status='pending'`
- **Tiempo de sincronización**: `SELECT EXTRACT(EPOCH FROM (updated_at - created_at)) FROM ar_sync_history`

### Health Check
```bash
curl -X POST http://respaldosucursal.servicios.care/api/health
```

## 🔄 Mantenimiento

### Limpieza de Archivos Temporales
```bash
# Archivos temporales del servidor (> 7 días)
find /tmp/ar/ -type f -mtime +7 -delete

# Logs antiguos (> 30 días)
find /var/www/respaldoSucursal/logs/ -name "*.log" -mtime +30 -delete
```

### Actualización de Lista de Archivos
1. Modificar tabla `ar_global_files` en BD
2. Clientes actualizarán automáticamente en próxima verificación (3600s)
3. Versión MD5 de lista se almacena en `config.json`

## 📝 Notas de Implementación

### Compatibilidad Multiplataforma
- **Windows**: Escanea `C:\` y `D:\`, excluye `Program Files`, `Windows`, etc.
- **Linux**: Solo escanea `/srv/`, mantiene compatibilidad con rutas Windows

### Persistencia de Estado
- Cliente: `config.json` con lista de archivos y versión
- Servidor: PostgreSQL con estado de archivos y chunks
- 4 copias garantizan resiliencia ante fallos

### Optimizaciones
- **Comparación por tamaño**: Más rápido que hash completo
- **Patching incremental**: Minimiza transferencia de datos
- **Cache local**: Evita re-sincronización de archivos no modificados

## 🤝 Contribución

1. Fork el repositorio
2. Crea una rama para tu feature (`git checkout -b feature/amazing-feature`)
3. Commit tus cambios (`git commit -m 'Add amazing feature'`)
4. Push a la rama (`git push origin feature/amazing-feature`)
5. Abre un Pull Request

## 📄 Licencia

Este proyecto está licenciado bajo la Licencia MIT - ver el archivo LICENSE para más detalles.

## 🙏 Agradecimientos

- Sistema diseñado para operación continua 24/7
- Compatibilidad con infraestructura legacy Windows
- Optimizado para conexiones de banda ancha limitada
- Resiliente ante interrupciones de red