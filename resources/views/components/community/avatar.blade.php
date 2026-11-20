@props(['initials' => null, 'src' => null, 'alt' => ''])
<span {{ $attributes->class(['community-avatar', 'community-avatar-initials' => $initials && ! $src]) }} aria-hidden="{{ $src && $alt ? 'false' : 'true' }}">
    @if ($src)
        <img src="{{ $src }}" alt="{{ $alt }}" loading="lazy" decoding="async">
    @elseif ($initials)
        {{ $initials }}
    @else
        <x-lucide-user-round />
    @endif
</span>
