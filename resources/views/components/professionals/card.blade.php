@props(['profile', 'featured' => false, 'hidden' => false])
<article class="professional-card {{ $featured ? 'professional-card-featured' : '' }}" data-professional-card="{{ $profile['id'] }}" @if ($hidden) hidden @endif>
    @if ($featured)
        <div class="professional-cover" aria-hidden="true"><svg viewBox="0 0 600 100" preserveAspectRatio="none"><path d="M0 90C120 120 170 0 300 42S470 100 600 15V100H0Z" /></svg></div>
    @endif
    <div class="professional-card-top"><span class="professional-role">{{ $profile['role'] }}</span><button type="button" class="professional-dismiss" data-dismiss-profile title="Ocultar recomendación"><x-lucide-x aria-hidden="true" /><span class="professionals-sr">Ocultar a {{ $profile['name'] }}</span></button></div>
    <div class="professional-identity"><span class="professional-avatar" aria-hidden="true">{{ $profile['initials'] }}</span><div><h3>{{ $profile['name'] }}</h3><p>{{ $profile['specialty'] }}</p></div></div>
    <div class="professional-practice"><span>Puede orientarte en</span><p>{{ implode(' · ', array_slice($profile['topics'], 0, 2)) }}</p></div>
    <div class="professional-meta"><span><x-lucide-map-pin aria-hidden="true" />{{ $profile['location'] }}</span><span><x-lucide-badge-check aria-hidden="true" />{{ $profile['verified'] ? 'Perfil verificado' : 'Perfil profesional' }}</span></div>
    <div class="professional-modality"><x-lucide-monitor-check aria-hidden="true" /><span>{{ implode(' · ', $profile['modalities']) }}</span></div>
    <a href="#perfil-{{ $profile['id'] }}" class="professional-view" data-open-profile="{{ $profile['id'] }}">Conocer perfil<span class="professionals-sr"> de {{ $profile['name'] }}</span><x-lucide-arrow-up-right aria-hidden="true" /></a>
</article>
