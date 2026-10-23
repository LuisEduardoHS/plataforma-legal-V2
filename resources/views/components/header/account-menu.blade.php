<div class="account-dropdown" x-data="{
    open: false,
    close(restoreFocus = false) { this.open = false; if (restoreFocus) this.$refs.trigger.focus(); },
    focusItem(position) {
        const items = Array.from(this.$refs.panel.querySelectorAll('a[href], button:not(:disabled)'));
        if (!items.length) return;
        const current = items.indexOf(document.activeElement);
        const index = position === 'first' ? 0 : position === 'last' ? items.length - 1 : (current + position + items.length) % items.length;
        items[index].focus();
    }
}" @keydown.escape.stop.prevent="close(true)" @focusout="if ($event.relatedTarget && !$el.contains($event.relatedTarget)) close()" @click.outside="close()">
    <button type="button" class="account-trigger" x-ref="trigger" aria-label="Abrir menú de cuenta" aria-controls="account-dropdown-panel" :aria-expanded="open.toString()"
        @click="open = !open"
        @keydown.arrow-down.prevent="open = true; $nextTick(() => focusItem('first'))"
        @keydown.arrow-up.prevent="open = true; $nextTick(() => focusItem('last'))">
        <span class="account-chevron"><x-lucide-chevron-down aria-hidden="true" /></span>
    </button>
    <nav id="account-dropdown-panel" class="account-panel" x-ref="panel" x-show="open" x-cloak x-transition.opacity.duration.150ms aria-label="Cuenta"
        @keydown.arrow-down.prevent="focusItem(1)" @keydown.arrow-up.prevent="focusItem(-1)"
        @keydown.home.prevent="focusItem('first')" @keydown.end.prevent="focusItem('last')">
        @foreach (['profile' => ['user-round', 'Perfil'], 'saved' => ['bookmark', 'Guardados'], 'settings' => ['settings', 'Configuración'], 'help' => ['life-buoy', 'Ayuda']] as $route => [$icon, $label])
            <a href="{{ route($route) }}" @if (request()->routeIs($route)) aria-current="page" @endif><x-dynamic-component :component="'lucide-' . $icon" aria-hidden="true" /><span>{{ $label }}</span></a>
        @endforeach
        <div class="account-logout">
            @if (Illuminate\Support\Facades\Route::has('logout'))
                <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit"><x-lucide-log-out aria-hidden="true" /><span>Cerrar sesión</span></button></form>
            @else
                <button type="button" disabled title="El cierre de sesión se habilitará al conectar la autenticación."><x-lucide-log-out aria-hidden="true" /><span>Cerrar sesión</span></button>
            @endif
        </div>
    </nav>
</div>
