@extends('layouts.app')
@section('title', 'Ayuda | Plataforma Legal')
@section('page-assets')
    @vite(['resources/css/library-help.css'])
@endsection
@section('content')
@php($questions = require resource_path('views/pages/help/questions.php'))
<section class="personal-page" data-help aria-labelledby="help-title">
    <div class="personal-wrap">
        <header class="personal-intro"><div><span class="personal-eyebrow">A tu ritmo</span><h1 id="help-title">¿Cómo podemos ayudarte?</h1><p>Encuentra respuestas para moverte por la plataforma.</p></div><x-lucide-life-buoy class="personal-intro-icon" aria-hidden="true" /></header>
        <div class="personal-layout">
            <div>
                <label class="personal-search"><x-lucide-search aria-hidden="true" /><span class="personal-sr">Buscar en Ayuda</span><input type="search" data-help-search placeholder="Busca una pregunta o un tema…" autocomplete="off"></label>
                <div class="personal-filters" role="group" aria-label="Temas de ayuda">
                    @foreach (['all' => 'Todo', 'primeros-pasos' => 'Primeros pasos', 'comunidad' => 'Comunidad', 'lexa' => 'LEXA', 'guardados' => 'Guardados', 'cuenta' => 'Cuenta'] as $id => $label)
                        <button type="button" data-help-category="{{ $id }}" aria-pressed="{{ $loop->first ? 'true' : 'false' }}">{{ $label }}</button>
                    @endforeach
                </div>
                <div class="help-guides" data-help-guides aria-label="Accesos para comenzar">
                    <a class="help-guide" href="{{ route('lexa') }}"><x-lucide-sparkles aria-hidden="true" /><strong>Ordena tus dudas</strong><span>Empieza una consulta con LEXA</span></a>
                    <a class="help-guide" href="{{ route('community') }}"><x-lucide-users aria-hidden="true" /><strong>Participa y comparte</strong><span>Conoce la comunidad</span></a>
                    <a class="help-guide" href="{{ route('saved') }}"><x-lucide-bookmark aria-hidden="true" /><strong>Vuelve a lo importante</strong><span>Encuentra tus guardados</span></a>
                </div>
                <div class="personal-toolbar"><h2 class="personal-section-title" style="margin:0">Preguntas frecuentes</h2><span class="personal-muted" data-help-count role="status">{{ count($questions) }} preguntas</span></div>
                <div class="help-faqs">
                    @foreach ($questions as $question)
                        <details class="help-faq" data-help-question data-category="{{ $question['category'] }}"><summary>{{ $question['question'] }}<x-lucide-chevron-down aria-hidden="true" /></summary><div class="help-answer"><p>{{ $question['answer'] }}</p><a href="{{ route($question['route']) }}">{{ $question['link'] }}<x-lucide-arrow-right aria-hidden="true" /></a></div></details>
                    @endforeach
                </div>
                <div class="personal-empty" data-help-empty hidden><x-lucide-search-x aria-hidden="true" /><h2>No encontramos esa pregunta</h2><p>Prueba con otra palabra o explora todos los temas.</p><button type="button" class="personal-button" data-help-reset>Ver todas las preguntas</button></div>
            </div>
            <aside class="personal-sidebar" aria-label="Más opciones de ayuda">
                <section class="personal-card"><h2>Tu cuenta, a tu manera</h2><p class="personal-muted">Encuentra las opciones de perfil, privacidad y preferencias.</p><a href="{{ route('settings') }}" class="personal-button">Abrir Configuración<x-lucide-arrow-right aria-hidden="true" /></a></section>
                <section class="personal-card"><h2>¿Tienes una duda legal?</h2><p class="personal-muted">Este espacio explica cómo usar la plataforma. Para revisar tu situación, conoce a los profesionales disponibles.</p><a href="{{ route('professionals') }}" class="personal-button">Buscar un profesional<x-lucide-arrow-right aria-hidden="true" /></a></section>
            </aside>
        </div>
    </div>
</section>
@endsection
