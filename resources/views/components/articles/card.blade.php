@props(['article'])

<article 
    {{ $attributes->merge(['class' => 'group bg-white dark:bg-[#222222] rounded-2xl overflow-hidden shadow-xs hover:shadow-md hover:-translate-y-0.5 transition-all duration-200 cursor-pointer flex flex-col h-full border border-gray-100/60 dark:border-white/5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#9E1F52]']) }}
    tabindex="0"
    role="button"
    aria-label="{{ $article['title'] }}"
>
    {{-- Banner con onda decorativa --}}
    <div class="relative overflow-hidden w-full aspect-[16/9] select-none bg-[#BE2562]">
        <x-articles.card-banner class="transition-transform duration-300 group-hover:scale-[1.03]" />
    </div>

    {{-- Contenido de la tarjeta --}}
    <div class="p-4 sm:p-5 flex-1 flex flex-col justify-between">
        <div>
            <h3 class="text-sm sm:text-base font-bold text-gray-900 dark:text-gray-100 group-hover:text-[#9E1F52] dark:group-hover:text-[#BE2562] transition-colors leading-snug">
                {{ $article['title'] }}
            </h3>

            <p class="mt-2 text-xs sm:text-[13px] text-gray-500 dark:text-gray-400 leading-relaxed">
                {{ $article['summary'] }}
            </p>
        </div>

        {{-- Pie de la tarjeta --}}
        <div class="mt-4 pt-2 flex items-center justify-between gap-2">
            <span class="inline-flex items-center px-2.5 py-0.5 rounded-md bg-[#EEEEEE] dark:bg-[#2E2E30] text-[11px] font-medium text-gray-700 dark:text-gray-300">
                {{ $article['category'] }}
            </span>

            <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-500 dark:text-gray-400 group-hover:text-[#9E1F52] dark:group-hover:text-[#BE2562] transition-colors">
                <span>Ver más</span>
                <x-lucide-arrow-right class="w-3.5 h-3.5 group-hover:translate-x-1 transition-transform" />
            </span>
        </div>
    </div>
</article>
