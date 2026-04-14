# Plan: Normalizar files y dst_template por tipo en Distribución

## Contexto

Todos los registros de `distribucion` con el mismo `tipo` tienen los mismos `files` y `dst_template`.
Guardar estos valores en cada row es redundante. Se normaliza a una tabla `distribucion_tipos`.

## Modelo de datos

### Nueva tabla

```sql
CREATE TABLE distribucion_tipos (
    tipo text PRIMARY KEY,
    files text NOT NULL,
    dst_template text NOT NULL
);
```

### Cambios en `distribucion`

- Se quitan columnas `files` y `dst_template`
- Se agrega FK: `distribucion.tipo → distribucion_tipos.tipo`

## UI: Dos modales independientes

### Modal 1: "Nueva Distribución" (simplificado)

Solo 4 campos:
- Nombre (text input)
- Tipo (select desde `distribucion_tipos`)
- Plaza (text input con autocomplete)
- Origen / src_path (text input)

Al seleccionar un tipo en el select, se muestra debajo (readonly) qué archivos y destino hereda.

### Modal 2: "Nuevo Perfil" (CRUD de tipos)

Nuevo botón "Nuevo Perfil" hermano del botón "Nueva Distribución" en el page header.

Campos del form:
- Tipo (text input)
- Archivos (text input, coma-separados)
- Destino / dst_template (text input)

Con tabla listando los perfiles existentes y botones editar/eliminar.

## Archivos a modificar

### 1. SQL migration (nuevo archivo)

```sql
-- Crear tabla
CREATE TABLE IF NOT EXISTS distribucion_tipos (
    tipo text PRIMARY KEY,
    files text NOT NULL,
    dst_template text NOT NULL
);

-- Migrar datos existentes
INSERT INTO distribucion_tipos (tipo, files, dst_template)
SELECT DISTINCT tipo, files, dst_template FROM distribucion
ON CONFLICT (tipo) DO NOTHING;

-- Agregar FK
ALTER TABLE distribucion
    ADD CONSTRAINT distribucion_tipo_fkey
    FOREIGN KEY (tipo) REFERENCES distribucion_tipos(tipo);

-- Quitar columnas redundantes
ALTER TABLE distribucion DROP COLUMN files;
ALTER TABLE distribucion DROP COLUMN dst_template;
```

### 2. `Repositories/DistribucionRepository.php`

- `listAll()`: agregar `JOIN distribucion_tipos dt ON dt.tipo = d.tipo`, SELECT `dt.files, dt.dst_template`
- `get()`: mismo JOIN
- `create()`: quitar `files` y `dst_template` del INSERT (solo: nombre, tipo, plaza, src_path)
- `updateDistribucion()`: quitar `files` y `dst_template` del UPDATE
- Nuevos métodos:
  - `getTipos()`: `SELECT * FROM distribucion_tipos ORDER BY tipo`
  - `getTipo(string $tipo)`: `SELECT * FROM distribucion_tipos WHERE tipo = $1`
  - `createTipo(array $data)`: INSERT en `distribucion_tipos`
  - `updateTipo(string $tipo, array $data)`: UPDATE en `distribucion_tipos`
  - `deleteTipo(string $tipo)`: DELETE de `distribucion_tipos` (cascada a distribuciones)

### 3. `Services/DistribucionService.php`

- `create()`: quitar validación de `files`/`dst_template` (vienen del tipo). Solo validar: nombre, tipo existe, plaza, src_path válido
- `update()`: igual
- `evaluarVersion()` y `copiar()`: sin cambios, ya leen `dist['files']` y `dist['dst_template']` que vienen del JOIN
- Nuevos métodos:
  - `getTipos()`: retorna todos los tipos con sus files/dst_template
  - `createTipo($data)`: valida y crea tipo
  - `updateTipo($tipo, $data)`: valida y actualiza tipo
  - `deleteTipo($tipo)`: elimina tipo

### 4. `routes/distribucion.php`

Agregar actions en el match:
```php
'tipos'          => $svc->getTipos(),
'create_tipo'    => $svc->createTipo($data),
'update_tipo'    => $svc->updateTipo($data['tipo'] ?? '', $data),
'delete_tipo'    => $svc->deleteTipo($data['tipo'] ?? ''),
```

### 5. `distribucion.php` (HTML)

- Agregar botón "Nuevo Perfil" al lado de "Nueva Distribución" (page header)
- Modal de edición: reemplazar inputs `edit-tipo`, `edit-files`, `edit-dst` por `<select id="edit-tipo">` + campo readonly de preview
- Agregar nuevo modal `#perfilModal` con form: tipo, archivos, dst_template + tabla de perfiles existentes

### 6. `js/modules/distribucionView.js`

- `openEdit()`: cargar tipos en select, no llenar files/dst
- `saveDist()`: enviar solo `{nombre, tipo, plaza, src_path}`, sin files/dst
- Nuevos: `loadPerfiles()`, `openPerfil()`, `savePerfil()`, `deletePerfil()`
- Al cambiar select de tipo: mostrar preview de archivos/destino
- Quitar AutocompleteInput de `edit-tipo` (ahora es un select)

### 7. `js/apiService.js`

No se requieren cambios (usa `api.distribucion(data)` genérico).

## Orden de ejecución

1. Crear y ejecutar SQL migration
2. Actualizar Repository (JOIN + CRUD tipos)
3. Actualizar Service (adaptar create/update + nuevos métodos tipos)
4. Actualizar Routes (nuevos actions)
5. Actualizar HTML (`distribucion.php`)
6. Actualizar JS (`distribucionView.js`)
7. Probar con `php -l` y verificar flujo
