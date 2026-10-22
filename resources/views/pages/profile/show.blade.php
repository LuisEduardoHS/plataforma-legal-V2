
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

@section('title', 'Perfil | LEXA')

@section('content')

@php
    // Simulando los datos que normalmente vendrían del Controlador
    $recommendations = [
        ['name' => 'Noé Medina', 'location' => 'San Nicolás de los...', 'followers' => 184],
        ['name' => 'Noé Medina', 'location' => 'San Nicolás de los...', 'followers' => 184],
        ['name' => 'Noé Medina', 'location' => 'San Nicolás de los...', 'followers' => 184],
        ['name' => 'Noé Medina', 'location' => 'San Nicolás de los...', 'followers' => 184],
    ];

    $posts = [
        [
            'id' => 1,
            'author' => 'Juanito Pérez',
            'tag' => 'Arrendamiento',
            'time' => 'Hace 3 h',
            'title' => '¿Mi arrendador puede pedirme que desocupe antes de que termine mi contrato?',
            'excerpt' => 'Tengo contrato hasta diciembre, pero el propietario me pidió entregar la casa el próximo mes porque...',
            'replies' => 14,
        ],
        [
            'id' => 2,
            'author' => 'Juanito Pérez',
            'tag' => 'Arrendamiento',
            'time' => 'Hace 3 h',
            'title' => '¿Mi arrendador puede pedirme que desocupe antes de que termine mi contrato?',
            'excerpt' => 'Tengo contrato hasta diciembre, pero el propietario me pidió entregar la casa el próximo mes porque...',
            'replies' => 14,
        ],
    ];
@endphp

