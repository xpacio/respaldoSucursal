# AGENTS.md - Sesión 2026-04-30

## Resumen de Cambios Realizados

### 1. Límites y Almacenamiento de Chunks (srv.php)
- **Límite de archivo**: Cambiado de 5GB (5368709120) a 1GB (1073741824)
- **Almacenamiento de chunks**: 
  - Antes: Se escribían directo al archivo work (`Storage::saveChunk()`)
  - Ahora: Se guardan en `.chunks/{filename}/{chunk_index}` como archivos separados
  - Ensamblado atómico en finalize() usando archivo temporal `.assembling`

### 2. Detección de Cambios en Archivos
- **Bug identificado**: No detectaba cambios cuando se modificaba 1 byte al inicio o final
- **Causa**: Usaba `$srv['status'] === 'completed'` que fallaba después del primer cambio detectado
- **Fix**: Ahora usa `file_exists($destFile)` para verificar si el archivo existe en destino
- **Comparación inteligente**: Cuando el hash cambia pero el tamaño se mantiene igual, compara chunks del archivo destino (no borra .chunks/)

### 3. Preservación de Timestamps
- Se aplica el timestamp original del cliente (file_mtime) al archivo ensamblado ANTES de moverlo a destino
- Uso de `touch($assemblyFile, $originalMtime)` en finalize()

### 4. Seguimiento de Cambios para el Cliente
En la respuesta de `sync()`, se incluye `file_changes` con:
- `hash`: Hash del archivo
- `dest`: Ruta destino donde se guardará
- `old_size` / `new_size`: Tamaños en bytes
- `diff_bytes`: Diferencia en bytes
- `diff_kb` / `diff_mb`: Diferencia en KB y MB
- `growth_pct`: Porcentaje de crecimiento
- `old_size_fmt` / `new_size_fmt` / `diff_fmt`: Formato legible (B, KB, MB)
- `old_mtime` / `new_mtime`: Timestamps
- `time_diff_sec` / `time_diff_fmt`: Diferencia de tiempo en segundos, minutos, horas, días

### 5. Resumen de Ejecución del Servicio
El cliente (cli.php) ahora muestra al final de cada servicio:
- **Time**: Tiempo de ejecución en ms
- **Data**: Total de datos transferidos (KB/MB)
- **Chunks**: Total de chunks subidos
- **Size +**: Incremento total de tamaño de archivos procesados

### 6. Interfaz Web (web.php)
- Agregado botón "Create new service" con estilo Tabler
- Tabla de servicios ordenada: enabled primero, luego alfabéticamente
- Columna "Archivos" ahora muestra conteo en lugar de listado
- Agregadas columnas: MaxAge y Exclude
- Eliminados colores de badges (badge bg-info, bg-warning)

### 7. Limpieza de Chunks
- En finalize() exitoso:
  - Se borran registros de `file_chunks` de la DB
  - Se elimina directorio `.chunks/` del disco
- En caso de error: Se mantienen los chunks para reintentos

### 8. Control de Versiones
- Formato: `X.YMMDc`
  - X: Versión mayor (0=inicial)
  - Y: Último dígito del año (6=2026)
  - MM: Mes (01-12)
  - DD: Día (01-31)
  - c: Letra del commit (a-z, 'a' es el primer commit del día)
- Ejemplo actual: `0.60430j` (10° commit del 2026-04-30)

---

## Cómo se Trackean los Cambios de Peso y Chunks

### Tracking de Tamaño (Peso) de Archivos

