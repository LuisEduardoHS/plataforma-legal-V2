import test from 'node:test';
import assert from 'node:assert/strict';
import { matchingProfiles, sectionResults } from '../../resources/js/professionals/state.js';

const profiles = {
    ana: { id: 'ana', name: 'Ana Martínez', specialty_id: 'laboral', specialty: 'Derecho laboral', location: 'Monterrey, N. L.' },
    noe: { id: 'noe', name: 'Noé Medina', specialty_id: 'civil', specialty: 'Derecho civil', location: 'San Nicolás de los Garza, N. L.' },
    camila: { id: 'camila', name: 'Camila Navarro', specialty_id: 'laboral', specialty: 'Derecho laboral', location: 'Ciudad de México' },
};
const section = { profile_ids: ['noe', 'ana', 'camila'], limit: 2 };

test('searches names, specialties and locations without accents or case, combining words', () => {
    assert.deepEqual(matchingProfiles(profiles, { query: '  MARTINEZ monterrey  ' }).map(p => p.id), ['ana']);
    assert.deepEqual(matchingProfiles(profiles, { query: 'nicolas' }).map(p => p.id), ['noe']);
    assert.deepEqual(matchingProfiles(profiles, { query: 'LABORAL' }).map(p => p.id), ['ana', 'camila']);
    assert.equal(matchingProfiles(profiles, { query: '<script>' }).length, 0);
});
test('combines specialty, search and dismissals without changing source data', () => {
    const before = JSON.stringify(profiles);
    assert.deepEqual(matchingProfiles(profiles, { specialty: 'laboral', query: 'mexico', dismissed: ['ana'] }).map(p => p.id), ['camila']);
    assert.equal(matchingProfiles(profiles, { specialty: 'civil', query: 'mexico' }).length, 0);
    assert.equal(matchingProfiles(profiles, { dismissed: ['ana', 'noe', 'camila'] }).length, 0);
    assert.equal(JSON.stringify(profiles), before);
});
test('preserves section order and backfills a dismissed card before limiting', () => {
    const matches = matchingProfiles(profiles, { dismissed: ['noe'] });
    assert.deepEqual(sectionResults(section, matches).ids, ['ana', 'camila']);
    assert.equal(sectionResults(section, matches).canExpand, false);
});
test('search reveals matches beyond the collapsed section and ignores missing or duplicate ids', () => {
    const duplicate = { ...section, profile_ids: ['missing', 'noe', 'ana', 'ana', 'camila'] };
    assert.deepEqual(sectionResults(duplicate, Object.values(profiles)).ids, ['noe', 'ana']);
    assert.deepEqual(sectionResults(duplicate, Object.values(profiles), { expanded: true }).ids, ['noe', 'ana', 'camila']);
    const matches = matchingProfiles(profiles, { query: 'camila' });
    assert.deepEqual(sectionResults(duplicate, matches, { query: 'camila' }), { ids: ['camila'], total: 1, canExpand: false });
});
test('specialty filtering also shows all matches instead of truncating them', () => {
    const narrow = { ...section, limit: 1 };
    const matches = matchingProfiles(profiles, { specialty: 'laboral' });
    assert.deepEqual(sectionResults(narrow, matches, { specialty: 'laboral' }).ids, ['ana', 'camila']);
    assert.equal(sectionResults(section, []).total, 0);
});
