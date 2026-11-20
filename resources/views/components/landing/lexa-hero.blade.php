<section class="landing-hero" aria-labelledby="landing-title">
    <div class="landing-hero-halo" aria-hidden="true"></div>
    <div class="landing-container landing-hero-content">
        <p class="landing-lexa-identity"><span class="landing-lexa-wordmark">LEXA</span></p>
        <h1 id="landing-title"><span data-landing-typewriter>Un punto de partida para tus dudas legales.</span></h1>
        <p class="landing-hero-description">Explora información y profesionales en México.</p>
        <form class="landing-example landing-lexa-composer" data-landing-lexa-composer action="{{ route('lexa') }}" aria-label="Preparar una consulta en LEXA">
            <label class="landing-sr" for="landing-lexa-question">Describe tu situación legal</label>
            <textarea id="landing-lexa-question" name="question" data-landing-lexa-question rows="1" maxlength="5000" placeholder="Escribe una duda legal…" aria-describedby="landing-lexa-composer-help"></textarea>
            <div class="landing-lexa-composer-tools">
                <button type="submit" data-landing-lexa-send disabled aria-label="Continuar consulta en LEXA"><x-lucide-arrow-up aria-hidden="true" /></button>
            </div>
        </form>
        <p class="landing-demo-access">La demostración requiere iniciar sesión.</p>
        <div class="landing-hero-actions"><a class="landing-button landing-button-brand" href="{{ route('lexa') }}">Conocer la demostración</a><a class="landing-button landing-button-white" href="#posibilidades">Explorar la plataforma</a></div>
    </div>
</section>
