<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

final class DatabaseFailureReporter
{
    public function __invoke(QueryException $error): void
    {
        // Never pass the exception, its message, SQL, bindings or trace to a logger.
        $state = $error->errorInfo[0] ?? null;
        $code = $error->errorInfo[1] ?? null;
        preg_match('/\A\s*(SELECT|INSERT|UPDATE|DELETE|ALTER|CREATE|DROP)\b/i', $error->getSql(), $operation);

        Log::error('database.operation_failed', [
            'sqlstate' => is_string($state) && preg_match('/\A[A-Z0-9]{5}\z/', $state) ? $state : null,
            'driver_code' => is_int($code) ? $code : null,
            'operation' => isset($operation[1]) ? strtoupper($operation[1]) : 'OTHER',
        ]);
    }
}
