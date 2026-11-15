// Only ISO instants with an explicit offset can be localized. Never reinterpret
// server UTC strings as local dates, or convert previously formatted text.
export function formatMessageDate(iso, { compact = false, locale = 'es-MX', timeZone } = {}) {
    if (typeof iso !== 'string' || !/(?:Z|[+-]\d{2}:\d{2})$/.test(iso)) return null;
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return null;
    try {
        const full = { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', timeZoneName: 'short', timeZone };
        const title = new Intl.DateTimeFormat(locale, full).format(date);
        const label = compact ? new Intl.DateTimeFormat(locale, { hour: '2-digit', minute: '2-digit', timeZone }).format(date) : title;
        return { label, title };
    } catch { return null; }
}

export function localizeMessageTimes(root, options = {}) {
    root.querySelectorAll('time[data-message-time]').forEach(element => {
        const formatted = formatMessageDate(element.getAttribute('datetime'), { ...options, compact: element.dataset.messageTime === 'compact' });
        if (!formatted) return; // Keep the explicit UTC fallback.
        element.textContent = formatted.label;
        element.title = formatted.title;
    });
}
