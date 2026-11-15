@props(['page'])
@if ($page->previousPageUrl() || $page->nextPageUrl())
<nav class="content-pagination" aria-label="Paginación">@if ($page->previousPageUrl())<a href="{{ $page->previousPageUrl() }}">Anterior</a>@endif @if ($page->nextPageUrl())<a href="{{ $page->nextPageUrl() }}">Siguiente</a>@endif</nav>
@endif
