
{{--
    Página principal de Artículos y Recursos.

    Mostrará contenido informativo sobre temas legales en México,
    incluyendo artículos, guías y recursos jurídicos.

    Permitirá explorar contenido por categorías, realizar
    búsquedas y consultar artículos publicados por profesionales.

    Cada artículo podrá contar con su propia página de detalle.

    La interfaz y las funcionalidades se implementarán
    posteriormente.

    resources/views/pages/articles/
    ├── index.blade.php     # Listado de artículos y recursos
    ├── show.blade.php      # Lectura de un artículo
    └── create.blade.php    # Formulario de publicación

--}}

@extends('layouts.app')

@section('title', 'Artículos y Recursos | Plataforma Legal')

@section('content')
    <section class="mx-auto max-w-5xl px-6 py-12">
        <h1 class="text-3xl font-bold text-[#7F1842]">
            Artículos y Recursos
        </h1>

        <p class="mt-3 text-gray-600">
            Explora artículos, guías y recursos informativos
            sobre temas legales en México.
        </p>
    </section>
@endsection
