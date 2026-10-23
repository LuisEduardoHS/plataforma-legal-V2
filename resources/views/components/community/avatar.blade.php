@props(['initials' => null])
<span {{ $attributes->class(['community-avatar', 'community-avatar-initials' => $initials]) }} aria-hidden="true">
    @if ($initials) {{ $initials }} @else <x-lucide-user-round /> @endif
</span>
