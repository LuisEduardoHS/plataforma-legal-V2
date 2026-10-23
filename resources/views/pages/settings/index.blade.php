
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

@section('title', 'Configuración | LEXA')

@section('content')
{{-- x-data engloba todos los estados de la página --}}
<div x-data="{ 
        activeTab: 'profile',
        searchQuery: '',
        showLogoutModal: false,
        
        username: 'Juanito Pérez',
        birthDate: '',
        showDatePicker: false,
        email: 'juanitoperez@gmail.com',
        password: '•••••••',
        hasGoogleLinked: true,
        
        isEditingPersonalData: false,
        personalData: { livesIn: 'Monterrey', from: 'Monterrey', gender: 'Hombre' },
        
        privacySettings: { profileVisibility: 'public', directMessages: true, showOnlineStatus: true, searchIndexing: false, allowTags: true },
        
        twoFactorEnabled: false,
        loginAlerts: true,
        
        preferences: { language: 'es', theme: 'light', autoplay: true },
        
        accessibility: { fontSize: 'normal', highContrast: false, reduceMotion: false, subtitles: true },
        
        notifications: { email: true, push: true, comments: true, directMessages: true, weeklyDigest: false },
        
        savedToast: null,
        triggerToast(msg) {
            this.savedToast = msg;
            setTimeout(() => { this.savedToast = null; }, 2500);
        }
    }" 
    class="min-h-screen bg-bg dark:bg-bg-dark flex flex-col font-sans pb-24 lg:pb-16 text-text dark:text-text-dark relative"
    x-cloak>

    {{-- Toast Alert --}}
    <div x-show="savedToast" 
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
         x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100 sm:scale-100"
         x-transition:leave-end="opacity-0 sm:scale-95"
         class="fixed top-5 right-5 z-50 bg-dark-accent dark:bg-light-accent-dark text-white dark:text-black px-4 py-2.5 rounded-xl shadow-lg flex items-center gap-2 text-xs font-medium"
         style="display: none;">
        <x-lucide-check class="w-4 h-4" stroke-width="2" />
        <span x-text="savedToast"></span>
    </div>

    {{-- Main Container --}}
    <main class="w-full max-w-[1080px] mx-auto px-4 sm:px-6 pt-7">
        <div class="flex flex-col lg:flex-row gap-5 items-start">

            {{-- Left Sidebar Menu Card --}}
            <aside class="w-full lg:w-64 xl:w-72 shrink-0">
                <div class="bg-white dark:bg-black rounded-2xl p-5 sm:p-6 shadow-xs border border-gray-100/50 dark:border-gray-800/50 flex flex-col justify-between min-h-[520px]">
                    <div>
                        {{-- Title --}}
                        <h1 class="font-bold text-base sm:text-lg text-text dark:text-text-dark">Configuración</h1>

                        {{-- Search Bar --}}
                        <div class="relative mt-3.5 mb-5">
                            <x-lucide-search class="absolute left-3 top-1/2 -translate-y-1/2 w-[15px] h-[15px] text-light-text dark:text-light-text-dark stroke-[1.8] pointer-events-none" />
                            <input
                                type="text"
                                x-model="searchQuery"
                                placeholder="Buscar"
                                class="w-full bg-[#EEEEEE] dark:bg-gray-800 placeholder:text-light-text dark:placeholder:text-light-text-dark text-text dark:text-text-dark text-xs rounded-xl pl-9 pr-3 py-2 outline-none focus:ring-1 focus:ring-dark-accent/40 dark:focus:ring-light-accent-dark/40 transition-all"
                            />
                            <button
                                x-show="searchQuery !== ''"
                                @click="searchQuery = ''"
                                class="absolute right-2.5 top-1/2 -translate-y-1/2 text-light-text dark:text-light-text-dark hover:text-text dark:hover:text-text-dark cursor-pointer"
                                style="display: none;"
                            >
                                <x-lucide-x class="w-[13px] h-[13px]" />
                            </button>
                        </div>

                        {{-- Navigation Sections --}}
                        <nav class="flex flex-col gap-4">
                            
                            {{-- Grupo: Tu cuenta --}}
                            <div x-show="searchQuery === '' || 'perfil privacidad seguridad'.includes(searchQuery.toLowerCase())">
                                <h2 class="text-xs font-semibold text-light-text dark:text-light-text-dark mb-2 px-1">Tu cuenta</h2>
                                <div class="flex flex-col gap-1">
                                    <button x-show="searchQuery === '' || 'perfil'.includes(searchQuery.toLowerCase())"
                                        @click="activeTab = 'profile'"
                                        :class="activeTab === 'profile' ? 'bg-[#EEEEEE] dark:bg-gray-800 text-text dark:text-text-dark font-bold shadow-2xs' : 'text-light-text dark:text-light-text-dark hover:bg-gray-50 dark:hover:bg-gray-900 hover:text-text dark:hover:text-text-dark font-medium'"
                                        class="w-full flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-xs sm:text-[13px] transition-colors duration-150 cursor-pointer text-left">
                                        <x-lucide-user class="w-[18px] h-[18px] shrink-0 stroke-[1.8]" ::class="activeTab === 'profile' ? 'text-text dark:text-text-dark' : 'text-light-text dark:text-light-text-dark'" />
                                        <span>Perfil</span>
                                    </button>
                                    <button x-show="searchQuery === '' || 'privacidad'.includes(searchQuery.toLowerCase())"
                                        @click="activeTab = 'privacy'"
                                        :class="activeTab === 'privacy' ? 'bg-[#EEEEEE] dark:bg-gray-800 text-text dark:text-text-dark font-bold shadow-2xs' : 'text-light-text dark:text-light-text-dark hover:bg-gray-50 dark:hover:bg-gray-900 hover:text-text dark:hover:text-text-dark font-medium'"
                                        class="w-full flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-xs sm:text-[13px] transition-colors duration-150 cursor-pointer text-left">
                                        <x-lucide-lock class="w-[18px] h-[18px] shrink-0 stroke-[1.8]" ::class="activeTab === 'privacy' ? 'text-text dark:text-text-dark' : 'text-light-text dark:text-light-text-dark'" />
                                        <span>Privacidad</span>
                                    </button>
                                    <button x-show="searchQuery === '' || 'seguridad'.includes(searchQuery.toLowerCase())"
                                        @click="activeTab = 'security'"
                                        :class="activeTab === 'security' ? 'bg-[#EEEEEE] dark:bg-gray-800 text-text dark:text-text-dark font-bold shadow-2xs' : 'text-light-text dark:text-light-text-dark hover:bg-gray-50 dark:hover:bg-gray-900 hover:text-text dark:hover:text-text-dark font-medium'"
                                        class="w-full flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-xs sm:text-[13px] transition-colors duration-150 cursor-pointer text-left">
                                        <x-lucide-shield class="w-[18px] h-[18px] shrink-0 stroke-[1.8]" ::class="activeTab === 'security' ? 'text-text dark:text-text-dark' : 'text-light-text dark:text-light-text-dark'" />
                                        <span>Seguridad</span>
                                    </button>
                                </div>
                            </div>

                            {{-- Grupo: Preferencias --}}
                            <div x-show="searchQuery === '' || 'preferencias accesibilidad notificaciones'.includes(searchQuery.toLowerCase())">
                                <h2 class="text-xs font-semibold text-light-text dark:text-light-text-dark mb-2 px-1">Preferencias</h2>
                                <div class="flex flex-col gap-1">
                                    <button x-show="searchQuery === '' || 'preferencias'.includes(searchQuery.toLowerCase())"
                                        @click="activeTab = 'preferences'"
                                        :class="activeTab === 'preferences' ? 'bg-[#EEEEEE] dark:bg-gray-800 text-text dark:text-text-dark font-bold shadow-2xs' : 'text-light-text dark:text-light-text-dark hover:bg-gray-50 dark:hover:bg-gray-900 hover:text-text dark:hover:text-text-dark font-medium'"
                                        class="w-full flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-xs sm:text-[13px] transition-colors duration-150 cursor-pointer text-left">
                                        <x-lucide-sliders-horizontal class="w-[18px] h-[18px] shrink-0 stroke-[1.8]" ::class="activeTab === 'preferences' ? 'text-text dark:text-text-dark' : 'text-light-text dark:text-light-text-dark'" />
                                        <span>Preferencias</span>
                                    </button>
                                    <button x-show="searchQuery === '' || 'accesibilidad'.includes(searchQuery.toLowerCase())"
                                        @click="activeTab = 'accessibility'"
                                        :class="activeTab === 'accessibility' ? 'bg-[#EEEEEE] dark:bg-gray-800 text-text dark:text-text-dark font-bold shadow-2xs' : 'text-light-text dark:text-light-text-dark hover:bg-gray-50 dark:hover:bg-gray-900 hover:text-text dark:hover:text-text-dark font-medium'"
                                        class="w-full flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-xs sm:text-[13px] transition-colors duration-150 cursor-pointer text-left">
                                        <x-lucide-accessibility class="w-[18px] h-[18px] shrink-0 stroke-[1.8]" ::class="activeTab === 'accessibility' ? 'text-text dark:text-text-dark' : 'text-light-text dark:text-light-text-dark'" />
                                        <span>Accesibilidad</span>
                                    </button>
                                    <button x-show="searchQuery === '' || 'notificaciones'.includes(searchQuery.toLowerCase())"
                                        @click="activeTab = 'notifications'"
                                        :class="activeTab === 'notifications' ? 'bg-[#EEEEEE] dark:bg-gray-800 text-text dark:text-text-dark font-bold shadow-2xs' : 'text-light-text dark:text-light-text-dark hover:bg-gray-50 dark:hover:bg-gray-900 hover:text-text dark:hover:text-text-dark font-medium'"
                                        class="w-full flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-xs sm:text-[13px] transition-colors duration-150 cursor-pointer text-left">
                                        <x-lucide-bell class="w-[18px] h-[18px] shrink-0 stroke-[1.8]" ::class="activeTab === 'notifications' ? 'text-text dark:text-text-dark' : 'text-light-text dark:text-light-text-dark'" />
                                        <span>Notificaciones</span>
                                    </button>
                                </div>
                            </div>
                        </nav>
                    </div>

                    {{-- Bottom "Cerrar sesión" button --}}
                    <div class="pt-6">
                        <button
                            @click="showLogoutModal = true"
                            class="w-full bg-dark-accent dark:bg-light-accent-dark hover:opacity-90 transition-opacity text-white dark:text-black font-semibold text-xs py-2.5 px-4 rounded-xl cursor-pointer shadow-xs text-center"
                        >
                            Cerrar sesión
                        </button>
                    </div>
                </div>
            </aside>

            {{-- Right Main Content Card --}}
            <section class="flex-1 w-full">
                <div class="bg-white dark:bg-black rounded-2xl p-5 sm:p-6 shadow-xs border border-gray-100/50 dark:border-gray-800/50 min-h-[520px]">

                    {{-- TAB 1: PERFIL --}}
                    <div x-show="activeTab === 'profile'">
                        <h2 class="font-bold text-base text-text dark:text-text-dark mb-4">Editar perfil</h2>

                        {{-- Banner + Avatar Section --}}
                        <div class="relative mb-5">
                            <div class="h-28 sm:h-32 md:h-36 w-full relative overflow-hidden bg-light-accent dark:bg-light-accent-dark rounded-2xl">
                                <svg class="absolute inset-0 w-full h-full pointer-events-none" viewBox="0 0 523 119" preserveAspectRatio="none" fill="none">
                                    <path d="M 190 119 C 260 55, 330 65, 380 84 C 430 102, 470 90, 523 48 L 523 119 Z" fill="#9E1F52" opacity="0.95"/>
                                </svg>
                                <button @click="triggerToast('Cambiar foto de portada')" class="absolute top-3 right-3 sm:top-3.5 sm:right-4 size-7 sm:size-7.5 rounded-full bg-white dark:bg-gray-800 shadow-sm flex items-center justify-center cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                                    <x-lucide-pencil class="w-[13px] h-[13px] text-[#555555] dark:text-gray-300 stroke-[1.8]" />
                                </button>
                            </div>

                            <div class="flex flex-col sm:flex-row items-center sm:items-start gap-4 px-2">
                                <div class="-mt-14 sm:-mt-16 ml-3 sm:ml-4 shrink-0 relative">
                                    <div class="size-24 sm:size-28 rounded-full border-4 border-white dark:border-black bg-[#EEEEEE] dark:bg-gray-800 overflow-hidden shrink-0 shadow-xs flex items-center justify-center">
                                        <svg viewBox="0 0 100 100" class="w-full h-full" fill="#D3D3D3">
                                            <circle cx="50" cy="35" r="17" />
                                            <path d="M 18 88 C 18 64, 32 58, 50 58 C 68 58, 82 64, 82 88 Z" />
                                        </svg>
                                    </div>
                                    <button @click="triggerToast('Cambiar foto de perfil')" class="absolute top-2 -right-0.5 sm:top-2.5 sm:right-0 size-6 sm:size-6.5 rounded-full bg-white dark:bg-gray-800 shadow-sm border border-gray-100 dark:border-gray-700 flex items-center justify-center cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                                        <x-lucide-pencil class="w-[11px] h-[11px] text-[#555555] dark:text-gray-300 stroke-[1.8]" />
                                    </button>
                                </div>

                                <div class="pt-1.5 sm:pt-2 text-center sm:text-left">
                                    <h3 class="text-xl sm:text-2xl font-bold text-text dark:text-text-dark tracking-tight leading-tight" x-text="username"></h3>
                                    <div class="flex items-center justify-center sm:justify-start gap-1 text-xs text-light-text dark:text-light-text-dark mt-0.5">
                                        <x-lucide-map-pin class="w-[14px] h-[14px] shrink-0 stroke-[1.8]" />
                                        <span x-text="personalData.livesIn + ', Nuevo León, México'"></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-12 gap-6 items-start mt-6">
                            {{-- Datos personales --}}
                            <div class="md:col-span-5 lg:col-span-4">
                                <div class="flex items-center gap-1.5 mb-3.5">
                                    <h4 class="text-xs font-semibold text-light-text dark:text-light-text-dark">Datos personales</h4>
                                    <button @click="isEditingPersonalData = true" class="text-light-text dark:text-light-text-dark hover:text-text dark:hover:text-text-dark cursor-pointer p-0.5">
                                        <x-lucide-pencil class="w-[13px] h-[13px] stroke-[1.8]" />
                                    </button>
                                </div>
                                <ul class="flex flex-col gap-3 text-xs text-text dark:text-text-dark">
                                    <li class="flex items-center gap-2.5">
                                        <x-lucide-home class="w-[17px] h-[17px] text-light-text dark:text-light-text-dark stroke-[1.8] shrink-0" />
                                        <span>Vives en <strong class="font-bold text-text dark:text-text-dark" x-text="personalData.livesIn"></strong></span>
                                    </li>
                                    <li class="flex items-center gap-2.5">
                                        <x-lucide-map-pin class="w-[17px] h-[17px] text-light-text dark:text-light-text-dark stroke-[1.8] shrink-0" />
                                        <span>Eres de <strong class="font-bold text-text dark:text-text-dark" x-text="personalData.from"></strong></span>
                                    </li>
                                    <li class="flex items-center gap-2.5">
                                        <svg class="w-[17px] h-[17px] text-light-text dark:text-light-text-dark shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="9" cy="15" r="5.5" />
                                            <circle cx="15" cy="9" r="5.5" />
                                        </svg>
                                        <span class="text-light-text dark:text-light-text-dark" x-text="personalData.gender"></span>
                                    </li>
                                </ul>
                            </div>

                            {{-- Form Fields --}}
                            <div class="md:col-span-7 lg:col-span-8 flex flex-col gap-3.5">
                                <div>
                                    <label class="block text-xs text-light-text dark:text-light-text-dark mb-1">Nombre de usuario</label>
                                    <input type="text" x-model="username" class="w-full bg-[#EEEEEE] dark:bg-gray-800 text-text dark:text-text-dark font-bold text-xs sm:text-[13px] px-3.5 py-2.5 rounded-xl outline-none focus:ring-1 focus:ring-dark-accent/40 dark:focus:ring-light-accent-dark/40 transition-all" />
                                </div>
                                
                                <div>
                                    <label class="block text-xs text-light-text dark:text-light-text-dark mb-1">Fecha de nacimiento</label>
                                    <div class="relative w-36 sm:w-40">
                                        <button @click="showDatePicker = !showDatePicker" type="button" class="w-full h-9 bg-[#EEEEEE] dark:bg-gray-800 text-text dark:text-text-dark font-bold text-xs px-3.5 py-2 rounded-xl flex items-center justify-between cursor-pointer outline-none">
                                            <span x-text="birthDate"></span>
                                            <div class="text-gray-500 flex items-center justify-center">
                                                <svg class="w-3.5 h-3.5 fill-gray-500 dark:fill-gray-400" viewBox="0 0 20 20">
                                                    <path d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" />
                                                </svg>
                                            </div>
                                        </button>
                                        <div x-show="showDatePicker" @click.away="showDatePicker = false" class="absolute top-11 left-0 z-20 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl p-3 shadow-lg w-52" style="display: none;">
                                            <label class="block text-[11px] text-light-text dark:text-light-text-dark mb-1">Seleccionar fecha:</label>
                                            <input type="date" x-model="birthDate" @change="showDatePicker = false; triggerToast('Fecha de nacimiento actualizada')" class="w-full bg-transparent text-text dark:text-text-dark text-xs p-1.5 border border-gray-200 dark:border-gray-700 rounded-lg outline-none" />
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-xs text-light-text dark:text-light-text-dark mb-1">Correo electrónico</label>
                                    <input type="email" x-model="email" class="w-full bg-[#EEEEEE] dark:bg-gray-800 text-text dark:text-text-dark font-bold text-xs sm:text-[13px] px-3.5 py-2.5 rounded-xl outline-none focus:ring-1 focus:ring-dark-accent/40 dark:focus:ring-light-accent-dark/40 transition-all" />
                                </div>

                                <div>
                                    <label class="block text-xs text-light-text dark:text-light-text-dark mb-1">Contraseña</label>
                                    <input type="password" x-model="password" class="w-full bg-[#EEEEEE] dark:bg-gray-800 text-text dark:text-text-dark font-bold text-xs sm:text-[13px] px-3.5 py-2.5 rounded-xl outline-none focus:ring-1 focus:ring-dark-accent/40 dark:focus:ring-light-accent-dark/40 transition-all tracking-widest" />
                                </div>
                            </div>
                        </div>

                        {{-- Cuentas vinculadas --}}
                        <div class="mt-7 pt-2">
                            <h4 class="text-xs font-semibold text-text dark:text-text-dark mb-2 px-0.5">Cuentas vinculadas</h4>
                            
                            <div x-show="hasGoogleLinked" class="w-full bg-[#EEEEEE] dark:bg-gray-800 rounded-xl px-4 py-2.5 flex items-center justify-between">
                                <div class="flex items-center gap-2.5">
                                    <svg class="w-5 h-5 shrink-0" viewBox="0 0 24 24">
                                        <path fill="#757575" d="M12.48 10.92v3.28h7.84c-.24 1.84-.853 3.187-1.787 4.133-1.147 1.147-2.933 2.4-6.053 2.4-4.827 0-8.6-3.893-8.6-8.72s3.773-8.72 8.6-8.72c2.6 0 4.507 1.027 5.907 2.347l2.307-2.307C18.747 1.44 16.133 0 12.48 0 5.867 0 .307 5.387.307 12s5.56 12 12.173 12c3.573 0 6.267-1.173 8.373-3.36 2.16-2.16 2.84-5.213 2.84-7.667 0-.76-.053-1.467-.173-2.053H12.48z" />
                                    </svg>
                                    <span class="text-xs sm:text-sm font-medium text-gray-600 dark:text-gray-300">Google</span>
                                </div>
                                <div class="flex items-center gap-3">
                                    <span class="text-xs text-gray-500 dark:text-gray-400 font-normal hidden sm:inline" x-text="email"></span>
                                    <button @click="hasGoogleLinked = false; triggerToast('Cuenta de Google desvinculada')" class="text-gray-500 dark:text-gray-400 hover:text-red-600 dark:hover:text-red-500 transition-colors cursor-pointer p-1">
                                        <x-lucide-trash-2 class="w-4 h-4 stroke-[1.8]" />
                                    </button>
                                </div>
                            </div>

                            <div x-show="!hasGoogleLinked" class="w-full border border-dashed border-gray-300 dark:border-gray-700 rounded-xl p-3 flex items-center justify-between text-xs" style="display: none;">
                                <span class="text-light-text dark:text-light-text-dark">No tienes cuentas vinculadas.</span>
                                <button @click="hasGoogleLinked = true; triggerToast('Cuenta de Google vinculada')" class="text-xs font-semibold text-dark-accent dark:text-light-accent-dark hover:underline cursor-pointer">
                                    + Vincular con Google
                                </button>
                            </div>
                        </div>
                    </div>

                    {{-- TAB 2: PRIVACIDAD --}}
                    <div x-show="activeTab === 'privacy'" style="display: none;">
                        <h2 class="font-bold text-base text-text dark:text-text-dark mb-1">Privacidad de la cuenta</h2>
                        <p class="text-xs text-light-text dark:text-light-text-dark mb-5">Controla quién puede ver tu actividad, enviarte mensajes y encontrar tu perfil en la plataforma.</p>

                        <div class="flex flex-col gap-4 divide-y divide-gray-100 dark:divide-gray-800">
                            <div class="pt-2">
                                <label class="block text-xs font-semibold text-text dark:text-text-dark mb-1.5">Visibilidad de tu perfil</label>
                                <div class="grid grid-cols-3 gap-2">
                                    <button @click="privacySettings.profileVisibility = 'public'; triggerToast('Visibilidad cambiada a Público')" :class="privacySettings.profileVisibility === 'public' ? 'bg-dark-accent dark:bg-light-accent-dark text-white dark:text-black shadow-2xs' : 'bg-[#EEEEEE] dark:bg-gray-800 text-text dark:text-text-dark hover:bg-gray-200 dark:hover:bg-gray-700'" class="py-2 px-3 rounded-xl text-xs font-semibold text-center cursor-pointer transition-colors">Público</button>
                                    <button @click="privacySettings.profileVisibility = 'followers'; triggerToast('Visibilidad cambiada a Seguidores')" :class="privacySettings.profileVisibility === 'followers' ? 'bg-dark-accent dark:bg-light-accent-dark text-white dark:text-black shadow-2xs' : 'bg-[#EEEEEE] dark:bg-gray-800 text-text dark:text-text-dark hover:bg-gray-200 dark:hover:bg-gray-700'" class="py-2 px-3 rounded-xl text-xs font-semibold text-center cursor-pointer transition-colors">Seguidores</button>
                                    <button @click="privacySettings.profileVisibility = 'private'; triggerToast('Visibilidad cambiada a Privado')" :class="privacySettings.profileVisibility === 'private' ? 'bg-dark-accent dark:bg-light-accent-dark text-white dark:text-black shadow-2xs' : 'bg-[#EEEEEE] dark:bg-gray-800 text-text dark:text-text-dark hover:bg-gray-200 dark:hover:bg-gray-700'" class="py-2 px-3 rounded-xl text-xs font-semibold text-center cursor-pointer transition-colors">Privado</button>
                                </div>
                            </div>
                            
                            {{-- Toggles Privacidad --}}
                            <div class="flex items-center justify-between py-2.5">
                                <div class="pr-4">
                                    <span class="text-xs sm:text-sm font-semibold text-text dark:text-text-dark block leading-tight">Permitir mensajes directos</span>
                                    <span class="text-[11px] text-light-text dark:text-light-text-dark block mt-0.5">Cualquier usuario registrado podrá iniciar una conversación contigo.</span>
                                </div>
                                <button @click="privacySettings.directMessages = !privacySettings.directMessages; triggerToast('Preferencia actualizada')" :class="privacySettings.directMessages ? 'bg-dark-accent dark:bg-light-accent-dark' : 'bg-gray-300 dark:bg-gray-700'" class="relative inline-flex h-5 w-10 shrink-0 cursor-pointer rounded-full transition-colors duration-200 ease-in-out focus:outline-none">
                                    <span :class="privacySettings.directMessages ? 'translate-x-5' : 'translate-x-0.5'" class="pointer-events-none inline-block size-4 transform rounded-full bg-white dark:bg-black shadow-md ring-0 transition duration-200 ease-in-out mt-0.5"></span>
                                </button>
                            </div>
                            
                            <div class="flex items-center justify-between py-2.5">
                                <div class="pr-4">
                                    <span class="text-xs sm:text-sm font-semibold text-text dark:text-text-dark block leading-tight">Mostrar estado de conexión</span>
                                    <span class="text-[11px] text-light-text dark:text-light-text-dark block mt-0.5">Permite que otros usuarios vean cuándo estás activo en la plataforma.</span>
                                </div>
                                <button @click="privacySettings.showOnlineStatus = !privacySettings.showOnlineStatus; triggerToast('Estado de conexión actualizado')" :class="privacySettings.showOnlineStatus ? 'bg-dark-accent dark:bg-light-accent-dark' : 'bg-gray-300 dark:bg-gray-700'" class="relative inline-flex h-5 w-10 shrink-0 cursor-pointer rounded-full transition-colors duration-200 ease-in-out focus:outline-none">
                                    <span :class="privacySettings.showOnlineStatus ? 'translate-x-5' : 'translate-x-0.5'" class="pointer-events-none inline-block size-4 transform rounded-full bg-white dark:bg-black shadow-md ring-0 transition duration-200 ease-in-out mt-0.5"></span>
                                </button>
                            </div>

                            <div class="flex items-center justify-between py-2.5">
                                <div class="pr-4">
                                    <span class="text-xs sm:text-sm font-semibold text-text dark:text-text-dark block leading-tight">Indexación en buscadores externos</span>
                                    <span class="text-[11px] text-light-text dark:text-light-text-dark block mt-0.5">Permite que Google y otros motores de búsqueda muestren tu perfil público.</span>
                                </div>
                                <button @click="privacySettings.searchIndexing = !privacySettings.searchIndexing; triggerToast('Indexación actualizada')" :class="privacySettings.searchIndexing ? 'bg-dark-accent dark:bg-light-accent-dark' : 'bg-gray-300 dark:bg-gray-700'" class="relative inline-flex h-5 w-10 shrink-0 cursor-pointer rounded-full transition-colors duration-200 ease-in-out focus:outline-none">
                                    <span :class="privacySettings.searchIndexing ? 'translate-x-5' : 'translate-x-0.5'" class="pointer-events-none inline-block size-4 transform rounded-full bg-white dark:bg-black shadow-md ring-0 transition duration-200 ease-in-out mt-0.5"></span>
                                </button>
                            </div>

                            <div class="flex items-center justify-between py-2.5">
                                <div class="pr-4">
                                    <span class="text-xs sm:text-sm font-semibold text-text dark:text-text-dark block leading-tight">Etiquetado en publicaciones</span>
                                    <span class="text-[11px] text-light-text dark:text-light-text-dark block mt-0.5">Permitir que abogados o miembros de la comunidad te mencionen en publicaciones.</span>
                                </div>
                                <button @click="privacySettings.allowTags = !privacySettings.allowTags; triggerToast('Preferencia de etiquetas actualizada')" :class="privacySettings.allowTags ? 'bg-dark-accent dark:bg-light-accent-dark' : 'bg-gray-300 dark:bg-gray-700'" class="relative inline-flex h-5 w-10 shrink-0 cursor-pointer rounded-full transition-colors duration-200 ease-in-out focus:outline-none">
                                    <span :class="privacySettings.allowTags ? 'translate-x-5' : 'translate-x-0.5'" class="pointer-events-none inline-block size-4 transform rounded-full bg-white dark:bg-black shadow-md ring-0 transition duration-200 ease-in-out mt-0.5"></span>
                                </button>
                            </div>
                        </div>
                    </div>

                    {{-- TAB 3: SEGURIDAD --}}
                    <div x-show="activeTab === 'security'" style="display: none;">
                        <h2 class="font-bold text-base text-text dark:text-text-dark mb-1">Seguridad y acceso</h2>
                        <p class="text-xs text-light-text dark:text-light-text-dark mb-5">Gestiona los métodos de inicio de sesión, contraseñas y las sesiones abiertas en tus dispositivos.</p>

                        <div class="flex flex-col gap-5">
                            {{-- 2FA --}}
                            <div class="bg-[#FAFAFA] dark:bg-gray-900 border border-gray-100 dark:border-gray-800 rounded-xl p-4">
                                <div class="flex items-start justify-between">
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <h3 class="text-xs sm:text-sm font-semibold text-text dark:text-text-dark">Autenticación en dos pasos (2FA)</h3>
                                            <span class="bg-emerald-100 dark:bg-emerald-900/30 text-emerald-800 dark:text-emerald-400 text-[10px] font-bold px-2 py-0.5 rounded-full">Recomendado</span>
                                        </div>
                                        <p class="text-xs text-light-text dark:text-light-text-dark mt-1">Añade una capa extra de protección solicitando un código de seguridad al iniciar sesión.</p>
                                    </div>
                                    <button @click="twoFactorEnabled = !twoFactorEnabled; triggerToast(twoFactorEnabled ? '2FA activado' : '2FA desactivado')" :class="twoFactorEnabled ? 'bg-emerald-600 text-white' : 'bg-dark-accent dark:bg-light-accent-dark text-white dark:text-black hover:opacity-90'" class="px-3 py-1.5 rounded-xl text-xs font-semibold cursor-pointer transition-colors shrink-0">
                                        <span x-text="twoFactorEnabled ? 'Activado' : 'Activar 2FA'"></span>
                                    </button>
                                </div>
                            </div>

                            {{-- Active Sessions --}}
                            <div>
                                <h3 class="text-xs font-semibold text-text dark:text-text-dark mb-2.5">Sesiones activas</h3>
                                <div class="flex flex-col gap-2">
                                    <div class="bg-[#EEEEEE] dark:bg-gray-800 rounded-xl p-3 flex items-center justify-between">
                                        <div class="flex items-center gap-3">
                                            <x-lucide-laptop class="w-[18px] h-[18px] text-dark-accent dark:text-light-accent-dark shrink-0" />
                                            <div>
                                                <p class="text-xs font-bold text-text dark:text-text-dark">Windows · Google Chrome</p>
                                                <p class="text-[11px] text-light-text dark:text-light-text-dark">Monterrey, México · Sesión actual</p>
                                            </div>
                                        </div>
                                        <span class="text-[11px] font-bold text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/20 px-2 py-0.5 rounded-md">Activo ahora</span>
                                    </div>
                                    <div class="bg-[#EEEEEE] dark:bg-gray-800 rounded-xl p-3 flex items-center justify-between">
                                        <div class="flex items-center gap-3">
                                            <x-lucide-smartphone class="w-[18px] h-[18px] text-gray-500 dark:text-gray-400 shrink-0" />
                                            <div>
                                                <p class="text-xs font-bold text-text dark:text-text-dark">Android · App Móvil</p>
                                                <p class="text-[11px] text-light-text dark:text-light-text-dark">Monterrey, México · Hace 2 días</p>
                                            </div>
                                        </div>
                                        <button @click="triggerToast('Sesión de Android finalizada')" class="text-xs font-semibold text-dark-accent dark:text-light-accent-dark hover:underline cursor-pointer">Cerrar</button>
                                    </div>
                                </div>
                            </div>

                            {{-- Toggle Alerts --}}
                            <div class="flex items-center justify-between py-2.5">
                                <div class="pr-4">
                                    <span class="text-xs sm:text-sm font-semibold text-text dark:text-text-dark block leading-tight">Alertas de inicio de sesión no reconocido</span>
                                    <span class="text-[11px] text-light-text dark:text-light-text-dark block mt-0.5">Te enviaremos un correo si detectamos un inicio de sesión desde un nuevo navegador o dispositivo.</span>
                                </div>
                                <button @click="loginAlerts = !loginAlerts; triggerToast('Preferencia actualizada')" :class="loginAlerts ? 'bg-dark-accent dark:bg-light-accent-dark' : 'bg-gray-300 dark:bg-gray-700'" class="relative inline-flex h-5 w-10 shrink-0 cursor-pointer rounded-full transition-colors duration-200 ease-in-out focus:outline-none">
                                    <span :class="loginAlerts ? 'translate-x-5' : 'translate-x-0.5'" class="pointer-events-none inline-block size-4 transform rounded-full bg-white dark:bg-black shadow-md ring-0 transition duration-200 ease-in-out mt-0.5"></span>
                                </button>
                            </div>
                        </div>
                    </div>

                    {{-- TAB 4: PREFERENCIAS --}}
                    <div x-show="activeTab === 'preferences'" style="display: none;">
                        <h2 class="font-bold text-base text-text dark:text-text-dark mb-1">Preferencias generales</h2>
                        <p class="text-xs text-light-text dark:text-light-text-dark mb-5">Personaliza el idioma, aspecto visual y comportamientos generales de la plataforma legal.</p>

                        <div class="flex flex-col gap-4">
                            <div>
                                <label class="block text-xs font-semibold text-text dark:text-text-dark mb-1.5">Idioma de la plataforma</label>
                                <div class="relative">
                                    <x-lucide-globe class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-light-text dark:text-light-text-dark pointer-events-none" />
                                    <select x-model="preferences.language" @change="triggerToast('Idioma actualizado')" class="w-full bg-[#EEEEEE] dark:bg-gray-800 text-text dark:text-text-dark text-xs font-medium pl-9 pr-8 py-2.5 rounded-xl outline-none cursor-pointer appearance-none">
                                        <option value="es">Español (México / América Latina)</option>
                                        <option value="en">English (United States)</option>
                                    </select>
                                    <x-lucide-chevron-down class="absolute right-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-light-text dark:text-light-text-dark pointer-events-none" />
                                </div>
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-text dark:text-text-dark mb-1.5">Tema de interfaz</label>
                                <div class="grid grid-cols-2 gap-2">
                                    <button @click="preferences.theme = 'light'; triggerToast('Tema claro seleccionado')" :class="preferences.theme === 'light' ? 'bg-dark-accent dark:bg-light-accent-dark text-white dark:text-black shadow-2xs' : 'bg-[#EEEEEE] dark:bg-gray-800 text-text dark:text-text-dark hover:bg-gray-200 dark:hover:bg-gray-700'" class="py-2 px-3 rounded-xl text-xs font-semibold flex items-center justify-center gap-2 cursor-pointer transition-colors">
                                        <x-lucide-sun class="w-[15px] h-[15px]" />
                                        <span>Claro</span>
                                    </button>
                                    <button @click="preferences.theme = 'dark'; triggerToast('Tema oscuro seleccionado')" :class="preferences.theme === 'dark' ? 'bg-dark-accent dark:bg-light-accent-dark text-white dark:text-black shadow-2xs' : 'bg-[#EEEEEE] dark:bg-gray-800 text-text dark:text-text-dark hover:bg-gray-200 dark:hover:bg-gray-700'" class="py-2 px-3 rounded-xl text-xs font-semibold flex items-center justify-center gap-2 cursor-pointer transition-colors">
                                        <x-lucide-moon class="w-[15px] h-[15px]" />
                                        <span>Oscuro</span>
                                    </button>
                                </div>
                            </div>

                            <div class="flex items-center justify-between py-2.5">
                                <div class="pr-4">
                                    <span class="text-xs sm:text-sm font-semibold text-text dark:text-text-dark block leading-tight">Reproducción automática de multimedia</span>
                                    <span class="text-[11px] text-light-text dark:text-light-text-dark block mt-0.5">Reproducir videos y animaciones automáticamente mientras exploras el feed.</span>
                                </div>
                                <button @click="preferences.autoplay = !preferences.autoplay; triggerToast('Preferencia actualizada')" :class="preferences.autoplay ? 'bg-dark-accent dark:bg-light-accent-dark' : 'bg-gray-300 dark:bg-gray-700'" class="relative inline-flex h-5 w-10 shrink-0 cursor-pointer rounded-full transition-colors duration-200 ease-in-out focus:outline-none">
                                    <span :class="preferences.autoplay ? 'translate-x-5' : 'translate-x-0.5'" class="pointer-events-none inline-block size-4 transform rounded-full bg-white dark:bg-black shadow-md ring-0 transition duration-200 ease-in-out mt-0.5"></span>
                                </button>
                            </div>
                        </div>
                    </div>

                    {{-- TAB 5: ACCESIBILIDAD --}}
                    <div x-show="activeTab === 'accessibility'" style="display: none;">
                        <h2 class="font-bold text-base text-text dark:text-text-dark mb-1">Accesibilidad</h2>
                        <p class="text-xs text-light-text dark:text-light-text-dark mb-5">Ajustes de visualización y navegación para adaptar la experiencia a tus necesidades.</p>

                        <div class="flex flex-col gap-4 divide-y divide-gray-100 dark:divide-gray-800">
                            <div class="pt-2">
                                <label class="block text-xs font-semibold text-text dark:text-text-dark mb-1.5">Tamaño del texto</label>
                                <div class="grid grid-cols-3 gap-2">
                                    <button @click="accessibility.fontSize = 'small'; triggerToast('Tamaño: Pequeño')" :class="accessibility.fontSize === 'small' ? 'bg-dark-accent dark:bg-light-accent-dark text-white dark:text-black shadow-2xs' : 'bg-[#EEEEEE] dark:bg-gray-800 text-text dark:text-text-dark hover:bg-gray-200 dark:hover:bg-gray-700'" class="py-2 px-2 rounded-xl text-xs font-semibold flex flex-col items-center justify-center cursor-pointer transition-colors">
                                        <span class="font-bold text-sm">A-</span><span class="text-[10px] mt-0.5">Pequeño</span>
                                    </button>
                                    <button @click="accessibility.fontSize = 'normal'; triggerToast('Tamaño: Predeterminado')" :class="accessibility.fontSize === 'normal' ? 'bg-dark-accent dark:bg-light-accent-dark text-white dark:text-black shadow-2xs' : 'bg-[#EEEEEE] dark:bg-gray-800 text-text dark:text-text-dark hover:bg-gray-200 dark:hover:bg-gray-700'" class="py-2 px-2 rounded-xl text-xs font-semibold flex flex-col items-center justify-center cursor-pointer transition-colors">
                                        <span class="font-bold text-sm">A</span><span class="text-[10px] mt-0.5">Predeterminado</span>
                                    </button>
                                    <button @click="accessibility.fontSize = 'large'; triggerToast('Tamaño: Grande')" :class="accessibility.fontSize === 'large' ? 'bg-dark-accent dark:bg-light-accent-dark text-white dark:text-black shadow-2xs' : 'bg-[#EEEEEE] dark:bg-gray-800 text-text dark:text-text-dark hover:bg-gray-200 dark:hover:bg-gray-700'" class="py-2 px-2 rounded-xl text-xs font-semibold flex flex-col items-center justify-center cursor-pointer transition-colors">
                                        <span class="font-bold text-sm">A+</span><span class="text-[10px] mt-0.5">Grande</span>
                                    </button>
                                </div>
                            </div>

                            <div class="flex items-center justify-between py-2.5">
                                <div class="pr-4">
                                    <span class="text-xs sm:text-sm font-semibold text-text dark:text-text-dark block leading-tight">Modo de alto contraste</span>
                                    <span class="text-[11px] text-light-text dark:text-light-text-dark block mt-0.5">Incrementa la distinción de bordes y legibilidad del texto.</span>
                                </div>
                                <button @click="accessibility.highContrast = !accessibility.highContrast; triggerToast('Contraste actualizado')" :class="accessibility.highContrast ? 'bg-dark-accent dark:bg-light-accent-dark' : 'bg-gray-300 dark:bg-gray-700'" class="relative inline-flex h-5 w-10 shrink-0 cursor-pointer rounded-full transition-colors duration-200 ease-in-out focus:outline-none">
                                    <span :class="accessibility.highContrast ? 'translate-x-5' : 'translate-x-0.5'" class="pointer-events-none inline-block size-4 transform rounded-full bg-white dark:bg-black shadow-md ring-0 transition duration-200 ease-in-out mt-0.5"></span>
                                </button>
                            </div>
                            
                            <div class="flex items-center justify-between py-2.5">
                                <div class="pr-4">
                                    <span class="text-xs sm:text-sm font-semibold text-text dark:text-text-dark block leading-tight">Reducir movimiento y transiciones</span>
                                    <span class="text-[11px] text-light-text dark:text-light-text-dark block mt-0.5">Minimiza las animaciones de pantalla para evitar distracciones.</span>
                                </div>
                                <button @click="accessibility.reduceMotion = !accessibility.reduceMotion; triggerToast('Animaciones actualizadas')" :class="accessibility.reduceMotion ? 'bg-dark-accent dark:bg-light-accent-dark' : 'bg-gray-300 dark:bg-gray-700'" class="relative inline-flex h-5 w-10 shrink-0 cursor-pointer rounded-full transition-colors duration-200 ease-in-out focus:outline-none">
                                    <span :class="accessibility.reduceMotion ? 'translate-x-5' : 'translate-x-0.5'" class="pointer-events-none inline-block size-4 transform rounded-full bg-white dark:bg-black shadow-md ring-0 transition duration-200 ease-in-out mt-0.5"></span>
                                </button>
                            </div>
                            
                            <div class="flex items-center justify-between py-2.5">
                                <div class="pr-4">
                                    <span class="text-xs sm:text-sm font-semibold text-text dark:text-text-dark block leading-tight">Subtítulos automáticos</span>
                                    <span class="text-[11px] text-light-text dark:text-light-text-dark block mt-0.5">Mostrar subtítulos en videos explicativos y recursos formativos legales.</span>
                                </div>
                                <button @click="accessibility.subtitles = !accessibility.subtitles; triggerToast('Subtítulos actualizados')" :class="accessibility.subtitles ? 'bg-dark-accent dark:bg-light-accent-dark' : 'bg-gray-300 dark:bg-gray-700'" class="relative inline-flex h-5 w-10 shrink-0 cursor-pointer rounded-full transition-colors duration-200 ease-in-out focus:outline-none">
                                    <span :class="accessibility.subtitles ? 'translate-x-5' : 'translate-x-0.5'" class="pointer-events-none inline-block size-4 transform rounded-full bg-white dark:bg-black shadow-md ring-0 transition duration-200 ease-in-out mt-0.5"></span>
                                </button>
                            </div>
                        </div>
                    </div>

                    {{-- TAB 6: NOTIFICACIONES --}}
                    <div x-show="activeTab === 'notifications'" style="display: none;">
                        <h2 class="font-bold text-base text-text dark:text-text-dark mb-1">Notificaciones</h2>
                        <p class="text-xs text-light-text dark:text-light-text-dark mb-5">Elige qué avisos quieres recibir en tiempo real y cuáles enviar a tu bandeja de correo.</p>

                        <div class="flex flex-col gap-3 divide-y divide-gray-100 dark:divide-gray-800">
                            <div class="flex items-center justify-between py-2.5">
                                <div class="pr-4">
                                    <span class="text-xs sm:text-sm font-semibold text-text dark:text-text-dark block leading-tight">Notificaciones Push en el navegador</span>
                                    <span class="text-[11px] text-light-text dark:text-light-text-dark block mt-0.5">Recibe alertas instantáneas en tu dispositivo.</span>
                                </div>
                                <button @click="notifications.push = !notifications.push; triggerToast('Preferencia actualizada')" :class="notifications.push ? 'bg-dark-accent dark:bg-light-accent-dark' : 'bg-gray-300 dark:bg-gray-700'" class="relative inline-flex h-5 w-10 shrink-0 cursor-pointer rounded-full transition-colors duration-200 ease-in-out focus:outline-none">
                                    <span :class="notifications.push ? 'translate-x-5' : 'translate-x-0.5'" class="pointer-events-none inline-block size-4 transform rounded-full bg-white dark:bg-black shadow-md ring-0 transition duration-200 ease-in-out mt-0.5"></span>
                                </button>
                            </div>

                            <div class="flex items-center justify-between py-2.5">
                                <div class="pr-4">
                                    <span class="text-xs sm:text-sm font-semibold text-text dark:text-text-dark block leading-tight">Avisos por correo electrónico</span>
                                    <span class="text-[11px] text-light-text dark:text-light-text-dark block mt-0.5">Enviarte un resumen de novedades y respuestas importantes a tu email registrado.</span>
                                </div>
                                <button @click="notifications.email = !notifications.email; triggerToast('Preferencia actualizada')" :class="notifications.email ? 'bg-dark-accent dark:bg-light-accent-dark' : 'bg-gray-300 dark:bg-gray-700'" class="relative inline-flex h-5 w-10 shrink-0 cursor-pointer rounded-full transition-colors duration-200 ease-in-out focus:outline-none">
                                    <span :class="notifications.email ? 'translate-x-5' : 'translate-x-0.5'" class="pointer-events-none inline-block size-4 transform rounded-full bg-white dark:bg-black shadow-md ring-0 transition duration-200 ease-in-out mt-0.5"></span>
                                </button>
                            </div>

                            <div class="flex items-center justify-between py-2.5">
                                <div class="pr-4">
                                    <span class="text-xs sm:text-sm font-semibold text-text dark:text-text-dark block leading-tight">Respuestas a tus publicaciones</span>
                                    <span class="text-[11px] text-light-text dark:text-light-text-dark block mt-0.5">Notificar cuando un abogado o usuario comente en una de tus consultas.</span>
                                </div>
                                <button @click="notifications.comments = !notifications.comments; triggerToast('Preferencia actualizada')" :class="notifications.comments ? 'bg-dark-accent dark:bg-light-accent-dark' : 'bg-gray-300 dark:bg-gray-700'" class="relative inline-flex h-5 w-10 shrink-0 cursor-pointer rounded-full transition-colors duration-200 ease-in-out focus:outline-none">
                                    <span :class="notifications.comments ? 'translate-x-5' : 'translate-x-0.5'" class="pointer-events-none inline-block size-4 transform rounded-full bg-white dark:bg-black shadow-md ring-0 transition duration-200 ease-in-out mt-0.5"></span>
                                </button>
                            </div>

                            <div class="flex items-center justify-between py-2.5">
                                <div class="pr-4">
                                    <span class="text-xs sm:text-sm font-semibold text-text dark:text-text-dark block leading-tight">Mensajes directos nuevos</span>
                                    <span class="text-[11px] text-light-text dark:text-light-text-dark block mt-0.5">Avisar inmediatamente cuando recibas un mensaje privado.</span>
                                </div>
                                <button @click="notifications.directMessages = !notifications.directMessages; triggerToast('Preferencia actualizada')" :class="notifications.directMessages ? 'bg-dark-accent dark:bg-light-accent-dark' : 'bg-gray-300 dark:bg-gray-700'" class="relative inline-flex h-5 w-10 shrink-0 cursor-pointer rounded-full transition-colors duration-200 ease-in-out focus:outline-none">
                                    <span :class="notifications.directMessages ? 'translate-x-5' : 'translate-x-0.5'" class="pointer-events-none inline-block size-4 transform rounded-full bg-white dark:bg-black shadow-md ring-0 transition duration-200 ease-in-out mt-0.5"></span>
                                </button>
                            </div>

                            <div class="flex items-center justify-between py-2.5">
                                <div class="pr-4">
                                    <span class="text-xs sm:text-sm font-semibold text-text dark:text-text-dark block leading-tight">Resumen semanal de casos</span>
                                    <span class="text-[11px] text-light-text dark:text-light-text-dark block mt-0.5">Boletín semanal con temas jurídicos destacados.</span>
                                </div>
                                <button @click="notifications.weeklyDigest = !notifications.weeklyDigest; triggerToast('Preferencia actualizada')" :class="notifications.weeklyDigest ? 'bg-dark-accent dark:bg-light-accent-dark' : 'bg-gray-300 dark:bg-gray-700'" class="relative inline-flex h-5 w-10 shrink-0 cursor-pointer rounded-full transition-colors duration-200 ease-in-out focus:outline-none">
                                    <span :class="notifications.weeklyDigest ? 'translate-x-5' : 'translate-x-0.5'" class="pointer-events-none inline-block size-4 transform rounded-full bg-white dark:bg-black shadow-md ring-0 transition duration-200 ease-in-out mt-0.5"></span>
                                </button>
                            </div>
                        </div>
                    </div>

                </div>
            </section>

        </div>
    </main>

    {{-- Modal: Editar Datos Personales --}}
    <div x-show="isEditingPersonalData" 
         class="fixed inset-0 z-50 bg-black/40 backdrop-blur-sm flex items-center justify-center p-4"
         style="display: none;">
        <div @click.away="isEditingPersonalData = false" class="bg-white dark:bg-black rounded-2xl max-w-sm w-full p-5 shadow-xl">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-bold text-sm text-text dark:text-text-dark">Editar datos personales</h3>
                <button @click="isEditingPersonalData = false" class="text-light-text dark:text-light-text-dark hover:text-text dark:hover:text-text-dark cursor-pointer">
                    <x-lucide-x class="w-4 h-4" />
                </button>
            </div>

            <div class="flex flex-col gap-3">
                <div>
                    <label class="block text-xs text-light-text dark:text-light-text-dark mb-1">Vives en</label>
                    <input type="text" x-model="personalData.livesIn" class="w-full bg-[#EEEEEE] dark:bg-gray-800 text-text dark:text-text-dark font-bold text-xs px-3.5 py-2.5 rounded-xl outline-none" />
                </div>
                <div>
                    <label class="block text-xs text-light-text dark:text-light-text-dark mb-1">Eres de</label>
                    <input type="text" x-model="personalData.from" class="w-full bg-[#EEEEEE] dark:bg-gray-800 text-text dark:text-text-dark font-bold text-xs px-3.5 py-2.5 rounded-xl outline-none" />
                </div>
                <div>
                    <label class="block text-xs text-light-text dark:text-light-text-dark mb-1">Género</label>
                    <select x-model="personalData.gender" class="w-full bg-[#EEEEEE] dark:bg-gray-800 text-text dark:text-text-dark font-medium text-xs px-3.5 py-2.5 rounded-xl outline-none">
                        <option value="Hombre">Hombre</option>
                        <option value="Mujer">Mujer</option>
                        <option value="No binario">No binario</option>
                        <option value="Prefiero no decir">Prefiero no decir</option>
                    </select>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 mt-5">
                <button @click="isEditingPersonalData = false" class="px-4 py-2 rounded-xl text-xs font-medium text-light-text dark:text-light-text-dark hover:bg-gray-100 dark:hover:bg-gray-800 cursor-pointer">
                    Cancelar
                </button>
                <button @click="isEditingPersonalData = false; triggerToast('Datos personales actualizados')" class="px-5 py-2 rounded-xl text-xs font-semibold bg-dark-accent dark:bg-light-accent-dark text-white dark:text-black hover:opacity-90 cursor-pointer">
                    Guardar
                </button>
            </div>
        </div>
    </div>

    {{-- Modal: Confirmar Cerrar Sesión --}}
    <div x-show="showLogoutModal" 
         class="fixed inset-0 z-50 bg-black/40 backdrop-blur-sm flex items-center justify-center p-4"
         style="display: none;">
        <div @click.away="showLogoutModal = false" class="bg-white dark:bg-black rounded-2xl max-w-sm w-full p-5 shadow-xl text-center">
            <div class="size-12 rounded-full bg-red-50 dark:bg-red-900/20 text-dark-accent dark:text-light-accent-dark flex items-center justify-center mx-auto mb-3">
                <x-lucide-log-out class="w-[22px] h-[22px] stroke-[2]" />
            </div>
            <h3 class="font-bold text-base text-text dark:text-text-dark mb-1.5">¿Cerrar sesión?</h3>
            <p class="text-xs text-light-text dark:text-light-text-dark mb-5 leading-relaxed">Tendrás que volver a ingresar tus credenciales para acceder a tus consultas legales y perfil.</p>

            <div class="flex items-center gap-2.5">
                <button @click="showLogoutModal = false" class="flex-1 py-2.5 rounded-xl text-xs font-semibold bg-[#EEEEEE] dark:bg-gray-800 text-text dark:text-text-dark hover:bg-gray-200 dark:hover:bg-gray-700 cursor-pointer transition-colors">
                    Cancelar
                </button>
                
                {{-- Formulario oficial de Laravel para cerrar sesión seguro --}}
                <form method="POST" action="{{ route('login') }}" class="flex-1">
                    @csrf
                    <button type="submit" class="w-full py-2.5 rounded-xl text-xs font-semibold bg-dark-accent dark:bg-light-accent-dark text-white dark:text-black hover:opacity-90 cursor-pointer transition-opacity">
                        Cerrar sesión
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
