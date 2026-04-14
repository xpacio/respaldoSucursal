<?php
/**
 * RepositoryFactory - Factory for creating repositories
 */
class RepositoryFactory {
    private static array $repositories = [];
    
    /**
     * Get repository instance
     */
    public static function getRepository(string $repositoryClass, Database $db): object {
        if (!isset(self::$repositories[$repositoryClass])) {
            self::$repositories[$repositoryClass] = new $repositoryClass($db);
        }
        return self::$repositories[$repositoryClass];
    }
    
    /**
     * Get ClientRepository
     */
    public static function getClientRepository(Database $db): ClientRepository {
        return self::getRepository(ClientRepository::class, $db);
    }
    
    /**
     * Get OverlayRepository
     */
    public static function getOverlayRepository(Database $db): OverlayRepository {
        return self::getRepository(OverlayRepository::class, $db);
    }
    
    /**
     * Get PlantillaRepository
     */
    public static function getPlantillaRepository(Database $db): PlantillaRepository {
        return self::getRepository(PlantillaRepository::class, $db);
    }
    
    /**
     * Get JobRepository
     */
    public static function getJobRepository(Database $db): JobRepository {
        return self::getRepository(JobRepository::class, $db);
    }
}