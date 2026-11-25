'use strict';
(function(root){
 function findCorners(frame,qr,homography){
  if(!frame||!qr?.location||frame.width<200||frame.height<200)return null;
  const loc=qr.location,tl=loc.topLeftCorner,tr=loc.topRightCorner,bl=loc.bottomLeftCorner,br=loc.bottomRightCorner;
  if(!tl||!tr||!bl||!br)return null;
  // Require upright form, not a sideways or upside-down capture.
  if(tr.x-tl.x<Math.abs(tr.y-tl.y)*1.2||bl.y-tl.y<Math.abs(bl.x-tl.x)*1.2)return null;
  const scale=Math.min(1,1100/Math.max(frame.width,frame.height)),w=Math.round(frame.width*scale),h=Math.round(frame.height*scale);
  const dark=new Uint8Array(w*h),queue=new Int32Array(w*h),pixels=frame.data;
  const hist=new Uint32Array(256);for(let i=0;i<pixels.length;i+=64)hist[Math.round((pixels[i]+pixels[i+1]+pixels[i+2])/3)]++;let total=hist.reduce((a,b)=>a+b,0),sum=0,white=255;for(let i=0;i<256;i++){sum+=hist[i];if(sum>=total*.75){white=i;break;}}const threshold=Math.max(65,Math.min(175,white*.67))*3;
  for(let y=0;y<h;y++)for(let x=0;x<w;x++){const i=(Math.min(frame.height-1,Math.round(y/scale))*frame.width+Math.min(frame.width-1,Math.round(x/scale)))*4;dark[y*w+x]=(pixels[i]+pixels[i+1]+pixels[i+2]<threshold)?1:0;}
  const candidates=[],minSide=Math.max(4,Math.min(w,h)*.005),maxSide=Math.min(w,h)*.10;
  for(let i=0;i<dark.length;i++){
   if(!dark[i])continue;dark[i]=0;queue[0]=i;let head=0,tail=1,l=i%w,r=l,t=Math.floor(i/w),b=t;
   while(head<tail){const p=queue[head++],x=p%w,y=Math.floor(p/w);l=Math.min(l,x);r=Math.max(r,x);t=Math.min(t,y);b=Math.max(b,y);
    for(const k of [x>0?p-1:-1,x<w-1?p+1:-1,y>0?p-w:-1,y<h-1?p+w:-1])if(k>=0&&dark[k]){dark[k]=0;queue[tail++]=k;}
   }
   const bw=r-l+1,bh=b-t+1;
   if(Math.min(bw,bh)<minSide||Math.max(bw,bh)>maxSide||Math.max(bw,bh)/Math.min(bw,bh)>1.7||tail/(bw*bh)<.55)continue;
   candidates.push({p:[(l+r)/2/scale,(t+b)/2/scale],size:Math.sqrt(bw*bh)/scale});
  }
  const W=frame.width,H=frame.height;
  const qx=(tl.x+tr.x+bl.x+br.x)/4,qy=(tl.y+tr.y+bl.y+br.y)/4,qrWidth=Math.hypot(tr.x-tl.x,tr.y-tl.y);
  const modules=Number.isInteger(qr.version)&&qr.version>=1&&qr.version<=40?17+4*qr.version:29;
  const edge=104*modules/(modules+8),ux=[(tr.x-tl.x+br.x-bl.x)/(2*edge),(tr.y-tl.y+br.y-bl.y)/(2*edge)],uy=[(bl.x-tl.x+br.x-tr.x)/(2*edge),(bl.y-tl.y+br.y-tr.y)/(2*edge)];
  const predict=(x,y)=>[qx+(x-620)*ux[0]+(y-95)*uy[0],qy+(x-620)*ux[1]+(y-95)*uy[1]];
  const mid=predict(360,500);
  const targets=[[28,28],[692,28],[692,972],[28,972]].map(([x,y])=>predict(x,y));
  const det=ux[0]*uy[1]-ux[1]*uy[0];if(Math.abs(det)<.0001)return null;
  const lists=targets.map(([x,y],i)=>candidates.filter(c=>{const dx=c.p[0]-mid[0],dy=c.p[1]-mid[1],u=(dx*uy[1]-dy*uy[0])/det,v=(dy*ux[0]-dx*ux[1])/det;return (i===0||i===3?u<0:u>0)&&(i<2?v<0:v>0);}).sort((a,b)=>Math.hypot(a.p[0]-x,a.p[1]-y)-Math.hypot(b.p[0]-x,b.p[1]-y)).slice(0,7));
  if(lists.some(l=>!l.length))return null;
  let best=null,bestError=Infinity;
  for(const a of lists[0])for(const b of lists[1])for(const c of lists[2])for(const d of lists[3]){
   const sizes=[a.size,b.size,c.size,d.size];if(Math.max(...sizes)/Math.min(...sizes)>2.2)continue;
   const p=[a.p,b.p,c.p,d.p],cross=p.map((v,i)=>{const u=p[(i+1)%4],z=p[(i+2)%4];return (u[0]-v[0])*(z[1]-u[1])-(u[1]-v[1])*(z[0]-u[0]);});
   if(cross.some(v=>v<=0))continue;
   const area=p.reduce((s,v,i)=>s+v[0]*p[(i+1)%4][1]-v[1]*p[(i+1)%4][0],0)/2;
   if(area<W*H*.12)continue;
   try{
    const map=homography(p),center=map(620,95),left=map(580,95),right=map(660,95);
    const predicted=Math.hypot(right[0]-left[0],right[1]-left[1]);
    if(!Number.isFinite(predicted)||predicted<=0||qrWidth/predicted<.6||qrWidth/predicted>1.5)continue;
    const err=Math.hypot(center[0]-qx,center[1]-qy)+Math.abs(predicted-qrWidth)*.35;
    if(err<bestError){bestError=err;best=p;}
   }catch{}
  }
  return bestError<Math.max(14,qrWidth*.4)?best:null;
 }
 function upright(frame,qr){
  const l=qr?.location;if(!l?.topLeftCorner||!l?.topRightCorner)return {frame,qr};
  const dx=l.topRightCorner.x-l.topLeftCorner.x,dy=l.topRightCorner.y-l.topLeftCorner.y;
  const turns=Math.abs(dx)>=Math.abs(dy)?(dx<0?2:0):(dy>0?3:1);
  if(!turns)return {frame,qr};
  const w=frame.width,h=frame.height,nw=turns%2?h:w,nh=turns%2?w:h;
  const rotate=(x,y)=>turns===1?[h-1-y,x]:turns===2?[w-1-x,h-1-y]:[y,w-1-x];
  const data=new Uint8ClampedArray(nw*nh*4);
  for(let y=0;y<h;y++)for(let x=0;x<w;x++){const [xx,yy]=rotate(x,y),a=(y*w+x)*4,b=(yy*nw+xx)*4;data[b]=frame.data[a];data[b+1]=frame.data[a+1];data[b+2]=frame.data[a+2];data[b+3]=frame.data[a+3];}
  const location={};for(const [key,p] of Object.entries(l)){if(p&&Number.isFinite(p.x)&&Number.isFinite(p.y)){const [x,y]=rotate(p.x,p.y);location[key]={x,y};}else location[key]=p;}
  return {frame:{width:nw,height:nh,data},qr:{...qr,location}};
 }
 function analyze(frame,qr,homography){
  const u=upright(frame,qr);return {...u,points:findCorners(u.frame,u.qr,homography)};
 }
 const api={findCorners,upright,analyze};
 if(typeof module==='object'&&module.exports)module.exports=api;else root.PaperScan=api;
})(typeof globalThis!=='undefined'?globalThis:this);
