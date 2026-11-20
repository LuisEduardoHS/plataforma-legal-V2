@extends('layouts.app')
@section('title', 'Editar mensaje | Lefenda')
@section('page-assets') @vite(['resources/css/messages.css']) @endsection
@section('content')
<section class="messages-edit"><h1>Editar mensaje</h1><p>Solo cambia el texto. Los archivos asociados se conservan.</p><x-ui.error-summary :fields="['content' => 'message-edit-content']" />
<form method="POST" action="{{ route('messages.update', ['conversation' => $context['id'], 'message' => $item['id']]) }}">@csrf @method('PUT')<input type="hidden" name="version" value="{{ old('version', $item['version']) }}"><x-ui.input name="content" id="message-edit-content" label="Contenido" multiline :value="$item['content']" maxlength="10000" rows="10" /><div><button class="messages-action">Guardar cambios</button><a class="messages-action" href="{{ route('messages.show', $context['id']) }}">Cancelar</a></div></form></section>
@endsection
