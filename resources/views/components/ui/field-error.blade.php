@props(['field', 'id', 'active' => true])
@php($messages = $active ? array_merge($errors->get($field), $errors->get($field.'.*')) : [])
@if ($messages)
    <div id="{{ $id }}" class="ui-field-error">@foreach (\Illuminate\Support\Arr::flatten($messages) as $message)<p>{{ $message }}</p>@endforeach</div>
@endif
