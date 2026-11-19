@props(['fields' => [], 'active' => true])
@if ($active && $errors->any())
    <div {{ $attributes->class('ui-error-summary') }} role="alert" tabindex="-1" data-validation-summary>
        <h2>Revisa el formulario</h2>
        <ul>@foreach ($errors->getMessages() as $field => $messages)
            @php($target = $fields[$field] ?? $fields[explode('.', $field)[0]] ?? null)
            @foreach ($messages as $message)<li>@if ($target)<a href="#{{ $target }}">{{ $message }}</a>@else{{ $message }}@endif</li>@endforeach
        @endforeach</ul>
    </div>
@endif
