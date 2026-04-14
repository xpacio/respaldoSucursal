<?php

class DistribucionService {
    private Database $db;
    private Logger $logger;
    private DistribucionRepository $repo;
    private BackendWorker $worker;

    public function __construct(Database $db, Logger $logger) {
        $this->db = $db;
        $this->logger = Logger::create($db);
        $this->repo = new DistribucionRepository($db);
        $this->worker = new BackendWorker($db, $logger, new System([]));
    }

    // ─── CRUD ───

    public function list(array $filters = []): array {
        $rows = $this->repo->listAll($filters);
        return ['ok' => true, 'distribuciones' => $rows];
    }

    public function options(): array {
        $opts = $this->repo->options();
        return ['ok' => true, 'tipos' => $opts['tipos'], 'plazas' => $opts['plazas']];
    }

    public function get(int $id): array {
        $d = $this->repo->get($id);
        if (!$d) return ['ok' => false, 'error' => 'No encontrada', 'code' => 'NOT_FOUND'];
        return ['ok' => true, 'distribucion' => $d];
    }

    public function create(array $data): array {
        $nombre = trim($data['nombre'] ?? '');
        $tipo = trim($data['tipo'] ?? '');
        $plaza = trim($data['plaza'] ?? '');
        $src = trim($data['src_path'] ?? '');

        if (!$nombre) {
            return ['ok' => false, 'error' => 'Nombre es requerido', 'field' => 'nombre'];
        }
        if (!$tipo) {
            return ['ok' => false, 'error' => 'Tipo es requerido', 'field' => 'tipo'];
        }
        if (!$plaza) {
            return ['ok' => false, 'error' => 'Plaza es requerida', 'field' => 'plaza'];
        }

        // Validar que el tipo existe
        $tipoData = $this->repo->getTipo($tipo);
        if (!$tipoData) {
            return ['ok' => false, 'error' => "Perfil '$tipo' no existe", 'field' => 'tipo'];
        }

        if ($this->repo->existsByNombreTipoPlaza($nombre, $tipo, $plaza)) {
            return ['ok' => false, 'error' => "Ya existe distribución '$nombre' con tipo '$tipo' en '$plaza'", 'field' => 'nombre'];
        }

        if (strpos($src, ' ') !== false) {
            return ['ok' => false, 'error' => 'Ruta origen no debe contener espacios', 'field' => 'src'];
        }

        $normalizedSrc = str_starts_with($src, '/') ? $src : '/srv/' . ltrim($src, '/');
        if ($src && !is_dir($normalizedSrc)) {
            return ['ok' => false, 'error' => "Ruta origen no existe: $normalizedSrc", 'field' => 'src'];
        }

        // Validar que los archivos del tipo existen en la ruta origen
        $fileList = array_map('trim', explode(',', $tipoData['files']));
        foreach ($fileList as $file) {
            if ($file && !file_exists($normalizedSrc . '/' . $file)) {
                return ['ok' => false, 'error' => "Archivo no encontrado: $file en $normalizedSrc", 'field' => 'src'];
            }
        }

        $data['src_path'] = $normalizedSrc;
        $id = $this->repo->create($data);
        return ['ok' => true, 'id' => $id];
    }

