@props(['author'])
@if ($author['professional_public'] ?? false)
<span class="inline-flex flex-wrap gap-2 items-center text-xs"><a class="underline" href="{{ route('professionals.show', $author['id']) }}">Perfil profesional</a>@if ($author['professional_verified'] ?? false)<x-profiles.verification status="verified" />@endif</span>
@endif
