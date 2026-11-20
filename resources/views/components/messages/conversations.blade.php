@props(['inbox','context','archived','filters'])
<aside class="messages-conversations" aria-labelledby="messages-list-title">
    <div class="messages-list-heading">
        <div class="messages-list-top">
            <h2 id="messages-list-title">Conversaciones</h2>
            <a href="{{ route('professionals') }}" class="messages-new-chat" title="Buscar personas para iniciar chat">
                <x-lucide-user-plus aria-hidden="true" />
                <span>Nuevo</span>
            </a>
        </div>
        <form method="GET" action="{{ route('professionals') }}" class="messages-search" role="search" aria-label="Buscar personas para iniciar conversación">
            <label for="messages-people-search" class="sr-only">Buscar personas</label>
            <x-lucide-search aria-hidden="true" />
            <input id="messages-people-search" type="search" name="q" placeholder="Buscar en el directorio…" autocomplete="off">
        </form>
        <nav class="messages-filters" aria-label="Bandejas">
            <a href="{{ route('messages') }}" @if (!$archived) aria-current="page" @endif>Activas</a>
            <a href="{{ route('messages', ['archived'=>1]) }}" @if ($archived) aria-current="page" @endif>Archivadas</a>
        </nav>
    </div>
    <div class="messages-conversation-list" data-conversation-list>@include('pages.messages.partials.inbox')</div>
</aside>