    public function update(int $id, array $data): array {
        $nombre = trim($data['nombre'] ?? '');
        $tipo = trim($data['tipo'] ?? '');
        $plaza = trim($data['plaza'] ?? '');
        $src = trim($data['src_path'] ?? '');

        if (!$nombre) {
            return ['ok' => false, 'error' => 'Nombre es requerido', 'field' => 'nombre'];
        }
        if (!$tipo) {
            return ['ok' => false, 'error' => 'Tipo es requerido', 'field' => 'tipo'];
        }
        if (!$plaza) {
            return ['ok' => false, 'error' => 'Plaza es requerida', 'field' => 'plaza'];
        }

        // Validar que el tipo existe
        $tipoData = $this->repo->getTipo($tipo);
        if (!$tipoData) {
            return ['ok' => false, 'error' => "Perfil '$tipo' no existe", 'field' => 'tipo'];
        }

        if ($this->repo->existsByNombreTipoPlaza($nombre, $tipo, $plaza, $id)) {
            return ['ok' => false, 'error' => "Ya existe distribución '$nombre' con tipo '$tipo' en '$plaza'", 'field' => 'nombre'];
        }

        if (strpos($src, ' ') !== false) {
            return ['ok' => false, 'error' => 'Ruta origen no debe contener espacios', 'field' => 'src'];
        }

        $normalizedSrc = str_starts_with($src, '/') ? $src : '/srv/' . ltrim($src, '/');
        if ($src && !is_dir($normalizedSrc)) {
            return ['ok' => false, 'error' => "Ruta origen no existe: $normalizedSrc", 'field' => 'src'];
        }

        // Validar que los archivos del tipo existen en la ruta origen
        $fileList = array_map('trim', explode(',', $tipoData['files']));
        foreach ($fileList as $file) {
            if ($file && !file_exists($normalizedSrc . '/' . $file)) {
                return ['ok' => false, 'error' => "Archivo no encontrado: $file en $normalizedSrc", 'field' => 'src'];
            }
        }

        $data['src_path'] = $normalizedSrc;
        $this->repo->updateDistribucion($id, $data);
        return ['ok' => true];
    }

    public function delete(int $id): array {
        $this->repo->deleteDistribucion($id);
        return ['ok' => true];
    }

    // ─── Clientes ───

    public function getClientes(int $id): array {
        $clientes = $this->repo->getClientes($id);
        return ['ok' => true, 'clientes' => array_column($clientes, 'rbfid')];
    }

    public function getClientesPorPlaza(string $plaza, int $distId): array {
        $dist = $this->repo->get($distId);
        if (!$dist) return ['ok' => false, 'error' => 'Distribución no encontrada'];

        $clientes = $this->repo->getClientesPorPlaza($plaza);
        $enDistribucion = $this->repo->getClientes($distId);
        $enDistribucionMap = array_flip(array_column($enDistribucion, 'rbfid'));

        $result = array_map(function($c) use ($enDistribucionMap) {
            return [
                'rbfid' => $c['rbfid'],
                'emp' => $c['emp'] ?? '',
                'in_dist' => isset($enDistribucionMap[$c['rbfid']])
            ];
        }, $clientes);

        return ['ok' => true, 'clientes' => $result];
    }

    public function addCliente(int $id, string $rbfid): array {
        $dist = $this->repo->get($id);
        if (!$dist) return ['ok' => false, 'error' => 'Distribución no encontrada'];

        $conflict = $this->repo->clienteEnOtraDistribucion($dist['tipo'], $dist['plaza'], $id, $rbfid);
        if ($conflict) {
            return ['ok' => false, 'error' => "Cliente ya está en {$conflict['tipo']} {$conflict['plaza']}"];
        }

        $this->repo->addCliente($id, $rbfid);
        return ['ok' => true];
    }

    public function removeCliente(int $id, string $rbfid): array {
        $this->repo->removeCliente($id, $rbfid);
        return ['ok' => true];
    }

    // ─── Evaluar versión ───

    public function evaluarVersion(int $id): array {
        $dist = $this->repo->get($id);
        if (!$dist) return ['ok' => false, 'error' => 'No encontrada'];

        $files = array_map('trim', explode(',', $dist['files']));
        $srcPath = rtrim($dist['src_path'], '/');
        $resultados = [];

        foreach ($files as $file) {
            $fullPath = $srcPath . '/' . $file;
            if (!file_exists($fullPath)) {
                $resultados[] = ['archivo' => $file, 'estado' => 'no_existe_en_origen'];
                continue;
            }

            $currentHash = hash('xxh3', file_get_contents($fullPath));
            $currentSize = filesize($fullPath);
            $ultima = $this->repo->getUltimaVersion($id, $file);

            if (!$ultima || $ultima['xxh3'] !== $currentHash) {
                $newVersion = ($ultima['version'] ?? 0) + 1;
                $this->repo->createVersion($id, $file, $currentHash, $currentSize, $newVersion);
                $resultados[] = ['archivo' => $file, 'estado' => 'nueva_version', 'version' => $newVersion, 'hash' => $currentHash];
            } else {
                $resultados[] = ['archivo' => $file, 'estado' => 'sin_cambios', 'version' => $ultima['version'], 'hash' => $currentHash];
            }
        }

        return ['ok' => true, 'evaluacion' => $resultados];
    }

