
{{--
    Página principal de Guardados.

    Permitirá a los usuarios consultar y administrar el contenido
    que hayan guardado dentro de la plataforma.

    Incluirá publicaciones de la comunidad, artículos y otros
    recursos jurídicos que el usuario desee revisar después.

    Posteriormente se implementarán filtros por tipo de contenido,
    organización de elementos guardados y opciones para eliminarlos
    de esta sección.

    La interfaz y las funcionalidades se implementarán
    posteriormente.
--}}

@extends('layouts.app')

@section('title', 'Guardados | Plataforma Legal')

@section('content')
    <section class="mx-auto max-w-5xl px-6 py-12">
        <h1 class="text-3xl font-bold text-[#7F1842]">
            Guardados
        </h1>

        <p class="mt-3 text-gray-600">
            Consulta las publicaciones, artículos y recursos
            que has guardado para revisar más adelante.
        </p>
    </section>
@endsection
