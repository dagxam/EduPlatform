const assert=require('node:assert/strict'),fs=require('node:fs');const h=fs.readFileSync('paper-workspace.html','utf8'),a=fs.readFileSync('app.js','utf8'),b=fs.readFileSync('paper-branding.js','utf8');const main=fs.readFileSync('index.html','utf8');const nav=main.match(/<nav class="nav teacher-nav">([\s\S]*?)<\/nav>/)[1];for(const m of nav.matchAll(/data-view="([^"]+)"/g)){if(m[1]==='paper-tests')continue;assert(h.includes('./index.html?view='+m[1]),'missing '+m[1]);}assert(h.includes('id="paperMainSidebar"'));assert(h.includes('data-paper-role="platform"'));assert(b.includes('api/school/branding/get.php'));assert(a.includes("requested === 'journal'"));assert(a.includes("staffViews.add('system-backups')"));console.log('PAPER navigation and school branding wiring OK');
// The two cabinets must retain the same menu labels, SVG icons and order.
const paperNav=h.match(/<nav class="paper-main-nav nav">([\s\S]*?)<\/nav>/)[1];
const buttons=[...nav.matchAll(/<button\b[^>]*data-view="([^"]+)"[^>]*>([\s\S]*?)<\/button>/g)];
const links=[...paperNav.matchAll(/<a\b[^>]*href="([^"]+)"[^>]*>([\s\S]*?)<\/a>/g)];
assert.equal(links.length,buttons.length);
for(let i=0;i<buttons.length;i++){
 const view=buttons[i][1];
 assert.equal(links[i][1],view==='paper-tests'?'./paper-workspace.html':'./index.html?view='+view);
 const content=buttons[i][2].match(/<span class="nav-icon"[\s\S]*?<\/svg><\/span><span class="nav-label">[^<]+<\/span>/)[0];
 assert.equal(links[i][2],content,'Different menu design for '+view);
}
assert(/class="paper-main-link nav-item active"[^>]*aria-current="page"/.test(paperNav));
console.log('Shared UROVIA/PAPER sidebar labels, icons and routes OK');
