@extends('layouts.verification')
@section('verification-title', ($review ? 'Revisar expediente ' : 'Mi expediente ').$dossier['id'])
@section('verification-content')
<section class="profile-panel space-y-4">
    <h2 class="font-semibold text-lg">Estado de la solicitud</h2><x-verification.status :status="$dossier['status']" />
    @if ($dossier['reason'])<div><h3 class="font-semibold">Observaciones comunicadas al solicitante</h3><p class="whitespace-pre-wrap break-words">{{ $dossier['reason'] }}</p></div>@endif
    @if ($dossier['submitted_at'])<p class="text-sm break-words">Presentada: {{ $dossier['submitted_at'] }} UTC</p>@endif
    @if ($dossier['resolved_at'])<p class="text-sm break-words">Resolución: {{ $dossier['resolved_at'] }} UTC</p>@endif
    @if ($can_audit)<a class="underline inline-block" href="{{ route('verification.history', $dossier['id']) }}">Consultar historial autorizado</a>@endif
    @if (in_array($dossier['status'], ['rejected', 'revoked', 'invalidated'], true))<p class="text-sm">Este expediente se conserva y no se reabre. Puedes corregir tus datos profesionales e iniciar una solicitud nueva desde Mi verificación.</p>@endif
</section>
@if ($review)
<section class="profile-panel space-y-4"><h2 class="font-semibold text-lg">Datos para revisión interna</h2>
    @if ($profile_matches && $material)
        <p class="text-sm">Estos datos materiales coinciden con los presentados. Consúltalos sólo para resolver este expediente; no copies la cédula ni datos sensibles en las observaciones.</p>
        <dl class="grid sm:grid-cols-2 gap-4 text-sm"><div><dt class="font-semibold">Título profesional</dt><dd class="break-words">{{ $material['headline'] }}</dd></div><div><dt class="font-semibold">Cédula declarada por el solicitante</dt><dd class="break-all">{{ $material['license_number'] }}</dd></div><div><dt class="font-semibold">Experiencia declarada</dt><dd>{{ $material['years_experience'] ?? 'Sin indicar' }}</dd></div><div><dt class="font-semibold">Modalidad declarada</dt><dd>{{ ['in_person' => 'Presencial', 'remote' => 'En línea', 'hybrid' => 'Mixta'][$material['attention_mode']?->value ?? ''] ?? 'Sin indicar' }}</dd></div></dl>
        <p class="whitespace-pre-wrap break-words text-sm">{{ $material['description'] }}</p><p class="text-sm">Especialidades: {{ implode(' · ', $specialties) }}</p>
    @else<p class="text-sm">Los datos actuales ya no corresponden a la presentación. No se muestran como si fueran el perfil original ni se permite aprobarlos. El dominio no conserva una copia completa de las versiones del perfil; las evidencias y el historial permanecen protegidos.</p>@endif
