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
            <div class="min-w-0">
                <div data-help-tools hidden>
                    <label class="personal-search !min-h-10 !rounded-full !px-4">
                        <x-lucide-search class="!size-4" aria-hidden="true" />
                        <span class="personal-sr">Buscar en Ayuda</span>
                        <input type="search" data-help-search placeholder="Busca una pregunta o un tema…" autocomplete="off" class="text-sm">
                    </label>
                    <div class="personal-filters !my-3.5 !gap-1.5" role="group" aria-label="Temas de ayuda">
                        @foreach (['all' => 'Todo', 'primeros-pasos' => 'Primeros pasos', 'cuenta' => 'Cuenta', 'perfiles' => 'Perfiles', 'comunidad' => 'Comunidad', 'biblioteca' => 'Biblioteca', 'mensajes' => 'Mensajes', 'verificacion' => 'Verificación', 'lexa' => 'LEXA', 'personal-autorizado' => 'Personal autorizado'] as $id => $label)
                            <button type="button" data-help-category="{{ $id }}" aria-pressed="{{ $loop->first ? 'true' : 'false' }}" class="!min-h-8 !rounded-full !px-3.5 !py-1 !text-xs font-medium">{{ $label }}</button>
                        @endforeach
                    </div>
                </div>
                <div class="help-guides !mb-6" data-help-guides aria-label="Accesos para comenzar">
                    <a class="help-guide transition-shadow hover:shadow-[0_4px_16px_color-mix(in_srgb,var(--color-text)_7%,transparent)]" href="{{ route('lexa') }}">
                        <span class="landing-lexa-wordmark !pl-0 !text-xs">LEXA</span>
                        <strong>Conoce la demo de LEXA</strong>
                        <span>Respuestas de ejemplo, sin IA real.@guest Requiere iniciar sesión.@endguest</span>
                    </a>
                    <a class="help-guide transition-shadow hover:shadow-[0_4px_16px_color-mix(in_srgb,var(--color-text)_7%,transparent)]" href="{{ route('community') }}">
                        <x-lucide-users class="!size-5 text-accent dark:text-accent-text-dark" aria-hidden="true" />
                        <strong>Participa y comparte</strong>
                        <span>Conoce la comunidad</span>
                    </a>
                    <a class="help-guide transition-shadow hover:shadow-[0_4px_16px_color-mix(in_srgb,var(--color-text)_7%,transparent)]" href="{{ route('saved') }}">
                        <x-lucide-bookmark class="!size-5 text-accent dark:text-accent-text-dark" aria-hidden="true" />
                        <strong>Vuelve a lo importante</strong>
                        <span>Encuentra tus guardados.@guest Requiere iniciar sesión.@endguest</span>
                    </a>
                </div>
                <div class="personal-toolbar !mb-3">
                    <h2 class="!m-0 text-sm font-semibold text-text dark:text-text-dark">Preguntas frecuentes</h2>
                    <span class="text-xs text-light-text dark:text-light-text-dark" data-help-count role="status">{{ count($questions) }} preguntas</span>
                </div>
                <div class="help-faqs">
                    @foreach ($questions as $question)
                        <details class="help-faq" data-help-question data-category="{{ $question['category'] }}">
                            <summary class="!py-4 !px-5 !text-[14.5px]">{{ $question['question'] }}<x-lucide-chevron-down aria-hidden="true" /></summary>
                            <div class="help-answer !px-5 !pb-5">
                                <span class="help-availability !mb-2 !inline-flex items-center !rounded-full !bg-accent/10 !px-2.5 !py-0.5 !text-[11px] font-semibold !text-accent dark:!bg-selected-dark dark:!text-accent-text-dark">{{ ['available' => 'Disponible', 'configuration' => 'Requiere configuración de entrega', 'demo' => 'Demostración', 'future' => 'Funcionalidad futura'][$question['status']] }}</span>
                                <p>{{ $question['answer'] }}</p>
                                @if (isset($question['route']))
                                    <a class="!text-accent dark:!text-accent-text-dark" href="{{ route($question['route']) }}">
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
                <div class="personal-empty" data-help-empty hidden>
                    <x-lucide-search-x aria-hidden="true" />
                    <h2>No encontramos esa pregunta</h2>
                    <p>Prueba con otra palabra o explora todos los temas.</p>
                    <button type="button" class="inline-flex min-h-9 items-center justify-center rounded-[var(--radius-ui)] bg-light-hover px-4 text-xs font-semibold text-accent transition-colors hover:bg-accent/10 dark:bg-accent-bg-dark dark:text-accent-text-dark" data-help-reset>Ver todas las preguntas</button>
                </div>
            </div>
            <aside class="personal-sidebar" aria-label="Más opciones de ayuda">
                <section>
                    <h2 class="mb-2 text-sm font-semibold text-text dark:text-text-dark">Tu cuenta, a tu manera</h2>
                    <div class="rounded-[var(--radius-ui)] bg-white p-3.5 dark:bg-surface-dark">
                        <p class="mb-3 text-xs leading-relaxed text-light-text dark:text-light-text-dark">Consulta los accesos disponibles y las opciones que siguen pendientes. Las próximas funciones se muestran deshabilitadas.</p>
                        <a href="{{ route('settings') }}" class="inline-flex min-h-9 w-full items-center justify-center gap-1.5 rounded-[var(--radius-ui)] bg-light-hover px-3 text-xs font-semibold text-accent transition-colors hover:bg-accent/10 dark:bg-accent-bg-dark dark:text-accent-text-dark dark:hover:bg-selected-dark">
                            Abrir Configuración
                            @guest
                                · Requiere iniciar sesión
                            @endguest
                            <x-lucide-arrow-right class="!size-3.5" aria-hidden="true" />
                        </a>
                    </div>
                </section>
                <section>
                    <h2 class="mb-2 text-sm font-semibold text-text dark:text-text-dark">¿Tienes una duda legal?</h2>
                    <div class="rounded-[var(--radius-ui)] bg-white p-3.5 dark:bg-surface-dark">
                        <p class="mb-3 text-xs leading-relaxed text-light-text dark:text-light-text-dark">Este espacio explica cómo usar la plataforma. Para revisar tu situación, conoce a los profesionales disponibles.</p>
                        <a href="{{ route('professionals') }}" class="inline-flex min-h-9 w-full items-center justify-center gap-1.5 rounded-[var(--radius-ui)] bg-light-hover px-3 text-xs font-semibold text-accent transition-colors hover:bg-accent/10 dark:bg-accent-bg-dark dark:text-accent-text-dark dark:hover:bg-selected-dark">Buscar un profesional <x-lucide-arrow-right class="!size-3.5" aria-hidden="true" /></a>
                    </div>
                </section>
            </aside>
        </div>
    </div>
</section>
@endsection
