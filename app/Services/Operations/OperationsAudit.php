<?php

namespace App\Services\Operations;

use App\Models\OperationsEvent;
use Illuminate\Support\Facades\DB;

class OperationsAudit
{
    public function record(?int $actor, ?int $target, string $action, string $resource, ?int $id, array $before, array $after, string $reason, ?string $permission = null, string $source = 'http'): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('La auditoría debe compartir la transacción de la operación.');
        }
        $event = new OperationsEvent;
        $event->forceFill([
            'actor_id' => $actor, 'target_user_id' => $target, 'action' => $action,
            'resource_type' => $resource, 'resource_id' => $id, 'permission_name' => $permission,
            'before_state' => $before, 'after_state' => $after, 'reason' => $reason,
            'source' => $source, 'created_at' => now(),
        ])->save();
    }
}