</section>
@endif
<section class="profile-panel space-y-4"><h2 class="font-semibold text-lg">Evidencia documental privada</h2>
    <p class="text-sm">Se utiliza para la revisión humana de esta solicitud. Sólo tú y el personal con permiso documental pueden descargarla por este expediente. No se publica en tu perfil ni se utiliza en Comunidad o Mensajes. Los archivos presentados quedan retenidos; no existe liberación automática ni eliminación física desde esta pantalla.</p>
    @php($types = ['professional_license' => 'Cédula profesional', 'certificate' => 'Certificado', 'supporting_document' => 'Documento de apoyo'])
    @forelse ($evidences as $evidence)<article class="border-b border-gray-200 dark:border-border-dark py-3 space-y-2"><h3 class="font-semibold break-words">{{ $evidence['name'] }}</h3><p class="text-sm">{{ $types[$evidence['type']] }} · {{ max(1, (int) ceil($evidence['size_bytes'] / 1024)) }} KiB</p>
        @if ($can_download)<a class="underline inline-block" href="{{ route('verification.download', ['dossier' => $dossier['id'], 'evidence' => $evidence['id']]) }}" data-turbo="false">Descargar evidencia {{ $evidence['id'] }}</a>@else<p class="text-sm">Tu permiso de revisión no incluye la descarga documental.</p>@endif
        @if ($can_edit)<form method="POST" action="{{ route('verification.evidence.destroy', ['dossier' => $dossier['id'], 'evidence' => $evidence['id']]) }}">@csrf @method('DELETE')<input type="hidden" name="version" value="{{ $dossier['version'] }}"><button class="profile-button-secondary">Retirar del borrador</button></form>@endif
    </article>@empty<p>Todavía no hay documentos en este expediente.</p>@endforelse
    @if ($can_edit)
        @php($formats = array_filter(config('attachments.types'), fn ($type) => in_array($type['category'], ['image', 'document'], true)))
        <form method="POST" action="{{ route('verification.evidence.store', $dossier['id']) }}" enctype="multipart/form-data" class="pt-4">@csrf<input type="hidden" name="version" value="{{ $dossier['version'] }}">
            <h3 class="font-semibold">Añadir un documento</h3>
            <div class="profile-field"><label for="evidence-type">Propósito del documento</label><select name="type" id="evidence-type" required @error('type') aria-invalid="true" aria-describedby="evidence-type-error" @enderror>@foreach ($types as $value => $label)<option value="{{ $value }}" @selected(old('type', 'professional_license') === $value)>{{ $label }}</option>@endforeach</select>@error('type')<p id="evidence-type-error" class="profile-error">{{ $message }}</p>@enderror</div>
            <div class="profile-field"><label for="evidence-file">Archivo privado</label><input id="evidence-file" name="file" type="file" required accept="{{ implode(',', array_map(fn ($extension) => '.'.$extension, array_merge(...array_column($formats, 'extensions')))) }}" aria-describedby="evidence-file-hint{{ $errors->has('file') ? ' evidence-file-error' : '' }}" @error('file') aria-invalid="true" @enderror><p id="evidence-file-hint" class="text-xs">Hasta {{ config('verification.max_evidences') }} evidencias. Máximo por evidencia {{ config('verification.max_evidence_bytes') / 1048576 }} MiB; imágenes {{ min(config('verification.max_evidence_bytes'), config('attachments.max_bytes.image')) / 1048576 }} MiB y documentos {{ min(config('verification.max_evidence_bytes'), config('attachments.max_bytes.document')) / 1048576 }} MiB. No se admiten audio ni video. Los límites PHP/proxy también se aplican.</p>@error('file')<p id="evidence-file-error" class="profile-error">{{ $message }}</p>@enderror</div>
            <button class="profile-button" @disabled(count($evidences) >= config('verification.max_evidences'))>Añadir evidencia privada</button>
        </form>
    @endif
</section>
@if ($can_edit)
<section class="profile-panel space-y-4"><h2 class="font-semibold text-lg">Enviar a revisión</h2><p class="text-sm">Completa tus datos en el <a class="underline" href="{{ route('profile.professional.edit') }}">perfil profesional</a> y añade evidencia de tipo Cédula profesional. Al presentar se sellan los datos y documentos; ya no podrás modificar este expediente. Una edición material posterior del perfil invalida la solicitud o aprobación.</p><form method="POST" action="{{ route('verification.submit', $dossier['id']) }}">@csrf<input type="hidden" name="version" value="{{ $dossier['version'] }}"><button class="profile-button">Presentar solicitud a revisión</button></form></section>
@endif
@if ($can_decide || $can_revoke)
<section class="profile-panel space-y-4"><h2 class="font-semibold text-lg">Registrar decisión humana</h2><p class="text-sm" id="decision-hint">Justifica la decisión con 10 a 2000 caracteres. El solicitante podrá leer estas observaciones. No incluyas números de cédula, contenido documental, secretos ni notas internas.</p>
    <form method="POST" action="{{ route($can_revoke ? 'verification.revoke' : 'verification.reject', $dossier['id']) }}">@csrf<input type="hidden" name="version" value="{{ $dossier['version'] }}">
        <div class="profile-field"><label for="reason">Observaciones comunicables</label><textarea id="reason" name="reason" required minlength="10" maxlength="2000" rows="5" aria-describedby="decision-hint{{ $errors->has('reason') ? ' decision-reason-error' : '' }}" @error('reason') aria-invalid="true" @enderror>{{ old('reason', '') }}</textarea>@error('reason')<p id="decision-reason-error" class="profile-error">{{ $message }}</p>@enderror</div>
        <div class="flex flex-wrap gap-3">@if ($can_decide)<button class="profile-button" formaction="{{ route('verification.approve', $dossier['id']) }}" @disabled(! $can_approve)>Aprobar solicitud</button><button class="profile-button-secondary">Rechazar solicitud</button>@else<button class="profile-button">Revocar verificación</button>@endif</div>
    </form>
</section>
@elseif ($review)<p class="profile-panel text-sm">No hay decisiones disponibles para tus permisos y el estado actual. Abrir este expediente no concede permisos adicionales.</p>@endif
@endsection
