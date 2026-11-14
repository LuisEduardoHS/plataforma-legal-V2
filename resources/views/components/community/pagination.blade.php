@props(['page'])
@if ($page && ($page->previousPageUrl() || $page->nextPageUrl()))<nav class="community-pagination" aria-label="Paginación por cursor">@if ($page->previousPageUrl())<a class="community-secondary" href="{{ $page->previousPageUrl() }}">Anterior</a>@endif @if ($page->nextPageUrl())<a class="community-primary" href="{{ $page->nextPageUrl() }}">Siguiente</a>@endif</nav>@endif
