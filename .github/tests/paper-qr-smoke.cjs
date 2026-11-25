'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const root=path.join(__dirname,'../..');
const qrcode=require(path.join(root,'vendor/paper/qrcode.js'));
const jsQR=require(path.join(root,'vendor/paper/jsQR.js'));
const PaperScan=require(path.join(root,'paper-scan.js'));
const src=fs.readFileSync(path.join(root,'paper-workspace.js'),'utf8');
const start=src.indexOf('function homography('),end=src.indexOf('\nfunction darkness',start);
assert(start>=0&&end>start);
const homography=new Function(src.slice(start,end)+'\nreturn homography;')();
const w=720,h=1000,pixels=new Uint8ClampedArray(w*h*4);pixels.fill(255);
function black(x,y){if(x<0||x>=w||y<0||y>=h)return;const i=(y*w+x)*4;pixels[i]=pixels[i+1]=pixels[i+2]=0;}
for(const [cx,cy] of [[28,28],[692,28],[692,972],[28,972]])for(let y=cy-10;y<cy+10;y++)for(let x=cx-10;x<cx+10;x++)black(x,y);
const code='UP1:0123456789abcdef0123456789abcdef',qr=qrcode(0,'M');qr.addData(code);qr.make();
const n=qr.getModuleCount(),step=104/(n+8);
for(let y=0;y<n;y++)for(let x=0;x<n;x++)if(qr.isDark(y,x)){
 const l=568+(x+4)*step,t=43+(y+4)*step;
 for(let py=Math.round(t);py<Math.round(t+step);py++)for(let px=Math.round(l);px<Math.round(l+step);px++)black(px,py);
}
const decoded=jsQR(pixels,w,h,{inversionAttempts:'dontInvert'});assert(decoded,'QR must decode');assert.equal(decoded.data,code);
const corners=PaperScan.findCorners({width:w,height:h,data:pixels},decoded,homography);assert(corners,'Registration marks detected');
const expected=[[28,28],[692,28],[692,972],[28,972]];
for(let i=0;i<4;i++)assert(Math.hypot(corners[i][0]-expected[i][0],corners[i][1]-expected[i][1])<3);
const p=homography(corners)(620,95);assert(Math.hypot(p[0]-620,p[1]-95)<3);
console.log('PAPER local QR round-trip and automatic registration marks OK');
