import { onPage } from './ui/page.js';
import { setupPublicNavigation } from './ui/public-navigation.js';
import { navigate } from './ui/navigation.js';
import { writeHandoff } from './ui/handoff.js';

// Landing gets only this progressive disclosure, without the application bundle.
onPage('[data-public-navigation]', setupPublicNavigation);

onPage('.landing-page', (root, { listen, onCleanup }) => {
    const backToTop = root.querySelector('[data-back-to-top]');
    if (backToTop) {
        const update = () => backToTop.classList.toggle('is-visible', window.scrollY > 480);
        listen(backToTop, 'click', () => window.scrollTo({
            top: 0,
            behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
        }));
        listen(window, 'scroll', update, { passive: true });
        update();
    }

    const revealElements = root.querySelectorAll('[data-reveal]');
    if (revealElements.length) {
        if (!('IntersectionObserver' in window)) {
            revealElements.forEach((element) => element.classList.add('is-visible'));
        } else {
            const observer = new IntersectionObserver((entries) => {
                for (const entry of entries) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('is-visible');
                        observer.unobserve(entry.target);
                    }
                }
            }, { threshold: 0.12, rootMargin: '0px 0px -8% 0px' });

            revealElements.forEach((element) => observer.observe(element));
            onCleanup(() => observer.disconnect());
        }
    }

    const target = root.querySelector('[data-landing-typewriter]');
    if (target && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        const phrases = [
            'Un punto de partida para tus dudas legales.',
            '¿Qué debo revisar antes de firmar un contrato?',
            '¿Cómo puedo preparar una consulta jurídica?',
            '¿Qué información necesito para entender mi caso?',
        ];
        let phraseIndex = 0;
        let characterIndex = phrases[0].length;
        let timer = null;

        target.classList.add('is-typing');
        const next = () => {
            const phrase = phrases[phraseIndex];
            if (characterIndex > 0) {
                characterIndex -= 1;
                target.textContent = phrase.slice(0, characterIndex);
                timer = window.setTimeout(next, 24);
                return;
            }
            phraseIndex = (phraseIndex + 1) % phrases.length;
            characterIndex = 0;
            target.textContent = '';
            timer = window.setTimeout(type, 260);
        };

        const type = () => {
            const phrase = phrases[phraseIndex];
            if (characterIndex < phrase.length) {
                characterIndex += 1;
                target.textContent = phrase.slice(0, characterIndex);
                timer = window.setTimeout(type, 42);
                return;
            }
            timer = window.setTimeout(next, 3200);
        };

        timer = window.setTimeout(next, 3200);
        onCleanup(() => {
            if (timer) window.clearTimeout(timer);
            target.classList.remove('is-typing');
        });
    }
});

onPage('[data-landing-lexa-composer]', (root, { listen }) => {
    const question = root.querySelector('[data-landing-lexa-question]');
    const send = root.querySelector('[data-landing-lexa-send]');

    listen(question, 'input', () => {
        send.disabled = !question.value.trim();
        question.style.height = '44px';
    });

    listen(question, 'keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            if (question.value.trim()) root.requestSubmit();
        }
    });

    listen(root, 'submit', (event) => {
        event.preventDefault();
        if (!question.value.trim()) return;
        writeHandoff('lexa', { text: question.value.trim(), mode: 'orientation', context: false });
        navigate(root.action);
    });

    listen(document, 'pointerdown', (event) => {
        if (!root.contains(event.target)) {
            question.blur();
            const activeElement = document.activeElement;
            if (root.contains(activeElement)) activeElement.blur();
        }
    });
});
