# respaldoSucursal

## Arquitectura simplificada

Este proyecto ha sido refactorizado para enfocarse únicamente en las funcionalidades de backup y sincronización AR. El entrypoint `index.php` y el router han sido simplificados para eliminar lógica legacy de UI y autenticación.

### Endpoints disponibles

- `GET /` o `GET /health` - Health check básico
- `POST /ar/*` - Endpoints de sincronización AR (registro, config, sync, upload, etc.)
- `POST /backup/*` - Endpoints de backup (init, chunk)
- `POST /heartbeat` - Endpoint de heartbeat
- `POST /client/*`, `POST /job/*`, `POST /public/*` - Endpoints atómicos

### Pruebas

```bash
php shared/Backup/test_backup_flow.php
php test_ar_registration_flow.php
```

### Notas

- El proyecto usa solo las rutas API esenciales sin UI heredada.
- La configuración de base de datos se mantiene en `shared/Config/config.php`.
- El autoloader carga clases bajo demanda desde `shared/autoload.php`.

