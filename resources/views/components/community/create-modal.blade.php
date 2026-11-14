@props(['categories', 'specialties', 'tags'])
<dialog class="community-dialog community-create" data-create-dialog aria-labelledby="community-create-title">
    <div class="community-dialog-heading"><h2 id="community-create-title">Crear publicación</h2><button type="button" class="community-icon-button" data-close-create aria-label="Cerrar"><x-lucide-x aria-hidden="true" /></button></div>
    <x-community.post-form :post="null" :categories="$categories" :specialties="$specialties" :tags="$tags" />
</dialog>
