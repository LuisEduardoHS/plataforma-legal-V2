<?php

namespace App\View\Components\Header;

use App\Enums\EditorialKind;
use App\Services\Operations\OperationsAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\Component;

class AccountMenu extends Component
{
    public array $links = [];

    public bool $activeAccount;

    public string $accountLabel;

    public string $initial;

    public function __construct()
    {
        $actor = Auth::user();
        $this->activeAccount = $actor !== null && $actor->isActive();
        $this->accountLabel = $this->activeAccount ? $actor->name : 'Exploras como visitante';
        $this->initial = $this->activeAccount ? mb_strtoupper(mb_substr($actor->name, 0, 1)) : '';

        if (! $this->activeAccount) {
            $this->link('login', 'log-in', 'Iniciar sesión');
            $this->link('register', 'user-plus', 'Crear cuenta');
            $this->link('help', 'life-buoy', 'Ayuda');

            return;
        }

        $this->link('profile', 'user-round', 'Perfil', ['profile', 'profile.*']);
        $this->link('saved', 'bookmark', 'Guardados');
        $this->link('settings', 'settings', 'Configuración');
        $this->link('help', 'life-buoy', 'Ayuda');

        if (app(OperationsAccess::class)->enter($actor)) {
            $this->link('operations.index', 'shield', 'Operaciones', ['operations.*']);
        }
        if ($actor->can('verification.review')) {
            $this->link('verification.review.index', 'badge-check', 'Revisión profesional', ['verification.review.*']);
        }

        foreach (EditorialKind::cases() as $kind) {
            $prefix = $kind->permission();
            $model = $kind->model();
            // Discovery only: workspace permits own content even after grants are revoked.
            // Every destination retains its controller/policy authorization.
            if (Gate::forUser($actor)->allows('create', $model)
                || $actor->canAny([$prefix.'.update.own', $prefix.'.publish.own'])
                || $model::where('user_id', $actor->getKey())->exists()) {
                $this->link($prefix.'.workspace', $kind === EditorialKind::Article ? 'book-open' : 'archive',
                    $kind === EditorialKind::Article ? 'Gestionar artículos' : 'Gestionar recursos',
                    [$prefix.'.workspace', $prefix.'.create', $prefix.'.edit', $prefix.'.history']);
            }
        }
    }

    private function link(string $route, string $icon, string $label, array $patterns = []): void
    {
        $this->links[] = compact('route', 'icon', 'label') + [
            'current' => request()->routeIs(...($patterns ?: [$route])),
        ];
    }

    public function render(): View
    {
        return view('components.header.account-menu');
    }
}
