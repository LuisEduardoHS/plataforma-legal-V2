@extends('layouts.app')
@section('title', 'Ayuda | Plataforma Legal')
@section('page-assets')
    @vite(['resources/css/library-help.css'])
@endsection
@section('content')
@php($questions = require resource_path('views/pages/help/questions.php'))
<section class="personal-page" data-help aria-label="Ayuda">
    <div class="personal-wrap">
        <div class="personal-layout">
            <div>
                <div data-help-tools hidden>
                <label class="personal-search"><x-lucide-search aria-hidden="true" /><span class="personal-sr">Buscar en Ayuda</span><input type="search" data-help-search placeholder="Busca una pregunta o un tema…" autocomplete="off"></label>
                <div class="personal-filters" role="group" aria-label="Temas de ayuda">
                    @foreach (['all' => 'Todo', 'primeros-pasos' => 'Primeros pasos', 'cuenta' => 'Cuenta', 'perfiles' => 'Perfiles', 'comunidad' => 'Comunidad', 'biblioteca' => 'Biblioteca', 'mensajes' => 'Mensajes', 'verificacion' => 'Verificación', 'lexa' => 'LEXA', 'personal-autorizado' => 'Personal autorizado'] as $id => $label)
                        <button type="button" data-help-category="{{ $id }}" aria-pressed="{{ $loop->first ? 'true' : 'false' }}">{{ $label }}</button>
                    @endforeach
                </div>
                </div>
                <div class="help-guides" data-help-guides aria-label="Accesos para comenzar">
                    <a class="help-guide" href="{{ route('lexa') }}"><x-lucide-sparkles aria-hidden="true" /><strong>Conoce la demo de LEXA</strong><span>Respuestas de ejemplo, sin IA real.@guest Requiere iniciar sesión.@endguest</span></a>
                    <a class="help-guide" href="{{ route('community') }}"><x-lucide-users aria-hidden="true" /><strong>Participa y comparte</strong><span>Conoce la comunidad</span></a>
                    <a class="help-guide" href="{{ route('saved') }}"><x-lucide-bookmark aria-hidden="true" /><strong>Vuelve a lo importante</strong><span>Encuentra tus guardados.@guest Requiere iniciar sesión.@endguest</span></a>
                </div>
                <div class="personal-toolbar"><h2 class="personal-section-title" style="margin:0">Preguntas frecuentes</h2><span class="personal-muted" data-help-count role="status">{{ count($questions) }} preguntas</span></div>
                <div class="help-faqs">
                    @foreach ($questions as $question)
                        <details class="help-faq" data-help-question data-category="{{ $question['category'] }}">
                            <summary>{{ $question['question'] }}<x-lucide-chevron-down aria-hidden="true" /></summary>
                            <div class="help-answer">
                                <span class="help-availability">{{ ['available' => 'Disponible', 'configuration' => 'Requiere configuración de entrega', 'demo' => 'Demostración', 'future' => 'Funcionalidad futura'][$question['status']] }}</span>
                                <p>{{ $question['answer'] }}</p>
                                @if (isset($question['route']))
                                    <a href="{{ route($question['route']) }}">
                                        {{ $question['link'] }}
                                        @if (($question['requires_auth'] ?? false) && ! auth()->check())
                                            · Requiere iniciar sesión
                                        @endif
                                        <x-lucide-arrow-right aria-hidden="true" />
                                    </a>
                                @endif
                            </div>
                        </details>
                    @endforeach
                </div>
                <div class="personal-empty" data-help-empty hidden><x-lucide-search-x aria-hidden="true" /><h2>No encontramos esa pregunta</h2><p>Prueba con otra palabra o explora todos los temas.</p><button type="button" class="personal-button" data-help-reset>Ver todas las preguntas</button></div>
            </div>
            <aside class="personal-sidebar" aria-label="Más opciones de ayuda">
                <section class="personal-card">
                    <h2>Tu cuenta, a tu manera</h2>
                    <p class="personal-muted">Consulta los accesos disponibles y las opciones que siguen pendientes. Las próximas funciones se muestran deshabilitadas.</p>
                    <a href="{{ route('settings') }}" class="personal-button">
                        Abrir Configuración
                        @guest
                            · Requiere iniciar sesión
                        @endguest
                        <x-lucide-arrow-right aria-hidden="true" />
                    </a>
                </section>
                <section class="personal-card"><h2>¿Tienes una duda legal?</h2><p class="personal-muted">Este espacio explica cómo usar la plataforma. Para revisar tu situación, conoce a los profesionales disponibles.</p><a href="{{ route('professionals') }}" class="personal-button">Buscar un profesional<x-lucide-arrow-right aria-hidden="true" /></a></section>
            </aside>
        </div>
    </div>
</section>
@endsection
