{{--
    Página de inicio de la Plataforma Legal.

    Mostrará el panel principal de los usuarios autenticados,
    incluyendo acceso a LEXA, contenido recomendado, actividad
    de la comunidad y consultas recientes.

    Actualmente contiene una interfaz temporal de prueba.
--}}

@extends('layouts.app')

@section('title', 'Inicio | Plataforma Legal')

@section('content')
    <section class="flex min-h-screen items-center justify-center bg-gray-50 px-6">
        <div class="max-w-xl text-center">
            <h1 class="mb-4 text-4x1 font-bold text-[#7F1842]">
                Plataforma Legal
            </h1>

            <p class="mb-6 text-lg text-gray-600">
                Nuestra aplicación Laravel está funcionando
                correctamente con Blade y Tailwind CSS.
            </p>

            <div class="inline-flex rounded-lg bg-[#6C4ACB] px-6 py-3 font-medium text-white">
                LEXA - Próximamente
            </div>
        </div>
    </section>
@endsection