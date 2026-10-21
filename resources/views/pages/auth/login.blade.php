
{{--
    Página de inicio de sesión de la Plataforma Legal.

    Permitirá que los usuarios registrados accedan
    a sus cuentas mediante sus credenciales.

    Funcionalidades previstas:

    1. Inicio de sesión:
       - Correo electrónico.
       - Contraseña.
       - Opción para recordar la sesión.

    2. Recuperación de acceso:
       - Enlace para restablecer contraseña.
       - Acceso a la página de registro.

    3. Seguridad:
       - Validación de credenciales.
       - Protección CSRF.
       - Gestión segura de sesiones.
       - Limitación de intentos de inicio de sesión.

    Posteriormente se implementará la autenticación
    utilizando las herramientas de Laravel.

    Este archivo contiene únicamente la vista temporal.
--}}

@extends('layouts.guest')

@section('title', 'Iniciar sesión | Plataforma Legal')

@section('content')
    <section class="flex min-h-screen items-center justify-center bg-gray-50 px-6">
        <div class="w-full max-w-md rounded-xl bg-white p-8 shadow-sm">

            <h1 class="mb-3 text-center text-3xl font-bold text-[#7F1842]">
                Iniciar sesión
            </h1>

            <p class="text-center text-gray-600">
                Accede a tu cuenta de Plataforma Legal.
            </p>

            <p class="mt-6 text-center text-sm text-gray-500">
                El formulario de inicio de sesión
                se implementará próximamente.
            </p>

        </div>
    </section>
@endsection