    // ─── Copiar (ejecutar distribución) ───

    public function copiar(int $id): array {
        $dist = $this->repo->get($id);
        if (!$dist) return ['ok' => false, 'error' => 'No encontrada'];

        $distLabel = strtoupper($dist['nombre'] . '/' . $dist['tipo']);
        $this->logger->startExecution("d$id", "[DISTRIBUCION] $distLabel");

        // Evaluar versión
        $eval = $this->evaluarVersion($id);
        if (!$eval['ok']) {
            $this->logger->finish('error');
            return $eval;
        }

        // Determinar versión actual (mayor)
        $versiones = $this->repo->getVersiones($id);
        $versionActual = $versiones[0]['version'] ?? 1;

        // Determinar pendientes
        $hayVersionNueva = false;
        foreach ($eval['evaluacion'] as $ev) {
            if ($ev['estado'] === 'nueva_version') $hayVersionNueva = true;
        }

        if ($hayVersionNueva) {
            $clientes = $this->repo->getClientes($id);
            $pendientes = array_column($clientes, 'rbfid');
        } else {
            $erroresPendientes = $this->repo->getErroresNoResueltosUltimaVersion($id);
            $pendientes = array_column($erroresPendientes, 'cliente_rbfid');
        }

        if (empty($pendientes)) {
            $this->logger->finish('success');
            return [
                'ok' => true,
                'message' => 'Sin pendientes',
                'version' => $versionActual,
                'total' => 0,
                'exitosos' => 0,
                'fallidos' => 0
            ];
        }

        // Crear ejecución
        $ejecId = $this->repo->createEjecucion($id, null, $versionActual, count($pendientes));

        // Copiar archivos
        $files = array_map('trim', explode(',', $dist['files']));
        $srcPath = rtrim($dist['src_path'], '/');
        $dstTemplate = rtrim($dist['dst_template'], '/');

        // Validar que src_path existe
        if (!is_dir($srcPath)) {
            $this->repo->addError($ejecId, 'N/A', $srcPath, -99, null, null, "Ruta origen no existe: $srcPath");
            $this->logger->finish('error');
            return [
                'ok' => false,
                'error' => "Ruta origen no existe: $srcPath",
                'version' => $versionActual,
                'total' => count($pendientes),
                'exitosos' => 0,
                'fallidos' => count($pendientes),
                'errores' => [['rbfid' => 'N/A', 'error' => "Ruta origen no existe: $srcPath"]]
            ];
        }

        $exitosos = 0;
        $fallidos = 0;
        $pesoTotalOrigen = 0;
        $pesoTotalCopiados = 0;
        $errores = [];

        foreach ($pendientes as $rbfid) {
            // Obtener datos reales del cliente (NO de la distribución)
            $clientData = $this->repo->getClientData($rbfid);
            if (!$clientData) {
                $this->repo->addError($ejecId, $rbfid, '', -98, null, null, "Cliente $rbfid no encontrado en BD");
                $errores[] = ['rbfid' => $rbfid, 'error' => 'Cliente no encontrado en BD'];
                $fallidos++;
                continue;
            }

            // Resolver placeholders con datos DEL CLIENTE
            $dstDir = str_replace(
                ['{rbfid}', '{plaza}', '{emp}', '{tipo}', '${rbfid}'],
                [$rbfid, $clientData['plaza'] ?? '', $clientData['emp'] ?? '', $dist['tipo'], $rbfid],
                $dstTemplate
            );

            // Crear directorio destino si no existe
            if (!is_dir($dstDir)) {
                if (!@mkdir($dstDir, 0755, true)) {
                    $this->repo->addError($ejecId, $rbfid, $dstDir, -1, null, null, "No se pudo crear directorio: $dstDir");
                    $errores[] = ['rbfid' => $rbfid, 'error' => "No se pudo crear directorio: $dstDir"];
                    $fallidos++;
                    continue;
                }
            }

            $clienteOk = true;
            foreach ($files as $file) {
                $src = $srcPath . '/' . $file;
                $dst = $dstDir . '/' . $file;
                $dstTmp = $dst . '.tmp';
                $dstOld = $dst . '.old';

                // Validar que archivo existe en origen
                if (!file_exists($src)) {
                    $this->repo->addError($ejecId, $rbfid, $src, -1, null, null, "Archivo no existe en origen: $file");
                    $errores[] = ['rbfid' => $rbfid, 'error' => "Archivo no existe en origen: $file"];
                    $clienteOk = false;
                    continue;
                }

                $srcSize = filesize($src);
                $srcHash = hash_file('xxh3', $src);
                $pesoTotalOrigen += $srcSize;

                // 1. Copiar a .tmp
                $copied = @copy($src, $dstTmp);
                if (!$copied) {
                    $this->repo->addError($ejecId, $rbfid, $dst, -1, $srcHash, null, 'Error copiando a .tmp');
                    $errores[] = ['rbfid' => $rbfid, 'error' => "Error copiando $file a .tmp"];
                    $clienteOk = false;
                    continue;
                }

                // 2. Renombrar existente a .old
                $hadExisting = file_exists($dst);
                if ($hadExisting) {
                    @rename($dst, $dstOld);
                }

                // 3. Renombrar .tmp a definitivo
                @rename($dstTmp, $dst);

                // 4. Verificar hash
                if (file_exists($dst)) {
                    $dstHash = hash_file('xxh3', $dst);
                    if ($srcHash === $dstHash) {
                        $pesoTotalCopiados += $srcSize;
                        @unlink($dstOld); // 5. Limpiar .old
                    } else {
                        // Rollback: mv .old de vuelta
                        if ($hadExisting) @rename($dstOld, $dst);
                        $this->repo->addError($ejecId, $rbfid, $dst, -2, $srcHash, $dstHash, 'Hash no coincide después de copiar');
                        $errores[] = ['rbfid' => $rbfid, 'error' => "Hash no coincide para $file"];
                        $clienteOk = false;
                    }
                } else {
                    if ($hadExisting) @rename($dstOld, $dst);
                    $this->repo->addError($ejecId, $rbfid, $dst, -3, $srcHash, null, 'Archivo destino no existe después de rename');
                    $errores[] = ['rbfid' => $rbfid, 'error' => "Archivo destino no existe para $file"];
                    $clienteOk = false;
                }
            }

            if ($clienteOk) $exitosos++;
            else $fallidos++;
        }

        $this->repo->finishEjecucion($ejecId, $exitosos, $fallidos, $pesoTotalOrigen, $pesoTotalCopiados);
        $this->logger->finish($fallidos > 0 ? 'error' : 'success');

        return [
            'ok' => true,
            'ejecucion_id' => $ejecId,
            'version' => $versionActual,
            'total' => count($pendientes),
            'exitosos' => $exitosos,
            'fallidos' => $fallidos,
            'peso_origen_mb' => round($pesoTotalOrigen / 1024 / 1024, 2),
            'peso_copiados_mb' => round($pesoTotalCopiados / 1024 / 1024, 2),
            'errores' => $errores
        ];
    }

