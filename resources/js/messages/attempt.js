const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

// Retrying an ambiguous HTTP failure must reuse the exact logical request.
export function createSendAttempt() {
    let pending = null;
    return {
        current: () => pending,
        begin(data) {
            if (!pending) pending = Object.freeze({ token: data.token, content: data.content, ids: Object.freeze([...data.ids]) });
            return pending;
        },
        acknowledge(data) {
            if (!pending || !Number.isSafeInteger(data?.id) || data.id < 1 || !uuid.test(data.next_token || '') || data.next_token === pending.token) throw new Error('Invalid acknowledgement');
            pending = null;
            return data.next_token;
        },
        rejected() { pending = null; },
    };
}
