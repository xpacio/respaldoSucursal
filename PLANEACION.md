# PLANEACIÓN - Evolución del Sistema de Respaldo Sucursal

## 📋 Resumen Ejecutivo

**Objetivo**: Evolucionar el sistema actual de respaldo (upload-only) a un sistema multi-servicio que incluya:
1. **5 servicios de descarga** (vales + 4 más)
2. **3 servicios de metadata/monitoreo** (espacio disco, CPU, nombre equipo, reinicio)
3. **Mantener el servicio existente** de respaldo (upload)

**Arquitectura**: Cliente único extensible (`cli.php`) que funciona como orquestador y ejecuta servicios específicos mediante parámetros.

## 🎯 Principios de Diseño

### 1. Simplicidad Extrema
- **3 archivos base** en cliente: `cli.php`, `shared_core.php`, `config.json`
- **0 includes complejos** - solo `require_once 'shared_core.php';`
- **Mismo código** en servidor y cliente para utilidades compartidas

### 2. Resiliencia por Diseño
- **Cada servicio = proceso independiente**
- **Fallos no afectan** otros servicios
- **Auto-recuperación** mediante scheduling

### 3. Separación Clara Servidor/Cliente
- **Servidor**: Define qué, cuándo y cómo ejecutar servicios
- **Cliente**: Ejecuta servicios según instrucciones del servidor
- **Configuración centralizada** en base de datos del servidor

### 4. Compatibilidad Multiplataforma
- **Windows**: NSSM como servicio
- **Linux**: systemd como servicio
- **Mismo código PHP** en ambas plataformas

## 🏗️ Arquitectura Propuesta

### Estructura de Archivos

```
# SERVIDOR (/var/www/respaldoSucursal/api/)
api/
├── index.php              # Servidor extendido (upload + download endpoints)
├── shared_core.php        # Hash, Log, Constants, Chunk (ambos lados)
├── shared_server.php      # DB, Config, Totp, Storage (solo servidor)
└── (opcional) cli.php    # Para pruebas

# CLIENTE (C:\respaldoSucursal\ o /srv/respaldoSucursal/)
respaldoSucursal/
├── cli.php               # ORQUESTADOR + todos los servicios
├── shared_core.php       # Mismo que servidor (copiar)
├── shared_client.php     # ClientConfig, Platform, ServiceLauncher
└── config.json          # Configuración inicial
```

### Flujo de Ejecución

```
1. ORQUESTADOR (sin parámetros):
   php cli.php
   ↓
   Consulta servidor: "¿qué servicios debo ejecutar y cuándo?"
   ↓
   Para cada servicio programado:
     Ejecuta: php cli.php -descargaVales -rbfid roton
     ↓
     Proceso independiente termina

2. SERVICIO ESPECÍFICO (con parámetros):
   php cli.php -descargaVales -rbfid roton
   ↓
   Ejecuta lógica específica del servicio
   ↓
   Reporta resultados al servidor
   ↓
   Termina proceso (exit 0)
```

## 📊 Especificación de Servicios

### 1. Servicios de Descarga (5 totales)

#### 1.1 `descargaVales`
- **Archivos**: `EISYENC.DBF`, `EISYPAR.DBF`
- **Origen**: `/srv/vales/{emp}/{plaza}/{rbfid}/`
- **Destino**: `{base}/MODEM_ATM/`
- **Frecuencia**: Cada 300 segundos (5 minutos)
- **Crear si no existe**: Sí

#### 1.2-1.5 Otros servicios de descarga
- **Patrón común**: Origen → Destino + Lista de archivos
- **Configuración**: Definida en base de datos del servidor
- **Frecuencias**: Variables según necesidad

### 2. Servicios de Metadata/Monitoreo (3 totales)

#### 2.1 `monitoreoDisk`
- **Métrica**: Espacio en disco
- **Comando**: `df -h` (Linux) / `wmic logicaldisk` (Windows)
- **Frecuencia**: Cada 300 segundos

#### 2.2 `monitoreoCpu`
- **Métrica**: Uso de CPU
- **Comando**: `/proc/stat` (Linux) / WMI (Windows)
- **Frecuencia**: Cada 60 segundos

#### 2.3 `sistemaInfo`
- **Métricas**: Nombre de equipo, reinicio programado
- **Comando**: `hostname`, `uptime`, `shutdown`
- **Frecuencia**: Cada 3600 segundos

### 3. Servicio Existente de Respaldo

#### 3.1 `respaldo`
- **Funcionalidad**: Upload de archivos al servidor
- **Mantener**: Lógica actual sin cambios
- **Integración**: Como otro servicio más en el orquestador

## 🗄️ Base de Datos - Nuevas Tablas

