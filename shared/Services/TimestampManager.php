<?php

declare(strict_types=1);

namespace App\Services;

class TimestampManager
{
    private array $timestamps = []; // Cache de timestamps por rbfid
    
    public function update(string $rbfid, int $timestamp): void {
        $this->timestamps[$rbfid] = $timestamp;
    }
    
    public function get(string $rbfid): int {
        return $this->timestamps[$rbfid] ?? 0;
    }
    
    public function clear(string $rbfid): void {
        unset($this->timestamps[$rbfid]);
    }
    
    public function clearAll(): void {
        $this->timestamps = [];
    }
}