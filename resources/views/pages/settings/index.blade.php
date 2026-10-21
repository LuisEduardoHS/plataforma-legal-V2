
{{--
    Página principal de Configuración.

    Permitirá a los usuarios administrar las preferencias
    y configuraciones de su cuenta dentro de la plataforma.

    Contemplará las siguientes funcionalidades:

    1. Cuenta:
       - Actualización de datos personales.
       - Cambio de correo electrónico.
       - Administración de información de cuenta.

    2. Seguridad:
       - Cambio de contraseña.
       - Gestión de sesiones activas.
       - Opciones de seguridad de la cuenta.

    3. Privacidad:
       - Preferencias de visibilidad del perfil.
       - Configuración de interacciones y mensajes.

    4. Notificaciones:
       - Preferencias de notificaciones.
       - Avisos de mensajes y actividad.

    5. Apariencia:
       - Preferencias visuales.
       - Configuración de tema claro y oscuro.

    La interfaz y las funcionalidades se implementarán
    posteriormente.

    Mas adelante:

    resources/views/pages/settings/
    └── index.blade.php

    resources/views/components/settings/
    ├── account-form.blade.php
    ├── security-form.blade.php
    ├── privacy-form.blade.php
    └── notification-form.blade.php
--}}

@extends('layouts.app')

@section('title', 'Configuración | Plataforma Legal')

@section('content')
    <section class="mx-auto max-w-5xl px-6 py-12">
        <h1 class="text-3xl font-bold text-[#7F1842]">
            Configuración
        </h1>

        <p class="mt-3 text-gray-600">
            Administra las preferencias de tu cuenta,
            privacidad, seguridad y notificaciones.
        </p>
    </section>
@endsection
