@extends('layouts.app')
@section('title', 'Confirmar cambios profesionales | Lefenda')
@section('page-assets')
    @vite(['resources/css/professionals.css'])
@endsection
@section('content')
<div class="profile-editor min-h-screen bg-bg pb-20 text-text dark:bg-bg-dark dark:text-text-dark">
    <section class="mx-auto max-w-[760px] px-4 py-6 sm:px-6 sm:py-8" aria-labelledby="professional-confirm-title">
        <header class="mb-5 rounded-[10px] bg-white p-5 shadow-xs dark:bg-surface-dark sm:p-6">
            <div class="flex items-start gap-3"><span class="grid size-11 shrink-0 place-items-center rounded-[10px] bg-accent-bg-hover text-dark-accent dark:bg-accent-bg-hover-dark dark:text-accent-text-dark"><x-lucide-file-check-2 class="size-5" /></span><div><p class="text-xs font-semibold uppercase tracking-[0.12em] text-dark-accent dark:text-accent-text-dark">Cambios profesionales</p><h1 id="professional-confirm-title" class="mt-1 text-2xl font-bold">Revisa el efecto de guardar</h1></div></div>
        </header>
        <div class="profile-panel !rounded-[10px] border-0! shadow-xs space-y-5">
        <p>{{ $notice }}</p>
        <p class="rounded-[10px] bg-accent-bg-hover px-4 py-3 text-sm dark:bg-accent-bg-hover-dark">Todavía no se ha guardado nada.</p>
        <ul class="list-disc pl-5">@foreach ($categories as $category)<li>{{ $category }}</li>@endforeach</ul>
        <p>No se muestran números de cédula ni documentos. Una nueva revisión no garantiza aprobación ni un plazo.</p>
        <form method="POST" action="{{ route('profile.professional.update') }}" data-turbo="false" class="space-y-4">@csrf @method('PUT')<input type="hidden" name="pending_change" value="{{ $pending_change }}"><input type="hidden" name="confirm_material" value="1"><button class="profile-button">Confirmar y guardar estos cambios</button></form>
        <a href="{{ route('profile.professional.edit') }}" class="profile-button-secondary">Cancelar y abrir los datos guardados</a>
        <p class="text-sm">Cancelar conserva los datos guardados; no conserva la propuesta. La confirmación caduca en cinco minutos. Si cambian los datos o el expediente, debes revisarlos de nuevo.</p>
        </div>
    </section>
</div>
@endsection
