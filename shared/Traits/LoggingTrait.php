<?php

namespace App\Traits;

trait LoggingTrait
{
    public function log(string $message): void
    {
        error_log("AR: " . $message);
    }
}