@props(['name', 'label', 'value' => null, 'type' => 'text', 'multiline' => false])
<div class="profile-field">
    <label for="{{ $name }}">{{ $label }}</label>
    @if ($multiline)
        <textarea id="{{ $name }}" name="{{ $name }}" {{ $attributes }} @if ($errors->has($name)) aria-invalid="true" aria-describedby="error-{{ $name }}" @endif>{{ old($name, $value) }}</textarea>
    @else
        <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}" value="{{ $type === 'password' ? '' : old($name, $value) }}" {{ $attributes }} @if ($errors->has($name)) aria-invalid="true" aria-describedby="error-{{ $name }}" @endif>
    @endif
    @error($name)<p id="error-{{ $name }}" class="profile-error">{{ $message }}</p>@enderror
</div>
