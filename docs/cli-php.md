# Cliente PHP — cli.php

Cliente principal del sistema. Actúa como agente orquestador que ejecuta servicios programados dinámicamente.

## Uso

```
php cli.php [comando] | -servicio [rbfid]
```

### Comandos

| Comando | Alias | Descripción |
|---|---|---|
| `start` | `s`, `main` | Inicia el orquestador (loop infinito, polling cada 50s) |
| `scan` | `b`, `buscar`, `scann` | Escanea discos en busca de instalaciones PVSI |
| `ls` | `l` | Lista servicios de un RBFID: `php cli.php ls RBFID` |
| `info` | — | Muestra estado y servicios |
| `-servicio` | — | Ejecuta un servicio específico: `php cli.php -descargaVales RBFID` |

### Ejemplos

```bash
# Iniciar orquestador
php cli.php start

# Escanear discos
php cli.php scan

# Listar servicios de una sucursal
php cli.php ls roton

# Ejecutar un servicio específico
php cli.php -descargaVales roton

# Ver estado
php cli.php
```

## Orchestrator

El orquestador ejecuta un loop infinito con ciclos de **50 segundos**:

1. Envía **heartbeat** a servidor (`running`)
2. Consulta **schedule** → servidor devuelve servicios pendientes
3. Ejecuta cada servicio (upload o download según config)
4. Espera hasta completar el ciclo de 50s (o 10s si se atrasó)

## Servicios

### Upload (dirección por defecto)

1. Obtiene config del servicio (files, source, exclude, maxage, recursive)
2. Para cada archivo:
   - Si es **máscara** (`*`, `?`): usa robocopy para copiar al temp
   - Si es **archivo individual**: busca case-insensitive, copia al temp
3. Aplica **exclude masks** (wildcard)
4. Para cada archivo en temp:
   - Calcula hash xxh3 del archivo completo
   - Divide en chunks según tamaño
   - Envía sync → servidor responde qué chunks faltan
   - Sube chunks faltantes (comprimidos con gzcompress) con reintentos (3)
   - Repite sync hasta que no falten chunks

### Download

1. Obtiene config (source local, dest local, files)
2. Envía lista de archivos locales con hashes al servidor
3. Servidor responde qué archivos tienen diferencias
4. Para cada archivo: descarga chunks, ensambla, guarda en destino

## Salida de Servicio

Al finalizar cada servicio muestra:

```
Time: 1234ms | Data: 1.23 MB | Chunks: 45 | Size +: 0.5 MB
Compression: 1.5 MB -> 0.8 MB (saved: 0.7 MB, 46%)
```

## Dependencias

- PHP 8.0+
- ext-curl
- ext-xxh3 (o hash nativo xxh3 en PHP 8.1+)
- ext-zlib (gzcompress)
- robocopy (Windows, para máscaras)
