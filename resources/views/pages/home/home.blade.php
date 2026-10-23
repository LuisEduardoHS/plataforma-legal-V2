@extends('layouts.app')
@section('title', 'Inicio | Plataforma Legal')
@section('page-assets')
    @vite(['resources/css/home.css'])
@endsection
@section('content')
    @php($dashboard = $dashboard ?? require resource_path('views/pages/home/demo.php'))
    <section class="home-dashboard" data-home data-resources-url="{{ route('articles') }}" aria-label="Inicio">
        <script type="application/json" data-home-data>{!! Illuminate\Support\Js::encode($dashboard) !!}</script>
        <p class="home-error" data-home-error role="alert" hidden></p>
        <div class="home-grid">
            <div class="home-main">
                <h1 class="home-page-title">Inicio</h1>
                <section class="home-card home-start" aria-label="Comenzar una consulta en LEXA">
                    <div class="home-start-title"><span class="home-lexa-wordmark">LEXA</span><h2>¿Qué necesitas entender?</h2></div>
                        <form class="home-composer" data-home-composer>
                            <label for="home-question" class="home-sr">Describe tu situación legal</label>
                            <textarea id="home-question" data-home-question rows="2" maxlength="5000" placeholder="Cuéntame tu situación…"></textarea>
                            <div class="home-composer-tools">
                                <label class="home-mode"><x-lucide-sparkles aria-hidden="true" /><span class="home-sr">Tipo de consulta</span><select data-home-mode><option value="orientation">Orientación</option><option value="contract">Revisar un contrato</option><option value="organize">Ordenar mi consulta</option></select><x-lucide-chevron-down aria-hidden="true" /></label>
                                <a href="{{ route('lexa') }}" class="home-context" data-add-context aria-label="Agregar contexto"><x-lucide-paperclip aria-hidden="true" /><span>Agregar contexto</span></a>
                                <button type="submit" class="home-send" data-home-send disabled aria-label="Continuar consulta en LEXA"><x-lucide-arrow-up aria-hidden="true" /></button>
                            </div>

                        </form>
                        <div class="home-starters" aria-label="Ideas para comenzar">@foreach (['Mi finiquito' => 'Quiero entender qué información reunir sobre mi finiquito.', 'Un contrato' => 'Quiero preparar las preguntas para revisar un contrato.', 'Mi renta' => 'Tengo una duda sobre mi contrato de renta.'] as $label => $prompt)<button type="button" data-home-prompt="{{ $prompt }}">{{ $label }}</button>@endforeach</div>

                    <p class="home-disclaimer">Orientación informativa; no sustituye la asesoría profesional.</p>
                </section>
                <section aria-labelledby="home-pending-title">
                    <div class="home-section-heading"><h2 id="home-pending-title">Continuar</h2><span class="home-count" data-pending-count>{{ count($dashboard['continue']) }}</span></div>
                    <div class="home-continue-grid">@foreach ($dashboard['continue'] as $item)<x-home.continuation :item="$item" />@endforeach</div>
                    <p class="home-muted" data-pending-empty hidden>No tienes asuntos por retomar en esta vista.</p>
                </section>
                <section aria-labelledby="home-recommended-title">
                    <div class="home-section-heading"><h2 id="home-recommended-title">Para ti</h2><a href="{{ route('articles') }}">Explorar recursos <x-lucide-arrow-right aria-hidden="true" /></a></div>
                    <div class="home-recommend-grid">
                        <article class="home-card home-reading">
                            <div class="home-reading-heading"><span class="home-type"><x-lucide-book-open aria-hidden="true" />Artículo · {{ $dashboard['article']['duration'] }}</span><x-home.bookmark :label="$dashboard['article']['title']" /></div>
                            <h3>{{ $dashboard['article']['title'] }}</h3>
                            <div class="home-reading-footer"><span>{{ $dashboard['article']['category'] }}</span><button type="button" data-open-article>Leer artículo <x-lucide-arrow-right aria-hidden="true" /></button></div>
                        </article>
                        <article class="home-card home-professional" aria-label="Profesional recomendado">
                            <h3>Profesional</h3>
                            <div class="home-professional-identity"><span class="home-avatar" aria-hidden="true">AM</span><div><h4>{{ $dashboard['professional']['name'] }}</h4><p>{{ $dashboard['professional']['specialty'] }}</p></div></div>
                            <a href="{{ route('professionals') }}#perfil-ana-martinez" class="home-action">Conocer perfil</a>
                        </article>
                    </div>
                </section>
                <section class="home-community-peek" aria-labelledby="home-community-title">
                    <div class="home-section-heading"><h2 id="home-community-title">En la comunidad</h2><a href="{{ route('community') }}">Ver todo <x-lucide-arrow-right aria-hidden="true" /></a></div>
                    <div class="home-feed">@foreach ($dashboard['feed'] as $post)<x-home.post :post="$post" />@endforeach</div>
                </section>
            </div>
            <aside class="home-sidebar" aria-label="Accesos rápidos">
                <section class="home-card home-personal-links">
                    <h2>Tu espacio</h2>
                    <nav aria-label="Accesos personales">
                        <a href="{{ route('profile') }}"><span class="home-personal-icon"><x-lucide-user-round aria-hidden="true" /></span><span>Mi perfil</span><x-lucide-chevron-right aria-hidden="true" /></a>
                        <a href="{{ route('saved') }}"><span class="home-personal-icon"><x-lucide-bookmark aria-hidden="true" /></span><span>Mis guardados</span><x-lucide-chevron-right aria-hidden="true" /></a>
                        <a href="{{ route('settings') }}"><span class="home-personal-icon"><x-lucide-settings aria-hidden="true" /></span><span>Configuración</span><x-lucide-chevron-right aria-hidden="true" /></a>
                    </nav>
                </section>
                <section class="home-card home-explore"><h2>Explorar</h2><nav class="home-shortcuts" aria-label="Secciones de la plataforma">@foreach (['community' => ['users', 'Comunidad'], 'messages' => ['message-circle', 'Mensajes'], 'articles' => ['book-open', 'Recursos'], 'professionals' => ['briefcase-business', 'Profesionales']] as $route => [$icon, $label])<a href="{{ route($route) }}"><x-dynamic-component :component="'lucide-' . $icon" aria-hidden="true" /><span>{{ $label }}</span></a>@endforeach</nav></section>
                <section class="home-card home-share-note"><x-lucide-messages-square aria-hidden="true" /><h2>Comparte con la comunidad</h2><a href="{{ route('community') }}" class="home-action" data-home-share>Crear publicación</a></section>
            </aside>
        </div>
        <dialog class="home-dialog" data-home-dialog aria-labelledby="home-detail-title"><header><h2 id="home-detail-title"></h2><button type="button" class="home-icon" data-close-dialog aria-label="Cerrar detalle"><x-lucide-x aria-hidden="true" /></button></header><div data-home-detail></div></dialog>
        <p class="home-sr" data-home-status role="status" aria-live="polite"></p>
    </section>
@endsection
