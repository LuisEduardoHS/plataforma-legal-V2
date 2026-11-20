@props(['postId', 'parentId' => null])
@php
    $inputId = 'comment-content-'.($parentId ?? 'root');
    $sameForm = (string) old('parent_id', '') === (string) ($parentId ?? '');
    $invalid = $sameForm && $errors->has('content');
    $viewerName = auth()->user()?->name;
    $viewerInitial = $viewerName ? mb_strtoupper(mb_substr($viewerName, 0, 1)) : '';
@endphp
<form method="POST" action="{{ route('community.comments.store', $postId) }}" class="community-comment-form {{ $parentId ? 'community-comment-form--reply' : '' }}">
    @csrf @if ($parentId)<input type="hidden" name="parent_id" value="{{ $parentId }}">@endif
    <label for="{{ $inputId }}" class="community-sr-only">{{ $parentId ? 'Respuesta al comentario '.$parentId : 'Escribir un comentario' }}</label>
    <div class="community-comment-input-row">
        @if (! $parentId && ($viewerInitial || ! empty($viewerPhotoUrl)))
            <x-community.avatar :initials="$viewerInitial" :src="$viewerPhotoUrl ?? null" :alt="$viewerName ?? ''" />
        @endif
        <div class="community-comment-input-box">
            <textarea name="content" id="{{ $inputId }}" maxlength="{{ config('community.comment_max_length') }}" rows="{{ $parentId ? 2 : 3 }}" placeholder="{{ $parentId ? 'Escribe una respuesta...' : 'Escribe un comentario para la comunidad...' }}" required @if ($invalid) aria-invalid="true" aria-describedby="{{ $inputId }}-error" @endif>{{ $sameForm ? old('content', '') : '' }}</textarea>
            <div class="community-comment-form-footer">
                <p class="community-muted" data-submit-status role="status"></p>
                <button class="community-primary">{{ $parentId ? 'Responder' : 'Comentar' }}</button>
            </div>
        </div>
    </div>
    <x-community.field-error field="content" :id="$inputId.'-error'" :active="$sameForm" />
</form>
