
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
            class="mb-2 block text-sm font-medium text-gray-700"
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
            'w-full rounded-lg border bg-white px-4 py-2.5 text-sm text-gray-900',
            'placeholder:text-gray-400 transition-colors',
            'focus:outline-none focus:ring-2 focus:ring-[#7F1842]/20',
            'focus:border-[#7F1842]',
            'border-red-500' => $hasError,
            'border-gray-300' => ! $hasError,
        ]) }}
    >

    @error($name)
        <p id="{{ $inputId }}-error" class="mt-1 text-sm text-red-600">
            {{ $message }}
        </p>
    @enderror
</div>
