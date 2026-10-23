@props(['topics', 'questions', 'professionals'])
<aside class="community-sidebar" aria-label="Actividad de la comunidad">
    <section aria-labelledby="community-topics-title">
        <h2 id="community-topics-title">Temas activos</h2>
        <div class="community-sidebar-card community-surface">
            @foreach ($topics as $topic)
                <button type="button" class="community-topic" data-topic="{{ $topic['name'] }}" aria-pressed="false">
                    <strong>{{ $topic['name'] }}</strong><span>{{ $topic['count'] }} publicaciones</span>
                </button>
            @endforeach
            <button type="button" class="community-see-all" data-reset-filter>Ver todo <x-lucide-arrow-right aria-hidden="true" /></button>
        </div>
    </section>
    <section aria-labelledby="community-questions-title">
        <h2 id="community-questions-title">Necesitan respuesta</h2>
        <div class="community-sidebar-card community-surface">
            @foreach ($questions as $question)
                <button type="button" class="community-topic" data-post-target="post-{{ $question['post_id'] }}">
                    <strong>{{ $question['title'] }}</strong><span>{{ $question['replies'] }} {{ $question['replies'] === 1 ? 'respuesta' : 'respuestas' }}</span>
                </button>
            @endforeach
            <button type="button" class="community-see-all" data-show-unanswered>Ver todo <x-lucide-arrow-right aria-hidden="true" /></button>
        </div>
    </section>
    <section aria-labelledby="community-professionals-title">
        <h2 id="community-professionals-title">Profesionales activos</h2>
        <div class="community-sidebar-card community-surface">
            @foreach ($professionals as $professional)
                <div class="community-professional">
                    <div class="community-professional-info">
                        <x-community.avatar />
                        <div><strong>{{ $professional['name'] }}</strong><span class="community-location" title="{{ $professional['location'] }}">{{ $professional['location'] }}</span><span>{{ $professional['followers'] }} seguidores</span></div>
                    </div>
                    <button type="button" class="community-primary" disabled title="Requiere una cuenta conectada" aria-label="Seguir a {{ $professional['name'] }}: requiere una cuenta conectada">Seguir</button>
                </div>
            @endforeach
        </div>
    </section>
</aside>
