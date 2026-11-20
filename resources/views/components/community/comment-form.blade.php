@props(['postId', 'parentId' => null])
@php
    $inputId = 'comment-content-'.($parentId ?? 'root');
    $sameForm = (string) old('parent_id', '') === (string) ($parentId ?? '');
    $invalid = $sameForm && $errors->has('content');
@endphp
<form method="POST" action="{{ route('community.comments.store', $postId) }}" class="community-comment-form">
    @csrf @if ($parentId)<input type="hidden" name="parent_id" value="{{ $parentId }}">@endif
    <label for="{{ $inputId }}" class="community-sr-only">{{ $parentId ? 'Respuesta al comentario '.$parentId : 'Escribir un comentario' }}</label>
    <textarea name="content" id="{{ $inputId }}" maxlength="{{ config('community.comment_max_length') }}" rows="{{ $parentId ? 2 : 3 }}" placeholder="{{ $parentId ? 'Escribe una respuesta...' : 'Escribe un comentario para la comunidad...' }}" required @if ($invalid) aria-invalid="true" aria-describedby="{{ $inputId }}-error" @endif>{{ $sameForm ? old('content', '') : '' }}</textarea>
    <x-community.field-error field="content" :id="$inputId.'-error'" :active="$sameForm" />
    <div class="community-comment-form-footer">
        <p class="community-muted" data-submit-status role="status"></p>
        <button class="community-primary">{{ $parentId ? 'Responder' : 'Comentar' }}</button>
    </div>
</form>
