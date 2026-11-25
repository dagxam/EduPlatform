'use strict';const assert=require('node:assert/strict'),fs=require('node:fs');const s=fs.readFileSync('paper-workspace.js','utf8');const a=s.indexOf('function center('),b=s.indexOf('const isChoice',a);const center=new Function(s.slice(a,b)+'\nreturn formCenter;')();assert.deepEqual(center(0,0,20,1),[270,180]);assert.deepEqual(center(35,5,36,1),[610,915]);for(let n=1;n<=36;n++){const set=new Set();for(let i=0;i<n;i++)for(let j=0;j<6;j++){const [x,y]=center(i,j,n,2);assert(x>=96&&x<=610&&y>=220&&y<=880);const key=x+','+y;assert(!set.has(key));set.add(key);}}assert(s.includes('createLegacyForm(d)'));assert(s.includes('current.layout_version'));const work=fs.readFileSync('api/paper/work.php','utf8');assert(work.includes("stored['layout_version']??1"));console.log('PAPER legacy and two-column form geometry OK');
// Verify the generated SVG itself, not just the geometry formula.
const root=require('node:path').join(__dirname,'../..'),qrcode=require(root+'/vendor/paper/qrcode.js'),jsQR=require(root+'/vendor/paper/jsQR.js'),PaperScan=require(root+'/paper-scan.js');
class Node{constructor(tag){this.tag=tag;this.attrs={};this.children=[];}setAttribute(k,v){this.attrs[k]=v;}append(...nodes){this.children.push(...nodes);}}
const make=tag=>new Node(tag),svgNode=(tag,attrs,t)=>{const e=new Node(tag);e.attrs=attrs;e.textContent=t;return e;};
const marker=[[28,28],[692,28],[692,972],[28,972]],W=720,H=1000;
const create=new Function('make','svgNode','qrcode','marker','W','H',s.slice(a,s.indexOf('function buildPackage(d)'))+'\nreturn createForm;')(make,svgNode,qrcode,marker,W,H);
const homography=new Function(s.slice(s.indexOf('function homography('),s.indexOf('\nfunction darkness'))+'\nreturn homography;')();
for(const layout of [1,2]){
 const d={layout_version:layout,code:'0123456789abcdef0123456789abcdef',student:{first_name:'Тест',last_name:'Ученик',class_name:'7А'},variant_label:'A',questions:Array.from({length:20},()=>({type:'single',options:[{},{},{},{}]}))};
 const svg=create(d).children[0],pixels=new Uint8ClampedArray(W*H*4);pixels.fill(255);
 const put=(x,y)=>{if(x>=0&&y>=0&&x<W&&y<H){const i=(y*W+x)*4;pixels[i]=pixels[i+1]=pixels[i+2]=0;}};
 const rect=(x,y,w,h)=>{for(let py=Math.round(y);py<Math.round(y+h);py++)for(let px=Math.round(x);px<Math.round(x+w);px++)put(px,py);};
 for(const node of svg.children){const v=node.attrs;if(node.tag==='rect'&&v.fill==='#000')rect(v.x,v.y,v.width,v.height);if(node.tag==='path')for(const m of v.d.matchAll(/M([\d.]+) ([\d.]+)h([\d.]+)v([\d.]+)h-[\d.]+Z/g))rect(Number(m[1]),Number(m[2]),Number(m[3]),Number(m[4]));}
 const decoded=jsQR(pixels,W,H,{inversionAttempts:'dontInvert'});assert.equal(decoded?.data,'UP'+layout+':'+d.code);assert(PaperScan.findCorners({width:W,height:H,data:pixels},decoded,homography));
 const circles=svg.children.filter(n=>n.tag==='circle');assert.equal(circles.length,80);for(let i=0;i<20;i++)for(let j=0;j<4;j++)assert.deepEqual([circles[i*4+j].attrs.cx,circles[i*4+j].attrs.cy],center(i,j,20,layout));
 const selected=[0,3,1,2];for(let i=0;i<20;i++){const [x,y]=center(i,selected[i%4],20,layout);for(let dy=-6;dy<=6;dy++)for(let dx=-6;dx<=6;dx++)if(dx*dx+dy*dy<=36)put(Math.round(x+dx),Math.round(y+dy));}
 const frame={width:W,height:H,data:pixels};const dark=new Function('image',s.slice(s.indexOf('function darkness('),s.indexOf('function autoCorners('))+'\nreturn darkness;')(frame);const map=homography(marker);for(let i=0;i<20;i++)for(let j=0;j<4;j++){const [x,y]=center(i,j,20,layout);assert.equal(dark(x,y,map)-dark(x+(layout===2?18:22),y,map)>=.36,j===selected[i%4]);}
}
console.log('PAPER actual legacy and new SVG QR + circle coordinates OK');
