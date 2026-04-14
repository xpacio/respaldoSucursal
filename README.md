# respaldoSucursal

## Backup API compartida

Se agregó un módulo de backup compartido bajo `shared/Backup/` para procesar sesiones de backup y recibir chunks de archivos.

### Endpoints

- `POST /backup/init`
  - Request body: `filename`, `file_hash`.
  - Response: `{ "session_id": "...", "status": "ready" }`

- `POST /backup/chunk`
  - Request body: `session_id`, `file_hash`, `chunk_index`, `chunk_hash`, `data`, `is_last`
  - Response: `{ "verified": true, "chunk_index": 0 }` o `{ "verified": true, "finalized": true, "message": "Backup completed" }`

### Prueba rápida

Desde la raíz del proyecto:

```bash
php shared/Backup/test_backup_flow.php
php test_ar_registration_flow.php
```

### Notas

- El repositorio de backup usa directorios temporales bajo `sys_get_temp_dir()`.
- El nuevo endpoint se registra en el router estándar como `/backup/init` y `/backup/chunk`.

