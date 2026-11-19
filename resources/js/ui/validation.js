export function setupValidation(summary) {
    // Never move focus away from someone typing during a Turbo update.
    if (document.activeElement === document.body || document.activeElement?.id === 'main-content') summary.focus();
}
