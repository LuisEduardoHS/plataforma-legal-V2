@extends('layouts.app')
@section('title', ($isProfessional ? 'Editar presentación profesional' : 'Editar perfil').' | Plataforma Legal')
@section('page-assets')
    @vite(['resources/css/professionals.css'])
@endsection
@section('content')
<div class="profile-editor min-h-screen bg-bg dark:bg-bg-dark text-text dark:text-text-dark pb-24">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 py-8 space-y-6">
        <a href="{{ route('profile') }}" class="underline">← Volver a mi perfil</a>
        <h1 class="text-2xl font-bold">{{ $isProfessional ? 'Presentación profesional' : 'Editar mi perfil' }}</h1>
        @if ($errors->any())<div role="alert" class="profile-panel"><h2 class="font-semibold">Revisa el formulario</h2><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @if ($isProfessional)<p class="text-sm">Crear este perfil no acredita credenciales ni concede permisos. Los cambios de presentación, cédula, experiencia, modalidad, ubicación o especialidades invalidan la verificación existente. <a class="underline" href="{{ route('verification') }}">Consultar mi verificación y solicitudes</a>.</p>@endif
        <form method="GET" class="profile-panel" action="{{ route($isProfessional ? 'profile.professional.edit' : 'profile.edit') }}">
            <h2 class="font-semibold">Ubicación</h2>
            <p class="text-sm">Elige el estado y carga sus municipios antes de editar. Cargar el catálogo vuelve a abrir el formulario sin guardar los cambios.</p>
            <div class="profile-field"><label for="state_id">Estado del catálogo</label><select name="state_id" id="state_id"><option value="">Seleccionar estado</option>@foreach ($states as $state)<option value="{{ $state->id }}" @selected((string) $selectedState === (string) $state->id)>{{ $state->name }}</option>@endforeach</select></div>
            <button class="profile-button-secondary">Cargar municipios</button>
        </form>
        <form method="POST" action="{{ route($isProfessional ? 'profile.professional.update' : 'profile.update') }}" class="profile-panel space-y-5">
            @csrf @method('PUT')
            @if (! $isProfessional)
                <x-profiles.field name="display_name" label="Nombre público (opcional)" :value="$profile['display_name'] ?? null" maxlength="150" autocomplete="nickname" />
                <p class="text-sm">Si lo dejas vacío, se mostrará el nombre de tu cuenta cuando hagas público este perfil.</p>
                <x-profiles.field name="username" label="Alias" :value="$profile['username'] ?? null" maxlength="30" minlength="3" pattern="[a-zA-Z][a-zA-Z0-9_]{2,29}" autocapitalize="none" spellcheck="false" />
                <p class="text-sm">De 3 a 30 caracteres: letras sin acentos, números o guion bajo. Debe comenzar con una letra.</p>
                <x-profiles.field name="biography" label="Biografía" :value="$profile['biography'] ?? null" :multiline="true" maxlength="2000" rows="5" />
            @else
                <x-profiles.field name="headline" label="Presentación breve" :value="$profile['headline'] ?? null" maxlength="180" />
                <x-profiles.field name="description" label="Descripción profesional" :value="$profile['description'] ?? null" :multiline="true" maxlength="10000" rows="7" />
                <x-profiles.field name="years_experience" label="Años de experiencia" type="number" :value="$profile['years_experience'] ?? null" min="0" max="80" step="1" />
                <div class="profile-field"><label for="attention_mode">Modalidad de atención</label><select id="attention_mode" name="attention_mode"><option value="">Sin indicar</option>@foreach (['in_person' => 'Presencial', 'remote' => 'En línea', 'hybrid' => 'Presencial y en línea'] as $key => $label)<option value="{{ $key }}" @selected(old('attention_mode', $profile['attention_mode'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></div>
                <fieldset><legend class="font-semibold">Especialidades</legend>
                    @if (! empty($profile['specialties']))<p class="text-sm mt-2">Registradas: {{ implode(' · ', $profile['specialties']) }}. Las especialidades desactivadas no se muestran públicamente.</p>@endif
                    <div class="grid sm:grid-cols-2 gap-3 mt-3">@foreach ($specialties as $specialty)<label class="flex items-start gap-2"><input type="checkbox" name="specialty_ids[]" value="{{ $specialty->id }}" @checked(in_array($specialty->id, old('specialty_ids', $profile['specialty_ids'] ?? [])))>{{ $specialty->name }}</label>@endforeach</div>
                    <label class="flex items-start gap-2 mt-4"><input type="checkbox" name="update_specialties" value="1" @checked(old('update_specialties'))>Aplicar esta selección de especialidades</label>
                    <p class="text-sm mt-2">Marca la opción para reemplazar las especialidades registradas, incluidas las desactivadas. Una selección vacía las quitará todas.</p>
                </fieldset>
                <p class="text-sm">{{ ($profile['license_registered'] ?? false) ? 'Cédula registrada. Su número permanece oculto.' : 'No has registrado una cédula.' }}</p>
                <x-profiles.field name="license_number" label="Registrar o reemplazar cédula (privada)" type="password" inputmode="numeric" minlength="5" maxlength="12" pattern="[0-9]{5,12}" autocomplete="new-password" />
                <p class="text-sm">Déjala vacía para conservar la actual. Registrar un número no equivale a verificarlo.</p>
                <x-profiles.field name="contact_email" label="Correo profesional de contacto" type="email" :value="$profile['contact_email'] ?? null" maxlength="254" />
                <x-profiles.field name="contact_phone" label="Teléfono profesional de contacto" type="tel" :value="$profile['contact_phone'] ?? null" maxlength="30" pattern="[+0-9() .\-]{7,30}" />
                <input type="hidden" name="contact_visible" value="0">
                <label class="flex items-start gap-2"><input type="checkbox" name="contact_visible" value="1" @checked(old('contact_visible', $profile['contact_visible'] ?? false))>Autorizo publicar este correo y teléfono en mi presentación profesional pública.</label>
            @endif
            <div class="profile-field"><label for="municipality_id">Municipio</label><select id="municipality_id" name="municipality_id"><option value="">Sin ubicación</option>@foreach ($municipalities as $municipality)<option value="{{ $municipality->id }}" @selected((string) old('municipality_id', $profile['municipality_id'] ?? '') === (string) $municipality->id)>{{ $municipality->name }}</option>@endforeach</select></div>
            <x-profiles.field name="website" label="Sitio web" type="url" :value="$profile['website'] ?? null" maxlength="2048" placeholder="https://" />
            <div class="profile-field"><label for="visibility">Visibilidad de este perfil</label><select id="visibility" name="visibility" required><option value="private" @selected(old('visibility', $profile['visibility'] ?? 'private') === 'private')>Privado</option><option value="public" @selected(old('visibility', $profile['visibility'] ?? 'private') === 'public')>Público</option></select></div>
            <p class="text-sm">La visibilidad personal y la profesional son independientes. El correo de tu cuenta, la cédula y los documentos nunca se publican.</p>
            <button class="profile-button">Guardar {{ $isProfessional ? 'presentación profesional' : 'perfil' }}</button>
        </form>
        @if (! $isProfessional)
            <section class="profile-panel space-y-4"><h2 class="text-lg font-semibold">Fotografía privada</h2><p class="text-sm">Puedes cargar una imagen JPG, PNG, GIF o WebP de hasta 10 MiB y descargarla desde tu perfil. La publicación de fotografías todavía no está disponible; se muestra una inicial en los perfiles públicos.</p>
                <form method="POST" action="{{ route('profile.photo.store') }}" enctype="multipart/form-data" class="space-y-4">@csrf<x-profiles.field name="photo" label="Seleccionar fotografía" type="file" accept="image/jpeg,image/png,image/gif,image/webp" required /><button class="profile-button">Guardar fotografía privada</button></form>
                <form method="POST" action="{{ route('profile.photo.destroy') }}">@csrf @method('DELETE')<button class="profile-button-secondary">Quitar fotografía del perfil</button></form>
            </section>
        @else
            <p class="text-sm">La fotografía pertenece al perfil personal. <a class="underline" href="{{ route('profile.edit') }}">Administrar mi fotografía privada</a>.</p>
        @endif
    </div>
</div>
@endsection
