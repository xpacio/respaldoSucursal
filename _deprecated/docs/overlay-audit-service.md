# Overlay Audit Service

Servicio de auditoría y corrección automática de overlays. Ejecuta al boot y cada hora.

## Arquitectura

```
[Boot + cada hora]
        │
        ▼
systemd: overlay-audit.service (oneshot)
         overlay-audit.timer  (cada hora)
        │
        ▼
php /srv/app/www/sync/scripts/audit_overlays.php
        │
        ▼
OverlayAuditService->auditAll()
        │
        ├── Para cada cliente activo:
        │   └── auditClient($clientId)
        │       ├── Obtener overlays específicos (tabla overlays)
        │       ├── Obtener overlays de plantilla (tabla overlay_cliente_mounts)
        │       ├── Para cada overlay:
        │       │   ├── Comparar estado deseado (DB) vs estado real (filesystem)
        │       │   ├── Verificar permisos
        │       │   └── Corregir desviaciones
        │       └── Retornar resumen
        │
        └── Log detallado en systemd journal
```

## Lógica de Auditoría

**BD es la fuente de verdad.**

| Estado DB | Estado Real | Acción |
|-----------|-------------|--------|
| `mounted='t'` | Montado | ✅ OK - verificar permisos |
| `mounted='t'` | NO montado | 🔄 **Remontar** |
| `mounted='f'` | Montado | 🔄 **Desmontar** |
| `mounted='f'` | NO montado | ✅ OK |

**Excepción para plantillas con `auto_mount='t'`:** si `auto_mount='t'` y `mounted='f'`, se trata como si debería estar montado (remonta + corrige DB).

## Archivos

| Archivo | Descripción |
|---------|-------------|
| `Services/OverlayAuditService.php` | Clase de auditoría |
| `scripts/audit_overlays.php` | Script CLI |
| `systemd/overlay-audit.service` | Servicio systemd (oneshot) |
| `systemd/overlay-audit.timer` | Timer systemd (cada hora) |

### Symlinks en sistema

```bash
/etc/systemd/system/overlay-audit.service -> /srv/app/www/sync/systemd/overlay-audit.service
/etc/systemd/system/overlay-audit.timer   -> /srv/app/www/sync/systemd/overlay-audit.timer
```

## Comandos

```bash
# Ver estado del timer
systemctl status overlay-audit.timer

# Ejecutar auditoría manualmente
systemctl start overlay-audit.service

# Ver logs
journalctl -t overlay-audit --no-pager -n 50

# Habilitar/deshabilitar
systemctl enable overlay-audit.timer
systemctl disable overlay-audit.timer
```

## Dependencias

- `MountService::isMounted()` - verificar si un path está montado
- `MountService::mountBind()` - montar overlay
- `MountService::umount()` - desmontar overlay
- `MountService::ensurePermissions()` - verificar/corregir permisos

## Idempotencia

El servicio es completamente idempotente:
- Seguro ejecutar múltiples veces sin efectos secundarios
- Solo remonta lo que necesita ser remontado
- Solo corrige permisos que están incorrectos
- Actualiza DB solo cuando hay desviaciones
