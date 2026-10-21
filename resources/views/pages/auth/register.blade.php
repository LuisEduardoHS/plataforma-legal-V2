
{{--
    Página de registro de usuarios de la Plataforma Legal.

    Permitirá crear nuevas cuentas dentro de la plataforma.

    Contemplará dos tipos principales de usuarios:

    1. Usuario convencional:
       - Registro mediante datos personales básicos.
       - Correo electrónico y contraseña.
       - Acceso a las funcionalidades de la comunidad.

    2. Profesional legal:
       - Registro de información personal y profesional.
       - Especialidad y ubicación.
       - Presentación de documentación que acredite
         su ejercicio profesional.
       - Proceso de revisión para obtener la verificación.

    Funcionalidades previstas:
       - Validación de formularios.
       - Protección CSRF.
       - Almacenamiento seguro de contraseñas.
       - Verificación de correo electrónico.
       - Gestión de errores de registro.

    La verificación profesional será independiente
    de la creación inicial de la cuenta.

    La interfaz y el proceso de registro se implementarán
    posteriormente mediante Laravel.
--}}

@extends('layouts.guest')

@section('title', 'Crear cuenta | Plataforma Legal')

@section('content')
    <section class="flex min-h-screen items-center justify-center bg-gray-50 px-6">
        <div class="w-full max-w-md rounded-xl bg-white p-8 shadow-sm">

            <h1 class="mb-3 text-center text-3xl font-bold text-[#7F1842]">
                Crear cuenta
            </h1>

            <p class="text-center text-gray-600">
                Únete a nuestra comunidad y accede a información,
                recursos y profesionales legales.
            </p>

            <p class="mt-6 text-center text-sm text-gray-500">
                El formulario de registro se implementará
                próximamente.
            </p>

        </div>
    </section>
@endsection
