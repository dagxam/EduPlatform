import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
const s=readFileSync('pwa.js','utf8');
const sw=readFileSync('sw.js','utf8');
for(const name of ['index.html','login.html','paper.html','paper-workspace.html']){
 const html=readFileSync(name,'utf8');
 assert(html.includes('name="urovia-release"'));
 assert(/pwa\.js\?v=\d+/.test(html));
 assert(/pwa\.css\?v=\d+/.test(html));
}
assert(s.indexOf('checkForReleaseUpdate().catch(() => {});',s.indexOf('async function initializePwa')) < s.indexOf("if (!('serviceWorker' in navigator)) return;"));
assert(sw.includes("url.pathname.endsWith('/version.json')"));
assert(s.includes('if(!updateRequested){checkForReleaseUpdate()'));
let shown=0,hidden=0,remote='a'.repeat(40);
const check=new Function('window','loadedReleaseVersion','showUpdateReady','hideUpdateReady', 'let releaseCheckInFlight=false,latestReleaseVersion="",releaseUpdateAvailable=false,updatePending=false;'+s.slice(s.indexOf('async function checkForReleaseUpdate()'),s.indexOf('async function checkForUpdates()'))+';return checkForReleaseUpdate;')({fetch:async()=>({ok:true,json:async()=>({version:remote})})},()=> 'a'.repeat(40),()=>shown++,()=>hidden++);
await check();assert.equal(shown,0);assert.equal(hidden,1);
remote='b'.repeat(40);await check();assert.equal(shown,1);
remote='bad';await check();assert.equal(shown,1);
console.log('Release marker detection across all four UROVIA entry points OK');
