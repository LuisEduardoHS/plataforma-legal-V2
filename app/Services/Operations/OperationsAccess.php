<?php

namespace App\Services\Operations;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class OperationsAccess
{
    public const CAPABILITIES = [
        'operations.accounts', 'operations.permissions.editorial',
        'operations.permissions.verification', 'operations.permissions.moderation', 'operations.audit',
    ];

    public const VERIFICATION = ['verification.review', 'verification.evidence.read', 'verification.decide', 'verification.revoke', 'verification.audit'];

    public const EDITORIAL = [
        'articles.create', 'articles.update.own', 'articles.publish.own', 'articles.manage',
        'resources.create', 'resources.update.own', 'resources.publish.own', 'resources.manage',
    ];

    public const MODERATION = ['posts.moderate', 'comments.moderate'];

    /** Current SQL reads, including inherited grants; never cached role definitions. */
    public function grants(User $user): array
    {
        $direct = DB::table('model_has_permissions as assigned')->join('permissions as permission', 'permission.id', '=', 'assigned.permission_id')
            ->where('assigned.model_type', $user->getMorphClass())->where('assigned.model_id', $user->id)
            ->where('permission.guard_name', 'web')->sharedLock()->pluck('permission.name')->all();
        $inherited = DB::table('model_has_roles as assigned')->join('roles as role', 'role.id', '=', 'assigned.role_id')
            ->join('role_has_permissions as grants', 'grants.role_id', '=', 'role.id')
            ->join('permissions as permission', 'permission.id', '=', 'grants.permission_id')
            ->where('assigned.model_type', $user->getMorphClass())->where('assigned.model_id', $user->id)
            ->where('role.guard_name', 'web')->where('permission.guard_name', 'web')->sharedLock()->pluck('permission.name')->all();
        sort($direct);
        sort($inherited);

        return ['direct' => array_values(array_unique($direct)), 'inherited' => array_values(array_unique($inherited))];
    }

    public function effective(User $user): array
    {
        $grants = $this->grants($user);

        return array_values(array_unique([...$grants['direct'], ...$grants['inherited']]));
    }

    public function can(User $user, string $capability): bool
    {
        $permissions = $this->effective($user);

        return $user->isActive() && ! array_intersect(self::VERIFICATION, $permissions)
            && in_array($capability, self::CAPABILITIES, true) && in_array($capability, $permissions, true);
    }

    public function enter(User $user): bool
    {
        $permissions = $this->effective($user);

        return $user->isActive() && ((array_intersect(self::CAPABILITIES, $permissions) && ! array_intersect(self::VERIFICATION, $permissions))
            || (bool) array_intersect(self::MODERATION, $permissions));
    }

    public function authorize(User $user, string $capability): void
    {
        if (! $this->can($user, $capability)) {
            throw new AuthorizationException;
        }
    }

    public function group(string $permission): ?string
    {
        return match (true) {
            in_array($permission, self::EDITORIAL, true) => 'editorial',
            in_array($permission, self::VERIFICATION, true) => 'verification',
            in_array($permission, self::MODERATION, true) => 'moderation',
            default => null,
        };
    }
}
