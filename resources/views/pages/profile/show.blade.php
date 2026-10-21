
{{--
    Página de perfil de usuario.

    Mostrará la información pública de los usuarios registrados
    dentro de la Plataforma Legal.

    Contemplará dos tipos principales de perfiles:

    1. Usuario convencional:
       - Información personal y descripción.
       - Publicaciones realizadas.
       - Actividad e interacciones de la comunidad.

    2. Profesional legal verificado:
       - Información profesional y especialidades.
       - Credenciales y estado de verificación.
       - Experiencia y ubicación.
       - Publicaciones y artículos.
       - Opciones para contactar al profesional.

    El contenido se adaptará según el tipo de perfil
    y los permisos de visualización correspondientes.

    La interfaz y las funcionalidades se implementarán
    posteriormente.

    Mas adelante:

    resources/views/components/
    └── professionals/
        ├── verification-badge.blade.php
        ├── professional-info.blade.php
        └── specialty-tag.blade.php
--}}

@extends('layouts.app')

@section('title', 'Perfil | Plataforma Legal')

@section('content')
    <section class="mx-auto max-w-5xl px-6 py-12">
        <h1 class="text-3xl font-bold text-[#7F1842]">
            Perfil
        </h1>

        <p class="mt-3 text-gray-600">
            Aquí se mostrará la información pública del usuario
            y su actividad dentro de la plataforma.
        </p>
    </section>
@endsection
