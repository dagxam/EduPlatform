'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),qrcode=require('../../vendor/paper/qrcode.js'),jsQR=require('../../vendor/paper/jsQR.js'),PaperScan=require('../../paper-scan.js');
const s=fs.readFileSync('paper-workspace.js','utf8'),geom=s.slice(s.indexOf('function center('),s.indexOf('const isChoice'));
const homography=new Function(s.slice(s.indexOf('function homography('),s.indexOf('\nfunction darkness'))+'\nreturn homography;')();
class Node{constructor(tag){this.tag=tag;this.children=[];this.attrs={};}append(...items){this.children.push(...items);}}
const make=tag=>new Node(tag),svgNode=(tag,attrs,t)=>Object.assign(new Node(tag),{attrs,textContent:t});
const create=new Function('make','svgNode','qrcode',geom+s.slice(s.indexOf('function createCompactForm('),s.indexOf('function buildCompactPackage('))+'\nreturn {createCompactForm,formCenter};')(make,svgNode,qrcode);
for(const count of [1,10,20]){
const d={code:'0123456789abcdef0123456789abcdef',variant_label:'A',questions:Array.from({length:count},()=>({type:'single',options:[{},{},{},{},{},{}]}))};
const svg=create.createCompactForm(d).children[0],W=900,H=1100,ox=90,oy=740,pixels=new Uint8ClampedArray(W*H*4);pixels.fill(255);
function put(x,y){x+=ox;y+=oy;if(x>=0&&x<W&&y>=0&&y<H){const p=(y*W+x)*4;pixels[p]=pixels[p+1]=pixels[p+2]=0;}}
function rect(x,y,w,h){for(let py=Math.round(y);py<Math.round(y+h);py++)for(let px=Math.round(x);px<Math.round(x+w);px++)put(px,py);}
for(const node of svg.children){const v=node.attrs;if(node.tag==='rect'&&v.fill==='#000')rect(v.x,v.y,v.width,v.height);if(node.tag==='path')for(const m of v.d.matchAll(/M([\d.]+) ([\d.]+)h([\d.]+)v([\d.]+)h-[\d.]+Z/g))rect(+m[1],+m[2],+m[3],+m[4]);}
const qr=jsQR(pixels,W,H,{inversionAttempts:'dontInvert'});assert.equal(qr?.data,'UP3:'+d.code);const points=PaperScan.findCorners({width:W,height:H,data:pixels},qr,homography);assert(points,'compact markers on a full page');const map=homography(points),circles=svg.children.filter(n=>n.tag==='circle');
assert.equal(circles.length,count*6);
for(let i=0;i<count;i++)for(let j=0;j<6;j++){const v=circles[i*6+j].attrs,[x,y]=map(...create.formCenter(i,j,count,3));assert(Math.hypot(x-v.cx-ox,y-v.cy-oy)<3);}
}
assert(s.includes('packet.scrollHeight<=packet.clientHeight+1'));
console.log('PAPER compact QR and bubble coordinates OK');