```sql
-- Servicios disponibles
CREATE TABLE services (
    id SERIAL PRIMARY KEY,
    name VARCHAR(50) UNIQUE,        -- 'descargaVales', 'monitoreoDisk', etc.
    type VARCHAR(20),               -- 'download', 'monitor', 'upload'
    description TEXT,
    enabled BOOLEAN DEFAULT true
);

-- Configuración por cliente-servicio
CREATE TABLE client_services (
    client_rbfid TEXT REFERENCES clients(rbfid),
    service_id INTEGER REFERENCES services(id),
    frequency_seconds INTEGER,      -- 300, 3600, etc.
    config JSONB,                   -- {origen: '/srv/vales/...', destino: '...'}
    enabled BOOLEAN DEFAULT true,
    last_execution TIMESTAMP,
    next_execution TIMESTAMP,
    PRIMARY KEY (client_rbfid, service_id)
);

-- Historial de ejecuciones
CREATE TABLE service_executions (
    id SERIAL PRIMARY KEY,
    client_rbfid TEXT,
    service_name VARCHAR(50),
    started_at TIMESTAMP DEFAULT NOW(),
    completed_at TIMESTAMP,
    status VARCHAR(20),             -- 'success', 'failed', 'partial'
    results JSONB,                  -- {files_downloaded: [...], errors: [...]}
    execution_time_ms INTEGER
);

-- Health checks del orquestador
CREATE TABLE client_health (
    client_rbfid TEXT PRIMARY KEY,
    last_heartbeat TIMESTAMP DEFAULT NOW(),
    orchestrator_status VARCHAR(20), -- 'running', 'stopped', 'error'
    services_running JSONB,          -- {descargaVales: true, monitoreoDisk: false}
    system_info JSONB               -- {platform: 'windows', php_version: '8.2'}
);
```

## 🔌 Endpoints API Nuevos

### 1. Gestión de Servicios
```
GET    /api/services/{rbfid}/schedule
POST   /api/services/{rbfid}/config
GET    /api/services/{rbfid}/config/{service}
```

### 2. Descarga de Archivos
```
GET    /api/download/{rbfid}/list/{service}
POST   /api/download/{rbfid}/chunk
GET    /api/download/{rbfid}/file/{filename}
```

### 3. Reporte de Resultados
```
POST   /api/services/{rbfid}/result
POST   /api/services/{rbfid}/health
```

### 4. Metadata/Monitoreo
```
POST   /api/monitoring/{rbfid}/metrics
```

## 🛠️ Implementación por Fases

### Fase 1: Refactorización Core (rama `evo`) [✅ COMPLETADO]
1. **Separar `shared.php`** en: [✅]
   - `shared_core.php`: Hash, Log, Constants, Chunk
   - `shared_server.php`: DB, Config, Totp, Storage
   - `shared_client.php`: ClientConfig, Platform, ServiceLauncher

2. **Extender `index.php`** con: [✅]
   - Nuevas tablas en BD
   - Endpoints de scheduling y descarga
   - Sistema de resultados

3. **Reescribir `cli.php`** como: [✅]
   - Orquestador (sin parámetros)
   - Ejecutor de servicios (con parámetros)
   - Sistema de auto-llamada

### Fase 2: Servicio `descargaVales`
1. **Implementar lógica de descarga**:
   - Consultar configuración del servidor
   - Descargar archivos por chunks
   - Copiar a carpeta `MODEM_ATM`

2. **Integrar con sistema existente**:
   - Reutilizar autenticación TOTP
   - Usar mismo sistema de chunks
   - Mantener logs unificados

### Fase 3: Servicios de Monitoreo
1. **Implementar comandos multiplataforma**:
   - Disk: `df -h` / `wmic logicaldisk`
   - CPU: `/proc/stat` / WMI
   - Sistema: `hostname`, `uptime`

2. **Normalizar métricas**:
   - Formato JSON consistente
   - Unidades estandarizadas
   - Timestamps UTC

### Fase 4: Sistema de Scheduling
1. **Orquestador inteligente**:
   - Consultar frecuencias del servidor
   - Ejecutar servicios según schedule
   - Manejar conflictos y prioridades

2. **Health checks**:
   - Reportar estado al servidor
   - Detectar servicios colgados
   - Auto-recuperación

## 🔄 Migración desde Sistema Actual

### Paso 1: Compatibilidad
- Mantener endpoints existentes sin cambios
- `cli.php` nuevo convive con antiguo
- Configuración migrable automáticamente

### Paso 2: Deployment Gradual
1. Instalar nuevo `cli.php` junto al existente
2. Probar servicios individualmente
3. Migrar scheduling de cron/NSSM a orquestador
4. Deshabilitar sistema antiguo

### Paso 3: Rollback Sencillo
- Volver a `cli.php` antiguo
- Restaurar cron/NSSM original
- Mantener datos en BD compatibles

## ⚠️ Riesgos y Mitigaciones

### Riesgo 1: `cli.php` crece demasiado
**Mitigación**: Separar en `cli_orchestrator.php` y `cli_services.php` si supera 1000 líneas

