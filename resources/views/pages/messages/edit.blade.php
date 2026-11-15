@extends('layouts.app')
@section('title', 'Editar mensaje | Plataforma Legal')
@section('page-assets') @vite(['resources/css/messages.css']) @endsection
@section('content')
<section class="messages-edit"><h1>Editar mensaje</h1><p>Solo cambia el texto. Los archivos asociados se conservan.</p>@if ($errors->any())<div role="alert">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
<form method="POST" action="{{ route('messages.update', ['conversation' => $context['id'], 'message' => $item['id']]) }}">@csrf @method('PUT')<input type="hidden" name="version" value="{{ old('version', $item['version']) }}"><label>Contenido<textarea name="content" maxlength="10000" rows="10">{{ old('content', $item['content']) }}</textarea></label><div><button class="messages-action">Guardar cambios</button><a class="messages-action" href="{{ route('messages.show', $context['id']) }}">Cancelar</a></div></form></section>
@endsection
