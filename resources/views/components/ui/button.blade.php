
{{--
    Componente reutilizable de botón.

    Se utilizará para mantener un estilo consistente
    en las acciones de la Plataforma Legal.

    Permite personalizar su tipo, apariencia y contenido.
    Posteriormente se ampliará según el sistema de diseño.
--}}

@props([
    'type' => 'button',
    'variant' => 'primary',
])

@php
    $variants = [
        'primary' => 'bg-[#7F1842] text-white hover:bg-[#9E1F52]',
        'secondary' => 'bg-gray-100 dark:bg-accent-bg-dark text-gray-900 dark:text-text-dark hover:bg-gray-200 dark:hover:bg-accent-bg-hover-dark',
        'lexa' => 'bg-[#6C4ACB] text-white hover:bg-[#593AA9]',
    ];

    $variantClasses = $variants[$variant] ?? $variants['primary'];
@endphp

<button
    type="{{ $type }}"
    {{ $attributes->class([
        'inline-flex items-center justify-center rounded-lg px-5 py-2.5',
        'text-sm font-medium transition-colors',
        'focus-visible:outline-2 focus-visible:outline-offset-2',
        'focus-visible:outline-[#7F1842] dark:focus-visible:outline-focus-dark',
        $variantClasses,
    ]) }}
>
    {{ $slot }}
</button>