### Riesgo 2: Auto-llamada recursiva
**Mitigación**:
```php
if (getenv('SERVICE_RUNNING')) {
    die("Error: Servicio ya en ejecución\n");
}
putenv('SERVICE_RUNNING=1');
```

### Riesgo 3: Pérdida de procesos hijos
**Mitigación**: Orquestador monitorea PIDs y relanza si es necesario

### Riesgo 4: Configuración desincronizada
**Mitigación**: Servidor provee versión, cliente actualiza si es necesario

## 📈 Métricas de Éxito

### 1. Funcionalidad
- [ ] 5 servicios de descarga implementados
- [ ] 3 servicios de monitoreo implementados
- [ ] Servicio de respaldo mantenido
- [ ] Scheduling centralizado funcionando

### 2. Rendimiento
- [ ] < 1% de pérdida de procesos hijos
- [ ] < 100ms overhead por servicio
- [ ] < 5MB memoria por proceso servicio

### 3. Confiabilidad
- [ ] 99.9% uptime del orquestador
- [ ] 0% interferencia entre servicios
- [ ] Recuperación automática en < 60 segundos

### 4. Mantenibilidad
- [ ] < 30 minutos para agregar nuevo servicio
- [ ] Configuración 100% desde servidor
- [ ] Logs estructurados y buscables

## 🚀 Plan de Ejecución

### Semana 1: Arquitectura Base
- [ ] Crear rama `evo` desde `mono`
- [ ] Refactorizar `shared.php` en componentes
- [ ] Diseñar nuevas tablas de BD
- [ ] Implementar endpoints básicos de scheduling

### Semana 2: Servicio `descargaVales`
- [ ] Implementar lógica de descarga
- [ ] Integrar con sistema de chunks existente
- [ ] Probar flujo completo
- [ ] Documentar API

### Semana 3: Servicios de Monitoreo
- [ ] Implementar comandos multiplataforma
- [ ] Normalizar métricas
- [ ] Probar en Windows y Linux
- [ ] Optimizar frecuencia de ejecución

### Semana 4: Sistema de Scheduling
- [ ] Implementar orquestador inteligente
- [ ] Agregar health checks
- [ ] Probar resiliencia
- [ ] Documentar deployment

### Semana 5: Integración y Pruebas
- [ ] Migración gradual desde sistema actual
- [ ] Pruebas de carga y estrés
- [ ] Documentación completa
- [ ] Plan de rollback

## 📝 Decisiones de Diseño Confirmadas

### 1. Autenticación
- **Mantener TOTP** actual sin cambios
- **Solo RBFID** necesario en cliente
- `emp` y `plaza` se obtienen de la BD del servidor

### 2. Configuración
- **Ningún secret** en `config.json`
- **Todo TOTP-based** como actualmente
- **Configuración servidor → cliente** vía API

### 3. Logs
- **Un archivo por servicio**: `logs/descargaVales.log`
- **Orquestador separado**: `logs/orchestrator.log`
- **Sin retención automática** inicialmente

### 4. Scheduling
- **Frecuencias en segundos**: 300, 3600, etc.
- **Definido en servidor** (BD)
- **Cliente consulta** periódicamente

### 5. Prioridades
- **Todos iguales** por diseño
- **Diferenciación por frecuencia**
- **Sin dependencias** entre servicios

## 🔧 Herramientas y Tecnologías

### Mantenidas
- **PHP 8.0+**: Lenguaje base
- **PostgreSQL**: Base de datos
- **TOTP**: Autenticación
- **XXH3**: Hashing

### Nuevas
- **JSONB**: Configuración flexible en PostgreSQL
- **cron-expressions**: Parsing de schedules (opcional)
- **Multiplataforma**: Comandos Windows/Linux

## 🤝 Responsabilidades de Equipo

### Backend (Servidor)
- Diseño de base de datos
- Endpoints API
- Lógica de scheduling
- Sistema de resultados

### Frontend/Cliente
- Orquestador `cli.php`
- Servicios específicos
- Compatibilidad multiplataforma
- Sistema de logs

### DevOps/Deployment
- Scripts de instalación
- Configuración NSSM/systemd
- Monitoreo y alertas
- Plan de rollback

## 📞 Puntos de Contacto y Decisiones Pendientes

### Por Definir
1. **Nombres exactos** de los 5 servicios de descarga
2. **Frecuencias específicas** por servicio
3. **Estructura de `config.json`** final
4. **Política de retención** de logs históricos
5. **Sistema de alertas** para fallos críticos

### Decisiones Técnicas Pendientes
1. ¿Usar `cron-expressions` o segundos simples?
2. ¿Comprimir logs automáticamente?
3. ¿Sistema de actualización automática de código?
4. ¿Backup de configuración cliente?

---

*Documento creado: 2026-04-18*
*Última actualización: 2026-04-18*
*Estado: Planificación aprobada, listo para implementación*