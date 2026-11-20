<section class="community-composer community-surface" aria-label="Nueva publicación">
    <div class="community-composer-input"><x-community.avatar /><a class="community-compose-prompt" href="{{ route('community.create') }}" data-open-create>¿Qué quieres compartir con la comunidad?</a></div>
    <div class="community-composer-tools"><div class="community-attachments">@foreach (['image' => ['image', 'Imagen'], 'video' => ['video', 'Video'], 'document' => ['file-text', 'Documento']] as $kind => [$icon, $label])<a href="{{ route('community.create') }}" data-open-create><x-dynamic-component :component="'lucide-'.$icon" aria-hidden="true" /><span>{{ $label }}</span></a>@endforeach</div><a class="community-primary community-compose-button" href="{{ route('community.create') }}" data-open-create>Crear publicación</a></div>
    @auth
        @cannot('posts.create')
            <p class="mt-3 flex items-center gap-2 border-t border-black/8 pt-2.5 text-xs text-light-text dark:border-white/10 dark:text-light-text-dark">
                <x-lucide-info class="!size-4 shrink-0" aria-hidden="true" />
                <span>Tu cuenta no tiene permiso para crear publicaciones.</span>
            </p>
        @endcannot
    @else
        <div class="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-black/8 pt-2.5 text-xs text-light-text dark:border-white/10 dark:text-light-text-dark">
            <span class="flex items-center gap-2">
                <x-lucide-lock class="!size-3.5 shrink-0" aria-hidden="true" />
                <span>Inicia sesión para publicar y participar.</span>
            </span>
            <a class="inline-flex min-h-7 items-center rounded-full bg-accent/10 px-3 py-1 text-xs font-semibold text-accent transition-colors hover:bg-accent hover:text-white dark:bg-accent-text-dark/15 dark:text-accent-text-dark dark:hover:bg-accent dark:hover:text-white" href="{{ route('login') }}">Iniciar sesión</a>
        </div>
    @endauth
</section>
