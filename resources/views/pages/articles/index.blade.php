@extends('layouts.app')

@section('title', 'Recursos | Plataforma Legal')

@section('content')
    @vite(['resources/css/articles.css'])

    @php
        $data = $data ?? require resource_path('views/pages/articles/demo.php');
    @endphp

    <div 
        class="articles-page min-h-screen bg-[#F5F5F5] dark:bg-[#181818] text-gray-900 dark:text-gray-100 pb-24 sm:pb-20 lg:pb-16"
        x-data="articlesPage({
            popular: @js($data['popular']),
            recommendations: @js($data['recommendations']),
            categories: @js($data['categories'])
        })"
    >
        {{-- Contenedor principal alineado y centrado exactamente como en el diseño --}}
        <div class="max-w-[1040px] mx-auto px-4 sm:px-6 lg:px-8 pt-8 sm:pt-10">

            {{-- Encabezado de la página --}}
            <header class="space-y-1">
                <h1 class="text-3xl sm:text-[34px] font-bold text-[#9E1F52] dark:text-[#BE2562] tracking-tight">
                    Aprende a defenderte
                </h1>
                <p class="text-lg sm:text-xl font-bold text-gray-900 dark:text-gray-100">
                    Documentación y recursos para todos.
                </p>
            </header>

            {{-- Barra de búsqueda interactiva --}}
            <div class="mt-5 sm:mt-6">
                <div class="relative w-full">
                    <label for="articles-search-input" class="sr-only">Buscar artículos y recursos</label>
                    <div class="flex items-center w-full bg-[#EEEEEE] dark:bg-[#262626] rounded-xl px-4 py-2.5 sm:py-3 transition-all duration-200 focus-within:ring-2 focus-within:ring-[#9E1F52]/40 focus-within:bg-white dark:focus-within:bg-[#2C2C2C] border border-transparent focus-within:border-gray-200 dark:focus-within:border-gray-700">
                        <x-lucide-search class="w-4 h-4 text-gray-400 dark:text-gray-500 shrink-0 mr-3" aria-hidden="true" />
                        <input 
                            id="articles-search-input"
                            type="text" 
                            x-model="searchQuery" 
                            placeholder="Buscar" 
                            autocomplete="off"
                            class="w-full bg-transparent border-0 outline-none text-xs sm:text-sm text-gray-900 dark:text-gray-100 placeholder:text-gray-500 dark:placeholder:text-gray-400"
                        />
                        {{-- Botón para limpiar búsqueda --}}
                        <button 
                            type="button" 
                            x-show="searchQuery.trim().length > 0" 
                            x-cloak
                            @click="searchQuery = ''"
                            class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors p-1"
                            aria-label="Limpiar campo de búsqueda"
                        >
                            <x-lucide-x class="w-4 h-4" />
                        </button>
                    </div>
                </div>

                {{-- Filtro de Categorías (Píldoras) --}}
                <div class="mt-3 sm:mt-3.5 flex items-center gap-2 overflow-x-auto pb-1.5 scrollbar-none sm:flex-wrap" role="toolbar" aria-label="Filtro por categorías">
                    @foreach ($data['categories'] as $index => $category)
                        <button 
                            type="button"
                            @click="toggleCategory('{{ $category }}', {{ $index }})"
                            :class="activePillIndex === {{ $index }} 
                                ? 'bg-[#9E1F52] text-white shadow-xs' 
                                : 'bg-[#EEEEEE] dark:bg-[#262626] text-gray-800 dark:text-gray-200 hover:bg-gray-300/80 dark:hover:bg-[#323232]'"
                            class="px-4 py-1.5 rounded-lg text-xs sm:text-sm font-normal sm:font-medium whitespace-nowrap transition-colors duration-150 cursor-pointer focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-[#9E1F52]"
                        >
                            {{ $category }}
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Estado vacío si no hay coincidencias con la búsqueda o filtro --}}
            <div 
                x-show="totalMatches() === 0" 
                x-cloak 
                x-transition.opacity.duration.200ms
                class="mt-12 text-center py-12 px-6 bg-white dark:bg-[#222222] rounded-2xl border border-dashed border-gray-300 dark:border-gray-700 max-w-lg mx-auto"
            >
                <div class="w-12 h-12 rounded-full bg-[#9E1F52]/10 flex items-center justify-center mx-auto text-[#9E1F52] mb-3">
                    <x-lucide-search class="w-6 h-6" />
                </div>
                <h3 class="text-base font-bold text-gray-900 dark:text-gray-100">
                    No encontramos resultados
                </h3>
                <p class="text-xs sm:text-sm text-gray-500 dark:text-gray-400 mt-1.5">
                    No hay recursos que coincidan con tu búsqueda. Prueba con otros términos o restablece los filtros.
                </p>
                <button 
                    type="button" 
                    @click="resetFilters()"
                    class="mt-4 inline-flex items-center gap-1.5 px-4 py-2 bg-[#9E1F52] hover:bg-[#7F1842] text-white text-xs font-semibold rounded-lg transition-colors cursor-pointer"
                >
                    <x-lucide-rotate-ccw class="w-3.5 h-3.5" />
                    <span>Restablecer búsqueda</span>
                </button>
            </div>

            {{-- SECCIÓN 1: Popular en este momento --}}
            <section 
                class="mt-10 sm:mt-12" 
                aria-labelledby="popular-heading"
                x-show="hasMatchesInPopular()"
                x-transition.opacity.duration.200ms
            >
                {{-- Encabezado de la sección --}}
                <div class="flex items-center justify-between">
                    <h2 id="popular-heading" class="text-lg sm:text-xl font-bold text-gray-900 dark:text-gray-100">
                        Popular en este momento
                    </h2>
                    <button 
                        type="button"
                        @click="handleViewAll('popular')"
                        class="inline-flex items-center gap-1 text-xs sm:text-sm font-medium text-gray-600 dark:text-gray-400 hover:text-[#9E1F52] dark:hover:text-[#BE2562] transition-colors cursor-pointer"
                    >
                        <span>Ver todo</span>
                        <x-lucide-arrow-right class="w-3.5 h-3.5" />
                    </button>
                </div>

                {{-- Cuadrícula de 3 tarjetas --}}
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5 sm:gap-6 mt-4 sm:mt-5">
                    @foreach ($data['popular'] as $article)
                        <div 
                            x-show="matchesFilter(@js($article))"
                            x-transition.opacity.duration.200ms
                            tabindex="0"
                            role="button"
                            aria-label="{{ $article['title'] }}"
                            class="group bg-white dark:bg-[#222222] rounded-2xl overflow-hidden shadow-xs hover:shadow-md hover:-translate-y-0.5 transition-all duration-200 cursor-pointer flex flex-col h-full border border-gray-100/60 dark:border-white/5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#9E1F52]"
                        >
                            {{-- Banner decorativo con onda idéntica a la imagen --}}
                            <div class="relative overflow-hidden w-full aspect-[16/9] select-none bg-[#BE2562]">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 166" preserveAspectRatio="none" class="w-full h-full block transition-transform duration-300 group-hover:scale-[1.03]">
                                    <rect width="300" height="166" fill="#BE2562" />
                                    <path d="M 45 166 C 75 145, 105 120, 130 122 C 160 124, 180 134, 205 133 C 240 130, 275 98, 300 86 L 300 166 Z" fill="#9E1F52" />
                                </svg>
                            </div>

                            {{-- Cuerpo de la tarjeta --}}
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
                        </div>
                    @endforeach
                </div>
            </section>

            {{-- SECCIÓN 2: Recomendaciones para ti --}}
            <section 
                class="mt-10 sm:mt-12" 
                aria-labelledby="recommendations-heading"
                x-show="hasMatchesInRecommendations()"
                x-transition.opacity.duration.200ms
            >
                {{-- Encabezado de la sección --}}
                <div class="flex items-center justify-between">
                    <h2 id="recommendations-heading" class="text-lg sm:text-xl font-bold text-gray-900 dark:text-gray-100">
                        Recomendaciones para ti
                    </h2>
                    <button 
                        type="button"
                        @click="handleViewAll('recommendations')"
                        class="inline-flex items-center gap-1 text-xs sm:text-sm font-medium text-gray-600 dark:text-gray-400 hover:text-[#9E1F52] dark:hover:text-[#BE2562] transition-colors cursor-pointer"
                    >
                        <span>Ver todo</span>
                        <x-lucide-arrow-right class="w-3.5 h-3.5" />
                    </button>
                </div>

                {{-- Cuadrícula de 6 tarjetas (2 filas de 3) --}}
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5 sm:gap-6 mt-4 sm:mt-5">
                    @foreach ($data['recommendations'] as $article)
                        <div 
                            x-show="matchesFilter(@js($article))"
                            x-transition.opacity.duration.200ms
                            tabindex="0"
                            role="button"
                            aria-label="{{ $article['title'] }}"
                            class="group bg-white dark:bg-[#222222] rounded-2xl overflow-hidden shadow-xs hover:shadow-md hover:-translate-y-0.5 transition-all duration-200 cursor-pointer flex flex-col h-full border border-gray-100/60 dark:border-white/5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#9E1F52]"
                        >
                            {{-- Banner decorativo con onda idéntica a la imagen --}}
                            <div class="relative overflow-hidden w-full aspect-[16/9] select-none bg-[#BE2562]">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 166" preserveAspectRatio="none" class="w-full h-full block transition-transform duration-300 group-hover:scale-[1.03]">
                                    <rect width="300" height="166" fill="#BE2562" />
                                    <path d="M 45 166 C 75 145, 105 120, 130 122 C 160 124, 180 134, 205 133 C 240 130, 275 98, 300 86 L 300 166 Z" fill="#9E1F52" />
                                </svg>
                            </div>

                            {{-- Cuerpo de la tarjeta --}}
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
                        </div>
                    @endforeach
                </div>
            </section>

        </div>

        

        {{-- Notificación flotante Toast para interacciones frontend --}}
        <div 
            x-show="toastVisible" 
            x-cloak
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 translate-y-3 scale-95"
            x-transition:enter-end="opacity-100 translate-y-0 scale-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100 translate-y-0 scale-100"
            x-transition:leave-end="opacity-0 translate-y-3 scale-95"
            class="fixed bottom-6 right-6 z-50 bg-gray-900 text-white dark:bg-white dark:text-gray-900 px-4 py-2.5 rounded-xl shadow-xl flex items-center gap-2.5 text-xs font-medium"
        >
            <x-lucide-check-circle-2 class="w-4 h-4 text-emerald-400 dark:text-emerald-600 shrink-0" />
            <span x-text="toastMessage"></span>
        </div>

    </div>

    {{-- Script de interacción frontend con Alpine.js --}}
    <script>
        function articlesPage(initialData) {
            return {
                searchQuery: '',
                selectedCategory: '',
                activePillIndex: null,
                activeArticle: null,
                isModalOpen: false,
                savedArticles: [],
                toastMessage: '',
                toastVisible: false,
                toastTimeout: null,
                popular: initialData.popular || [],
                recommendations: initialData.recommendations || [],
                categories: initialData.categories || [],

                matchesFilter(item) {
                    if (this.selectedCategory) {
                        const targetCat = this.selectedCategory.toLowerCase().trim();
                        const itemCat = (item.category || '').toLowerCase().trim();
                        // Coincidencia exacta o contenida (ej: 'Derecho laboral' vs 'Laboral')
                        const matchCat = itemCat.includes(targetCat) || targetCat.includes(itemCat);
                        if (!matchCat) {
                            return false;
                        }
                    }

                    if (this.searchQuery && this.searchQuery.trim().length > 0) {
                        const q = this.searchQuery.toLowerCase().trim();
                        const titleMatch = (item.title || '').toLowerCase().includes(q);
                        const summaryMatch = (item.summary || '').toLowerCase().includes(q);
                        const catMatch = (item.category || '').toLowerCase().includes(q);
                        if (!titleMatch && !summaryMatch && !catMatch) {
                            return false;
                        }
                    }

                    return true;
                },

                hasMatchesInPopular() {
                    return this.popular.some(item => this.matchesFilter(item));
                },

                hasMatchesInRecommendations() {
                    return this.recommendations.some(item => this.matchesFilter(item));
                },

                totalMatches() {
                    let count = 0;
                    this.popular.forEach(item => { if (this.matchesFilter(item)) count++; });
                    this.recommendations.forEach(item => { if (this.matchesFilter(item)) count++; });
                    return count;
                },

                toggleCategory(cat, index) {
                    if (this.activePillIndex === index) {
                        this.activePillIndex = null;
                        this.selectedCategory = '';
                        this.showToast('Mostrando todos los recursos');
                    } else {
                        this.activePillIndex = index;
                        this.selectedCategory = cat;
                        this.showToast(`Filtrando por categoría: ${cat}`);
                    }
                },

                resetFilters() {
                    this.searchQuery = '';
                    this.selectedCategory = '';
                    this.activePillIndex = null;
                    this.showToast('Filtros restablecidos');
                },

                handleViewAll(section) {
                    this.searchQuery = '';
                    this.selectedCategory = '';
                    this.activePillIndex = null;
                    const label = section === 'popular' ? 'populares' : 'recomendaciones';
                    this.showToast(`Mostrando todos los recursos ${label}`);
                },

                openModal(article) {
                    this.activeArticle = article;
                    this.isModalOpen = true;
                    document.body.style.overflow = 'hidden';
                },

                closeModal() {
                    this.isModalOpen = false;
                    document.body.style.overflow = '';
                },

                isSaved(id) {
                    return id ? this.savedArticles.includes(id) : false;
                },

                toggleSave(id) {
                    if (!id) return;
                    if (this.isSaved(id)) {
                        this.savedArticles = this.savedArticles.filter(item => item !== id);
                        this.showToast('Artículo eliminado de tus guardados');
                    } else {
                        this.savedArticles.push(id);
                        this.showToast('Artículo guardado en tus recursos');
                    }
                },

                copyLink() {
                    if (navigator.clipboard) {
                        navigator.clipboard.writeText(window.location.href);
                    }
                    this.showToast('Enlace copiado al portapapeles');
                },

                showToast(msg) {
                    this.toastMessage = msg;
                    this.toastVisible = true;
                    if (this.toastTimeout) clearTimeout(this.toastTimeout);
                    this.toastTimeout = setTimeout(() => {
                        this.toastVisible = false;
                    }, 3000);
                }
            };
        }
    </script>
@endsection
