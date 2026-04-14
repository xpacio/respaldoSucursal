<?php
require_once __DIR__ . '/AbstractRepository.php';

class DistribucionRepository extends AbstractRepository {
    protected string $tableName = 'distribucion';
    
    public function __construct(Database $db) {
        parent::__construct($db);
    }
    
    // ─── Distribuciones ───
    
    public function listAll(array $filters = []): array {
        $conditions = [];
        $params = [];
        $pi = 0;

        $nombre = $filters['nombre'] ?? null;
        $tipo = $filters['tipo'] ?? null;
        $plaza = $filters['plaza'] ?? null;
        $origen = $filters['origen'] ?? null;

        if ($nombre) {
            $conditions[] = "d.nombre ILIKE :f_nombre";
            $params[':f_nombre'] = "%$nombre%";
        }
        if ($tipo) {
            $pi++;
            $conditions[] = "d.tipo ILIKE :f_tipo";
            $params[':f_tipo'] = "%$tipo%";
        }
        if ($plaza) {
            $pi++;
            $conditions[] = "LOWER(d.plaza) = LOWER(:f_plaza)";
            $params[':f_plaza'] = $plaza;
        }
        if ($origen) {
            $pi++;
            $conditions[] = "d.src_path ILIKE :f_origen";
            $params[':f_origen'] = "%$origen%";
        }

        $where = count($conditions) > 0 ? "WHERE " . implode(" AND ", $conditions) : "";

        $limit = '';
        if (!empty($filters['limit'])) {
            $limit = ' LIMIT :f_limit';
            $params[':f_limit'] = intval($filters['limit']);
        }

        return $this->db->fetchAll("
            SELECT d.*, dt.files, dt.dst_template,
                (SELECT COUNT(*) FROM distribucion_clientes WHERE distribucion_id = d.id) as total_clientes
            FROM distribucion d
            JOIN distribucion_tipos dt ON dt.tipo = d.tipo
            $where ORDER BY d.nombre, d.tipo, d.plaza
            $limit
        ", $params);
    }

    public function options(): array {
        $tipos = $this->db->fetchAll("SELECT LOWER(tipo) as tipo FROM distribucion_tipos ORDER BY LOWER(tipo)");
        $plazas = $this->db->fetchAll("SELECT DISTINCT LOWER(plaza) as plaza FROM distribucion WHERE plaza IS NOT NULL AND plaza != '' ORDER BY LOWER(plaza)");
        return [
            'tipos' => array_column($tipos, 'tipo'),
            'plazas' => array_column($plazas, 'plaza')
        ];
    }
    
    public function get(int $id): ?array {
        return $this->db->fetchOne(
            "SELECT d.*, dt.files, dt.dst_template FROM distribucion d JOIN distribucion_tipos dt ON dt.tipo = d.tipo WHERE d.id = :id",
            [':id' => $id]
        );
    }
    
    public function create(array $data): int {
        return $this->db->insert(
            "INSERT INTO distribucion (nombre, tipo, plaza, src_path) VALUES (:nombre, :tipo, :plaza, :src_path) RETURNING id",
            [':nombre' => $data['nombre'], ':tipo' => $data['tipo'], ':plaza' => $data['plaza'], ':src_path' => $data['src_path']]
        );
    }
    
    public function updateDistribucion(int $id, array $data): bool {
        return $this->db->execute(
            "UPDATE distribucion SET nombre=:nombre, tipo=:tipo, plaza=:plaza, src_path=:src_path, activa=:activa WHERE id=:id",
            [':nombre' => $data['nombre'], ':tipo' => $data['tipo'], ':plaza' => $data['plaza'], ':src_path' => $data['src_path'], ':activa' => $data['activa'] ?? true, ':id' => $id]
        ) > 0;
    }
    
    public function deleteDistribucion(int $id): bool {
        return $this->db->execute("DELETE FROM distribucion WHERE id = :id", [':id' => $id]) > 0;
    }
    
    public function existsByNombreTipoPlaza(string $nombre, string $tipo, string $plaza, ?int $excludeId = null): bool {
        $sql = "SELECT 1 FROM distribucion WHERE LOWER(nombre) = LOWER(:nombre) AND LOWER(tipo) = LOWER(:tipo) AND LOWER(plaza) = LOWER(:plaza)";
        $params = [':nombre' => $nombre, ':tipo' => $tipo, ':plaza' => $plaza];
        if ($excludeId) {
            $sql .= " AND id != :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }
        $row = $this->db->fetchOne($sql, $params);
        return $row !== null;
    }
    
    // ─── Clientes ───
    
    public function getClientes(int $distId): array {
        return $this->db->fetchAll(
            "SELECT rbfid FROM distribucion_clientes WHERE distribucion_id = :dist_id ORDER BY rbfid",
            [':dist_id' => $distId]
        );
    }

    public function getClientData(string $rbfid): ?array {
        return $this->db->fetchOne(
            "SELECT rbfid, emp, plaza FROM clients WHERE rbfid = :rbfid",
            [':rbfid' => $rbfid]
        );
    }
    
    public function getClientesPorPlaza(string $plaza): array {
        return $this->db->fetchAll(
            "SELECT rbfid, emp FROM clients WHERE LOWER(plaza) = LOWER(:plaza) ORDER BY rbfid",
            [':plaza' => $plaza]
        );
    }
    
    public function addCliente(int $distId, string $rbfid): bool {
        return $this->db->execute(
            "INSERT INTO distribucion_clientes (distribucion_id, rbfid) VALUES (:dist_id, :rbfid) ON CONFLICT DO NOTHING",
            [':dist_id' => $distId, ':rbfid' => $rbfid]
        ) > 0;
    }
    
    public function removeCliente(int $distId, string $rbfid): bool {
        return $this->db->execute(
            "DELETE FROM distribucion_clientes WHERE distribucion_id = :dist_id AND rbfid = :rbfid",
            [':dist_id' => $distId, ':rbfid' => $rbfid]
        ) > 0;
    }
    
    public function clienteEnOtraDistribucion(string $tipo, string $plaza, int $excludeId, string $rbfid): ?array {
        return $this->db->fetchOne(
            "SELECT d.id, d.tipo, d.plaza FROM distribucion_clientes dc JOIN distribucion d ON d.id = dc.distribucion_id WHERE d.tipo = :tipo AND d.plaza = :plaza AND d.id != :exclude_id AND dc.rbfid = :rbfid",
            [':tipo' => $tipo, ':plaza' => $plaza, ':exclude_id' => $excludeId, ':rbfid' => $rbfid]
        );
    }
    
    // ─── Versiones ───
    
    public function getUltimaVersion(int $distId, string $archivo): ?array {
        return $this->db->fetchOne(
            "SELECT * FROM distribucion_versiones WHERE distribucion_id = :dist_id AND nombre_archivo = :archivo ORDER BY version DESC LIMIT 1",
            [':dist_id' => $distId, ':archivo' => $archivo]
        );
    }
    
    public function getVersiones(int $distId): array {
        return $this->db->fetchAll(
            "SELECT * FROM distribucion_versiones WHERE distribucion_id = :dist_id ORDER BY version DESC, nombre_archivo",
            [':dist_id' => $distId]
        );
    }
    
    public function createVersion(int $distId, string $archivo, string $xxh3, int $peso, int $version): int {
        return $this->db->insert(
            "INSERT INTO distribucion_versiones (distribucion_id, nombre_archivo, xxh3, peso, version) VALUES (:dist_id, :archivo, :xxh3, :peso, :version) RETURNING id",
            [':dist_id' => $distId, ':archivo' => $archivo, ':xxh3' => $xxh3, ':peso' => $peso, ':version' => $version]
        );
    }
    
    // ─── Ejecuciones ───
    
    public function createEjecucion(int $distId, ?string $jobId, int $versionActual, int $totalClientes): int {
        return $this->db->insert(
            "INSERT INTO distribucion_ejecuciones (distribucion_id, job_id, version_actual, total_clientes) VALUES (:dist_id, :job_id, :version, :total) RETURNING id",
            [':dist_id' => $distId, ':job_id' => $jobId, ':version' => $versionActual, ':total' => $totalClientes]
        );
    }
    
    public function finishEjecucion(int $ejecId, int $exitosos, int $fallidos, int $pesoOrigen, int $pesoCopiados): void {
        $this->db->execute(
            "UPDATE distribucion_ejecuciones SET exitosos=:exitosos, fallidos=:fallidos, peso_total_origen=:peso_origen, peso_total_copiados=:peso_copiados, finished_at=NOW() WHERE id=:ejec_id",
            [':exitosos' => $exitosos, ':fallidos' => $fallidos, ':peso_origen' => $pesoOrigen, ':peso_copiados' => $pesoCopiados, ':ejec_id' => $ejecId]
        );
    }
    
    public function getEjecuciones(int $distId, int $limit = 20): array {
        return $this->db->fetchAll(
            "SELECT * FROM distribucion_ejecuciones WHERE distribucion_id = :dist_id ORDER BY started_at DESC LIMIT :lim",
            [':dist_id' => $distId, ':lim' => $limit]
        );
    }
    
    // ─── Errores ───
    
    public function addError(int $ejecId, string $rbfid, string $dstPath, int $exitCode, ?string $srcHash, ?string $dstHash, ?string $mensaje): void {
        $this->db->execute(
            "INSERT INTO distribucion_errores (ejecucion_id, cliente_rbfid, dst_path, exit_code, src_hash, dst_hash, mensaje) VALUES (:ejec_id, :rbfid, :dst_path, :exit_code, :src_hash, :dst_hash, :mensaje)",
            [':ejec_id' => $ejecId, ':rbfid' => $rbfid, ':dst_path' => $dstPath, ':exit_code' => $exitCode, ':src_hash' => $srcHash, ':dst_hash' => $dstHash, ':mensaje' => $mensaje]
        );
    }
    
    public function getErrores(int $distId, bool $soloNoResueltos = true): array {
        $where = $soloNoResueltos ? "AND e.resuelto = false" : "";
        return $this->db->fetchAll(
            "SELECT e.* FROM distribucion_errores e JOIN distribucion_ejecuciones ej ON ej.id = e.ejecucion_id WHERE ej.distribucion_id = :dist_id $where ORDER BY e.created_at DESC",
            [':dist_id' => $distId]
        );
    }
    
    public function resolverError(int $errorId): bool {
        return $this->db->execute("UPDATE distribucion_errores SET resuelto = true WHERE id = :id", [':id' => $errorId]) > 0;
    }
    
    public function getErroresNoResueltosUltimaVersion(int $distId): array {
        return $this->db->fetchAll(
            "SELECT e.cliente_rbfid FROM distribucion_errores e JOIN distribucion_ejecuciones ej ON ej.id = e.ejecucion_id WHERE ej.distribucion_id = :dist_id AND e.resuelto = false AND ej.version_actual = (SELECT MAX(version_actual) FROM distribucion_ejecuciones WHERE distribucion_id = :dist_id2) GROUP BY e.cliente_rbfid",
            [':dist_id' => $distId, ':dist_id2' => $distId]
        );
    }

    // ─── Logs ───

    public function getLogs(): array {
        return $this->db->fetchAll(
            "SELECT e.id, e.rbfid, e.action, e.status, e.source_type, e.started_at, e.finished_at,
                    (SELECT COUNT(*) FROM execution_steps WHERE execution_id = e.id) as steps_count
             FROM executions e
             ORDER BY e.started_at DESC
             LIMIT 20"
        );
    }

    // ─── Tipos (perfiles) ───

    public function getTipos(): array {
        return $this->db->fetchAll("SELECT * FROM distribucion_tipos ORDER BY tipo");
    }

    public function getTipo(string $tipo): ?array {
        return $this->db->fetchOne("SELECT * FROM distribucion_tipos WHERE tipo = :tipo", [':tipo' => $tipo]);
    }

    public function createTipo(string $tipo, string $files, string $dstTemplate): bool {
        return $this->db->execute(
            "INSERT INTO distribucion_tipos (tipo, files, dst_template) VALUES (:tipo, :files, :dst)",
            [':tipo' => $tipo, ':files' => $files, ':dst' => $dstTemplate]
        ) > 0;
    }

    public function updateTipo(string $tipo, string $files, string $dstTemplate): bool {
        return $this->db->execute(
            "UPDATE distribucion_tipos SET files = :files, dst_template = :dst WHERE tipo = :tipo",
            [':tipo' => $tipo, ':files' => $files, ':dst' => $dstTemplate]
        ) > 0;
    }

    public function deleteTipo(string $tipo): bool {
        return $this->db->execute("DELETE FROM distribucion_tipos WHERE tipo = :tipo", [':tipo' => $tipo]) > 0;
    }
}