    // ─── Ejecutar como job ───

    public function copiarComoJob(int $id): array {
        $jobId = $this->worker->enqueue('distribuir', ['distribucion_id' => $id], 'agente');
        return ['ok' => true, 'job_id' => $jobId, 'status' => 'queued'];
    }

    // ─── Versiones y errores ───

    public function getVersiones(int $id): array {
        $versiones = $this->repo->getVersiones($id);
        return ['ok' => true, 'versiones' => $versiones];
    }

    public function getErrores(int $id): array {
        $errores = $this->repo->getErrores($id);
        return ['ok' => true, 'errores' => $errores];
    }

    public function resolverError(int $errorId): array {
        $this->repo->resolverError($errorId);
        return ['ok' => true];
    }

    public function getEjecuciones(int $id): array {
        $ejecuciones = $this->repo->getEjecuciones($id);
        return ['ok' => true, 'ejecuciones' => $ejecuciones];
    }

    // ─── Logs ───

    public function getLogs(): array {
        $logs = $this->repo->getLogs();
        return ['ok' => true, 'logs' => $logs];
    }

    // ─── Tipos (perfiles) ───

    public function getTipos(): array {
        $tipos = $this->repo->getTipos();
        return ['ok' => true, 'tipos' => $tipos];
    }

    public function createTipo(array $data): array {
        $tipo = trim($data['tipo'] ?? '');
        $files = trim($data['files'] ?? '');
        $dst = trim($data['dst_template'] ?? '');

        if (!$tipo) {
            return ['ok' => false, 'error' => 'Tipo es requerido', 'field' => 'tipo'];
        }
        if (!$files) {
            return ['ok' => false, 'error' => 'Archivos es requerido', 'field' => 'files'];
        }
        if (!$dst) {
            return ['ok' => false, 'error' => 'Destino es requerido', 'field' => 'dst_template'];
        }
        if (strpos($files, ' ') !== false) {
            return ['ok' => false, 'error' => 'Archivos no debe contener espacios', 'field' => 'files'];
        }

        $dst = rtrim($dst, '/') . '/';

        $existing = $this->repo->getTipo($tipo);
        if ($existing) {
            return ['ok' => false, 'error' => "Perfil '$tipo' ya existe", 'field' => 'tipo'];
        }

        $this->repo->createTipo($tipo, $files, $dst);
        return ['ok' => true, 'tipo' => $tipo];
    }

