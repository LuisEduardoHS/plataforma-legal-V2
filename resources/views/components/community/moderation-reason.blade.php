@props(['suffix'])
@php
    $submitted = session('community.moderation_error') === $suffix;
    $invalid = $submitted && $errors->has('reason');
@endphp
<div class="space-y-2">
    <label for="moderation-reason-{{ $suffix }}">Motivo de moderación</label>
    <textarea class="w-full rounded border border-gray-300 dark:border-border-dark bg-white dark:bg-surface-dark p-2" id="moderation-reason-{{ $suffix }}" name="reason" required minlength="10" maxlength="2000" aria-describedby="moderation-help-{{ $suffix }}{{ $invalid ? ' moderation-error-'.$suffix : '' }}" @if ($invalid) aria-invalid="true" @endif>{{ $submitted ? old('reason') : '' }}</textarea>
    <p id="moderation-help-{{ $suffix }}" class="text-sm">Describe la decisión (10 a 2,000 caracteres). El motivo quedará registrado.</p>
    @if ($invalid)<p id="moderation-error-{{ $suffix }}" role="alert">{{ $errors->first('reason') }}</p>@endif
</div>
