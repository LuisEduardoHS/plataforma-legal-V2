import test from 'node:test';
import assert from 'node:assert/strict';
import { validateCommunityFiles } from '../../resources/js/community/files.js';

const rules = { maxCount: 4, maxBytes: { image: 10, video: 100, document: 20 }, types: {
    'image/png': { category: 'image', extensions: ['png'] },
    'image/gif': { category: 'image', extensions: ['gif'] },
    'video/mp4': { category: 'video', extensions: ['mp4'] },
    'application/pdf': { category: 'document', extensions: ['pdf'] },
    'text/plain': { category: 'document', extensions: ['txt'] },
} };
const file = (name, type, size) => ({ name, type, size });
test('community accepts configured formats, including GIF and TXT, at their limits', () => {
    assert.deepEqual(validateCommunityFiles([file('foto.PNG','image/png',10),file('animada.gif','image/gif',10),file('video.mp4','video/mp4',100),file('notas.txt','text/plain',20)],rules), []);
});
test('community accepts unknown browser MIME only for a configured extension', () => {
    assert.deepEqual(validateCommunityFiles([file('foto.png','',10)],rules), []);
    assert.equal(validateCommunityFiles([file('office.docx','',10)],rules).length,1);
});
test('community rejects mismatched MIME and unsupported documents', () => {
    assert.equal(validateCommunityFiles([file('foto.png','application/pdf',10),file('office.docx','application/vnd.openxmlformats-officedocument.wordprocessingml.document',10)],rules).length,2);
});
test('community uses category size limits and rejects empty files', () => {
    assert.equal(validateCommunityFiles([file('foto.png','image/png',11),file('video.mp4','video/mp4',101),file('notas.txt','text/plain',0)],rules).length,3);
});
test('community counts retained attachments together with new uploads', () => {
    assert.deepEqual(validateCommunityFiles([file('foto.png','image/png',10)],rules,3), []);
    assert.equal(validateCommunityFiles([file('foto.png','image/png',10)],rules,4).length,1);
});
