@extends('layouts.guest')
@section('title', ($reset ? 'Restablecer contraseña' : 'Recuperar acceso').' | Plataforma Legal')
@section('content')
<div class="min-h-screen flex items-center justify-center bg-bg dark:bg-bg-dark px-4 py-8">
    <section class="bg-white dark:bg-surface-dark rounded-2xl w-full max-w-xl p-6 sm:p-10 space-y-5 text-text dark:text-text-dark">
        <a class="underline text-sm" href="{{ route('login') }}">← Iniciar sesión</a>
        <h1 class="text-2xl font-bold">{{ $reset ? 'Restablecer contraseña' : 'Recuperar acceso' }}</h1>
        @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
        @if ($errors->any())<div role="alert"><h2 class="font-semibold">Revisa el formulario</h2><ul>@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>@if ($reset)<p>Vuelve a abrir el enlace del correo para cargar el código otra vez.</p>@endif</div>@endif
        @if ($captureOnly)<p class="text-sm">Entorno de desarrollo/pruebas: el correo se captura en memoria, sin entrega externa. Hace falta un proveedor de correo configurado para recibir enlaces reales.</p>@endif
        <p class="text-sm">{{ $reset ? 'Utiliza el enlace temporal recibido. No compartas el código. Al cambiar la contraseña se rota el recuerdo y se rechazan las sesiones anteriores en su siguiente acceso.' : 'Escribe tu correo. La respuesta no confirma si hay una cuenta registrada. Sólo las cuentas activas pueden recuperar el acceso.' }}</p>
        <form method="POST" action="{{ route($reset ? 'password.update' : 'password.email') }}" data-turbo="false" @if ($reset) data-password-reset @endif class="space-y-4">
            @csrf
            <div><label for="recovery-email" class="block text-sm mb-2">Correo electrónico</label><input id="recovery-email" type="email" name="email" value="{{ old('email', '') }}" autocomplete="email" maxlength="255" required class="login-input w-full" @error('email') aria-invalid="true" aria-describedby="recovery-email-error" @enderror>@error('email')<p id="recovery-email-error">{{ $message }}</p>@enderror</div>
            @if ($reset)
                <div><label for="recovery-token" class="block text-sm mb-2">Código de recuperación</label><input id="recovery-token" type="password" name="token" autocomplete="off" required maxlength="64" class="login-input w-full" aria-describedby="recovery-token-hint{{ $errors->has('token') ? ' recovery-token-error' : '' }}" @error('token') aria-invalid="true" @enderror><p id="recovery-token-hint" class="text-xs mt-2">El enlace lo carga en el navegador. Sin JavaScript, copia el valor token del enlace recibido.</p>@error('token')<p id="recovery-token-error">{{ $message }}</p>@enderror</div>
                <div><label for="recovery-password" class="block text-sm mb-2">Contraseña nueva</label><input id="recovery-password" type="password" name="password" autocomplete="new-password" minlength="12" required class="login-input w-full" aria-describedby="recovery-password-hint{{ $errors->has('password') ? ' recovery-password-error' : '' }}" @error('password') aria-invalid="true" @enderror><p id="recovery-password-hint" class="text-xs mt-2">Al menos 12 caracteres y hasta 72 bytes, sin bytes nulos.</p>@error('password')<p id="recovery-password-error">{{ $message }}</p>@enderror</div>
                <div><label for="recovery-confirmation" class="block text-sm mb-2">Confirmar contraseña</label><input id="recovery-confirmation" type="password" name="password_confirmation" autocomplete="new-password" minlength="12" required class="login-input w-full" @error('password_confirmation') aria-invalid="true" aria-describedby="recovery-confirmation-error" @enderror>@error('password_confirmation')<p id="recovery-confirmation-error">{{ $message }}</p>@enderror</div>
                <p data-fragment-status role="status" class="text-sm"></p>
            @endif
            <button class="bg-dark-accent dark:bg-light-accent text-white dark:text-black rounded-xl w-full px-4 py-3 font-semibold">{{ $reset ? 'Cambiar contraseña' : 'Solicitar instrucciones' }}</button>
        </form>
        @if ($reset)<a class="underline text-sm inline-block" href="{{ route('password.request') }}">Solicitar otro enlace</a>@endif
    </section>
</div>
@endsection
