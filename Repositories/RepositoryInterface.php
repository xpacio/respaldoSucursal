<?php
/**
 * RepositoryInterface - Base interface for all repositories
 */
interface RepositoryInterface {
    public function findOneBy(array $criteria): ?array;
    public function save(array $data): string;
    public function update(int $id, array $data): bool;
    public function delete(int $id): bool;
}
