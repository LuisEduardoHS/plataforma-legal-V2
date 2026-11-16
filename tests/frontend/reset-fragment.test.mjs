import test from 'node:test';
import assert from 'node:assert/strict';
import { parseResetFragment } from '../../resources/js/auth/reset-fragment.js';

test('recovery fragments decode once into input values without changing the token', () => {
    const token = 'a'.repeat(64);
    assert.deepEqual(parseResetFragment(`#token=${token}&email=USER%2Btest%40example.test`).data, { token, email: 'user+test@example.test' });
});
test('ordinary navigation hashes are left alone', () => {
    for (const hash of ['', '#publication-12', '#email=user@example.test']) assert.deepEqual(parseResetFragment(hash), { recognized: false, data: null });
});
test('malformed or duplicated recovery values are recognized for scrubbing but never hydrated', () => {
    for (const hash of ['#token=short&email=a@b.test', `#token=${'b'.repeat(64)}&email=a%00@b.test`, `#token=${'c'.repeat(64)}&token=${'d'.repeat(64)}&email=a@b.test`, `#token=${'e'.repeat(64)}&email=a@b.test&email=c@d.test`]) {
        assert.deepEqual(parseResetFragment(hash), { recognized: true, data: null });
    }
});
