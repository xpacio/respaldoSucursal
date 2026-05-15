# Interfaz de Administración Web — web.php

Panel de administración web para gestionar servicios, clientes, tablas y logs.

- Framework CSS: **Tabler** (CDN)
- Autenticación básica por sesión
- Backend PHP sin frameworks

## Acceso

```
http://servidor/
```

Login por defecto: `admin` / `admin123`

## Páginas

### Dashboard (`/`)

Muestra tarjetas con todas las tablas del sistema PostgreSQL. Cada tarjeta enlaza al visor de tabla.

### Servicios (`/services`)

Tabla de servicios del sistema ordenada por enabled + nombre.

| Columna | Descripción |
|---|---|
| ID | Identificador numérico |
| Name | Nombre del servicio |
| Type | Ícono: Upload ↑ / Download ↓ |
| Direction | upload / download |
| Archivos | Cantidad de archivos que procesa |
| MaxAge | Edad máxima en días (opcional) |
| Exclude | Patrones de exclusión |
| Recursive | Sí / No (para máscaras) |
| Enabled | Toggle switch (AJAX) |
| Freq | Selector de frecuencia (segundos) |
| Edit | Botón para editar |

Botón **"Create new service"** para agregar servicios.

### Editar Servicio (`/edit-service/{id}`)

Formulario completo:

- **name** — Nombre único del servicio
- **type** — upload / download / backup
- **direction** — upload / download
- **description** — Texto descriptivo
- **frequency_seconds** — Intervalo en segundos
- **files** — Lista separada por comas (archivos o máscaras `*.DBF`)
- **source** — Directorio origen (`{base}` = raíz de instalación PVSI)
- **temp** — Directorio temporal (`%tmp%`)
- **dest** — Directorio destino (solo download)
- **recursive** — Procesar subdirectorios
- **enabled** — Activar/desactivar
- **exclude** — Patrones a excluir (separados por coma)
- **maxage** — Edad máxima en días

### Clientes (`/clients`)

Lista de clientes (sucursales) registrados.

| Columna | Descripción |
|---|---|
| RBFID | Identificador único |
| Emp | Empresa |
| Plaza | Plaza/Sucursal |
| Razón Social | Nombre legal |
| Tipo | sucursal / bodega / otro |
| Heartbeat | Indicador de conexión (colores: verde < 30s, naranja > 50s, rojo > 30min) |
| Last Interaction | Última interacción del orquestador |
| Enabled | Activo/Inactivo |
| Edit / Delete | Acciones |

### Visor de Tablas (`/table/{nombre}`)

Muestra las primeras 100 filas de cualquier tabla del sistema. Incluye botón **"Truncate Table"** (solo admin).

### Logs (`/logs`)

Visor con pestañas para:
- Syslog
- Lighttpd (access + error)
- PHP-FPM
- PostgreSQL

## API de Acciones

| Acción | Método | Descripción |
|---|---|---|
| Login | POST (login_btn) | Autenticación |
| Logout | GET (?logout) | Cerrar sesión |
| Toggle Enabled | GET (?toggle_switch=ID) | AJAX |
| Toggle Frequency | GET (?freq_toggle=ID&freq=N) | AJAX |
| Save Service | POST (save_service) | Crear/editar servicio |
| Save Client | POST (save_client) | Crear/editar cliente |
| Delete Client | POST (delete_client) | Eliminar cliente |
| Truncate Table | POST (truncate_table) | Vaciar tabla |
