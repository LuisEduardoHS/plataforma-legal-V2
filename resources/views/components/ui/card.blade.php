
{{--
    Componente reutilizable de tarjeta (Card).

    Proporciona un contenedor visual para organizar
    información dentro de la Plataforma Legal.

    Se utilizará como base para:
    - Publicaciones de la comunidad.
    - Tarjetas de profesionales.
    - Artículos y recursos informativos.
    - Contenido recomendado en Inicio.

    Los componentes específicos de cada módulo
    podrán utilizar esta tarjeta como contenedor.
--}}

@props([
    'padding' => true,
])

<div {{ $attributes->class([
    'rounded-xl border border-gray-200 bg-white shadow-sm',
    'p-5' => $padding,
]) }}>
    {{ $slot }}
</div>
