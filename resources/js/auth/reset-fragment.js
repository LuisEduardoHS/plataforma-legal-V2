// URL fragments never reach the HTTP server. Consume once, without browser storage.
export function parseResetFragment(fragment) {
    const params = new URLSearchParams(String(fragment).replace(/^#/, ''));
    if (!params.has('token')) return { recognized: false, data: null };
    const token = params.get('token');
    const email = params.get('email')?.trim().toLowerCase();
    const valid = params.getAll('token').length === 1 && params.getAll('email').length === 1
        && /^[a-f0-9]{64}$/.test(token) && email && email.length <= 255
        && !/[\x00-\x1f\x7f]/.test(email) && email.includes('@');
    return { recognized: true, data: valid ? { token, email } : null };
}
