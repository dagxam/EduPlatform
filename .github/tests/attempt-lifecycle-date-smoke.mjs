import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
const source=readFileSync('attempt-security.js','utf8');
const app=readFileSync('app.js','utf8');
function fixture(policy){
 let now=0,id=0,locks=[],events=[],jobs=new Map(),w={},d={hidden:false,hasFocus:()=>true};
 function target(obj){const handlers=new Map();obj.addEventListener=(n,fn)=>handlers.set(n,fn);obj.removeEventListener=n=>handlers.delete(n);obj.fire=(n,e={})=>handlers.get(n)?.(e);return obj;}
 target(w);target(d);
 w.setTimeout=(fn,ms)=>{jobs.set(++id,{fn,at:now+ms});return id;};w.clearTimeout=i=>jobs.delete(i);
 const fetch=async (url,opts)=>{events.push([url,JSON.parse(opts.body)]);return {ok:true,json:async()=>({ok:true,active:true,terminated:false})};};
 const s=new Function('window','document','fetch',source+';return AttemptSecurity;')(w,d,fetch);
 s.start({attemptId:1,focusPolicy:policy,onLocked:x=>locks.push(x)});
 const advance=ms=>{now+=ms;for(const [i,job] of [...jobs])if(job.at<=now){jobs.delete(i);job.fn();}};
 return {s,w,d,advance,locks,events};
}
let x=fixture('allow');x.d.hidden=true;x.d.fire('visibilitychange');x.advance(9000);x.w.fire('pagehide',{persisted:false});assert.equal(x.locks.length,0);
x=fixture('strict');x.d.hasFocus=()=>false;x.w.fire('blur');x.advance(6000);assert.equal(x.locks.length,0,'visible keyboard blur must not terminate');
x.d.hidden=true;x.d.fire('visibilitychange');x.advance(2000);x.d.hidden=false;x.d.fire('visibilitychange');x.advance(6000);assert.equal(x.locks.length,0,'brief hidden event');
x.w.fire('pagehide',{persisted:true});assert.equal(x.locks.length,0,'bfcache');
x.d.hidden=true;x.d.fire('visibilitychange');x.advance(6000);assert.deepEqual(x.locks,['hidden']);
x=fixture('strict');x.w.fire('pagehide',{persisted:false});assert.deepEqual(x.locks,['pagehide']);
const extract=(name,next)=>app.slice(app.indexOf('function '+name+'('),app.indexOf('function '+next+'('));
const parse=new Function(extract('uroviaUtcDate','resultDateTime')+'return uroviaUtcDate;')();
assert.equal(parse('2026-11-26 22:30:00').toISOString(),'2026-11-26T22:30:00.000Z');
assert.equal(parse('2026-11-27T01:30:00+03:00').toISOString(),'2026-11-26T22:30:00.000Z');
const short=new Function('uroviaUtcDate',extract('journalShortDate','journalAvailableClasses')+'return {journalShortDate,journalWorkDate};')(parse);
assert.equal(short.journalShortDate('2026-11-26 22:30:00'),'27.11');
assert.equal(short.journalWorkDate({id:1,created_at:'2026-11-01 09:00:00',due_at:'2026-11-30 10:00:00'},[{cells:{1:{performed_at:'2026-11-26 22:30:00'}}}]),'27.11');
assert(!app.includes("sendActiveAttemptCloseBeacon('browser_closed')"));
console.log('Quiz lifecycle, transient blur and actual test dates OK');
