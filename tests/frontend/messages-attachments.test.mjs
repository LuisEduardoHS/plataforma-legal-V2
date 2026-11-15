import test from 'node:test';
import assert from 'node:assert/strict';
import { validateAttachment, formatBytes, createAttachment, releaseAttachment } from '../../resources/js/messages/attachments.js';
const rules = { maxCount:4, maxBytes:{image:10*1024**2,document:20*1024**2,video:100*1024**2,other:20*1024**2}, types:{
    'image/jpeg':{category:'image',extensions:['jpg','jpeg']},'image/png':{category:'image',extensions:['png']},'image/gif':{category:'image',extensions:['gif']},'image/webp':{category:'image',extensions:['webp']},
    'application/pdf':{category:'document',extensions:['pdf']},'text/plain':{category:'document',extensions:['txt']},'audio/mpeg':{category:'other',extensions:['mp3']},'video/mp4':{category:'video',extensions:['mp4']},'video/webm':{category:'video',extensions:['webm']},
} };
const file = (name,type,size=1024) => ({name,type,size,lastModified:1});
test('messaging accepts actual domain formats including GIF TXT and MP3', () => {
    for(const [name,type] of [['a.JPG','image/jpeg'],['a.png','image/png'],['a.gif','image/gif'],['a.webp','image/webp'],['a.pdf','application/pdf'],['a.txt','text/plain'],['a.mp3','audio/mpeg'],['a.mp4','video/mp4'],['a.webm','video/webm'],['a.png','']]) assert.equal(validateAttachment(file(name,type),[],rules),null);
});
test('messaging rejects demo-only DOCX, SVG, MIME mismatches and empty files', () => {
    for(const f of [file('a.docx',''),file('a.svg','image/svg+xml'),file('a.png','text/html'),file('a.txt','text/plain',0)]) assert.ok(validateAttachment(f,[],rules));
});
test('messaging follows server category byte limits rather than demo video limits', () => {
    for(const [name,type,size] of [['a.png','image/png',10],['a.pdf','application/pdf',20],['a.mp4','video/mp4',100],['a.mp3','audio/mpeg',20]]) {
        assert.equal(validateAttachment(file(name,type,size*1024**2),[],rules),null);
        assert.ok(validateAttachment(file(name,type,size*1024**2+1),[],rules));
    }
});
test('messaging counts existing prepared files and rejects duplicate selections', () => {
    const f=file('a.png','image/png');assert.ok(validateAttachment(f,[f],rules));
    assert.ok(validateAttachment(f,[{},{},{},{}],rules));
    assert.equal(validateAttachment(f,[{},{},{}],rules),null);
});
test('file metadata uses readable units without creating local preview URLs', () => {
    assert.equal(formatBytes(1024),'1 KB'); assert.equal(formatBytes(1024**2),'1.0 MB');
});

test('legacy LEXA attachment exports remain usable without connecting its demo', async () => {
    const item = createAttachment(new File(['example'], 'lexa.pdf', {type:'application/pdf'}));
    assert.equal(item.kind,'document'); assert.equal(await (await fetch(item.url)).text(),'example');
    releaseAttachment(item); await assert.rejects(fetch(item.url));
});
