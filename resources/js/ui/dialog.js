// Native showModal supplies inert background, Escape and keyboard containment.
// The submit button remains a working POST fallback without this enhancement.
export function setupDialog(root, { listen, onCleanup }) {
    const trigger = root.querySelector('[data-dialog-open]');
    const dialog = root.querySelector('dialog');
    if (!trigger || typeof dialog?.showModal !== 'function') return;
    let overflow, opener, disposing = false;
    const restoreScroll = () => {
        if (overflow !== undefined) document.body.style.overflow = overflow;
        overflow = undefined;
    };
    trigger.setAttribute('aria-haspopup', 'dialog');
    trigger.setAttribute('aria-controls', dialog.id);
    listen(trigger, 'click', (event) => {
        event.preventDefault();
        if (dialog.open) return;
        opener = trigger;
        dialog.showModal();
        overflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        dialog.querySelector('[data-dialog-initial]')?.focus();
    });
    dialog.querySelectorAll('[data-dialog-close]').forEach((button) => {
        listen(button, 'click', () => dialog.close());
    });
    listen(dialog, 'close', () => {
        restoreScroll();
        if (!disposing && opener?.isConnected) opener.focus();
    });
    onCleanup(() => {
        disposing = true;
        dialog.close();
        restoreScroll();
    });
}
