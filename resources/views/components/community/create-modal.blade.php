@props(['categories', 'specialties', 'tags'])
<dialog class="community-dialog community-create !w-[min(860px,calc(100%-32px))] !border-0 !rounded-2xl" data-create-dialog aria-labelledby="community-create-title">
    <div class="community-dialog-heading !px-5 !py-3.5">
        <h2 id="community-create-title" class="!text-base !font-semibold">Crear publicación</h2>
        <button type="button" class="community-icon-button !min-w-8 !min-h-8 !rounded-full" data-close-create aria-label="Cerrar"><x-lucide-x class="!size-4" aria-hidden="true" /></button>
    </div>
    <x-community.post-form :post="null" :categories="$categories" :specialties="$specialties" :tags="$tags" />
</dialog>
