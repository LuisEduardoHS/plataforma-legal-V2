@extends('layouts.app')
@section('title', ($isProfessional ? 'Editar presentación profesional' : 'Editar perfil').' | Plataforma Legal')
@section('page-assets')
    @vite(['resources/css/professionals.css'])
@endsection
@section('content')
<div data-profile-location data-location-catalog-state="{{ $selectedState }}" data-municipalities-url="{{ route($isProfessional ? 'profile.professional.edit' : 'profile.edit') }}" class="profile-editor min-h-screen bg-bg dark:bg-bg-dark text-text dark:text-text-dark pb-24">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 py-8 space-y-6">
        <a href="{{ route('profile') }}" class="underline">← Volver a mi perfil</a>
        <h1 class="text-2xl font-bold">{{ $isProfessional ? 'Presentación profesional' : 'Editar mi perfil' }}</h1>
        @if ($errors->any())<div role="alert" class="profile-panel"><h2 class="font-semibold">Revisa el formulario</h2><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @if ($isProfessional)<p class="text-sm">Crear este perfil no acredita credenciales ni concede permisos. Los cambios de presentación, cédula, experiencia, modalidad, ubicación o especialidades invalidan la verificación existente. <a class="underline" href="{{ route('verification') }}">Consultar mi verificación y solicitudes</a>.</p>@endif
        <form data-location-form method="GET" class="profile-panel" action="{{ route($isProfessional ? 'profile.professional.edit' : 'profile.edit') }}">
            <h2 class="font-semibold">Ubicación</h2>
            <div data-location-fallback class="text-sm space-y-3">
                <p>Sin JavaScript, cargar municipios recarga la página y descarta los cambios sin guardar, la cédula escrita y los archivos seleccionados. Guarda primero tus cambios, vuelve a editar y carga el catálogo antes de continuar.</p>
                <button type="submit" form="profile-edit-form" class="profile-button-secondary">Guardar primero mis cambios</button>
                <label class="flex items-start gap-2"><input data-location-ack type="checkbox" required>Entiendo que cargar municipios y recargar descarta los cambios sin guardar.</label>
            </div>
            <div class="profile-field"><label for="state_id">Estado del catálogo</label><select data-location-state name="state_id" id="state_id" aria-describedby="profile-location-status"><option value="">Seleccionar estado</option>@foreach ($states as $state)<option value="{{ $state->id }}" @selected((string) $selectedState === (string) $state->id)>{{ $state->name }}</option>@endforeach</select></div>
            <button data-location-load type="submit" class="profile-button-secondary">Cargar municipios y recargar</button>
            <p id="profile-location-status" data-location-status role="status" aria-live="polite" aria-atomic="true" class="text-sm"></p>
        </form>
        <form id="profile-edit-form" data-profile-edit-form @if ($isProfessional) data-professional-edit @endif method="POST" action="{{ route($isProfessional ? 'profile.professional.prepare' : 'profile.update') }}" class="profile-panel space-y-5" @if ($isProfessional) data-turbo="false" @endif>
            @csrf
            @if ($isProfessional)<input type="hidden" name="profile_version" value="{{ old('profile_version', $editorVersion) }}">@else @method('PUT') @endif
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
                    @if (! empty($profile['inactive_specialties']))<p class="text-sm mt-2">Inactivas registradas: {{ implode(' · ', $profile['inactive_specialties']) }}. Guardar otros datos las conserva; aplicar la selección activa requerirá confirmar su retirada.</p>@endif
                    <div class="grid sm:grid-cols-2 gap-3 mt-3">@foreach ($specialties as $specialty)<label class="flex items-start gap-2"><input type="checkbox" name="specialty_ids[]" value="{{ $specialty->id }}" @checked(in_array($specialty->id, old('specialty_ids', $profile['specialty_ids'] ?? [])))>{{ $specialty->name }}</label>@endforeach</div>
                    <p class="text-sm mt-2" id="specialties-intention">Elige «Guardar datos y aplicar selección» para reemplazar las especialidades registradas. Una selección vacía las quitará todas. Si hay asociaciones inactivas, se pedirá confirmación antes de retirarlas. «Guardar otros datos y conservar especialidades» no modifica ninguna asociación.</p>
                    @if ($errors->any() && old('update_specialties') !== null)<p class="text-sm">En el último envío elegiste {{ old('update_specialties') ? 'aplicar la selección' : 'conservar las especialidades registradas' }}.</p>@endif
                </fieldset>
                <p class="text-sm">{{ ($profile['license_registered'] ?? false) ? 'Cédula registrada. Su número permanece oculto.' : 'No has registrado una cédula.' }}</p>
                <x-profiles.field name="license_number" label="Registrar o reemplazar cédula (privada)" type="password" inputmode="numeric" minlength="5" maxlength="12" pattern="[0-9]{5,12}" autocomplete="new-password" />
                <p class="text-sm">Déjala vacía para conservar la actual. Registrar un número no equivale a verificarlo.</p>
                <x-profiles.field name="contact_email" label="Correo profesional de contacto" type="email" :value="$profile['contact_email'] ?? null" maxlength="254" />
                <x-profiles.field name="contact_phone" label="Teléfono profesional de contacto" type="tel" :value="$profile['contact_phone'] ?? null" maxlength="30" pattern="[+0-9() .\-]{7,30}" />
                <input type="hidden" name="contact_visible" value="0">
                <label class="flex items-start gap-2"><input type="checkbox" name="contact_visible" value="1" @checked(old('contact_visible', $profile['contact_visible'] ?? false))>Autorizo publicar este correo y teléfono en mi presentación profesional pública.</label>
            @endif
            <div class="profile-field"><label for="municipality_id">Municipio</label><select data-location-municipality id="municipality_id" name="municipality_id" aria-describedby="profile-location-status{{ $errors->has('municipality_id') ? ' municipality_id-error' : '' }}" @if ($errors->has('municipality_id')) aria-invalid="true" @endif><option value="" @selected($selectedMunicipality === '')>Sin ubicación</option>@foreach ($municipalities as $municipality)<option value="{{ $municipality->id }}" @selected($selectedMunicipality === (string) $municipality->id)>{{ $municipality->name }}</option>@endforeach</select>@error('municipality_id')<p id="municipality_id-error" class="text-sm">{{ $message }}</p>@enderror</div>
            <x-profiles.field name="website" label="Sitio web" type="url" :value="$profile['website'] ?? null" maxlength="2048" placeholder="https://" />
            <div class="profile-field"><label for="visibility">Visibilidad de este perfil</label><select id="visibility" name="visibility" required><option value="private" @selected(old('visibility', $profile['visibility'] ?? 'private') === 'private')>Privado</option><option value="public" @selected(old('visibility', $profile['visibility'] ?? 'private') === 'public')>Público</option></select></div>
            <p class="text-sm">La visibilidad personal y la profesional son independientes. El correo de tu cuenta, la cédula y los documentos nunca se publican.</p>
            @if ($isProfessional)
                <p data-professional-feedback role="status" aria-live="polite" class="text-sm"></p>
                @php($preserveSpecialties = $errors->any() && old('update_specialties') !== null && ! filter_var(old('update_specialties'), FILTER_VALIDATE_BOOLEAN))
                <div class="flex flex-wrap gap-3">
                    @if ($preserveSpecialties)<button class="profile-button" name="update_specialties" value="0" aria-describedby="specialties-intention">Guardar otros datos y conservar especialidades</button>@endif
                    <button class="profile-button" name="update_specialties" value="1" aria-describedby="specialties-intention">Guardar datos y aplicar selección</button>
                    @unless ($preserveSpecialties)<button class="profile-button-secondary" name="update_specialties" value="0" aria-describedby="specialties-intention">Guardar otros datos y conservar especialidades</button>@endunless
                </div>
            @else<button class="profile-button">Guardar perfil</button>@endif
        </form>
        @if ($isProfessional)
            <div data-professional-confirmation hidden>
                <button type="button" data-dialog-open class="profile-button-secondary">Revisar confirmación pendiente</button>
                <dialog id="professional-material-dialog" class="professional-dialog profile-panel" aria-labelledby="professional-material-title" aria-describedby="professional-material-description">
                    <h2 id="professional-material-title" class="text-xl font-bold">Revisa el efecto de guardar</h2>
                    <p id="professional-material-description" data-professional-description></p>
                    <p>Todavía no se ha guardado nada. Una nueva revisión no garantiza aprobación ni un plazo.</p>
                    <ul data-professional-categories class="list-disc pl-5 my-4"></ul>
                    <button type="button" data-dialog-close data-dialog-initial class="profile-button-secondary">Cancelar y seguir editando</button>
                    <form data-professional-final method="POST" action="{{ route('profile.professional.update') }}" data-turbo="false">@csrf @method('PUT')<input type="hidden" name="pending_change" value=""><input type="hidden" name="confirm_material" value="1"><button class="profile-button">Confirmar y guardar estos cambios</button></form>
                </dialog>
            </div>
        @endif
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
