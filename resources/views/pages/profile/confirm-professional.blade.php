@extends('layouts.app')
@section('title', 'Confirmar cambios profesionales | Plataforma Legal')
@section('page-assets')
    @vite(['resources/css/professionals.css'])
@endsection
@section('content')
<div class="profile-editor min-h-screen bg-bg dark:bg-bg-dark text-text dark:text-text-dark pb-24">
    <section class="max-w-3xl mx-auto px-4 sm:px-6 py-8 space-y-5" aria-labelledby="professional-confirm-title">
        <h1 id="professional-confirm-title" class="text-2xl font-bold">Revisa el efecto de guardar</h1>
        <p>{{ $notice }}</p>
        <p>Todavía no se ha guardado nada.</p>
        <ul class="list-disc pl-5">@foreach ($categories as $category)<li>{{ $category }}</li>@endforeach</ul>
        <p>No se muestran números de cédula ni documentos. Una nueva revisión no garantiza aprobación ni un plazo.</p>
        <form method="POST" action="{{ route('profile.professional.update') }}" data-turbo="false" class="space-y-4">@csrf @method('PUT')<input type="hidden" name="pending_change" value="{{ $pending_change }}"><input type="hidden" name="confirm_material" value="1"><button class="profile-button">Confirmar y guardar estos cambios</button></form>
        <a href="{{ route('profile.professional.edit') }}" class="profile-button-secondary">Cancelar y abrir los datos guardados</a>
        <p class="text-sm">Cancelar conserva los datos guardados; no conserva la propuesta. La confirmación caduca en cinco minutos. Si cambian los datos o el expediente, debes revisarlos de nuevo.</p>
    </section>
</div>
@endsection