**En el servidor (srv.php - método sync()):**
```php
// Al procesar cada archivo en sync()
$oldSize = (int)($srv['file_size'] ?? 0);
$newSize = (int)$fileSize;
$diffBytes = $newSize - $oldSize;
$growthPct = $oldSize > 0 ? round(($diffBytes / $oldSize) * 100, 2) : 0;

// Se agrega al array file_changes[] que se envía en la respuesta JSON
$fileChanges[] = [
    'file' => $name,
    'old_size' => $oldSize,
    'new_size' => $newSize,
    'diff_bytes' => $diffBytes,
    'diff_kb' => round($diffBytes / 1024, 2),
    'diff_mb' => round($diffBytes / 1048576, 2),
    'growth_pct' => $growthPct,
    'old_size_fmt' => '1.5 MB',  // Formato legible
    'new_size_fmt' => '2.3 MB',
    'diff_fmt' => '0.8 MB'
];
```

**En el cliente (cli.php):**
```php
// Al recibir la respuesta de sync()
foreach ($req['file_changes'] as $fc) {
    Log::info("Size: {$fc['old_size_fmt']} -> {$fc['new_size_fmt']} (diff: {$fc['diff_fmt']}, {$fc['growth_pct']}%)");
}

// Al final del servicio, se acumula el incremento total
$GLOBALS['totalSizeIncrease'] = 0;
foreach ($req['file_changes'] as $fc) {
    $GLOBALS['totalSizeIncrease'] += $fc['diff_bytes'] ?? 0;
}
```

### Tracking de Chunks Transferidos

**En el servidor (srv.php - método upload()):**
```php
// En upload(), se guarda el chunk y se trackea el tamaño
$dataLen = strlen($data);
$GLOBALS['totalBytes'] = ($GLOBALS['totalBytes'] ?? 0) + $dataLen;
```

**En el cliente (cli.php - método uploadFile()):**
```php
// Al subir cada chunk exitosamente
$GLOBALS['totalBytesTransferred'] = ($GLOBALS['totalBytesTransferred'] ?? 0) + $dataLen;
$GLOBALS['totalChunks'] = ($GLOBALS['totalChunks'] ?? 0) + 1;
```

**Resumen al final del servicio (cli.php):**
```php
$execMs = (int)((microtime(true) - $start) * 1000);
$bytes = $GLOBALS['totalBytesTransferred'] ?? 0;
$chunks = $GLOBALS['totalChunks'] ?? 0;
$sizeInc = $GLOBALS['totalSizeIncrease'] ?? 0;

$fmtBytes = $bytes < 1048576 ? round($bytes/1024,2).' KB' : round($bytes/1048576,2).' MB';
$fmtSize = $sizeInc < 1048576 ? round($sizeInc/1024,2).' KB' : round($sizeInc/1048576,2).' MB';

Log::info("Time: {$execMs}ms | Data: $fmtBytes | Chunks: $chunks | Size +: $fmtSize");
```

---

## Commits Realizados en esta Sesión

| Commit | Descripción |
|--------|-------------|
| `2a2d56d` | refactor: save chunks separately and improve error recovery |
| `6eb2e03` | feat: improve services UI - add create button, show file count, add maxage/exclude columns |
| `8044a33` | fix: remove badge colors from services table, rename Files Count to Archivos |
| `f10896f` | fix: order services by enabled first, then alphabetically |
| `e545639` | fix: chunk change detection when file hash changes but size unchanged |
| `9bc59fa` | feat: preserve original file timestamp before moving to destination |
| `0eba8eb` | feat: track file size changes with diff bytes and growth percentage |
| `749d546` | feat: include formatted file size changes (B, KB, MB) in sync response |
| `decd775` | feat: add time difference tracking and document version format |
| `838e913` | bump: version 0.60430j (10 commits on 2026-04-30) |
| `cc9954f` | feat: show file hash, dest path, size diff and time diff in client log |
| `2023f56` | fix: detect file changes by checking dest file existence, add execution summary |
| `fd28707` | fix: complete execution summary and change detection |

---

## Estado Actual
- **Versión**: `0.60430j`
- **Branch**: `evo`
- **Commits hoy**: 13
- **Bug de detección de cambios**: SOLUCIONADO
- **Tracking completo**: Tamaños, chunks, tiempo, incrementos
