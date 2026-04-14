<?php
declare(strict_types=1);

require_once '/srv/app/www/sync/TotpValidator.php';

class AuthService {
    private $db;
    public function __construct($db) { $this->db = $db; }
    
    public function validate(string $rbfid, string $token): array {
        return validateTotp($this->db, $rbfid, $token);
    }
}
