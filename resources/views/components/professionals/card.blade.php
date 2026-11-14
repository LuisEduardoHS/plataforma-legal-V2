@props(['profile'])
<article class="professional-card">
    <div class="professional-card-top"><span class="professional-role">Perfil profesional</span></div>
    <div class="professional-identity"><span class="professional-avatar" aria-hidden="true">{{ $profile['initials'] }}</span><div><h3>{{ $profile['name'] }}</h3><p>{{ $profile['headline'] ?? 'Presentación profesional' }}</p></div></div>
    <div class="professional-practice"><span>Áreas de atención</span><p>{{ implode(' · ', $profile['specialties']) ?: 'Sin especialidades indicadas' }}</p></div>
    <div class="professional-meta"><span><x-lucide-map-pin aria-hidden="true" />{{ $profile['location'] }}</span><x-profiles.verification :status="$profile['verification_status']" /></div>
    <div class="professional-modality"><x-lucide-monitor-check aria-hidden="true" /><span>{{ ['in_person' => 'Presencial', 'remote' => 'En línea', 'hybrid' => 'Presencial y en línea'][$profile['attention_mode']] ?? 'Modalidad no indicada' }}</span></div>
    <a href="{{ $profile['url'] }}" class="professional-view">Conocer perfil<span class="professionals-sr"> de {{ $profile['name'] }}</span><x-lucide-arrow-up-right aria-hidden="true" /></a>
</article>
