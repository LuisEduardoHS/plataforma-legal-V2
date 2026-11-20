@extends('layouts.app')
@section('title', 'Inicio | Plataforma Legal')
@section('page-assets')
    @vite(['resources/css/home.css', 'resources/css/articles.css'])
@endsection
@section('content')
    <section class="home-dashboard" data-home data-resources-url="{{ route('articles') }}" aria-label="Inicio">
        <p class="home-error" data-home-error role="alert" hidden></p>
        <div class="home-grid">
            <div class="home-main">
                @php($greetingName = trim((string) (auth()->user()?->profile?->display_name ?? auth()->user()?->name ?? '')))
                <section class="home-card home-start" aria-label="Comenzar una consulta en LEXA">
                    <div class="home-start-top">
                        @if ($greetingName !== '')
                            <h2 class="home-start-greeting">Hola, {{ $greetingName }}</h2>
                        @endif
                        <span class="home-lexa-wordmark home-start-brand">LEXA</span>
                    </div>
                    <form class="home-composer" data-home-composer>
                        <label for="home-question" class="home-sr">Describe tu situación legal</label>
                        <textarea id="home-question" data-home-question rows="1" maxlength="5000" placeholder="Cuéntame tu situación o duda legal…"></textarea>
                        <div class="home-composer-tools">
                            <label class="home-mode"><span class="home-sr">Tipo de consulta</span><select data-home-mode><option value="orientation">Orientación</option><option value="contract">Revisar un contrato</option><option value="organize">Ordenar mi consulta</option></select><x-lucide-chevron-down aria-hidden="true" /></label>
                            <a href="{{ route('lexa') }}" class="home-context" data-add-context aria-label="Agregar contexto"><x-lucide-paperclip aria-hidden="true" /><span>Agregar contexto</span></a>
                            <button type="submit" class="home-send" data-home-send disabled aria-label="Continuar consulta en LEXA"><x-lucide-arrow-up aria-hidden="true" /></button>
                        </div>
                    </form>
                    <div class="home-starters" aria-label="Ideas para comenzar">
                        @foreach (['Mi finiquito' => 'Quiero entender qué información reunir sobre mi finiquito.', 'Un contrato' => 'Quiero preparar las preguntas para revisar un contrato.', 'Mi renta' => 'Tengo una duda sobre mi contrato de renta.'] as $label => $prompt)
                            <button type="button" data-home-prompt="{{ $prompt }}">{{ $label }}</button>
                        @endforeach
                    </div>
                </section>
                <section aria-labelledby="home-pending-title">
                    <svg width="0" height="0" aria-hidden="true" class="pointer-events-none absolute">
                        <defs>
                            <linearGradient id="home-magenta-grad" x1="0%" y1="100%" x2="100%" y2="0%">
                                <stop offset="0%" stop-color="#7F1842" />
                                <stop offset="55%" stop-color="#9E1F52" />
                                <stop offset="100%" stop-color="#BE2562" />
                            </linearGradient>
                        </defs>
                    </svg>
                    <div class="home-section-heading"><h2 id="home-pending-title">Continuar</h2></div>
                    <div class="home-continue-grid">
                        @foreach ([
                            'messages' => ['message-circle', 'Conversaciones', []],
                            'community' => ['file-text', 'Mis publicaciones', ['feed' => 'mine']],
                            'saved' => ['bookmark', 'Mis guardados', []],
                        ] as $route => [$icon, $title, $params])
                            <a class="home-card home-continuation" href="{{ route($route, $params) }}">
                                <span class="home-kind-icon" aria-hidden="true"><x-dynamic-component :component="'lucide-' . $icon" /></span>
                                <span class="home-continuation-title">{{ $title }}</span>
                                <x-lucide-chevron-right class="home-continuation-arrow" aria-hidden="true" />
                            </a>
                        @endforeach
                    </div>
                </section>
                <section aria-labelledby="home-recommended-title">
                    <div class="home-section-heading"><h2 id="home-recommended-title">Para ti</h2><a href="{{ route('articles') }}">Explorar recursos <x-lucide-arrow-right aria-hidden="true" /></a></div>
                    <article class="home-card home-reading">
                        @if ($reading)
                            <div class="home-reading-heading"><span class="home-type"><x-lucide-book-open aria-hidden="true" />Artículo publicado</span><x-content.save type="article" :id="$reading['id']" :saved="$reading['saved']" /></div>
                            <h3>{{ $reading['title'] }}</h3>
                            <div class="home-reading-footer"><span class="home-reading-category">{{ $reading['category']['name'] ?? 'Sin categoría' }}</span><a href="{{ route('articles.show', $reading['id']) }}">Leer artículo <x-lucide-arrow-right aria-hidden="true" /></a></div>
                        @else
                            <div class="home-reading-heading"><span class="home-type"><x-lucide-book-open aria-hidden="true" />Biblioteca legal</span></div>
                            <h3>Aún no hay artículos publicados</h3>
                            <div class="home-reading-footer"><span class="home-muted !p-0">Explora guías y recursos legales disponibles.</span><a href="{{ route('articles') }}">Ir a Recursos <x-lucide-arrow-right aria-hidden="true" /></a></div>
                        @endif
                    </article>
                </section>
                <section class="home-community-peek" aria-labelledby="home-community-title">
                    <div class="home-section-heading"><h2 id="home-community-title">En la comunidad</h2><a href="{{ route('community') }}">Ver todo <x-lucide-arrow-right aria-hidden="true" /></a></div>
                    <div class="home-feed">@forelse ($feed as $post)<x-home.post :post="$post" />@empty<div class="home-card"><p class="home-muted">No hay publicaciones públicas disponibles.</p><a class="home-action" href="{{ route('community') }}">Explorar Comunidad</a></div>@endforelse</div>
                </section>
            </div>
            <aside class="home-sidebar" aria-label="Accesos rápidos">
                <section>
                    <h2>Tu espacio</h2>
                    <div class="home-card home-sidebar-card">
                        <nav class="home-sidebar-nav" aria-label="Accesos personales">
                            <a class="home-sidebar-link" href="{{ route('profile') }}"><x-lucide-user-round aria-hidden="true" /><span>Mi perfil</span></a>
                            <a class="home-sidebar-link" href="{{ route('saved') }}"><x-lucide-bookmark aria-hidden="true" /><span>Mis guardados</span></a>
                            <a class="home-sidebar-link" href="{{ route('settings') }}"><x-lucide-settings aria-hidden="true" /><span>Configuración</span></a>
                        </nav>
                    </div>
                </section>
                <section>
                    <h2>Explorar</h2>
                    <div class="home-card home-sidebar-card">
                        <nav class="home-sidebar-nav" aria-label="Secciones de la plataforma">
                            @foreach (['community' => ['users', 'Comunidad'], 'messages' => ['message-circle', 'Mensajes'], 'articles' => ['book-open', 'Recursos'], 'professionals' => ['briefcase-business', 'Profesionales']] as $route => [$icon, $label])
                                <a class="home-sidebar-link" href="{{ route($route) }}"><x-dynamic-component :component="'lucide-' . $icon" aria-hidden="true" /><span>{{ $label }}</span></a>
                            @endforeach
                        </nav>
                    </div>
                </section>
                <section>
                    <h2>Comparte con la comunidad</h2>
                    <div class="home-card home-sidebar-card">
                        <p class="home-muted home-sidebar-copy">Publica dudas, análisis o recursos con la red.</p>
                        <a href="{{ route('community') }}" class="home-sidebar-cta" data-home-share><x-lucide-square-pen aria-hidden="true" /><span>Crear publicación</span></a>
                    </div>
                </section>
            </aside>
        </div>
        <p class="home-sr" data-home-status role="status" aria-live="polite"></p>
    </section>
@endsection
