<footer class="public-footer">
    <div class="landing-container public-footer-grid">
        <div><a class="public-brand" href="{{ route('landing') }}">Plataforma Legal</a><p>Información, comunidad y conexiones para dar tu siguiente paso.</p><small>© {{ now()->year }} Plataforma Legal</small></div>
        <nav aria-label="Explorar desde el pie de página"><h2>Explorar</h2><a href="{{ route('community') }}">Comunidad</a><a href="{{ route('professionals') }}">Profesionales</a><a href="{{ route('articles') }}">Artículos</a><a href="{{ route('resources') }}">Recursos jurídicos</a></nav>
        <nav aria-label="Ayuda y cuenta"><h2>Tu espacio</h2><a href="{{ route('help') }}">Ayuda</a><a href="{{ route('login') }}">Iniciar sesión</a><a href="{{ route('register') }}">Crear cuenta</a></nav>
    </div>
</footer>
