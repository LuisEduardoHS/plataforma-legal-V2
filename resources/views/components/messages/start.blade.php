@props(['recipient', 'profileType' => 'professional'])
@if (auth()->id() !== (int) $recipient)
    @auth<form method="POST" action="{{ route('messages.start', $recipient) }}">@csrf<button class="professional-view inline-flex items-center gap-2 rounded-ui px-3 py-2 text-accent dark:text-accent-text-dark"><x-lucide-message-circle class="w-4 h-4" aria-hidden="true" />Enviar mensaje</button></form>
    @else<a class="inline-block underline mt-2" href="{{ App\Services\Auth\ReadingReturn::loginUrl($profileType, $recipient) }}">Inicia sesión para enviar un mensaje</a>@endauth
@endif
