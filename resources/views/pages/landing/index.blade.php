
{{--
    Landing Page de la Plataforma Legal.

    Será la página principal para los visitantes que
    todavía no hayan iniciado sesión.

    Su objetivo será presentar la plataforma y sus servicios.

    Contemplará las siguientes secciones:

    1. Hero principal:
       - Presentación de la plataforma.
       - Campo para realizar una primera pregunta legal.
       - Acceso a LEXA, nuestro asistente de IA.

    2. Funcionalidades:
       - Acceso a información y recursos legales.
       - Comunidad de usuarios.
       - Directorio de profesionales verificados.
       - Orientación mediante LEXA.

    3. Información adicional:
       - Beneficios de la plataforma.
       - Explicación de su funcionamiento.
       - Invitaciones al registro.

    4. Pie de página:
       - Información institucional.
       - Enlaces de ayuda y contacto.
       - Términos y privacidad.

    La interfaz definitiva se implementará posteriormente.
--}}

@extends('layouts.guest')

@section('title', 'Bienvenido | Plataforma Legal')

@section('content')
    <section class="flex min-h-screen items-center justify-center bg-gray-50 dark:bg-bg-dark px-6">
        <div class="max-w-2xl text-center">

            <h1 class="mb-4 text-4xl font-bold text-[#7F1842] dark:text-accent-text-dark">
                Bienvenido a Plataforma Legal
            </h1>

            <p class="mb-8 text-lg text-gray-600 dark:text-light-text-dark">
                Un espacio para encontrar información,
                orientación y profesionales legales en México.
            </p>

            <div class="inline-flex rounded-lg bg-[#6C4ACB] px-6 py-3 font-medium text-white">
                Conoce a LEXA — Próximamente
            </div>

        </div>
    </section>
@endsection
