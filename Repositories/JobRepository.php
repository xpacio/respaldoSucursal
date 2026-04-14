<?php
require_once __DIR__ . '/AbstractRepository.php';

/**
 * JobRepository - Repository for jobs table
 */
class JobRepository extends AbstractRepository {
    protected string $tableName = 'jobs';
    
    public function __construct(Database $db) {
        parent::__construct($db);
    }
    
    public function createJob(string $action, array $params, string $createdBy = 'admin'): string {
        $jobId = 'job_' . bin2hex(random_bytes(8));
        $this->db->execute(
            "INSERT INTO jobs (id, action, params, status, created_by) VALUES (:id, :action, :params, 'queued', :created_by)",
            [':id' => $jobId, ':action' => $action, ':params' => json_encode($params), ':created_by' => $createdBy]
        );
        return $jobId;
    }
    
    public function getJob(string $jobId): ?array {
        return $this->db->fetchOne("SELECT * FROM jobs WHERE id = :id", [':id' => $jobId]);
    }
    
    public function markRunning(string $jobId, int $total): void {
        $this->db->execute(
            "UPDATE jobs SET status = 'running', progress_total = :total, started_at = NOW() WHERE id = :id",
            [':total' => $total, ':id' => $jobId]
        );
    }
    
    public function updateProgress(string $jobId, int $current, string $status = 'running'): void {
        $this->db->execute(
            "UPDATE jobs SET progress_current = :current, status = :status WHERE id = :id",
            [':current' => $current, ':status' => $status, ':id' => $jobId]
        );
    }
    
    public function markDone(string $jobId, array $results): void {
        $this->db->execute(
            "UPDATE jobs SET status = 'done', results = :results, finished_at = NOW() WHERE id = :id",
            [':results' => json_encode($results), ':id' => $jobId]
        );
    }
    
    public function markFailed(string $jobId, string $error): void {
        $this->db->execute(
            "UPDATE jobs SET status = 'failed', error = :error, finished_at = NOW() WHERE id = :id",
            [':error' => $error, ':id' => $jobId]
        );
    }
    
    public function cancelJob(string $jobId): bool {
        return $this->db->execute(
            "UPDATE jobs SET status = 'cancelled', finished_at = NOW() WHERE id = :id AND status IN ('queued', 'running')",
            [':id' => $jobId]
        ) > 0;
    }
    
    public function getJobsByStatus(string $status, int $limit = 50): array {
        return $this->db->fetchAll(
            "SELECT id, action, status, progress_current, progress_total, created_at, finished_at FROM jobs WHERE status = :status ORDER BY created_at DESC LIMIT :limit",
            [':status' => $status, ':limit' => $limit]
        );
    }
    
    public function getJobStatusResponse(string $jobId): array {
        $job = $this->getJob($jobId);
        if (!$job) {
            return ['ok' => false, 'error' => 'Job no encontrado', 'code' => 'JOB_NOT_FOUND'];
        }
        
        $response = [
            'ok' => true,
            'job_id' => $job['id'],
            'action' => $job['action'],
            'status' => $job['status'],
            'progress_current' => (int)$job['progress_current'],
            'progress_total' => (int)$job['progress_total'],
            'created_at' => $job['created_at'],
        ];
        
        if ($job['results']) {
            $response['results'] = json_decode($job['results'], true);
        }
        if ($job['error']) {
            $response['error'] = $job['error'];
        }
        if ($job['started_at']) {
            $response['started_at'] = $job['started_at'];
        }
        if ($job['finished_at']) {
            $response['finished_at'] = $job['finished_at'];
        }
        
        return $response;
    }
}
