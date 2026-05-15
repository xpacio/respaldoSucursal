# RespaldoSucursal — Propuesta Comercial

**Sistema de Respaldo, Monitoreo y Gestión Remota para Sucursales**

---

## ¿Qué es?

RespaldoSucursal es una plataforma que **centraliza la administración de todas tus sucursales** desde un solo panel web. Olvídate de ir físicamente a cada sucursal para verificar respaldos o actualizar sistemas.

## El Problema

Si hoy administras sucursales, seguramente enfrentas esto:

| Situación | Consecuencia |
|---|---|
| No sabes si la sucursal respaldó hoy | Pérdida de información crítica |
| Tienes que ir a cada sucursal a verificar | Horas perdidas en traslados |
| Cuando falla un respaldo, te enteras días después | Ventana de vulnerabilidad enorme |
| No hay visibilidad de qué archivos cambiaron | Imposible auditar cambios |
| Cada sucursal tiene configuraciones distintas | Procesos manuales uno por uno |

## La Solución

RespaldoSucursal **elimina todo eso** con tres componentes:

### 1. Panel Web Central

Desde cualquier navegador web puedes:

- **Ver el estado** de todas las sucursales en tiempo real (✅ verde, ⚠️ naranja, ❌ rojo)
- **Revisar logs** de ejecución de cada una
- **Administrar servicios** (qué respaldar, cada cuánto, con qué excluir)
- **Activar o desactivar** sucursales con un clic
- **Historial completo** de cada respaldo

### 2. Agente en Sucursal (2 versiones)

Un programa ligero que se instala en cada sucursal:

- **PHP** — Para entornos con PHP instalado
- **C Nativo (47KB)** — Ejecutable standalone, cero dependencias

Funciona 24/7 sin intervención. Solo necesita internet para comunicarse con el servidor central.

### 3. Servidor Central

Almacena, organiza y sirve los respaldos de todas las sucursales.

## Beneficios Clave

### 🔐 No Pierdes Información

Sincronización incremental por chunks: si un archivo cambia, solo se transfiere la parte modificada. Hasta 4 copias de cada archivo:

1. Archivo original en sucursal
2. Copia de trabajo local
3. Archivo temporal en servidor
4. Respaldo final inmutable

### ⏱️ Ahorras Tiempo

- **Monitoreo automático**: el sistema te alerta si una sucursal no reporta
- **Sin visitas físicas**: todo se administra desde el panel web
- **Configuración remota**: cambias parámetros desde el escritorio

### 📊 Visibilidad Total

- Estado de conexión en vivo de cada sucursal
- Histórico de cambios por archivo (tamaño, fecha, hash)
- Logs detallados de cada ejecución
- Reporte de compresión (cuánto datos se ahorraron en la transferencia)

### 🛡️ Seguro por Diseño

- **Autenticación TOTP**: token dinámico que cambia cada 100 segundos
- **Hash xxh3**: verificación de integridad de cada chunk transferido
- **Comunicación cifrada**: sobre HTTP/HTTPS
- **Tokens de único uso**: imposible reutilizar credenciales interceptadas

### 💻 Ligero y Eficiente

- **Cliente C**: 47KB, sin Java, sin PHP, sin librerías
- **Compresión en tiempo real**: hasta 50% de ahorro en ancho de banda
- **Consume mínimo**: funciona en equipos de bajo recursos (incluso en equipos POS)

## Casos de Uso

### Respaldo de Base de Datos (DBF)

El sistema está optimizado para archivos DBF/DBT/FPT usados por sistemas de facturación y punto de venta. Detecta automáticamente archivos como:

`VENTA.DBF`, `CLIENTE.DBF`, `INVENTARIO.DBF`, `FACTURAS.DBF`...

Sincroniza solo los bloques modificados, ahorrando ancho de banda.

### Descarga de Archivos desde Servidor Central

¿Necesitas distribuir archivos a sucursales (listas de precios, catálogos)? El servicio de **download** inverso permite que el servidor envíe archivos a las sucursales automáticamente.

### Monitoreo de Salud

Cada sucursal envía heartbeat periódicamente. Si una sucursal deja de reportar, el servidor lo detecta y se marca en rojo en el panel.

## Lo que Dicen los Números

| Métrica | Impacto |
|---|---|
| Tamaño del agente C | 47 KB |
| Ciclo de monitoreo | Cada 50 segundos |
| Compresión de datos | Hasta 50% |
| Consumo de RAM del agente | < 1 MB |
| Tiempo de recuperación tras fallo | 10 segundos |
| Ventana de autenticación | 100 segundos |

## Requerimientos Técnicos

### Servidor Central
- Linux con PHP 8.0+ y PostgreSQL
- Acceso web (Lighttpd, Nginx, Apache)
- Almacenamiento para respaldos

### Sucursal (Cliente)
- **Opción A**: PHP 8.0+ (cualquier SO)
- **Opción B**: Ejecutable C (Windows 7+) — **solo 47KB**
- Conexión HTTP al servidor central
- Sin puertos abiertos (todo es saliente)

## Modelo de Implementación

1. **Instalación del servidor** (1 hora)
   - Configurar PostgreSQL y base de datos
   - Desplegar archivos PHP
   - Configurar autenticación

2. **Registro de sucursales** (5 minutos cada una)
   - Copiar el agente a la sucursal
   - Ejecutar escaneo (detecta automáticamente la instalación)
   - El agente se registra solo contra el servidor

3. **Configuración de servicios** (30 minutos)
   - Desde el panel web, definir qué archivos respaldar
   - Establecer frecuencias y exclusiones
   - Activar monitoreo

4. **Automático** — De ahí en adelante, todo corre solo.

---

**RespaldoSucursal** — *Tu red de sucursales, bajo control desde un solo lugar.*

Para más información: [Documentación Técnica](index.md)
