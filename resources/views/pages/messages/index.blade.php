
{{--
    Página principal de Mensajes.

    Permitirá la comunicación privada entre usuarios y
    profesionales legales mediante conversaciones.

    Incluirá una lista de chats, historial de mensajes,
    envío de mensajes y gestión de conversaciones.

    La interfaz y las funcionalidades se implementarán
    posteriormente.
--}}

@extends('layouts.app')

@section('title', 'Mensajes | Plataforma Legal')

@section('content')
    <section class="mx-auto max-w-5xl px-6 py-12">
        <h1 class="text-3xl font-bold text-[#7F1842]">
            Mensajes
        </h1>

        <p class="mt-3 text-gray-600">
            Aquí podrás consultar tus conversaciones y comunicarte
            con otros usuarios y profesionales.
        </p>
    </section>
@endsection
