<aside class="messages-conversations" aria-labelledby="messages-list-title">
    <div class="messages-list-heading">
        <h2 id="messages-list-title">Conversaciones</h2>
        <p class="messages-list-description">Tus conversaciones en un solo lugar.</p>
        <label class="messages-search"><x-lucide-search aria-hidden="true" /><span class="messages-sr">Buscar conversaciones</span><input type="search" placeholder="Buscar…" data-search autocomplete="off"></label>
        <div class="messages-filters" role="group" aria-label="Filtrar conversaciones">
            <button type="button" data-list-filter="all" aria-pressed="true">Todas</button>
            <button type="button" data-list-filter="unread" aria-pressed="false">No leídas</button>
        </div>
    </div>
    <div class="messages-conversation-list" data-conversation-list></div>
    <p class="messages-list-empty" data-list-empty hidden>No hay conversaciones que coincidan con tu búsqueda.</p>
    <p class="messages-sr" role="status" data-list-status aria-live="polite"></p>
</aside>
