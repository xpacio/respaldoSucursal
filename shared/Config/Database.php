<?php

namespace App\Config;

use PDO;

/**
 * Database - Wrapper PDO con transacciones
 */
class Database {
    private static ?PDO $conn = null;
    private array $config;

    public function __construct(array $config) {
        $this->config = $config;
    }

    private function connect(): PDO {
        if (self::$conn === null) {
            $dsn = sprintf(
                "pgsql:host=%s;port=%d;dbname=%s",
                $this->config['host'],
                $this->config['port'],
                $this->config['dbname']
            );
            self::$conn = new PDO($dsn, $this->config['user'], $this->config['password']);
            self::$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
        return self::$conn;
    }

    public function fetchOne(string $sql, array $params = []): ?array {
        $stmt = $this->connect()->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function fetchAll(string $sql, array $params = []): array {
        $stmt = $this->connect()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function execute(string $sql, array $params = []): int {
        $stmt = $this->connect()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function insert(string $sql, array $params = []): string {
        $stmt = $this->connect()->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            return (string) reset($result);
        }
        return $this->connect()->lastInsertId();
    }
}
