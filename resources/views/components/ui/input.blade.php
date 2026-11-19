
@props(['name', 'label' => null, 'type' => 'text', 'placeholder' => '', 'value' => null, 'multiline' => false, 'errorActive' => true, 'restoreOld' => true])
@php
    $inputId = $attributes->get('id', $name);
    $hasError = $errorActive && $errors->has($name);
    $private = in_array($type, ['password', 'file'], true);
    $inputValue = $private ? '' : ($restoreOld ? old($name, $value) : $value);
    $inputValue = is_scalar($inputValue) ? $inputValue : '';
    $descriptions = trim(($attributes->get('aria-describedby') ?? '').($hasError ? ' '.$inputId.'-error' : ''));
    $control = $attributes->except(['id', 'aria-invalid', 'aria-describedby', 'value'])->class('ui-input');
@endphp
<div class="ui-field">
    @if ($label)<label for="{{ $inputId }}">{{ $label }}</label>@endif
    @if ($multiline)
        <textarea id="{{ $inputId }}" name="{{ $name }}" placeholder="{{ $placeholder }}" aria-invalid="{{ $hasError ? 'true' : 'false' }}" @if ($descriptions) aria-describedby="{{ $descriptions }}" @endif {{ $control }}>{{ $inputValue }}</textarea>
    @else
        <input id="{{ $inputId }}" name="{{ $name }}" type="{{ $type }}" placeholder="{{ $placeholder }}" @unless ($private) value="{{ $inputValue }}" @endunless aria-invalid="{{ $hasError ? 'true' : 'false' }}" @if ($descriptions) aria-describedby="{{ $descriptions }}" @endif {{ $control }}>
    @endif
    <x-ui.field-error :field="$name" :id="$inputId.'-error'" :active="$errorActive" />
</div>
