@extends('layouts.app')
@section('title', 'Configuración | Plataforma Legal')
@section('content')
<div x-data="{ activeTab: 'profile', searchQuery: '' }" class="min-h-screen bg-bg dark:bg-bg-dark flex flex-col font-sans pb-24 lg:pb-16 text-text dark:text-text-dark relative" data-dialog-root>
    <div class="w-full max-w-[1080px] mx-auto px-4 sm:px-6 pt-7">
        <div class="flex flex-col lg:flex-row gap-5 items-start">
            <aside class="w-full lg:w-64 xl:w-72 shrink-0">
                <div class="bg-white dark:bg-surface-dark rounded-2xl p-5 sm:p-6 shadow-xs border border-gray-100/50 dark:border-border-dark/50 flex flex-col justify-between min-h-[520px]">
                    <div>
                        {{-- Title --}}

                        {{-- Search Bar --}}
                        <div class="relative mt-3.5 mb-5">
                            <x-lucide-search class="absolute left-3 top-1/2 -translate-y-1/2 w-[15px] h-[15px] text-light-text dark:text-light-text-dark stroke-[1.8] pointer-events-none" />
                            <input
                                type="text"
                                x-model="searchQuery"
                                placeholder="Buscar" aria-label="Buscar secciones de configuración"
                                class="w-full bg-[#EEEEEE] dark:bg-accent-bg-dark placeholder:text-light-text dark:placeholder:text-light-text-dark text-text dark:text-text-dark text-xs rounded-xl pl-9 pr-3 py-2 outline-none focus:ring-1 focus:ring-dark-accent/40 dark:focus:ring-focus-dark/40 transition-all"
                            />
                            <button
                                x-show="searchQuery !== ''"
                                @click="searchQuery = ''" aria-label="Borrar búsqueda de configuración" type="button"
                                class="absolute right-2.5 top-1/2 -translate-y-1/2 text-light-text dark:text-light-text-dark hover:text-text dark:hover:text-text-dark cursor-pointer"
                                style="display: none;"
                            >
                                <x-lucide-x class="w-[13px] h-[13px]" />
                            </button>
                        </div>

                        {{-- Navigation Sections --}}
                        <nav class="flex flex-col gap-4" aria-label="Secciones de configuración">
                            
                            {{-- Grupo: Tu cuenta --}}
                            <div x-show="searchQuery === '' || 'perfil privacidad seguridad'.includes(searchQuery.toLowerCase())">
                                <h2 class="text-xs font-semibold text-light-text dark:text-light-text-dark mb-2 px-1">Tu cuenta</h2>
                                <div class="flex flex-col gap-1">
                                    <button x-show="searchQuery === '' || 'perfil'.includes(searchQuery.toLowerCase())"
                                        @click="activeTab = 'profile'"
                                        :class="activeTab === 'profile' ? 'bg-[#EEEEEE] dark:bg-accent-bg-dark text-text dark:text-text-dark font-bold shadow-2xs' : 'text-light-text dark:text-light-text-dark hover:bg-gray-50 dark:hover:bg-bg-dark hover:text-text dark:hover:text-text-dark font-medium'"
                                        class="w-full flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-xs sm:text-[13px] transition-colors duration-150 cursor-pointer text-left">
                                        <x-lucide-user class="w-[18px] h-[18px] shrink-0 stroke-[1.8]" ::class="activeTab === 'profile' ? 'text-text dark:text-text-dark' : 'text-light-text dark:text-light-text-dark'" />
                                        <span>Perfil</span>
                                    </button>
                                    <button x-show="searchQuery === '' || 'privacidad'.includes(searchQuery.toLowerCase())"
                                        @click="activeTab = 'privacy'"
                                        :class="activeTab === 'privacy' ? 'bg-[#EEEEEE] dark:bg-accent-bg-dark text-text dark:text-text-dark font-bold shadow-2xs' : 'text-light-text dark:text-light-text-dark hover:bg-gray-50 dark:hover:bg-bg-dark hover:text-text dark:hover:text-text-dark font-medium'"
                                        class="w-full flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-xs sm:text-[13px] transition-colors duration-150 cursor-pointer text-left">
                                        <x-lucide-lock class="w-[18px] h-[18px] shrink-0 stroke-[1.8]" ::class="activeTab === 'privacy' ? 'text-text dark:text-text-dark' : 'text-light-text dark:text-light-text-dark'" />
                                        <span>Privacidad</span>
                                    </button>
                                    <button x-show="searchQuery === '' || 'seguridad'.includes(searchQuery.toLowerCase())"
                                        @click="activeTab = 'security'"
                                        :class="activeTab === 'security' ? 'bg-[#EEEEEE] dark:bg-accent-bg-dark text-text dark:text-text-dark font-bold shadow-2xs' : 'text-light-text dark:text-light-text-dark hover:bg-gray-50 dark:hover:bg-bg-dark hover:text-text dark:hover:text-text-dark font-medium'"
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
                                        :class="activeTab === 'preferences' ? 'bg-[#EEEEEE] dark:bg-accent-bg-dark text-text dark:text-text-dark font-bold shadow-2xs' : 'text-light-text dark:text-light-text-dark hover:bg-gray-50 dark:hover:bg-bg-dark hover:text-text dark:hover:text-text-dark font-medium'"
                                        class="w-full flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-xs sm:text-[13px] transition-colors duration-150 cursor-pointer text-left">
                                        <x-lucide-sliders-horizontal class="w-[18px] h-[18px] shrink-0 stroke-[1.8]" ::class="activeTab === 'preferences' ? 'text-text dark:text-text-dark' : 'text-light-text dark:text-light-text-dark'" />
                                        <span>Preferencias</span>
                                    </button>
                                    <button x-show="searchQuery === '' || 'accesibilidad'.includes(searchQuery.toLowerCase())"
                                        @click="activeTab = 'accessibility'"
                                        :class="activeTab === 'accessibility' ? 'bg-[#EEEEEE] dark:bg-accent-bg-dark text-text dark:text-text-dark font-bold shadow-2xs' : 'text-light-text dark:text-light-text-dark hover:bg-gray-50 dark:hover:bg-bg-dark hover:text-text dark:hover:text-text-dark font-medium'"
                                        class="w-full flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-xs sm:text-[13px] transition-colors duration-150 cursor-pointer text-left">
                                        <x-lucide-accessibility class="w-[18px] h-[18px] shrink-0 stroke-[1.8]" ::class="activeTab === 'accessibility' ? 'text-text dark:text-text-dark' : 'text-light-text dark:text-light-text-dark'" />
                                        <span>Accesibilidad</span>
                                    </button>
                                    <button x-show="searchQuery === '' || 'notificaciones'.includes(searchQuery.toLowerCase())"
                                        @click="activeTab = 'notifications'"
                                        :class="activeTab === 'notifications' ? 'bg-[#EEEEEE] dark:bg-accent-bg-dark text-text dark:text-text-dark font-bold shadow-2xs' : 'text-light-text dark:text-light-text-dark hover:bg-gray-50 dark:hover:bg-bg-dark hover:text-text dark:hover:text-text-dark font-medium'"
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
                        <form method="POST" action="{{ route('logout') }}" data-turbo="false">@csrf
                        <button type="submit" data-dialog-open
                            class="w-full bg-dark-accent dark:bg-light-accent hover:opacity-90 transition-opacity text-white dark:text-black font-semibold text-xs py-2.5 px-4 rounded-xl cursor-pointer shadow-xs text-center"
                        >
                            Cerrar sesión
                        </button></form>
                    </div>
                </div>
            </aside>

            <section class="flex-1 w-full min-w-0" aria-label="Opciones de configuración">
                <div class="bg-white dark:bg-surface-dark rounded-2xl p-5 sm:p-6 shadow-xs border border-gray-100/50 dark:border-border-dark/50 min-h-[520px]">
                    <div x-show="activeTab === 'profile'">
                        <h2 class="font-bold text-base mb-4">Tu cuenta y perfil</h2>
                        <div class="relative mb-5">
                            <div class="h-28 sm:h-32 md:h-36 w-full relative overflow-hidden bg-light-accent rounded-2xl" aria-hidden="true"><svg class="absolute inset-0 w-full h-full" viewBox="0 0 523 119" preserveAspectRatio="none" fill="none"><path d="M 190 119 C 260 55, 330 65, 380 84 C 430 102, 470 90, 523 48 L 523 119 Z" fill="#9E1F52" opacity="0.95"/></svg></div>
                            <div class="flex flex-col sm:flex-row items-center gap-4 px-2">
                                <span class="-mt-12 sm:-mt-8 size-24 rounded-full border-4 border-white dark:border-surface-dark bg-[#EEEEEE] dark:bg-accent-bg-dark flex items-center justify-center text-3xl shrink-0" aria-hidden="true">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
                                <div class="min-w-0 pt-2 text-center sm:text-left"><h3 class="text-xl font-bold break-words">{{ auth()->user()->name }}</h3><p class="text-sm text-light-text dark:text-light-text-dark break-all">{{ auth()->user()->email }}</p><p class="text-xs mt-2">Datos privados de tu cuenta. Los datos públicos se eligen en tus perfiles.</p></div>
                            </div>
                        </div>
                        <div class="grid gap-3 text-sm">
                            <a class="rounded-xl bg-[#EEEEEE] dark:bg-accent-bg-dark px-4 py-3 underline" href="{{ route('profile.edit') }}">Editar mi perfil y fotografía privada</a>
                            <a class="rounded-xl bg-[#EEEEEE] dark:bg-accent-bg-dark px-4 py-3 underline" href="{{ route('profile.professional.edit') }}">Editar presentación profesional</a>
                            <a class="underline" href="{{ route('profile') }}">Ver mi perfil</a>
                        </div>
                        <p class="text-xs text-light-text dark:text-light-text-dark mt-5">Cambiar el correo o el nombre de la cuenta y vincular cuentas externas son funciones pendientes.</p>
                    </div>
                    <div x-show="activeTab === 'privacy'" style="display:none">
                        <h2 class="font-bold text-base mb-2">Privacidad de la cuenta</h2>
                        <p class="text-sm mb-5">La visibilidad personal y profesional se administra y guarda en sus formularios reales.</p>
                        <div class="flex flex-wrap gap-4 text-sm mb-5"><a class="underline" href="{{ route('profile.edit') }}">Visibilidad de mi perfil</a><a class="underline" href="{{ route('profile.professional.edit') }}">Visibilidad y contacto profesional</a><a class="underline" href="{{ route('messages') }}">Gestionar bloqueos en Mensajes</a></div>
                        <p class="text-xs text-light-text dark:text-light-text-dark">Las opciones adicionales de esta sección están pendientes y no modifican tu privacidad.</p>
                        @foreach (['Estado de conexión', 'Preferencias de indexación externa', 'Menciones en publicaciones'] as $option)
                            <div class="flex items-center justify-between gap-4 py-4 border-b border-gray-100 dark:border-border-dark"><span class="text-sm">{{ $option }}</span><button type="button" data-future-setting disabled class="rounded-xl bg-gray-100 dark:bg-accent-bg-dark px-3 py-2 text-xs opacity-70 cursor-not-allowed" aria-label="{{ $option }}: próximamente">Próximamente</button></div>
                        @endforeach
                    </div>
                    <div x-show="activeTab === 'security'" style="display:none">
                        <h2 class="font-bold text-base mb-2">Seguridad</h2><a class="underline inline-block mb-4" href="{{ route('password.request') }}">Recuperar contraseña por correo</a><p class="text-sm mb-5">El cierre de esta sesión está disponible en el menú. Puedes recuperar tu contraseña mediante un enlace enviado a tu correo. Las funciones adicionales indicadas abajo siguen pendientes.</p>
                        @foreach (['Autenticación en dos pasos (2FA)' => '2FA no está disponible. No se ha activado una protección adicional.', 'Sesiones de otros dispositivos' => 'Todavía no puedes consultar ni revocar otras sesiones desde esta pantalla.', 'Cambio directo de contraseña' => 'La edición con contraseña actual sigue pendiente. La recuperación mediante correo está disponible.', 'Alertas de inicio de sesión' => 'No se envían alertas configurables desde esta sección.'] as $option => $description)
                            <div class="bg-[#FAFAFA] dark:bg-bg-dark border border-gray-100 dark:border-border-dark rounded-xl p-4 mb-4"><h3 class="text-sm font-semibold">{{ $option }}</h3><p class="text-xs text-light-text dark:text-light-text-dark mt-2 mb-3">{{ $description }}</p><button type="button" data-future-setting disabled class="px-3 py-2 rounded-xl text-xs bg-gray-200 dark:bg-accent-bg-dark opacity-70 cursor-not-allowed" aria-label="{{ $option }}: próximamente">Próximamente</button></div>
                        @endforeach
                    </div>
                    @foreach (['preferences' => ['Preferencias', ['Idioma de la interfaz', 'Selección de tema', 'Reproducción automática']], 'accessibility' => ['Accesibilidad', ['Tamaño de fuente', 'Preferencia de contraste', 'Preferencia de animaciones', 'Subtítulos']], 'notifications' => ['Notificaciones', ['Notificaciones por correo', 'Notificaciones push', 'Avisos de comentarios', 'Avisos de mensajes', 'Resumen semanal']]] as $tab => [$title, $options])
                        <div x-show="activeTab === '{{ $tab }}'" style="display:none"><h2 class="font-bold text-base mb-2">{{ $title }}</h2><p class="text-sm mb-5">Estas preferencias están pendientes de implementación. No se guardan ni aplican cambios desde esta pantalla.</p>
                            @foreach ($options as $option)<div class="flex items-center justify-between gap-4 py-4 border-b border-gray-100 dark:border-border-dark"><span class="text-sm">{{ $option }}</span><button type="button" data-future-setting disabled class="rounded-xl bg-gray-100 dark:bg-accent-bg-dark px-3 py-2 text-xs opacity-70 cursor-not-allowed" aria-label="{{ $option }}: próximamente">Próximamente</button></div>@endforeach
                        </div>
                    @endforeach
                </div>
            </section>
        </div>
    </div>
    <dialog id="settings-logout" class="ui-dialog" aria-labelledby="settings-logout-title" aria-describedby="settings-logout-description">
        <div class="ui-dialog-panel">
            <h2 id="settings-logout-title" class="text-lg font-bold">¿Cerrar sesión?</h2>
            <p id="settings-logout-description">Tendrás que volver a ingresar tus credenciales para acceder a tus consultas legales y perfil.</p>
            <div class="flex flex-wrap gap-3 justify-center">
                <button type="button" data-dialog-close data-dialog-initial autofocus class="px-4 py-3 rounded-xl border">Cancelar</button>
                <form method="POST" action="{{ route('logout') }}" data-turbo="false">@csrf
                    <button type="submit" class="px-4 py-3 rounded-xl bg-dark-accent text-white">Cerrar sesión</button>
                </form>
            </div>
        </div>
    </dialog>
</div>
@endsection
