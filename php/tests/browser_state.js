// 通信切断・管理画面のキャッシュ・下書き削除失敗を、実データなしで検証する。
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '../..');
async function main() {
  const events = {}, saved = [], removed = [];
  let response = new Response('ok'), offline = false;
  const context = vm.createContext({URL, Response,
    self: {location: {origin:'https://salon.test'}, addEventListener:(n,f)=>events[n]=f,
      skipWaiting:async()=>{}, clients:{claim:async()=>{}}},
    fetch:async()=>{if(offline) throw Error('offline'); return response.clone();},
    caches:{keys:async()=>['zaiko-v8-grey-gold','zaiko-v9-static-only','another-app'],
      delete:async k=>removed.push(k), open:async()=>({put:async(req,res)=>saved.push(req.url)}),
      match:async(req)=>req.url.endsWith('icon.png') ? new Response('cached icon') : undefined}
  });
  vm.runInContext(fs.readFileSync(path.join(root,'static/sw.js'),'utf8'),context);
  let activate;
  events.activate({waitUntil:p=>activate=p}); await activate;
  assert.deepEqual(removed,['zaiko-v8-grey-gold']);
  async function request(url, method='GET') {
    const waits=[]; let result;
    events.fetch({request:{url:'https://salon.test'+url,method},
      waitUntil:p=>waits.push(p), respondWith:p=>result=p});
    const res=await result; await Promise.all(waits); return res;
  }
  for(const url of ['/','/stock','/customer-orders','/settings/web-orders','/order/1/pdf']) {
    assert.equal((await request(url)).status,200);
  }
  assert.equal(saved.length,0,'在庫・顧客・ログイン情報・PDFをキャッシュしない');
  await request('/static/icon.png'); assert.equal(saved.length,1);
  response=new Response('private',{headers:{'Cache-Control':'private, no-store'}});
  await request('/static/private'); assert.equal(saved.length,1,'保存禁止の応答を保存しない');
  assert.equal(await request('/api/stock','POST'),undefined,'POSTを変更しない');
  offline=true;
  assert.equal(await (await request('/static/icon.png')).text(),'cached icon');
  const unavailable=await request('/settings/web-orders');
  assert.equal(unavailable.status,503,'オフラインで古い管理画面を表示しない');
  assert.equal(unavailable.headers.get('Cache-Control'),'no-store');
  assert.equal((await request('/static/missing')).status,503);

  const source=fs.readFileSync(path.join(root,'templates/order.html'),'utf8');
  const discard=source.slice(source.indexOf('async function discardDraft()'),source.indexOf('function scheduleDraftSave()'));
  for(const mode of ['offline','denied','ok']) {
    let reloads=0, warnings=0;
    const ctx=vm.createContext({draftLocked:false,STORE:'イノ',SUPPLIER:'test',confirm:()=>true,
      fetch:async()=>{if(mode==='offline') throw Error('offline'); return {ok:mode==='ok',json:async()=>({ok:mode==='ok'})};},
      location:{reload:()=>reloads++},setDraftStatus:()=>warnings++,toast:()=>{}});
    vm.runInContext(discard,ctx); await vm.runInContext('discardDraft()',ctx);
    assert.equal(reloads,mode==='ok'?1:0);
    assert.equal(ctx.draftLocked,mode==='ok');
    assert.equal(warnings,mode==='ok'?0:1);
  }
  console.log('通信・キャッシュ・下書き: すべて成功');
}
main().catch(e=>{console.error(e);process.exitCode=1;});
