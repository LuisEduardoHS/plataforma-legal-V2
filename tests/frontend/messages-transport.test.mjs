import test from 'node:test';
import assert from 'node:assert/strict';
import { createSendAttempt } from '../../resources/js/messages/attempt.js';
import { createPoller } from '../../resources/js/messages/polling.js';
const token='d5c101d0-2ae1-4321-9123-123456789012';
const nextToken='d5c101d0-2ae1-4321-9123-123456789013';
test('lost acknowledgements retry the same token text and ordered attachment IDs', () => {
    const attempt=createSendAttempt();const ids=['8','3'];const first=attempt.begin({token,content:'Texto',ids});ids.push('10');
    const retry=attempt.begin({token:'new-token',content:'Texto modificado',ids:['99']});
    assert.equal(first,retry);assert.equal(retry.token,token);assert.equal(retry.content,'Texto');assert.deepEqual(retry.ids,['8','3']);assert.ok(Object.isFrozen(retry.ids));
});
test('malformed acknowledgements never rotate tokens or discard the retry request', () => {
    const attempt=createSendAttempt();const first=attempt.begin({token,content:'Texto',ids:[]});
    for(const payload of [null,{}, {id:1,next_token:'bad'},{id:0,next_token:token},{id:1,next_token:token}]) assert.throws(()=>attempt.acknowledge(payload));
    assert.equal(attempt.current(),first);
});
test('only a valid server acknowledgement completes an attempt', () => {
    const attempt=createSendAttempt();attempt.begin({token,content:'Texto',ids:[]});
    assert.equal(attempt.acknowledge({id:17,next_token:nextToken}),nextToken);assert.equal(attempt.current(),null);
    assert.equal(attempt.begin({token:nextToken,content:'Segundo',ids:[]}).content,'Segundo');
});
test('a known validation rejection releases frozen input while preserving caller token choice', () => {
    const attempt=createSendAttempt();attempt.begin({token,content:'',ids:[]});attempt.rejected();
    assert.equal(attempt.begin({token,content:'Corregido',ids:[]}).token,token);
});
function clock(){let next=0;const tasks=new Map();return {tasks,schedule(fn){tasks.set(++next,fn);return next;},cancel(id){tasks.delete(id);},async tick(){const [id,fn]=tasks.entries().next().value;tasks.delete(id);await fn();}};}
test('polling pauses HTTP work when inactive and resumes without multiplying timers',async()=>{
    const c=clock();let active=false,calls=0;const poll=createPoller({run:async()=>calls++,active:()=>active,schedule:c.schedule,cancel:c.cancel});
    poll.start();poll.start();assert.equal(c.tasks.size,1);await c.tick();assert.equal(calls,0);active=true;poll.wake();assert.equal(c.tasks.size,1);await c.tick();assert.equal(calls,1);poll.stop();assert.equal(c.tasks.size,0);
});
test('in-flight polls cannot overlap, and leaving the page prevents rescheduling',async()=>{
    const c=clock();let finish;let calls=0;const poll=createPoller({run:()=>{calls++;return new Promise(resolve=>finish=resolve);},active:()=>true,schedule:c.schedule,cancel:c.cancel});
    poll.start();const ongoing=c.tick();poll.wake();poll.start();assert.equal(calls,1);assert.equal(c.tasks.size,0);poll.stop();finish();await ongoing;assert.equal(c.tasks.size,0);poll.wake();assert.equal(c.tasks.size,0);
});
