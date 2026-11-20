@extends('layouts.public')
@section('content')
    <x-landing.lexa-hero />
    <div class="landing-container">
        <section id="posibilidades" class="landing-section landing-possibilities" data-reveal aria-labelledby="possibilities-title">
            <div class="landing-section-heading">
                <p class="landing-eyebrow">Más allá de una pregunta</p>
                <h2 id="possibilities-title">Un espacio para entender,<br>compartir y conectar.</h2>
                <p>Elige por dónde empezar. Puedes explorar los contenidos públicos sin crear una cuenta.</p>
            </div>
            <div class="landing-paths">
                <a class="landing-path" href="#comunidad"><span class="landing-path-icon"><x-lucide-users aria-hidden="true" /></span><h3>Comunidad</h3><p>Preguntas, experiencias y perspectivas que abren la conversación.</p><span class="landing-path-link">Conoce la comunidad <x-lucide-arrow-right aria-hidden="true" /></span></a>
                <a class="landing-path" href="#profesionales"><span class="landing-path-icon"><x-lucide-briefcase-business aria-hidden="true" /></span><h3>Profesionales</h3><p>Presentaciones públicas para encontrar con quién dar el siguiente paso.</p><span class="landing-path-link">Explora el directorio <x-lucide-arrow-right aria-hidden="true" /></span></a>
                <a class="landing-path" href="#biblioteca"><span class="landing-path-icon"><x-lucide-book-open aria-hidden="true" /></span><h3>Artículos y recursos</h3><p>Información jurídica para acercarte a los temas que te interesan.</p><span class="landing-path-link">Descubre la biblioteca <x-lucide-arrow-right aria-hidden="true" /></span></a>
            </div>
        </section>
        <section id="comunidad" class="landing-section landing-feature" data-reveal aria-labelledby="community-title">
            <div>
                <p class="landing-eyebrow">Comunidad</p>
                <h2 id="community-title">Las dudas también<br>abren conversaciones.</h2>
                <p>Lee publicaciones públicas, conoce otras perspectivas y encuentra temas cercanos a tu situación. Para publicar, comentar o participar, inicia sesión con una cuenta activa y los permisos correspondientes.</p>
                <a class="landing-text-link" href="{{ route('community') }}">Explorar Comunidad <x-lucide-arrow-up-right aria-hidden="true" /></a>
            </div>
            <aside class="landing-editorial-note" aria-label="Participar en Comunidad">
                <x-lucide-messages-square aria-hidden="true" />
                <p class="landing-note-title">Un lugar para compartir con cuidado.</p>
                <p>Elige qué haces público. Evita incluir datos personales o documentos sensibles en una publicación.</p>
                <span>La conversación orienta; no sustituye una consulta profesional.</span>
            </aside>
        </section>
        <section id="profesionales" class="landing-section landing-feature landing-feature-reverse" data-reveal aria-labelledby="professionals-title">
            <div>
                <p class="landing-eyebrow">Profesionales</p>
                <h2 id="professionals-title">Encuentra con quién dar<br>el siguiente paso.</h2>
                <p>Explora presentaciones públicas por especialidad y ubicación. Revisa la información que cada profesional comparte y las opciones de atención disponibles.</p>
                <a class="landing-text-link" href="{{ route('professionals') }}">Buscar profesionales <x-lucide-arrow-up-right aria-hidden="true" /></a>
            </div>
            <aside class="landing-directory-note" aria-label="Qué puedes consultar en el directorio">
                <p class="landing-note-kicker">Conoce antes de contactar</p>
                <ul><li><x-lucide-scale aria-hidden="true" /><span>Áreas de atención</span></li><li><x-lucide-map-pin aria-hidden="true" /><span>Ubicación y modalidad</span></li><li><x-lucide-badge-check aria-hidden="true" /><span>Estado vigente de verificación</span></li></ul>
                <p>La verificación disponible es una revisión documental interna. No es una comprobación gubernamental automática ni una garantía de calidad del servicio.</p>
            </aside>
        </section>
        <section id="biblioteca" class="landing-section" data-reveal aria-labelledby="library-title">
            <div class="landing-section-heading"><p class="landing-eyebrow">Biblioteca legal</p><h2 id="library-title">Más contexto para<br>entender mejor.</h2><p>Dos caminos para explorar información jurídica, a tu ritmo.</p></div>
            <div class="landing-library-grid">
                <article class="landing-library-card"><x-lucide-book-open-text aria-hidden="true" /><h3>Artículos</h3><p>Consulta textos publicados y explora sus temas y clasificaciones para ampliar tu perspectiva.</p><a class="landing-text-link" href="{{ route('articles') }}">Leer artículos <x-lucide-arrow-up-right aria-hidden="true" /></a></article>
                <article class="landing-library-card"><x-lucide-files aria-hidden="true" /><h3>Recursos jurídicos</h3><p>Explora materiales y enlaces publicados. Revisa su contexto y vigencia antes de utilizarlos.</p><a class="landing-text-link" href="{{ route('resources') }}">Consultar recursos <x-lucide-arrow-up-right aria-hidden="true" /></a></article>
            </div>
        </section>
        <section id="como-funciona" class="landing-section" data-reveal aria-labelledby="how-title">
            <div class="landing-section-heading"><p class="landing-eyebrow">A tu ritmo</p><h2 id="how-title">Empieza por donde estás.</h2></div>
            <ol class="landing-steps">
                <li><span class="landing-step-number" aria-hidden="true">01</span><h3>Explora</h3><p>Lee contenidos públicos y conoce las posibilidades de la plataforma sin crear una cuenta.</p></li>
                <li><span class="landing-step-number" aria-hidden="true">02</span><h3>Crea tu espacio</h3><p>Regístrate cuando quieras participar, guardar contenido o utilizar la mensajería privada.</p></li>
                <li><span class="landing-step-number" aria-hidden="true">03</span><h3>Da el siguiente paso</h3><p>Encuentra información y presentaciones profesionales. Inicia sesión para comenzar una conversación.</p></li>
            </ol>
        </section>
    </div>
    <section id="transparencia" class="landing-transparency" data-reveal aria-labelledby="trust-title">
        <div class="landing-container landing-transparency-grid">
            <div><p class="landing-eyebrow">Confianza y transparencia</p><h2 id="trust-title">Claridad sobre<br>lo que puedes esperar.</h2><a class="landing-text-link" href="{{ route('help') }}">Conoce más en Ayuda <x-lucide-arrow-up-right aria-hidden="true" /></a></div>
            <dl>
                <div><dt>Herramientas reales. LEXA, por ahora, una demostración.</dt><dd>Comunidad, perfiles, mensajería y Biblioteca están integrados. LEXA muestra respuestas de ejemplo y todavía no está conectada a una IA.</dd></div>
                <div><dt>Información para orientarte.</dt><dd>Los contenidos y la demostración no sustituyen la asesoría de un profesional ni evalúan tu situación particular.</dd></div>
                <div><dt>Comparte solo lo necesario.</dt><dd>Una publicación pública y una conversación privada tienen alcances distintos. No incluyas información sensible sin revisar dónde la compartes.</dd></div>
                <div><dt>Verificación con alcance definido.</dt><dd>La revisión documental es interna y su estado puede cambiar. No implica validación gubernamental automática ni garantiza el servicio profesional.</dd></div>
            </dl>
        </div>
    </section>
    <section class="landing-container landing-final-section" data-reveal aria-labelledby="join-title">
        <div class="landing-final">
            <p class="landing-eyebrow">Tu siguiente paso</p><h2 id="join-title">Crea tu espacio en<br>Lefenda.</h2><p>Explora, participa y vuelve a lo que te importa.</p>
            <a class="landing-button landing-button-brand" href="{{ route('register') }}">Crear mi cuenta <x-lucide-arrow-right aria-hidden="true" /></a>
            <p class="landing-final-login">¿Ya tienes cuenta? <a href="{{ route('login') }}">Iniciar sesión</a></p>
        </div>
    </section>
@endsection
