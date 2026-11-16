<?php

namespace App\Services\Operations;

use App\Enums\UserStatus;
use App\Models\OperationsEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class AccountOperations
{
    public function __construct(private OperationsAccess $access, private OperationsAudit $audit) {}

    public function current(User $actor): User
    {
        $current = User::whereKey($actor->id)->sharedLock()->first();
        if (! $current || ! $current->isActive()) {
            throw new AuthorizationException;
        }

        return $current;
    }

    public function overview(User $actor): array
    {
        $actor = $this->current($actor);
        if (! $this->access->enter($actor)) {
            throw new AuthorizationException;
        }
        $permissions = $this->access->effective($actor);

        return [
            'accounts' => $this->access->can($actor, 'operations.accounts'),
            'permissions' => $this->permissionGroups($actor),
            'audit' => $this->access->can($actor, 'operations.audit'),
            'posts' => in_array('posts.moderate', $permissions, true),
            'comments' => in_array('comments.moderate', $permissions, true),
            'articles' => (bool) array_intersect(['articles.create', 'articles.manage'], $permissions),
            'resources' => (bool) array_intersect(['resources.create', 'resources.manage'], $permissions),
        ];
    }

    public function users(User $actor, array $filters)
    {
        $actor = $this->current($actor);
        $this->authorizeDirectory($actor);
        $query = User::query()->select(['id', 'name', 'email', 'status'])->sharedLock();
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['q'])) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['q']).'%';
            $query->where(fn ($query) => $query->whereRaw("name LIKE ? ESCAPE '!'", [$term])->orWhereRaw("email LIKE ? ESCAPE '!'", [$term]));
        }

        return $query->orderBy('id')->paginate(20)->through(fn ($user) => $user->only(['id', 'name', 'email', 'status']))->appends($filters);
    }

    public function detail(User $actor, int $id): array
    {
        return $this->locked($actor, $id, function ($actor, $target) {
            $this->authorizeDirectory($actor);
            $groups = $this->permissionGroups($actor);
            $grants = $this->access->grants($target);
            $catalog = $groups === [] ? [] : array_merge(...array_map(fn ($group) => constant(OperationsAccess::class.'::'.strtoupper($group)), $groups));

            return [
                'account' => $target->only(['id', 'name', 'email', 'status']) + ['version' => $this->version($target)],
                'can_change_status' => $actor->id !== $target->id && $this->access->can($actor, 'operations.accounts')
                    && ! array_intersect(OperationsAccess::CAPABILITIES, $this->access->effective($target)) && $target->status !== 'pending_deletion',
                'permission_options' => array_map(fn ($name) => [
                    'name' => $name, 'direct' => in_array($name, $grants['direct'], true),
                    'inherited' => in_array($name, $grants['inherited'], true),
                ], $catalog),
                'can_change_permissions' => $actor->id !== $target->id,
            ];
        });
    }

    public function state(User $actor, int $id, string $next, string $version, string $reason): bool
    {
        $this->reason($reason);

        return $this->locked($actor, $id, function ($actor, $target) use ($next, $version, $reason) {
            $this->access->authorize($actor, 'operations.accounts');
            $this->notSelf($actor, $target);
            if (array_intersect(OperationsAccess::CAPABILITIES, $this->access->effective($target))) {
                $this->invalid('Las cuentas operadoras requieren mantenimiento confiable fuera de este formulario.');
            }
            if (! in_array($next, ['active', 'suspended', 'disabled'], true)
                || ! UserStatus::from($target->status)->canTransitionTo(UserStatus::from($next))) {
                $this->invalid('La transición de cuenta no está permitida.');
            }
            if ($target->status === $next) {
                return false;
            }
            $this->checkVersion($target, $version);
            $before = $target->status;
            $target->forceFill(['status' => $next, 'remember_token' => Str::random(60)])->save();
            $this->audit->record($actor->id, $target->id, 'account_status_changed', 'user', $target->id,
                ['status' => $before], ['status' => $target->status], trim($reason));

            return true;
        });
    }

    public function permission(User $actor, int $id, string $name, bool $grant, string $version, string $reason): bool
    {
        $this->reason($reason);
        $group = $this->access->group($name);
        if (! $group) {
            $this->invalid('El permiso no pertenece al catálogo administrable.');
        }

        return $this->locked($actor, $id, function ($actor, $target) use ($name, $group, $grant, $version, $reason) {
            $this->access->authorize($actor, 'operations.permissions.'.$group);
            $this->notSelf($actor, $target);
            $grants = $this->access->grants($target);
            if ($grant && (! $target->isActive() || ($group === 'verification'
                && array_intersect(OperationsAccess::CAPABILITIES, $this->access->effective($target))))) {
                $this->invalid('No se concede este permiso a una cuenta inactiva ni se combina revisión profesional con capacidades administrativas.');
            }
            if (! $grant && in_array($name, $grants['inherited'], true)) {
                $this->invalid('El permiso está heredado de un rol. Este formulario no altera roles ni simula su revocación.');
            }
            $direct = in_array($name, $grants['direct'], true);
            if (($grant && ($direct || in_array($name, $grants['inherited'], true))) || (! $grant && ! $direct)) {
                return false;
            }
            $this->checkVersion($target, $version);
            $permission = Permission::where('name', $name)->where('guard_name', 'web')->sharedLock()->first();
            if (! $permission) {
                $this->invalid('El permiso no está aprovisionado. No se crean permisos desde HTTP.');
            }
            $grant ? $target->givePermissionTo($permission) : $target->revokePermissionTo($permission);
            $this->audit->record($actor->id, $target->id, $grant ? 'permission_granted' : 'permission_revoked', 'user', $target->id,
                ['direct' => $direct], ['direct' => $grant], trim($reason), $name);
            DB::afterCommit(fn () => app(PermissionRegistrar::class)->forgetCachedPermissions());

            return true;
        });
    }

    public function bootstrap(int $id, array $capabilities, string $reason, bool $apply): array
    {
        if (! app()->runningInConsole()) {
            throw new AuthorizationException;
        }
        $this->reason($reason);
        Validator::make(['capabilities' => $capabilities], ['capabilities' => ['required', 'array', 'min:1', 'max:5'],
            'capabilities.*' => ['required', 'string', 'distinct', Rule::in(OperationsAccess::CAPABILITIES)]])->validate();

        return DB::transaction(function () use ($id, $capabilities, $reason, $apply) {
            $target = User::whereKey($id)->lockForUpdate()->first();
            $existing = User::whereHas('permissions', fn ($query) => $query->whereIn('name', OperationsAccess::CAPABILITIES))
                ->orWhereHas('roles.permissions', fn ($query) => $query->whereIn('name', OperationsAccess::CAPABILITIES))->exists();
            if (! $target || ! $target->isActive() || $existing || OperationsEvent::where('action', 'operator_bootstrapped')->exists()
                || array_intersect(OperationsAccess::VERIFICATION, $this->access->effective($target))) {
                $this->invalid('El aprovisionamiento inicial no es válido o ya fue realizado.');
            }
            if ($apply) {
                foreach ($capabilities as $name) {
                    $target->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));
                }
                $this->audit->record(null, $id, 'operator_bootstrapped', 'user', $id, [], ['capabilities' => $capabilities], trim($reason), source: 'console');
                DB::afterCommit(fn () => app(PermissionRegistrar::class)->forgetCachedPermissions());
            }

            return ['id' => $id, 'capabilities' => $capabilities, 'applied' => $apply];
        });
    }

    private function locked(User $actor, int $id, callable $operation): mixed
    {
        return DB::transaction(function () use ($actor, $id, $operation) {
            $users = User::whereKey([$actor->id, $id])->orderBy('id')->lockForUpdate()->get();
            $current = $users->firstWhere('id', $actor->id);
            $target = $users->firstWhere('id', $id);
            if (! $current || ! $current->isActive() || ! $target) {
                throw new AuthorizationException;
            }

            return $operation($current, $target);
        });
    }

    private function authorizeDirectory(User $actor): void
    {
        if (! $this->access->can($actor, 'operations.accounts') && $this->permissionGroups($actor) === []) {
            throw new AuthorizationException;
        }
    }

    private function permissionGroups(User $actor): array
    {
        return array_values(array_filter(['editorial', 'verification', 'moderation'], fn ($group) => $this->access->can($actor, 'operations.permissions.'.$group)));
    }

    private function version(User $user): string
    {
        // An audited round trip must not resurrect an old version, even when
        // timestamps have second precision and all visible fields match again.
        $event = OperationsEvent::where('target_user_id', $user->id)->orderByDesc('id')->sharedLock()->value('id');

        return hash_hmac('sha256', json_encode([$user->id, $user->status, $user->getRawOriginal('updated_at'), $this->access->grants($user), $event], JSON_THROW_ON_ERROR), config('app.key'));
    }

    private function checkVersion(User $user, string $version): void
    {
        abort_unless(hash_equals($this->version($user), $version), 409, 'La cuenta o sus concesiones cambiaron. Consulta de nuevo su estado.');
    }

    private function notSelf(User $actor, User $target): void
    {
        if ($actor->id === $target->id) {
            $this->invalid('No puedes modificar tu propia cuenta ni concederte permisos desde esta sección.');
        }
    }

    private function reason(string $reason): void
    {
        Validator::make(['reason' => trim($reason)], ['reason' => ['required', 'string', 'min:10', 'max:2000']])->validate();
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['operation' => $message]);
    }
}
