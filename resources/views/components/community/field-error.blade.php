@props(['field', 'id', 'active' => true, 'also' => []])
@php($messages = $active ? collect($errors->getMessages())->filter(fn ($messages, $key) => in_array($key, [$field, ...$also], true) || str_starts_with($key, $field.'.'))->flatten()->all() : [])
@if ($messages)<div id="{{ $id }}" class="community-file-error">@foreach ($messages as $message)<p>{{ $message }}</p>@endforeach</div>@endif