    public function updateTipo(string $tipo, array $data): array {
        $files = trim($data['files'] ?? '');
        $dst = trim($data['dst_template'] ?? '');

        if (!$tipo) {
            return ['ok' => false, 'error' => 'Tipo es requerido', 'field' => 'tipo'];
        }
        if (!$files) {
            return ['ok' => false, 'error' => 'Archivos es requerido', 'field' => 'files'];
        }
        if (!$dst) {
            return ['ok' => false, 'error' => 'Destino es requerido', 'field' => 'dst_template'];
        }
        if (strpos($files, ' ') !== false) {
            return ['ok' => false, 'error' => 'Archivos no debe contener espacios', 'field' => 'files'];
        }

        $existing = $this->repo->getTipo($tipo);
        if (!$existing) {
            return ['ok' => false, 'error' => "Perfil '$tipo' no existe", 'field' => 'tipo'];
        }

        $dst = rtrim($dst, '/') . '/';
        $this->repo->updateTipo($tipo, $files, $dst);
        return ['ok' => true];
    }

    public function deleteTipo(string $tipo): array {
        if (!$tipo) {
            return ['ok' => false, 'error' => 'Tipo es requerido', 'field' => 'tipo'];
        }

        $existing = $this->repo->getTipo($tipo);
        if (!$existing) {
            return ['ok' => false, 'error' => "Perfil '$tipo' no existe", 'code' => 'NOT_FOUND'];
        }

        $this->repo->deleteTipo($tipo);
        return ['ok' => true];
    }

    // ─── Importar distribuciones ───

