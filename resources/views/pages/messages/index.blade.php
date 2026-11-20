@extends('layouts.app')
@section('title', 'Mensajes | Plataforma Legal')
@section('page-assets') @vite(['resources/css/messages.css']) @endsection
@section('content')
<section class="messages" data-messaging data-view="{{ $context ? 'chat' : 'list' }}" @if ($context) data-poll-url="{{ route('messages.updates', ['conversation' => $context['id']] + $filters) }}" data-can-send="{{ $context['can_send'] ? 'true' : 'false' }}" @endif data-historical="{{ $historical || request()->filled('cursor') ? 'true' : 'false' }}" aria-label="Mensajes">
    @if ($context)@include('pages.messages.partials.recovery')@endif
    <div class="messages-layout">
        <x-messages.conversations :inbox="$inbox" :context="$context" :archived="$archived" :filters="$filters" />
        <section class="messages-chat" aria-labelledby="messages-contact-name">
            <header class="messages-chat-header"><a class="messages-icon messages-back" href="{{ route('messages', $filters) }}" aria-label="Volver a conversaciones"><x-lucide-arrow-left aria-hidden="true" /></a><div class="messages-chat-identity"><span class="messages-eyebrow">Chat privado</span><div class="messages-contact"><span class="messages-avatar" aria-hidden="true">{{ $context ? mb_strtoupper(mb_substr($context['participant']['display_name'],0,1)) : '' }}</span><h1 id="messages-contact-name">{{ $context['participant']['display_name'] ?? 'Selecciona una conversación' }}</h1></div></div>@if ($context)<button type="button" class="messages-icon" data-open-info aria-label="Información de la conversación"><x-lucide-info aria-hidden="true" /></button>@endif</header>
            <div class="messages-chat-body">
                @if (session('status'))<p class="messages-notice" role="status">{{ session('status') }}</p>@endif
                @if ($errors->any())<div class="messages-notice" role="alert">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
                <p class="messages-notice" role="status" data-poll-status>{{ $historical ? 'Viendo mensajes anteriores. La actualización automática está pausada.' : '' }}</p>
                <div class="messages-history" data-history role="region" aria-label="Historial de mensajes" tabindex="0">@if ($context)@include('pages.messages.partials.history')@else<p class="messages-empty-chat">Selecciona una conversación o inicia una desde un perfil público o el directorio profesional.</p>@endif</div>
                @if ($context)<x-messages.composer :context="$context" :composer="$composer" />@endif
            </div>
        </section>
        @if ($context)<x-messages.information :context="$context" :history="$history" /><dialog class="messages-info-dialog" data-info-dialog aria-label="Información de la conversación"></dialog>@endif
    </div>
</section>
@endsection
