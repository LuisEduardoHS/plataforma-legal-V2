@props(['inbox','context','archived','filters'])
<aside class="messages-conversations" aria-labelledby="messages-list-title">
    <div class="messages-list-heading">
        <div class="mb-3 flex items-center justify-between gap-2">
            <h2 id="messages-list-title" class="!mb-0 !text-left">Conversaciones</h2>
            <a href="{{ route('professionals') }}" class="inline-flex min-h-8 items-center gap-1.5 rounded-full bg-accent/10 px-3 text-xs font-semibold text-accent transition-colors hover:bg-accent hover:text-white dark:bg-accent-text-dark/15 dark:text-accent-text-dark dark:hover:bg-accent dark:hover:text-white" title="Buscar personas para iniciar chat">
                <x-lucide-user-plus class="!size-3.5" aria-hidden="true" />
                <span>Nuevo</span>
            </a>
        </div>
        <form method="GET" action="{{ route('professionals') }}" class="messages-search !rounded-full" role="search" aria-label="Buscar personas para iniciar conversación">
            <label for="messages-people-search" class="sr-only">Buscar personas</label>
            <x-lucide-search class="text-light-text dark:text-light-text-dark" aria-hidden="true" />
            <input id="messages-people-search" type="search" name="q" placeholder="Buscar personas..." autocomplete="off">
        </form>
        <nav class="messages-filters" aria-label="Bandejas">
            <a href="{{ route('messages') }}" class="!min-h-8 flex-1 rounded-[var(--radius-ui)] text-center text-xs font-medium transition-colors {{ !$archived ? 'bg-accent text-white' : 'text-light-text dark:text-light-text-dark' }}" @if (!$archived) aria-current="page" @endif>Activas</a>
            <a href="{{ route('messages', ['archived'=>1]) }}" class="!min-h-8 flex-1 rounded-[var(--radius-ui)] text-center text-xs font-medium transition-colors {{ $archived ? 'bg-accent text-white' : 'text-light-text dark:text-light-text-dark' }}" @if ($archived) aria-current="page" @endif>Archivadas</a>
        </nav>
    </div>
    <div class="messages-conversation-list" data-conversation-list>@include('pages.messages.partials.inbox')</div>
</aside>
