<header class="public-header">
    <div class="landing-container public-header-inner">
        <a class="public-brand" href="{{ route('landing') }}" aria-label="Plataforma Legal, portada"><span class="public-brand-mark" aria-hidden="true"></span>Plataforma Legal</a>
        <nav class="public-navigation" aria-label="Navegación pública">
            <details class="public-disclosure">
                <summary>Explorar <x-lucide-chevron-down aria-hidden="true" /></summary>
                <div class="public-dropdown">
                    <span class="public-dropdown-label">En esta página</span>
                    <a href="#posibilidades">La plataforma</a>
                    <a href="#como-funciona">Cómo funciona</a>
                    <a href="#transparencia">Confianza y transparencia</a>
                    <span class="public-dropdown-label">Encuentra tu camino</span>
                    <a href="{{ route('community') }}">Comunidad</a>
                    <a href="{{ route('professionals') }}">Profesionales</a>
                    <a href="{{ route('articles') }}">Artículos</a>
                    <a href="{{ route('resources') }}">Recursos jurídicos</a>
                    <a href="{{ route('help') }}">Ayuda</a>
                    <div class="public-dropdown-account"><a href="{{ route('login') }}">Iniciar sesión</a><a href="{{ route('register') }}">Crear cuenta</a></div>
                </div>
            </details>
            <a class="public-desktop-link" href="#como-funciona">Cómo funciona</a>
            <a class="public-desktop-link" href="{{ route('help') }}">Ayuda</a>
        </nav>
        <div class="public-account-actions"><a href="{{ route('login') }}">Iniciar sesión</a><a class="landing-button landing-button-small landing-button-brand" href="{{ route('register') }}">Crear cuenta</a></div>
    </div>
</header>