    public function importar(string $texto, bool $truncar = false): array {
        $lineas = array_filter(array_map('trim', explode("\n", $texto)));
        $exitosas = 0;
        $errores = 0;
        $saltadas = 0;
        $detalles = [];

        // Truncar si se solicita
        $eliminadas = 0;
        if ($truncar) {
            $eliminadas = $this->db->execute("DELETE FROM distribucion");
            $detalles[] = "Truncadas $eliminadas distribuciones existentes";
        }

        foreach ($lineas as $i => $linea) {
            $num = $i + 1;

            // Saltar comentarios
            if (str_starts_with($linea, '#')) {
                $saltadas++;
                continue;
            }

            $cols = array_map('trim', explode(',', $linea));

            if (count($cols) !== 4) {
                $detalles[] = "Línea $num: formato inválido (esperado: tipo,nombre,plaza,ruta)";
                $errores++;
                continue;
            }

            [$tipo, $nombre, $plaza, $ruta] = $cols;

            // Validar tipo existe
            $tipoData = $this->repo->getTipo($tipo);
            if (!$tipoData) {
                $detalles[] = "Línea $num ($nombre): perfil '$tipo' no existe";
                $errores++;
                continue;
            }

            // Validar nombre no vacío
            if (!$nombre) {
                $detalles[] = "Línea $num: nombre vacío";
                $errores++;
                continue;
            }

            // Validar plaza existe
            $clientesEnPlaza = $this->repo->getClientesPorPlaza($plaza);
            if (empty($clientesEnPlaza)) {
                $detalles[] = "Línea $num ($nombre): plaza '$plaza' no existe";
                $errores++;
                continue;
            }

            // Validar ruta sin espacios
            if (strpos($ruta, ' ') !== false) {
                $detalles[] = "Línea $num ($nombre): ruta contiene espacios";
                $errores++;
                continue;
            }

            // Normalizar ruta
            $ruta = rtrim($ruta, '/') . '/';
            $ruta = str_starts_with($ruta, '/') ? $ruta : '/srv/' . ltrim($ruta, '/');

            // Validar ruta existe en disco
            if (!is_dir($ruta)) {
                $detalles[] = "Línea $num ($nombre): ruta no existe: $ruta";
                $errores++;
                continue;
            }

            // Validar archivos del tipo existen en ruta
            $fileList = array_map('trim', explode(',', $tipoData['files']));
            $archivosFaltantes = [];
            foreach ($fileList as $file) {
                if ($file && !file_exists($ruta . $file)) {
                    $archivosFaltantes[] = $file;
                }
            }
            if (!empty($archivosFaltantes)) {
                $detalles[] = "Línea $num ($nombre): archivos no encontrados: " . implode(', ', $archivosFaltantes);
                $errores++;
                continue;
            }

            // Validar duplicada
            if ($this->repo->existsByNombreTipoPlaza($nombre, $tipo, $plaza)) {
                $detalles[] = "Línea $num ($nombre): ya existe";
                $errores++;
                continue;
            }

            // Guardar
            $this->repo->create([
                'nombre' => $nombre,
                'tipo' => $tipo,
                'plaza' => $plaza,
                'src_path' => $ruta
            ]);
            $exitosas++;
            $detalles[] = "Línea $num ($nombre): importada";
        }

        return [
            'ok' => true,
            'eliminadas' => $eliminadas,
            'exitosas' => $exitosas,
            'errores' => $errores,
            'saltadas' => $saltadas,
            'detalles' => $detalles
        ];
    }

    // ─── Exportar distribuciones ───

    public function exportar(): array {
        $rows = $this->repo->listAll();
        $lineas = [];
        foreach ($rows as $d) {
            $lineas[] = $d['tipo'] . ',' . $d['nombre'] . ',' . $d['plaza'] . ',' . rtrim($d['src_path'], '/');
        }
        return ['ok' => true, 'texto' => implode("\n", $lineas)];
    }

    // ─── Escanear /srv/precios/ y generar import ───

