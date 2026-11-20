@props(['name', 'username' => null, 'biography' => null, 'location' => null, 'website' => null, 'visibility' => 'private'])
<section class="bg-white dark:bg-surface-dark rounded-[10px] overflow-hidden shadow-xs border border-gray-100/50 dark:border-border-dark/50">
    <div class="h-36 sm:h-44 relative overflow-hidden bg-light-accent" aria-hidden="true">
        <svg class="absolute inset-0 w-full h-full" viewBox="0 0 523 119" preserveAspectRatio="none" fill="none"><path d="M190 119C260 55 330 65 380 84S470 90 523 48V119Z" fill="#9E1F52" /></svg>
    </div>
    <div class="relative px-6 pb-6 pt-3">
        <div class="flex flex-col sm:flex-row items-start gap-4">
            <span class="-mt-16 sm:-mt-20 size-24 sm:size-28 rounded-full border-4 border-white dark:border-surface-dark bg-bg dark:bg-bg-dark shrink-0 flex items-center justify-center text-3xl font-bold" aria-hidden="true">{{ mb_strtoupper(mb_substr($name, 0, 1)) }}</span>
            <div class="flex-1 min-w-0"><h1 class="text-xl sm:text-2xl font-bold break-words">{{ $name }}</h1>
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                    @if ($username)<p class="text-sm text-light-text dark:text-light-text-dark">{{ '@'.$username }}</p>@endif
                    <p class="inline-flex items-center gap-1.5 text-xs font-semibold text-light-text dark:text-light-text-dark">
                        @if ($visibility === 'public')
                            <x-lucide-globe class="size-3.5 shrink-0" />
                            <span>Perfil personal público</span>
                        @else
                            <x-lucide-lock class="size-3.5 shrink-0" />
                            <span>Perfil personal privado</span>
                        @endif
                    </p>
                </div>
                <p class="flex items-center gap-1.5 text-xs text-light-text dark:text-light-text-dark mt-2 -ml-3"><x-lucide-map-pin class="size-4 shrink-0" />{{ $location ?? 'Ubicación no indicada' }}</p>
            </div>
        </div>
        @if ($biography)<p class="whitespace-pre-line break-words text-sm mt-5">{{ $biography }}</p>@endif
        @if ($website)<a class="inline-block text-dark-accent dark:text-accent-text-dark underline break-all mt-3" href="{{ $website }}" rel="nofollow noopener noreferrer" target="_blank">Sitio web</a>@endif
        {{ $slot }}
    </div>
</section>
