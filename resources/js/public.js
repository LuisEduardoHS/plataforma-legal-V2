import { onPage } from './ui/page.js';
import { setupPublicNavigation } from './ui/public-navigation.js';
import { navigate } from './ui/navigation.js';
import { writeHandoff } from './ui/handoff.js';

// Landing gets only this progressive disclosure, without the application bundle.
onPage('[data-public-navigation]', setupPublicNavigation);

function setupBackToTop() {
    const button = document.querySelector('[data-back-to-top]');
    if (!button) return;

    const update = () => button.classList.toggle('is-visible', window.scrollY > 480);
    button.addEventListener('click', () => window.scrollTo({
        top: 0,
        behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
    }));
    window.addEventListener('scroll', update, { passive: true });
    update();
}

document.addEventListener('turbo:load', setupBackToTop);
setupBackToTop();

function setupLandingReveal() {
    const elements = document.querySelectorAll('[data-reveal]');
    if (!('IntersectionObserver' in window)) {
        elements.forEach((element) => element.classList.add('is-visible'));
        return;
    }

    const observer = new IntersectionObserver((entries) => {
        for (const entry of entries) {
            if (entry.isIntersecting) {
                entry.target.classList.add('is-visible');
                observer.unobserve(entry.target);
            }
        }
    }, { threshold: 0.12, rootMargin: '0px 0px -8% 0px' });

    elements.forEach((element) => observer.observe(element));
}

document.addEventListener('turbo:load', setupLandingReveal);
setupLandingReveal();

function setupLandingTypewriter() {
    if (window.__landingTypewriterTimer) {
        window.clearTimeout(window.__landingTypewriterTimer);
    }

    const target = document.querySelector('[data-landing-typewriter]');
    if (!target || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    const phrases = [
        'Un punto de partida para tus dudas legales.',
        '¿Qué debo revisar antes de firmar un contrato?',
        '¿Cómo puedo preparar una consulta jurídica?',
        '¿Qué información necesito para entender mi caso?',
    ];
    let phraseIndex = 0;
    let characterIndex = phrases[0].length;
    let deleting = false;

    target.classList.add('is-typing');
    const next = () => {
        const phrase = phrases[phraseIndex];

        if (characterIndex > 0) {
            characterIndex -= 1;
            target.textContent = phrase.slice(0, characterIndex);
            window.__landingTypewriterTimer = window.setTimeout(next, 24);
            return;
        }

        phraseIndex = (phraseIndex + 1) % phrases.length;
        deleting = false;
        characterIndex = 0;
        target.textContent = '';
        window.__landingTypewriterTimer = window.setTimeout(type, 260);
    };

    const type = () => {
        const phrase = phrases[phraseIndex];
        if (characterIndex < phrase.length) {
            characterIndex += 1;
            target.textContent = phrase.slice(0, characterIndex);
            window.__landingTypewriterTimer = window.setTimeout(type, 42);
            return;
        }
        window.__landingTypewriterTimer = window.setTimeout(next, 3200);
    };

    window.__landingTypewriterTimer = window.setTimeout(() => {
        deleting = true;
        next();
    }, 3200);

    document.addEventListener('turbo:before-render', () => {
        if (window.__landingTypewriterTimer) window.clearTimeout(window.__landingTypewriterTimer);
        target.classList.remove('is-typing');
    }, { once: true });

    window.addEventListener('pagehide', () => {
        if (window.__landingTypewriterTimer) window.clearTimeout(window.__landingTypewriterTimer);
    }, { once: true });

    void type;
}

document.addEventListener('turbo:load', setupLandingTypewriter);
setupLandingTypewriter();

onPage('[data-landing-lexa-composer]', (root) => {
    const question = root.querySelector('[data-landing-lexa-question]');
    const send = root.querySelector('[data-landing-lexa-send]');

    question.addEventListener('input', () => {
        send.disabled = !question.value.trim();
        question.style.height = '44px';
    });

    question.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            if (question.value.trim()) root.requestSubmit();
        }
    });

    root.addEventListener('submit', (event) => {
        event.preventDefault();
        if (!question.value.trim()) return;
        writeHandoff('lexa', { text: question.value.trim(), mode: 'orientation', context: false });
        navigate(root.action);
    });

    document.addEventListener('pointerdown', (event) => {
        if (!root.contains(event.target)) {
            question.blur();
            const activeElement = document.activeElement;
            if (root.contains(activeElement)) activeElement.blur();
        }
    });
});
