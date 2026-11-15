import test from 'node:test';
import assert from 'node:assert/strict';
import { formatMessageDate, localizeMessageTimes } from '../../resources/js/messages/dates.js';

test('UTC instants are presented in the selected browser zone without changing their meaning', () => {
    const iso = '2026-11-15T07:49:00+00:00';
    const mexico = formatMessageDate(iso, { compact: true, locale: 'en-GB', timeZone: 'America/Mexico_City' });
    const utc = formatMessageDate(iso, { compact: true, locale: 'en-GB', timeZone: 'UTC' });
    assert.equal(mexico.label, '01:49');
    assert.equal(utc.label, '07:49');
    assert.match(mexico.title, /2026/);
    assert.match(mexico.title, /01:49/);
});

test('local dates handle daylight saving and crossing calendar days', () => {
    assert.equal(formatMessageDate('2026-07-01T00:30:00Z', { compact: true, locale: 'en-GB', timeZone: 'America/New_York' }).label, '20:30');
    assert.match(formatMessageDate('2026-07-01T00:30:00Z', { locale: 'en-GB', timeZone: 'America/New_York' }).title, /30 Jun 2026/);
    assert.equal(formatMessageDate('2026-01-01T00:30:00Z', { compact: true, locale: 'en-GB', timeZone: 'America/New_York' }).label, '19:30');
});

test('naive or invalid dates and unavailable formatters preserve UTC fallback', () => {
    for (const iso of ['', null, '2026-11-15 07:49:00', 'not-a-dateZ']) assert.equal(formatMessageDate(iso), null);
    assert.equal(formatMessageDate('2026-11-15T07:49:00Z', { timeZone: 'not/a-zone' }), null);
});

test('inbox and newly polled timestamps localize repeatedly from the immutable ISO attribute', () => {
    const time = { dataset: { messageTime: 'compact' }, textContent: '07:49 UTC', title: '07:49 UTC', getAttribute: () => '2026-11-15T07:49:00+00:00' };
    const invalid = { dataset: {}, textContent: 'UTC fallback', getAttribute: () => 'invalid' };
    const nodes = [time, invalid];
    const root = { querySelectorAll: () => nodes };
    const options = { locale: 'en-GB', timeZone: 'America/Mexico_City' };
    localizeMessageTimes(root, options);
    assert.equal(time.textContent, '01:49');
    localizeMessageTimes(root, options);
    assert.equal(time.textContent, '01:49');
    assert.equal(invalid.textContent, 'UTC fallback');
    nodes.push({ dataset: { messageTime: 'full' }, textContent: '', getAttribute: () => '2026-11-15T07:49:00Z' });
    localizeMessageTimes(root, options);
    assert.match(nodes[2].textContent, /01:49/);
    assert.equal(time.getAttribute('datetime'), '2026-11-15T07:49:00+00:00');
});