    public function scanPrecios(bool $incluidas = true): array {
        $preciosDir = '/srv/precios';
        if (!is_dir($preciosDir)) {
            return ['ok' => false, 'error' => '/srv/precios/ no existe'];
        }

        // Perfiles existentes
        $perfiles = [];
        foreach ($this->repo->getTipos() as $t) {
            $files = array_map('trim', explode(',', $t['files']));
            foreach ($files as $f) {
                $perfiles[strtoupper($f)] = $t['tipo'];
            }
        }

        // Mapeo directorio → plaza desde distribuciones existentes
        $dirPlaza = [];
        foreach ($this->repo->listAll() as $d) {
            $dir = rtrim($d['src_path'], '/');
            $dir = preg_replace('#/(MASTER/)?(ENVIAR|LEALTAD)$#', '', $dir);
            $basename = basename($dir);
            if (!isset($dirPlaza[$basename])) {
                $dirPlaza[$basename] = $d['plaza'];
            }
        }

        // Escanear directorios
        $dirs = array_filter(scandir($preciosDir), fn($d) => $d[0] !== '.' && is_dir("$preciosDir/$d"));
        sort($dirs);

        $fileLocations = []; // filename → [dir => ruta]

        foreach ($dirs as $dir) {
            $path = "$preciosDir/$dir";
            $plaza = $dirPlaza[$dir] ?? null;

            // Archivos en root
            $rootRuta = ($dir === 'TAPACHULA') ? "$path/MASTER" : $path;
            if (is_dir($rootRuta)) {
                foreach (scandir($rootRuta) as $f) {
                    if ($f[0] === '.') continue;
                    if (is_file("$rootRuta/$f") && preg_match('/\.(DBF|CDX|dbf|cdx)$/i', $f)) {
                        $key = strtoupper($f);
                        if (!isset($fileLocations[$key])) $fileLocations[$key] = [];
                        $fileLocations[$key][$dir] = [
                            'plaza' => $plaza,
                            'ruta' => ($dir === 'TAPACHULA') ? "$preciosDir/TAPACHULA/MASTER/" : "$path/"
                        ];
                    }
                }
            }

            // Subdirectorios (LEALTAD, VENTAS_ESP, ARCERO subdir en TAPACHULA)
            $subdirsToScan = [];
            foreach (scandir($path) as $f) {
                if ($f[0] === '.') continue;
                if (is_dir("$path/$f") && !in_array($f, ['ENVIAR', '.'])) {
                    $subdirsToScan[] = ["$path/$f", "$path/$f/"];
                }
            }
            // TAPACHULA: también escanear MASTER/ subdirectorios
            if ($dir === 'TAPACHULA' && is_dir("$path/MASTER")) {
                foreach (scandir("$path/MASTER") as $f) {
                    if ($f[0] === '.') continue;
                    if (is_dir("$path/MASTER/$f") && !in_array($f, ['ENVIAR', '.'])) {
                        $subdirsToScan[] = ["$path/MASTER/$f", "$path/MASTER/$f/"];
                    }
                }
            }

            foreach ($subdirsToScan as [$subPath, $subRuta]) {
                foreach (scandir($subPath) as $sf) {
                    if ($sf[0] === '.') continue;
                    if (is_file("$subPath/$sf") && preg_match('/\.(DBF|CDX|dbf|cdx)$/i', $sf)) {
                        $key = strtoupper($sf);
                        if (!isset($fileLocations[$key])) $fileLocations[$key] = [];
                        $fileLocations[$key][$dir] = [
                            'plaza' => $plaza,
                            'ruta' => $subRuta
                        ];
                    }
                }
            }
        }

        // Generar texto de import por cada perfil/archivo
        // Marcar como comentario las ya importadas
        $existing = [];
        foreach ($this->repo->listAll() as $d) {
            $key = $d['tipo'] . '|' . $d['plaza'] . '|' . rtrim($d['src_path'], '/') . '/';
            $existing[$key] = true;
        }

        $output = [];
        $yaImportadas = [];
        foreach ($fileLocations as $filename => $locations) {
            $tipo = $perfiles[$filename] ?? null;
            if (!$tipo) continue; // Saltar archivos sin perfil

            foreach ($locations as $dir => $info) {
                if (!$info['plaza']) continue;
                $ruta = rtrim($info['ruta'], '/') . '/';
                $line = "$tipo,$dir,{$info['plaza']},$ruta";
                $key = $tipo . '|' . $info['plaza'] . '|' . $ruta;

                if (isset($existing[$key])) {
                    $yaImportadas[] = "# $line";
                } else {
                    $output[] = $line;
                }
            }
        }

        sort($output);
        sort($yaImportadas);

        $texto = '';
        if ($incluidas && !empty($yaImportadas)) {
            $texto .= "# Ya importadas (" . count($yaImportadas) . "):\n";
            $texto .= implode("\n", $yaImportadas) . "\n";
        }
        if ($incluidas && !empty($yaImportadas) && !empty($output)) {
            $texto .= "\n";
        }
        if (!empty($output)) {
            if ($incluidas) $texto .= "# Pendientes (" . count($output) . "):\n";
            $texto .= implode("\n", $output);
        }

        return ['ok' => true, 'texto' => $texto];
    }
}
