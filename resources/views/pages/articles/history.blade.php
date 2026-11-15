@extends('pages.articles.layout')
@section('library-title', 'Historial de revisiones')
@section('library-content')
<p class="content-notice">Historial privado de consulta. No restaura versiones ni permite descargar archivos históricos. Las descargas actuales se autorizan desde el contenido vigente.</p>
<div class="content-workspace">@foreach ($page as $revision)<article class="content-reading"><h2>Versión {{ $revision['version'] }}</h2><p class="content-muted">{{ $revision['created_at'] }} · {{ __('content.statuses.'.$revision['snapshot']['status'], [], 'es') }}</p><details><summary>{{ $revision['snapshot']['title'] }}</summary><div class="resource-detail-content">{{ $revision['snapshot']['summary'] ?? '' }}</div><div class="resource-detail-content">{{ $revision['snapshot']['body'] ?? $revision['snapshot']['description'] ?? '' }}</div><p>Clasificación histórica: {{ $revision['snapshot']['category']['name'] ?? 'Sin categoría' }}@foreach ($revision['snapshot']['tags'] as $tag) · #{{ $tag['name'] }}@endforeach @foreach ($revision['snapshot']['specialties'] as $specialty) · {{ $specialty['name'] }}@endforeach</p>@if (!empty($revision['snapshot']['external_url']))<p>Enlace registrado: {{ $revision['snapshot']['external_url'] }}</p>@endif<p class="content-muted">{{ count($revision['attachment_ids']) }} archivos retenidos.</p></details></article>@endforeach</div>
<x-content.pagination :page="$page" />
<a class="content-secondary" href="{{ route($kind->permission().'.workspace') }}">Volver al espacio editorial</a>
@endsection
