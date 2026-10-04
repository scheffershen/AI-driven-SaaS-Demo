import {test} from 'node:test';
import assert from 'node:assert/strict';
import {DraftSession} from '../public/draft-session.js';

const initial = {title:'',summary:'',category:'getting-started',language:'en',content_date:'2026-10-03',asset_id:'sample'};
function session(overrides={}) {
  return new DraftSession({values:initial, saveKey:'a'.repeat(32), validate:async values=>({ok:!!values.title, errors:values.title?{}:{title:'Required'}}), submit:async()=>({id:'persisted',status:'draft',published:false}), read:async()=>null,...overrides});
}
test('invalid preparation is atomic and unknown fields are refused',async()=>{
  const s=session(); const before=s.state();
  await assert.rejects(s.prepare({summary:'changed'}),/Required/);
  assert.deepEqual(s.state(),before);
  await assert.rejects(s.prepare({published:true}),/Unsupported/);
  assert.deepEqual(s.state(),before);
});
test('manual changes invalidate prepared revision',async()=>{
  const s=session(); const prepared=await s.prepare({title:'Welcome'});
  s.edit({title:'Edited manually'});
  await assert.rejects(s.save({revision:prepared.revision,save_key:prepared.save_key}),/Prepare/);
});
test('successful save returns persisted proof and prevents a second concurrent POST',async()=>{
  let finish; let calls=0;
  const s=session({submit:()=>{calls++; return new Promise(resolve=>{finish=resolve;});}});
  const prepared=await s.prepare({title:'Welcome'});
  const first=s.save({revision:prepared.revision,save_key:prepared.save_key});
  await assert.rejects(s.save({revision:prepared.revision,save_key:prepared.save_key}),/progress/);
  finish({id:'persisted',status:'draft',published:false});
  assert.equal((await first).id,'persisted'); assert.equal(calls,1);
});
test('lost response must be reconciled before another save',async()=>{
  let calls=0;
  const s=session({submit:async()=>{calls++;throw new Error('Network disconnected');},read:async key=>({id:'committed',save_key:key,status:'draft',published:false})});
  const prepared=await s.prepare({title:'Welcome'});
  await assert.rejects(s.save({revision:prepared.revision,save_key:prepared.save_key}),/Network/);
  assert.equal(s.state().phase,'uncertain');
  await assert.rejects(s.save({revision:prepared.revision,save_key:prepared.save_key}),/Reconcile/);
  assert.equal((await s.reconcile()).id,'committed'); assert.equal(calls,1);
});
test('an edit during async preparation is never overwritten',async()=>{
  let finish;
  const s=session({validate:()=>new Promise(resolve=>{finish=resolve;})});
  const promise=s.prepare({title:'Agent title'});
  s.edit({title:'Human edit'}); finish({ok:true});
  await assert.rejects(promise,/changed/);
  assert.equal(s.state().values.title,'Human edit');
});
