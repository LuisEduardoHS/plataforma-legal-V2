@extends('layouts.app')
@section('title', 'Inicio | Plataforma Legal')
@section('content')
    @vite(['resources/css/home.css', 'resources/js/home.js'])
    @php($dashboard = $dashboard ?? require resource_path('views/pages/home/demo.php'))
    <section class="home-dashboard" data-home aria-label="Inicio">
        <script type="application/json" data-home-data>{!! Illuminate\Support\Js::encode($dashboard) !!}</script>
        <h1 class="home-sr">Inicio</h1>
        <div class="home-grid">
            <div class="home-main">
                <section aria-label="Comenzar una consulta en LEXA">
                    <form class="home-card home-composer" data-home-composer>
                        <label for="home-question" class="home-sr">Describe tu situación legal</label>
                        <textarea id="home-question" data-home-question rows="3" maxlength="5000" placeholder="Describe tu situación legal o pregunta lo que necesites…"></textarea>
                        <div class="home-composer-tools">
                            <label class="home-mode"><x-lucide-sparkles aria-hidden="true" /><span class="home-sr">Tipo de consulta</span><select data-home-mode><option value="orientation">Orientación</option><option value="contract">Revisar un contrato</option><option value="organize">Ordenar mi consulta</option></select><x-lucide-chevron-down aria-hidden="true" /></label>
                            <a href="{{ route('lexa') }}" class="home-context" data-add-context><x-lucide-paperclip aria-hidden="true" /><span>Agregar contexto</span></a>
                            <div class="home-compose-actions"><button type="button" class="home-icon" disabled aria-label="Entrada de voz no habilitada"><x-lucide-mic aria-hidden="true" /></button><button type="submit" class="home-send" data-home-send disabled aria-label="Continuar consulta en LEXA"><x-lucide-arrow-up aria-hidden="true" /></button></div>
                        </div>
                    </form>
                    <p class="home-disclaimer">La orientación informativa no sustituye la asesoría profesional.</p>
                    <p class="home-error" data-home-error role="alert" hidden></p>
                </section>
                <section aria-labelledby="home-continue-title">
                    <div class="home-section-heading"><h2 id="home-continue-title">Continuar</h2></div>
                    <div class="home-three">@foreach ($dashboard['continue'] as $item)<x-home.continuation :item="$item" />@endforeach</div>
                </section>
                <section aria-labelledby="home-recommended-title">
                    <div class="home-section-heading"><h2 id="home-recommended-title">Recomendados para ti</h2><a href="{{ route('articles') }}">Ver todo <x-lucide-arrow-right aria-hidden="true" /></a></div>
                    <div class="home-three">
                        <article class="home-card home-recommendation">
                            <header><span class="home-type">Artículo</span><x-home.bookmark :label="$dashboard['article']['title']" /></header>
                            <h3>{{ $dashboard['article']['title'] }}</h3><p>{{ $dashboard['article']['summary'] }}</p>
                            <div class="home-recommendation-meta"><span><x-lucide-briefcase-business aria-hidden="true" />{{ $dashboard['article']['category'] }}</span><span><x-lucide-clock aria-hidden="true" />{{ $dashboard['article']['duration'] }}</span></div>
                            <button type="button" class="home-action" data-open-article>Leer artículo</button>
                        </article>
                        <article class="home-card home-recommendation">
                            <header><span class="home-type">Comunidad</span><x-home.bookmark :label="$dashboard['recommendation']['title']" /></header>
                            <h3>{{ $dashboard['recommendation']['title'] }}</h3>
                            <div class="home-recommendation-meta"><span>{{ $dashboard['recommendation']['category'] }} · {{ $dashboard['recommendation']['time'] }}</span><span><x-lucide-message-square aria-hidden="true" />{{ $dashboard['recommendation']['replies'] }} respuestas</span></div>
                            <a class="home-action" href="{{ route('community') }}#post-{{ $dashboard['recommendation']['id'] }}">Ver publicación</a>
                        </article>
                        <article class="home-card home-recommendation">
                            <header><span class="home-type">Abogada</span></header>
                            <span class="home-avatar home-avatar-large" aria-hidden="true">AM</span>
                            <h3>{{ $dashboard['professional']['name'] }}</h3><p>{{ $dashboard['professional']['specialty'] }}</p>
                            <div class="home-recommendation-meta"><span><x-lucide-map-pin aria-hidden="true" />{{ $dashboard['professional']['location'] }}</span><span><x-lucide-badge-check aria-hidden="true" />Perfil de referencia</span></div>
                            <button type="button" class="home-action" data-open-professional>Ver perfil</button>
                        </article>
                    </div>
                </section>
                <section aria-labelledby="home-community-title">
                    <div class="home-section-heading"><h2 id="home-community-title">De la comunidad</h2><a href="{{ route('community') }}">Ver todo <x-lucide-arrow-right aria-hidden="true" /></a></div>
                    <div class="home-feed">@foreach ($dashboard['feed'] as $post)<x-home.post :post="$post" />@endforeach</div>
                </section>
            </div>
            <aside class="home-sidebar" aria-label="Pendientes y accesos rápidos">
                <section aria-labelledby="home-pending-title"><div class="home-section-heading"><h2 id="home-pending-title">Pendientes</h2><span class="home-count" data-pending-count>3</span></div><div class="home-card home-pending">
                    @foreach ($dashboard['continue'] as $item)<div class="home-pending-item" data-pending-item><a href="{{ $item['url'] }}" data-continue="{{ $item['id'] }}">{{ $item['title'] }}</a><button type="button" class="home-icon" data-dismiss-pending aria-label="Ocultar recordatorio: {{ $item['title'] }}"><x-lucide-x aria-hidden="true" /></button></div>@endforeach
                    <p class="home-muted" data-pending-empty hidden>No tienes recordatorios en esta vista.</p>
                </div></section>
                <section aria-labelledby="home-shortcuts-title"><div class="home-section-heading"><h2 id="home-shortcuts-title">Accesos rápidos</h2></div><nav class="home-card home-shortcuts" aria-label="Accesos rápidos">
                    @foreach (['community' => ['users', 'Comunidad'], 'messages' => ['message-circle', 'Mensajes'], 'articles' => ['archive', 'Recursos'], 'professionals' => ['briefcase-business', 'Profesionales']] as $route => [$icon, $label])<a href="{{ route($route) }}"><x-dynamic-component :component="'lucide-' . $icon" aria-hidden="true" /><span>{{ $label }}</span></a>@endforeach
                </nav></section>
            </aside>
        </div>
        <dialog class="home-dialog" data-home-dialog aria-labelledby="home-detail-title"><header><h2 id="home-detail-title"></h2><button type="button" class="home-icon" data-close-dialog aria-label="Cerrar detalle"><x-lucide-x aria-hidden="true" /></button></header><div data-home-detail></div></dialog>
        <p class="home-sr" data-home-status role="status" aria-live="polite"></p>
    </section>
@endsection