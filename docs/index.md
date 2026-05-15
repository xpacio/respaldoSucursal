# Sistema de Orquestación y Respaldo de Sucursales

Sistema cliente-servidor para respaldo, monitoreo y gestión remota de sucursales.

## Componentes

| Componente | Tecnología | Descripción |
|---|---|---|
| `cli.php` | PHP 8+ | Cliente principal (orquestador, sync, upload/download) |
| `winc/cli.exe` | C (MinGW) | Cliente nativo Windows, misma funcionalidad, 47KB |
| `srv.php` | PHP 8+ | API REST del servidor |
| `web.php` | PHP 8+ + Tabler CSS | Interfaz de administración web |
| `index.php` | PHP 8+ | Router (API → srv.php, Web → web.php) |

## Documentación

- [`cli-php.md`](cli-php.md) — Cliente PHP (cli.php)
- [`winc-c.md`](winc-c.md) — Cliente C nativo (winc/cli.exe)
- [`web-admin.md`](web-admin.md) — Interfaz de administración web
- [`api.md`](api.md) — API REST del servidor
- [`config.md`](config.md) — Configuración

## Flujo Básico

```
Sucursal (cliente)                    Servidor Central
      │                                      │
      │──── POST /api/heartbeat ────────────>│
      │──── POST /api/schedule ─────────────>│
      │<─── servicios_a_ejecutar ────────────│
      │                                      │
      │──── POST /api/service_config ───────>│
      │<─── config (files, direction) ───────│
      │                                      │
      │─── sync → upload chunks ────────────>│
      │─── service_result ──────────────────>│
      │                                      │
      │ (cada 50s repite el ciclo)           │
```

## Versionado

Formato: `X.YMMDDc` — Ej: `0.60515b`
- X: Mayor, Y: año, MM: mes, DD: día, c: commit del día
