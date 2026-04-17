<?php
declare(strict_types=1);

namespace App\Api;

use App\TotpValidator;

class AuthService {
    private $db;
    public function __construct($db) { $this->db = $db; }
    
    public function validate(string $rbfid, string $token): array {
        return TotpValidator::validate($this->db, $rbfid, $token);
    }
}
