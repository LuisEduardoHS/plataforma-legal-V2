
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

@section('title', 'Iniciar sesión | LEXA')

@section('content')
<main class="flex flex-row min-h-screen items-center justify-center bg-bg dark:bg-bg-dark">
    <section class="bg-white dark:bg-surface-dark flex w-full sm:w-4xl h-164 rounded-lg overflow-clip">

        {{-- Banner side --}}
        <div class="sm:basis-1/2 hidden sm:flex relative flex-col justify-center">
            <div class="absolute bg-linear-to-l from-dark-accent to-light-accent size-full"></div>
        </div>

        {{-- Form side --}}
        <div class="sm:basis-1/2 basis-full flex flex-col justify-start py-6 px-9">
            
            <section class="flex flex-col items-end pt-5 mb-16">
                <h1 class="text-2xl font-bold text-text dark:text-text-dark">¡Bienvenid@ de nuevo!</h1>
                <h2 class="text-base text-light-text dark:text-light-text-dark">Inicia sesión para continuar</h2>
            </section>

            <form method="POST" action="{{ route('login') }}">
                @csrf

                {{-- Email --}}
                <div class="mb-4">
                    <label for="email" class="login-input-label">Correo electrónico o Usuario</label>
                    <input type="text" id="email" name="email" value="{{ old('email') }}" required autofocus class="login-input w-full" />
                </div>

                {{-- Password --}}
                <div class="mb-4" x-data="{ showPassword: false }">
                    <label for="password" class="login-input-label">Contraseña</label>
                    <div class="relative">
                        <input :type="showPassword ? 'text' : 'password'" id="password" name="password" required class="login-input w-full pr-10" />
                        
                        <button type="button" @click="showPassword = !showPassword" class="absolute right-3 top-1/2 -translate-y-1/2 text-light-text dark:text-light-text-dark hover:text-text dark:hover:text-text-dark transition-colors cursor-pointer" aria-label="Mostrar contraseña">
                            <x-lucide-eye x-show="!showPassword" class="w-5 h-5" stroke-width="1.8" />
                            <x-lucide-eye-off x-show="showPassword" class="w-5 h-5" stroke-width="1.8" style="display: none;" />
                        </button>
                    </div>
                </div>

                {{-- Remember Me --}}
                <div class="mb-5 mt-6 flex flex-row items-center">
                    <div class="relative flex items-center justify-center shrink-0">
                        <input type="checkbox" id="remember" name="remember" class="peer appearance-none size-5 rounded-md border-2 border-text/15 dark:border-text-dark/15 checked:bg-text dark:checked:bg-text-dark checked:border-text dark:checked:border-text-dark cursor-pointer transition-colors duration-200" />
                        
                        <x-lucide-check class="absolute w-3.5 h-3.5 text-white dark:text-black opacity-0 peer-checked:opacity-100 pointer-events-none transition-opacity duration-200" stroke-width="3.5" />
                    </div>
                    <label for="remember" class="login-remember-label ml-2 self-center cursor-pointer">Mantener mi sesión activa</label>
                </div>

                {{-- Submit Button --}}
                <button type="submit" class="bg-text/90 dark:bg-text-dark/90 hover:bg-text/85 dark:hover:bg-text-dark/85 transition-colors duration-200 cursor-pointer text-white dark:text-black text-base w-full font-medium py-3 px-4 rounded-xl">
                    Iniciar sesión
                </button>
            </form>

            {{-- Forgot Password --}}
            <div class="mt-2 text-end">
                @if (Route::has('password.request'))
                    <a href="{{ route('password.request') }}" class="login-link">Olvidé mi contraseña</a>
                @else
                    <a href="#" class="login-link">Olvidé mi contraseña</a>
                @endif
            </div>

            {{-- Alt login --}}
            <div class="mt-9 text-start">
                <span class="text-sm text-light-text dark:text-light-text-dark font-semibold">O ingresa con</span>
                <div class="mt-2 flex flex-row justify-between">
                    {{-- Google --}}
                    <button type="button" class="login-alt-button">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640" class="size-8 fill-light-text dark:fill-light-text-dark"><path d="M564 325.8C564 467.3 467.1 568 324 568C186.8 568 76 457.2 76 320C76 182.8 186.8 72 324 72C390.8 72 447 96.5 490.3 136.9L422.8 201.8C334.5 116.6 170.3 180.6 170.3 320C170.3 406.5 239.4 476.6 324 476.6C422.2 476.6 459 406.2 464.8 369.7L324 369.7L324 284.4L560.1 284.4C562.4 297.1 564 309.3 564 325.8z"/></svg>
                    </button>

                    {{-- LinkedIn --}}
                    <button type="button" class="login-alt-button">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640" class="size-8 fill-light-text dark:fill-light-text-dark"><path d="M512 96L127.9 96C110.3 96 96 110.5 96 128.3L96 511.7C96 529.5 110.3 544 127.9 544L512 544C529.6 544 544 529.5 544 511.7L544 128.3C544 110.5 529.6 96 512 96zM231.4 480L165 480L165 266.2L231.5 266.2L231.5 480L231.4 480zM198.2 160C219.5 160 236.7 177.2 236.7 198.5C236.7 219.8 219.5 237 198.2 237C176.9 237 159.7 219.8 159.7 198.5C159.7 177.2 176.9 160 198.2 160zM480.3 480L413.9 480L413.9 376C413.9 351.2 413.4 319.3 379.4 319.3C344.8 319.3 339.5 346.3 339.5 374.2L339.5 480L273.1 480L273.1 266.2L336.8 266.2L336.8 295.4L337.7 295.4C346.6 278.6 368.3 260.9 400.6 260.9C467.8 260.9 480.3 305.2 480.3 362.8L480.3 480z"/></svg>
                    </button>

                    {{-- Facebook --}}
                    <button type="button" class="login-alt-button">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640" class="size-8 fill-light-text dark:fill-light-text-dark"><path d="M576 320C576 178.6 461.4 64 320 64C178.6 64 64 178.6 64 320C64 440 146.7 540.8 258.2 568.5L258.2 398.2L205.4 398.2L205.4 320L258.2 320L258.2 286.3C258.2 199.2 297.6 158.8 383.2 158.8C399.4 158.8 427.4 162 438.9 165.2L438.9 236C432.9 235.4 422.4 235 409.3 235C367.3 235 351.1 250.9 351.1 292.2L351.1 320L434.7 320L420.3 398.2L351 398.2L351 574.1C477.8 558.8 576 450.9 576 320z"/></svg>
                    </button>
                </div>
            </div>

            {{-- Register --}}
            <div class="mt-3 text-start">
                <p class="text-sm text-light-text dark:text-light-text-dark font-semibold">
                    ¿Aún no tienes cuenta? 
                    <a href="{{ route('register') }}" class="login-link">
                        Regístrate ahora
                    </a>
                </p>
            </div>

        </div>
    </section>
</main>
@endsection