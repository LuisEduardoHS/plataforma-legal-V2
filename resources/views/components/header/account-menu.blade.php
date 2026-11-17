<details class="account-dropdown" data-account-menu>
    <summary class="account-trigger" aria-label="Menú de cuenta" aria-controls="account-dropdown-panel" data-account-trigger>
        @if ($activeAccount)<span class="account-avatar" aria-hidden="true">{{ $initial }}</span>@else<x-lucide-user-round class="account-guest-icon" aria-hidden="true" />@endif
        <span class="account-chevron"><x-lucide-chevron-down aria-hidden="true" /></span>
    </summary>
    <nav id="account-dropdown-panel" class="account-panel" aria-label="Cuenta" data-account-panel>
        <p class="account-heading">{{ $accountLabel }}</p>
        @foreach ($links as $link)
            <a href="{{ route($link['route']) }}" @if ($link['current']) aria-current="page" @endif><x-dynamic-component :component="'lucide-'.$link['icon']" aria-hidden="true" /><span>{{ $link['label'] }}</span></a>
        @endforeach
        @if ($activeAccount)
            <div class="account-logout">
                <form method="POST" action="{{ route('logout') }}" data-turbo="false">@csrf<button type="submit"><x-lucide-log-out aria-hidden="true" /><span>Cerrar sesión</span></button></form>
            </div>
        @endif
    </nav>
</details>
