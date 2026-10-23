
{{--
    Componente reutilizable de campo de entrada (Input).

    Proporciona un campo de formulario con estilos consistentes
    para las diferentes secciones de la Plataforma Legal.

    Se utilizará en:
    - Inicio de sesión y registro.
    - Formularios de perfil y configuración.
    - Búsquedas de profesionales y publicaciones.
    - Otros formularios de la aplicación.

    Admite diferentes tipos de entrada, etiquetas,
    mensajes de error y atributos HTML adicionales.
--}}

@props([
    'name',
    'label' => null,
    'type' => 'text',
    'placeholder' => '',
    'value' => null,
])

@php
    $inputId = $attributes->get('id', $name);
    $hasError = $errors->has($name);
@endphp

<div class="w-full">
    @if ($label)
        <label
            for="{{ $inputId }}"
            class="mb-2 block text-sm font-medium text-gray-700 dark:text-light-text-dark"
        >
            {{ $label }}
        </label>
    @endif

    <input
        id="{{ $inputId }}"
        name="{{ $name }}"
        type="{{ $type }}"
        placeholder="{{ $placeholder }}"
        @if ($type !== 'password')
            value="{{ old($name, $value) }}"
        @endif
        aria-invalid="{{ $hasError ? 'true' : 'false' }}"
        @if ($hasError)
            aria-describedby="{{ $inputId }}-error"
        @endif
        {{ $attributes->class([
            'w-full rounded-lg border bg-white dark:bg-surface-dark px-4 py-2.5 text-sm text-gray-900 dark:text-text-dark',
            'placeholder:text-gray-400 dark:placeholder:text-light-text-dark transition-colors',
            'focus:outline-none focus:ring-2 focus:ring-[#7F1842]/20',
            'focus:border-[#7F1842] dark:focus:border-focus-dark dark:focus:ring-focus-dark/20',
            'border-red-500' => $hasError,
            'border-gray-300 dark:border-border-dark' => ! $hasError,
        ]) }}
    >

    @error($name)
        <p id="{{ $inputId }}-error" class="mt-1 text-sm text-red-600 dark:text-red-400">
            {{ $message }}
        </p>
    @enderror
</div>
