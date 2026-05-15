# Cliente C Nativo — winc/cli.exe

Implementación en C del cliente `cli.php` para Windows. Compilado con MinGW, 47KB (UPX), sin dependencias externas.

## Binario

`winc/cli.exe` — Portable, no requiere PHP ni librerías adicionales.

## Compilación

```bash
# Cross-compile desde Linux
cd winc
make

# Limpiar
make clean
```

Requiere: `x86_64-w64-mingw32-gcc` (MinGW-w64).

## Uso

```
cli.exe [comando] | -servicio [rbfid]
```

### Comandos

| Comando | Alias | Descripción |
|---|---|---|
| `start` | `s`, `main` | Inicia el orquestador (loop infinito) |
| `scan` | `b`, `buscar` | Escanea discos (C: a H:) en busca de PVSI |
| `ls` | `l` | Lista servicios: `cli.exe ls RBFID` |
| `-servicio` | — | Ejecuta servicio: `cli.exe -descargaVales RBFID` |

Sin argumentos: muestra estado y uso.

## Funcionalidad vs PHP

| Característica | winc/cli.exe | cli.php |
|---|---|---|
| Orquestador (heartbeat + schedule) | ✓ | ✓ |
| Upload con chunk sync | ✓ | ✓ |
| Download (chunks, ensamblado) | ✓ | ✓ |
| Compresión | ✓ (miniz deflate) | ✓ (gzcompress) |
| Hash xxh3 | ✓ (xxhash.h inline) | ✓ (PHP hash) |
| TOTP auth | ✓ (mismo algoritmo) | ✓ |
| Robocopy para máscaras | ✓ | ✓ |
| Exclude masks (wildcard) | ✓ | ✓ |
| Case-insensitive lookup | ✓ | ✓ |
| Missing files reporting | ✓ | ✓ |
| Service result | ✓ | ✓ |
| Resumen compresión | ✓ | ✓ |
| File changes detallados | ✓ | ✓ |
| Scan de disco PVSI | ✓ (C: a H:) | ✓ (todas las letras) |
| Sin dependencias externas | ✓ (Todo en 1 exe) | — (requiere PHP) |
| Tamaño | 47KB (UPX) | — |

## Tecnología

- **HTTP**: WinINet (`wininet.h`) — nativo de Windows
- **Hash**: xxHash 3 (header-only, `xxhash.h`)
- **Compresión**: miniz (deflate, mismo formato que zlib)
- **JSON**: Parser mínimo inline (sin dependencias)
- **Archivo único**: `cli.c` (~690 líneas)

## Beneficios vs PHP

1. **No requiere PHP** — Ejecutable standalone
2. **Mucho más pequeño** — 47KB vs PHP + librerías
3. **Inicio instantáneo** — Sin overhead de interprete
4. **Memoria mínima** — Sin VM ni GC
5. **Portable** — Solo el .exe, copiar y ejecutar