<div x-data="{ 
        isFollowing: false, 
        bookmarkedPosts: {}, 
        followingUsers: {} 
    }" 
    class="min-h-screen bg-bg flex flex-col font-sans pb-24 lg:pb-12 text-text">

    <main class="w-full max-w-[1080px] mx-auto px-4 sm:px-6 pt-6">
        <div class="flex flex-col lg:flex-row gap-5 items-start">
          
            {{-- Columna Principal --}}
            <div class="w-full lg:flex-1 flex flex-col gap-5">

                {{-- Tarjeta de Perfil --}}
                <section class="bg-white rounded-2xl overflow-hidden shadow-xs border border-gray-100/50">
                    {{-- Banner Decorativo --}}
                    <div class="h-36 sm:h-44 w-full relative overflow-hidden bg-light-accent">
                        <svg class="absolute inset-0 w-full h-full pointer-events-none" viewBox="0 0 523 119" preserveAspectRatio="none" fill="none">
                            <path d="M 190 119 C 260 55, 330 65, 380 84 C 430 102, 470 90, 523 48 L 523 119 Z" fill="#9E1F52" opacity="0.95"/>
                        </svg>
                    </div>

                    {{-- Cuerpo del Perfil --}}
                    <div class="relative px-6 pb-5 pt-3">
                        <div class="flex flex-col sm:flex-row items-start gap-4">
                            
                            {{-- Avatar Grande --}}
                            <div class="-mt-16 sm:-mt-20 shrink-0">
                                <div class="size-24 sm:size-28 rounded-full border-4 border-white bg-[#EEEEEE] overflow-hidden shrink-0 shadow-xs flex items-center justify-center">
                                    <svg viewBox="0 0 100 100" class="w-full h-full" fill="#D3D3D3">
                                        <circle cx="50" cy="35" r="17" />
                                        <path d="M 18 88 C 18 64, 32 58, 50 58 C 68 58, 82 64, 82 88 Z" />
                                    </svg>
                                </div>
                            </div>

                            {{-- Información y Botones --}}
                            <div class="flex-1 w-full flex flex-col justify-between pt-1">
                                <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-3">
                                    <div>
                                        <h1 class="text-xl sm:text-2xl font-bold text-text tracking-tight leading-tight">
                                            Juanito Pérez
                                        </h1>
                                        <div class="flex items-center gap-1.5 text-xs text-light-text mt-1">
                                            <x-lucide-map-pin class="w-3.5 h-3.5 shrink-0" stroke-width="1.8" />
                                            <span>Monterrey, Nuevo León, México</span>
                                        </div>
                                    </div>

                                    {{-- Botones de Acción --}}
                                    <div class="flex items-center gap-2.5 self-start sm:self-center">
                                        <button
                                            @click="isFollowing = !isFollowing"
                                            :class="isFollowing 
                                                ? 'bg-gray-100 text-text border border-gray-300 hover:bg-gray-200' 
                                                : 'bg-dark-accent hover:opacity-90 text-white'"
                                            class="text-xs sm:text-sm font-semibold px-7 py-1.5 rounded-full cursor-pointer transition-all duration-200 shadow-xs"
                                        >
                                            <span x-text="isFollowing ? 'Siguiendo' : 'Seguir'"></span>
                                        </button>
                                        
                                        <button class="bg-dark-accent hover:opacity-90 transition-opacity text-white size-8 sm:size-8.5 rounded-full flex items-center justify-center cursor-pointer shadow-xs shrink-0" title="Enviar mensaje">
                                            <x-lucide-message-circle class="w-4 h-4" stroke-width="2" />
                                        </button>
                                    </div>
                                </div>

                                {{-- Estadísticas --}}
                                <div class="flex items-center gap-4 sm:gap-5 mt-2.5 text-xs text-light-text">
                                    <div class="flex items-center gap-1">
                                        <span class="font-bold text-text text-xs sm:text-sm" x-text="isFollowing ? 85 : 84">84</span>
                                        <span>Seguidores</span>
                                    </div>
                                    <div class="flex items-center gap-1">
                                        <span class="font-bold text-text text-xs sm:text-sm">43</span>
                                        <span>Seguidos</span>
                                    </div>
                                    <div class="flex items-center gap-1">
                                        <span class="font-bold text-text text-xs sm:text-sm">12</span>
                                        <span>Publicaciones</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                {{-- Grid Inferior: Datos y Publicaciones --}}
                <div class="grid grid-cols-1 md:grid-cols-12 gap-5 items-start">
                  
                    {{-- Datos personales --}}
                    <div class="md:col-span-4">
                        <section class="bg-white rounded-2xl p-5 shadow-xs border border-gray-100/50">
                            <h2 class="text-xs font-semibold text-light-text mb-4">Datos personales</h2>
                            <ul class="flex flex-col gap-3.5 text-xs text-text">
                                <li class="flex items-center gap-2.5">
                                    <x-lucide-home class="w-[18px] h-[18px] text-light-text shrink-0" stroke-width="1.8" />
                                    <span>Vive en <strong class="font-bold text-text">Monterrey</strong></span>
                                </li>
                                <li class="flex items-center gap-2.5">
                                    <x-lucide-map-pin class="w-[18px] h-[18px] text-light-text shrink-0" stroke-width="1.8" />
                                    <span>Es de <strong class="font-bold text-text">Monterrey</strong></span>
                                </li>
                                <li class="flex items-center gap-2.5">
                                    <svg class="w-[18px] h-[18px] text-light-text shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="9" cy="15" r="5.5" />
                                        <circle cx="15" cy="9" r="5.5" />
                                    </svg>
                                    <span class="text-light-text font-normal">Hombre</span>
                                </li>
                            </ul>
                        </section>
                    </div>

                    {{-- Feed de Publicaciones --}}
                    <div class="md:col-span-8 flex flex-col gap-4">
                        @foreach($posts as $post)
                            <article class="bg-white rounded-2xl p-5 shadow-xs border border-gray-100/50">
                                
                                <div class="flex items-start justify-between">
                                    <div class="flex items-center gap-3">
                                        <div class="size-9 rounded-full bg-[#757575] shrink-0"></div>
                                        <div>
                                            <h3 class="font-bold text-xs sm:text-sm text-text leading-tight">{{ $post['author'] }}</h3>
                                            <div class="flex items-center gap-1.5 text-xs mt-0.5">
                                                <span class="bg-[#EEEEEE] text-light-text px-2 py-0.5 rounded text-[11px] font-normal">{{ $post['tag'] }}</span>
                                                <span class="text-light-text text-[10px]">·</span>
                                                <span class="text-light-text text-xs">{{ $post['time'] }}</span>
                                            </div>
                                        </div>
                                    </div>

                                    {{-- Botón Guardar (Bookmark) dinámico --}}
                                    <button
                                        @click="bookmarkedPosts[{{ $post['id'] }}] = !bookmarkedPosts[{{ $post['id'] }}]"
                                        class="text-dark-accent hover:opacity-80 transition-opacity cursor-pointer p-0.5"
                                        title="Guardar publicación"
                                    >
                                        <x-lucide-bookmark 
                                            class="w-5 h-5 transition-all duration-200" 
                                            stroke-width="1.8" 
                                            ::fill="bookmarkedPosts[{{ $post['id'] }}] ? '#7F1842' : 'none'" 
                                        />
                                    </button>
                                </div>

                                <h4 class="font-bold text-sm sm:text-base text-text mt-3.5 leading-snug">{{ $post['title'] }}</h4>
                                <p class="text-xs sm:text-sm text-light-text mt-1.5 leading-relaxed">{{ $post['excerpt'] }}</p>

                                <hr class="border-t border-[#EEEEEE] my-3.5 mx-0.5" />

                                <div class="flex items-center justify-between text-xs">
                                    <div class="flex items-center gap-1.5 text-light-text">
                                        <x-lucide-message-circle class="w-4 h-4 text-dark-accent" stroke-width="1.8" />
                                        <span>{{ $post['replies'] }} respuestas</span>
                                    </div>
                                    <a href="#" class="font-semibold text-dark-accent hover:underline flex items-center gap-1 cursor-pointer">
                                        <span>Ver publicación</span>
                                        <span class="text-sm">→</span>
                                    </a>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Sidebar Derecho: Más para ti --}}
            <aside class="w-full lg:w-60 xl:w-64 shrink-0">
                <div class="bg-white rounded-2xl p-4 sm:p-5 shadow-xs border border-gray-100/50">
                    <h2 class="font-bold text-sm sm:text-base text-text mb-3.5 px-0.5">Más para ti</h2>

                    <div class="flex flex-col gap-3">
                        @foreach($recommendations as $index => $rec)
                            <div class="bg-[#FAFAFA] rounded-xl p-3 border border-gray-100/60">
                                <div class="flex items-start gap-2.5">
                                    
                                    {{-- Avatar Pequeño --}}
                                    <div class="size-9 rounded-full bg-[#EEEEEE] overflow-hidden shrink-0 flex items-center justify-center">
                                        <svg viewBox="0 0 100 100" class="w-full h-full" fill="#D3D3D3">
                                            <circle cx="50" cy="35" r="17" />
                                            <path d="M 18 88 C 18 64, 32 58, 50 58 C 68 58, 82 64, 82 88 Z" />
                                        </svg>
                                    </div>

                                    <div class="min-w-0 flex-1">
                                        <h3 class="font-bold text-xs sm:text-sm text-text truncate leading-tight">{{ $rec['name'] }}</h3>
                                        <p class="text-[11px] text-light-text truncate leading-tight mt-0.5">{{ $rec['location'] }}</p>
                                        <p class="text-[11px] text-light-text mt-0.5">
                                            <strong class="font-bold text-text" x-text="followingUsers[{{ $index }}] ? {{ $rec['followers'] + 1 }} : {{ $rec['followers'] }}">
                                                {{ $rec['followers'] }}
                                            </strong> Seguidores
                                        </p>
                                    </div>
                                </div>

                                {{-- Botón de Seguir Recomendación dinámico --}}
                                <button
                                    @click="followingUsers[{{ $index }}] = !followingUsers[{{ $index }}]"
                                    :class="followingUsers[{{ $index }}] 
                                        ? 'bg-gray-100 text-text border border-gray-300 hover:bg-gray-200' 
                                        : 'bg-dark-accent hover:opacity-90 text-white'"
                                    class="w-full mt-2.5 text-xs font-semibold py-1.5 rounded-full cursor-pointer transition-all duration-200 text-center shadow-xs"
                                >
                                    <span x-text="followingUsers[{{ $index }}] ? 'Siguiendo' : 'Seguir'"></span>
                                </button>
                            </div>
                        @endforeach
                    </div>
                </div>
            </aside>
        </div>
    </main>
</div>
@endsection