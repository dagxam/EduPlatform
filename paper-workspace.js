'use strict';
(() => {
const $=id=>document.getElementById(id), assignment=$('assignment'), cls=$('class'), student=$('student');
let current=null, rows=[], generation=0, image=null, corners=[], batchBusy=false,stream=null,cameraTimer=null,cameraEpoch=0,scanBusy=false,ready=false,ocrEnabled=false;
const make=(tag,text,klass)=>{const e=document.createElement(tag);if(text!==undefined&&text!==null)e.textContent=String(text);if(klass)e.className=klass;return e;};
function report(id,text,error=false){const e=$(id);e.textContent=text;e.classList.toggle('error',error);}
async function request(path,body){const options={credentials:'same-origin',cache:'no-store'};if(body){options.method='POST';options.headers={'Content-Type':'application/json'};options.body=JSON.stringify(body);}const r=await fetch(path,options),d=await r.json();if(!r.ok||!d.ok)throw Error(d.error||'Ошибка запроса.');return d;}
function fill(select,items,placeholder,label){select.replaceChildren(new Option(placeholder,''));for(const item of items)select.add(new Option(label(item),String(item.id)));}
function reset(){generation++;current=null;rows=[];image=null;corners=[];$('review').hidden=true;$('navReview').hidden=true;$('photoCanvas').hidden=true;$('photo').value='';$('verify').value='';$('confirmed').checked=false;$('printArea').replaceChildren();$('printArea').hidden=true;$('save').disabled=true;$('batchPrint').disabled=true;$('batchPrint').hidden=true;report('result','');report('scanState','');}
function buttons(){ $('cameraStart').disabled=!ready||!!stream||scanBusy;$('cameraStop').disabled=!stream;$('cameraCapture').disabled=!stream||scanBusy;$('plain').disabled=!assignment.value;$('prepare').disabled=batchBusy||!(assignment.value&&cls.value&&student.value);$('batch').disabled=batchBusy||!(assignment.value&&cls.value&&Array.from(student.options).some(o=>o.value)); }
function payload(action){return {action,assignment_id:Number(assignment.value),class_id:Number(cls.value),student_id:Number(student.value)};}
async function init(){try{const [a,c]=await Promise.all([request('./api/assignments/list.php?mode=paper'),request('./api/classes/list.php')]);fill(assignment,a.assignments||a.items||[],'Выберите бумажное задание',x=>[x.title,x.subject_name].filter(Boolean).join(' · '));fill(cls,c.classes||c.items||[],'Выберите класс',x=>x.name);const id=new URLSearchParams(location.search).get('assignment_id');if(id)assignment.value=id;ready=true;buttons();request('./api/paper/ocr.php').then(d=>{ocrEnabled=d.enabled===true;}).catch(()=>{});}catch(e){report('message',e.message,true);}}
assignment.addEventListener('change',()=>{reset();buttons();});
cls.addEventListener('change',async()=>{reset();fill(student,[],'Загрузка учеников…',()=> '');buttons();const id=cls.value;if(!id){fill(student,[],'Выберите ученика',()=> '');return;}try{const d=await request('./api/classes/students.php?class_id='+encodeURIComponent(id));if(id!==cls.value)return;fill(student,(d.students||[]).filter(x=>Number(x.is_active)===1),'Выберите ученика',x=>[x.last_name,x.first_name,x.middle_name].filter(Boolean).join(' '));buttons();}catch(e){if(id===cls.value)report('message',e.message,true);}});
student.addEventListener('change',()=>{reset();buttons();});
$('plain').addEventListener('click',()=>window.open('./paper.html?assignment_id='+encodeURIComponent(assignment.value),'_blank','noopener,noreferrer'));
function svgNode(tag,attrs,text){const e=document.createElementNS('http://www.w3.org/2000/svg',tag);Object.entries(attrs).forEach(([k,v])=>e.setAttribute(k,String(v)));if(text!==undefined)e.textContent=text;return e;}
const W=720,H=1000, marker=[[28,28],[692,28],[692,972],[28,972]];
function center(i,j){return [270+j*68,180+i*21];}
const isChoice=q=>['single','multiple','true_false'].includes(q.type);
function createForm(d){const page=make('section',null,'form-page'),svg=svgNode('svg',{viewBox:'0 0 720 1000',xmlns:'http://www.w3.org/2000/svg',role:'img','aria-label':'Бланк ответов'});const add=(tag,attrs,t)=>svg.append(svgNode(tag,attrs,t));add('rect',{x:0,y:0,width:W,height:H,fill:'#fff'});for(const [x,y] of marker)add('rect',{x:x-10,y:y-10,width:20,height:20,fill:'#000'});
const text=(x,y,t,size=18)=>add('text',{x,y,'font-size':size,'font-family':'Arial, sans-serif',fill:'#000'},t);
text(70,45,'UROVIA PAPER · БЛАНК ОТВЕТОВ',22);const fullName=[d.student.last_name,d.student.first_name,d.student.middle_name].filter(Boolean).join(' ');text(70,76,'Ученик: '+(fullName.length>43?fullName.slice(0,40)+'…':fullName),16);text(70,101,'Класс: '+d.student.class_name+' · Вариант: '+d.variant_label,16);text(70,125,'Код: '+d.code,13);text(70,145,'Закрасьте выбранные кружки. Письменные ответы — в задании.',12);
const qr=qrcode(0,'M');qr.addData('UP1:'+d.code);qr.make();const n=qr.getModuleCount(),step=104/(n+8);add('rect',{x:568,y:43,width:104,height:104,fill:'#fff'});let path='';for(let y=0;y<n;y++)for(let x=0;x<n;x++)if(qr.isDark(y,x)){const left=568+(x+4)*step,top=43+(y+4)*step;path+='M'+left+' '+top+'h'+step+'v'+step+'h-'+step+'Z';}add('path',{d:path,fill:'#000','shape-rendering':'crispEdges'});
for(let j=0;j<6;j++)text(center(0,j)[0]-6,165,String.fromCharCode(65+j),17);
d.questions.forEach((q,i)=>{text(90,center(i,0)[1]+5,String(i+1).padStart(2,'0'),15);text(135,center(i,0)[1]+5,isChoice(q)?(q.type==='multiple'?'Неск.':'Один'):'Письм.',12);q.options.forEach((_,j)=>{const [x,y]=center(i,j);add('circle',{cx:x,cy:y,r:7.5,fill:'#fff',stroke:'#000','stroke-width':1.4});});});
text(70,954,'urovia.ru · Сохраните бланк до подтверждения оценки.',14);page.append(svg);return page;}
function buildPackage(d){const name=[d.student.last_name,d.student.first_name,d.student.middle_name].filter(Boolean).join(' ');const packet=make('div',null,'paper-packet'),sheet=make('article',null,'sheet');sheet.append(make('div','UROVIA PAPER · Вариант '+d.variant_label,'stamp'),make('h1',d.assignment.title),make('p',name+' · '+d.student.class_name),make('p','Код бланка: '+d.code,'paper-code'),make('p','Отметьте варианты на отдельном бланке. Письменные ответы запишите под вопросами.'));
d.questions.forEach((q,i)=>{const section=make('section',null,'question');section.append(make('h2',(i+1)+'. '+q.text+' ('+q.points+' балл.)'));for(const a of q.assets||[]){const url=new URL(a.url,location.href);if(url.origin!==location.origin||!url.pathname.endsWith('/api/questions/asset.php'))throw Error('Неверный адрес изображения.');const img=make('img',null,'asset');img.src=url.href;img.alt=a.original_name||'Изображение';section.append(img);}if(isChoice(q)){const ul=make('ul',null,'options');q.options.forEach((o,j)=>ul.append(make('li',String.fromCharCode(65+j)+'. '+o.text)));section.append(ul);}else{const structured=q.structured||{};if(structured.original_text)section.append(make('p',structured.original_text,'original'));const list=(items,alpha)=>{const ol=make('ol');if(alpha)ol.type='A';for(const item of items||[])ol.append(make('li',item.text));return ol;};if(q.type==='order'){section.append(make('p','Запишите последовательность букв.'),list(structured.items,true));}if(q.type==='matching'){const grid=make('div',null,'match-grid');grid.append(list(structured.left,false),list(structured.right,true));section.append(make('p','Запишите пары, например 1-A, 2-B.'),grid);}for(let k=0;k<(q.type==='essay'?8:3);k++)section.append(make('div',null,'writing-line'));}sheet.append(section);});packet.append(sheet,createForm(d));return packet;}
async function render(d,version){const name=[d.student.last_name,d.student.first_name,d.student.middle_name].filter(Boolean).join(' ');$('identity').textContent=name+' · '+d.student.class_name+' · Вариант '+d.variant_label+' · код '+d.code.slice(-8).toUpperCase();const area=$('printArea'),packet=buildPackage(d);area.replaceChildren(packet);area.hidden=false;await Promise.all(Array.from(packet.querySelectorAll('img'),img=>img.decode()));if(version!==generation)return;$('print').disabled=false;
const answers=$('answers');answers.replaceChildren();rows=d.questions.map((q,i)=>{
const row=make('div',null,'answer-row');row.append(make('strong',(i+1)+'.'));
const changed=()=>{row.classList.remove('uncertain');$('confirmed').checked=false;updateSave();};
if(!isChoice(q)){
 row.classList.add('written-row');const title=make('span',q.text,'written-title');const input=make('textarea');input.rows=q.type==='essay'?5:2;input.maxLength=8000;input.placeholder=q.type==='order'?'A B C':q.type==='matching'?'1-A, 2-B':'Введите или распознайте письменный ответ';input.setAttribute('aria-label','Письменный ответ '+(i+1));input.addEventListener('input',changed);
 const label=make('label','Баллы учителя (0–'+q.points+')');const score=make('input');score.type='number';score.min='0';score.max=String(q.points);score.step='0.01';score.placeholder=q.manual_required?'Обязательно':'Автоматически по ключу';score.addEventListener('input',changed);label.append(score);
 const ocr=make('label','Распознать фото отдельного ответа (если разрешено администратором)');const file=make('input');file.type='file';file.accept='image/*';ocr.append(file);const status=make('small');file.addEventListener('change',()=>recognizeWriting(file,input,status,changed,q.id));row.append(title,input,label);if(ocrEnabled)row.append(ocr,status);answers.append(row);return {row,inputs:[],input,score,manual:q.manual_required};
}
const inputs=q.options.map((_,j)=>{const label=make('label'),input=make('input');input.type='checkbox';input.setAttribute('aria-label','Вопрос '+(i+1)+', ответ '+String.fromCharCode(65+j));input.addEventListener('change',()=>{if(q.type!=='multiple'&&input.checked)for(const other of inputs)if(other!==input)other.checked=false;changed();});label.append(input,make('span',String.fromCharCode(65+j)));row.append(label);return input;});answers.append(row);return {row,inputs};});}
async function prepareSelected(){
 reset();const version=generation; $('prepare').disabled=true;report('message','Подготовка бланка…');
 try{const d=await request('./api/paper/work.php',payload('prepare'));if(version!==generation)return null;if(d.saved){report('message','Уже проверено: оценка '+d.result.grade+' ('+d.result.percent+'%).');return null;}current=d;$('print').disabled=true;await render(d,version);if(version!==generation)return null;$('review').hidden=false;$('navReview').hidden=false;report('message','Бланк закреплён за учеником.');return d;}
 catch(e){if(version===generation){reset();report('message',e.message,true);}return null;}
 finally{buttons();}
}
$('prepare').addEventListener('click',prepareSelected);
$('print').addEventListener('click',()=>{if(current&&!$('print').disabled)window.print();});
$('batchPrint').addEventListener('click',()=>{if(!$('batchPrint').disabled&&!$('printArea').hidden)window.print();});
$('batch').addEventListener('click',async()=>{
  if(batchBusy)return;
  const targets=Array.from(student.options).filter(o=>o.value).map(o=>({id:Number(o.value),name:o.textContent}));
  if(!targets.length)return;
  if(targets.length>100){report('message','Для комплекта более 100 учеников используйте индивидуальную печать.',true);return;}
  reset();const version=generation;batchBusy=true;buttons();
  const body={action:'prepare',assignment_id:Number(assignment.value),class_id:Number(cls.value)};
  const packets=[],skipped=[];
  try{
    for(const target of targets){
      if(version!==generation)return;
      report('message','Подготовка класса: '+(packets.length+skipped.length)+' / '+targets.length+'…');
      try{
        const d=await request('./api/paper/work.php',{...body,student_id:target.id});
        if(version!==generation)return;
        if(d.saved){skipped.push(target.name+': уже проверено');continue;}
        const packet=buildPackage(d);
        await Promise.all(Array.from(packet.querySelectorAll('img'),img=>img.decode()));
        if(version!==generation)return;
        packets.push(packet);
      }catch(e){if(version!==generation)return;skipped.push(target.name+': '+e.message);}
    }
    if(version!==generation)return;
    const area=$('printArea');area.replaceChildren(...packets);area.hidden=packets.length===0;
    $('batchPrint').hidden=packets.length===0;$('batchPrint').disabled=packets.length===0;
    report('message','Готово к печати: '+packets.length+' из '+targets.length+(skipped.length?'\nПропущено:\n'+skipped.join('\n'):''),packets.length===0);
  }finally{batchBusy=false;buttons();}
});
function updateSave(){const valid=rows.every(r=>!r.score||(r.score.value===''?!r.manual:r.score.validity.valid));$('save').disabled=!(current&&valid&&$('confirmed').checked&&$('verify').value.trim().toLowerCase()===current.code.slice(-8).toLowerCase());}
$('confirmed').addEventListener('change',updateSave);$('verify').addEventListener('input',updateSave);
$('save').addEventListener('click',async()=>{if($('save').disabled)return;const version=generation;$('save').disabled=true;try{const d=await request('./api/paper/work.php',{...payload('submit'),code:current.code,confirmed:true,answers:rows.map(r=>r.input?{text:r.input.value,score:r.score.value===''?null:Number(r.score.value)}:r.inputs.flatMap((x,j)=>x.checked?[j]:[]))});if(version!==generation)return;report('result','Сохранено в журнале: оценка '+d.result.grade+' · '+d.result.percent+'% · '+d.result.score+' / '+d.result.max_score+' балл.');current=null;$('confirmed').checked=false;updateSave();}catch(e){if(version===generation){report('result',e.message,true);updateSave();}}});
const canvas=$('photoCanvas'),ctx=canvas.getContext('2d',{willReadFrequently:true});
async function decodeFile(file,max=1800){
 const url=URL.createObjectURL(file);
 try{const img=new Image();img.src=url;await img.decode();const scale=Math.min(1,max/Math.max(img.naturalWidth,img.naturalHeight));const c=document.createElement('canvas');c.width=Math.max(1,Math.round(img.naturalWidth*scale));c.height=Math.max(1,Math.round(img.naturalHeight*scale));c.getContext('2d').drawImage(img,0,0,c.width,c.height);return c;}
 finally{URL.revokeObjectURL(url);}
}
async function recognizeWriting(file,input,status,changed,questionId){
 const source=file.files[0],d=current,version=generation;if(!source||!d)return;
 if(!window.confirm('Отправить только вырезанный обезличенный фрагмент рукописного ответа внешнему ИИ Groq? Не отправляйте ФИО, класс, QR-код или другие персональные данные. Необходимо разрешение школы.')){file.value='';return;}
 file.disabled=true;status.textContent='Распознавание…';
 try{const c=await decodeFile(source,1400);if(version!==generation)return;const result=await request('./api/paper/ocr.php',{code:d.code,question_id:questionId,consent:true,image:c.toDataURL('image/jpeg',.82)});if(version!==generation)return;input.value=result.text||'';changed();status.textContent='Черновик распознавания. Обязательно сверьте с оригиналом.';}
 catch(e){status.textContent=e.message+' Введите ответ вручную.';}finally{file.disabled=false;file.value='';}
}
function qrResult(frame){try{return typeof jsQR==='function'?jsQR(frame.data,frame.width,frame.height,{inversionAttempts:'dontInvert'}):null;}catch{return null;}}
function qrCode(result){const match=String(result?.data||'').match(/^UP1:([a-f0-9]{32})$/i);return match?match[1].toLowerCase():null;}
async function openByCode(code){
 if(current?.code===code)return current;
 if(current&&!window.confirm('Открыть другой бланк? Несохранённые ответы текущего бланка будут сброшены.'))return null;
 const version=generation;const target=await request('./api/paper/lookup.php',{code});if(version!==generation)return null;
 if(!Array.from(assignment.options).some(o=>o.value===String(target.assignment_id)))assignment.add(new Option(target.title||'Ранее выпущенный бланк № '+target.assignment_id,String(target.assignment_id)));assignment.value=String(target.assignment_id);cls.value=String(target.class_id);
 if(!assignment.value||!cls.value)throw Error('Задание или класс недоступны в текущей школе.');
 const roster=await request('./api/classes/students.php?class_id='+encodeURIComponent(cls.value));if(version!==generation)return null;
 fill(student,(roster.students||[]).filter(x=>Number(x.is_active)===1),'Выберите ученика',x=>[x.last_name,x.first_name,x.middle_name].filter(Boolean).join(' '));student.value=String(target.student_id);if(!student.value)throw Error('Ученик недоступен.');
 buttons();const d=await prepareSelected();if(!d)return null;if(d.code!==code)throw Error('Код не совпадает с текущим бланком.');return d;
}
function showFrame(frame,points,qrVerified){
 canvas.width=frame.width;canvas.height=frame.height;ctx.putImageData(frame,0,0);image=frame;corners=points||[];canvas.hidden=false;$('confirmed').checked=false;if(qrVerified)$('verify').value=current.code.slice(-8);else $('verify').value='';updateSave();
 if(corners.length===4){try{scan();return;}catch(e){corners=[];report('scanState',e.message+' Укажите углы вручную.',true);return;}}
 report('scanState','Укажите четыре чёрных квадрата: сначала верхний левый, затем верхний правый, нижний правый, нижний левый.');
}
async function processFrame(frame,result,points){
 const code=qrCode(result);
 if(code){const d=await openByCode(code);if(!d)return false;showFrame(frame,points||autoCorners(frame,result),true);return true;}
 if(current){showFrame(frame,null,false);report('scanState','QR не распознан. Укажите четыре угла и введите код вручную.',true);return true;}
 throw Error('Не найден QR UROVIA PAPER. Выберите ученика вручную или наведите камеру на бланк.');
}
$('photo').addEventListener('change',async()=>{const file=$('photo').files[0];if(!file)return;const version=generation;try{const c=await decodeFile(file);if(version!==generation)return;const frame=c.getContext('2d').getImageData(0,0,c.width,c.height);const result=qrResult(frame);await processFrame(frame,result,null);}catch(e){report('cameraState',e.message,true);}finally{$('photo').value='';}});
function videoFrame(){const video=$('cameraVideo');if(video.readyState<2||!video.videoWidth)return null;const c=document.createElement('canvas'),scale=Math.min(1,1440/Math.max(video.videoWidth,video.videoHeight));c.width=Math.round(video.videoWidth*scale);c.height=Math.round(video.videoHeight*scale);const x=c.getContext('2d',{willReadFrequently:true});x.drawImage(video,0,0,c.width,c.height);return x.getImageData(0,0,c.width,c.height);}
function stopCamera(){cameraEpoch++;clearTimeout(cameraTimer);cameraTimer=null;if(stream)stream.getTracks().forEach(t=>t.stop());stream=null;const video=$('cameraVideo');video.srcObject=null;video.hidden=true;buttons();}
async function liveLoop(epoch){
 if(epoch!==cameraEpoch||!stream)return;
 if(!scanBusy){try{const frame=videoFrame();if(frame){const result=qrResult(frame),code=qrCode(result);if(code){const points=autoCorners(frame,result);if(points){scanBusy=true;buttons();stopCamera();try{const opened=await processFrame(frame,result,points);report('cameraState',opened?'QR распознан, кадр зафиксирован. Проверьте ответы.':'Бланк уже проверен либо открытие отменено.');}catch(e){report('cameraState',e.message,true);}finally{scanBusy=false;buttons();}return;}report('cameraState','QR найден. Отдалите камеру, чтобы были видны все четыре угла листа.');}}}catch(e){report('cameraState','Сканирование: '+e.message,true);}}
 if(epoch===cameraEpoch&&stream)cameraTimer=setTimeout(()=>liveLoop(epoch),350);
}
$('cameraStart').addEventListener('click',async()=>{if(stream||scanBusy)return;scanBusy=true;buttons();const epoch=++cameraEpoch;try{if(!navigator.mediaDevices?.getUserMedia)throw Error('Камера недоступна. Загрузите фотографию.');const media=await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:'environment'},width:{ideal:1280},height:{ideal:960}},audio:false});if(epoch!==cameraEpoch){media.getTracks().forEach(t=>t.stop());return;}stream=media;const video=$('cameraVideo');video.srcObject=stream;video.hidden=false;await video.play();report('cameraState','Наведите камеру на весь бланк.');}catch(e){stopCamera();report('cameraState','Камера недоступна: '+e.message,true);}finally{scanBusy=false;buttons();if(stream)liveLoop(epoch);}});
$('cameraStop').addEventListener('click',stopCamera);
$('cameraCapture').addEventListener('click',async()=>{if(!stream||scanBusy)return;const frame=videoFrame();if(!frame)return;scanBusy=true;stopCamera();try{await processFrame(frame,qrResult(frame),null);}catch(e){report('cameraState',e.message,true);}finally{scanBusy=false;buttons();}});
window.addEventListener('pagehide',stopCamera);document.addEventListener('visibilitychange',()=>{if(document.hidden)stopCamera();});

function homography(points){const [p0,p1,p2,p3]=points;const dx1=p1[0]-p2[0],dx2=p3[0]-p2[0],dx3=p0[0]-p1[0]+p2[0]-p3[0],dy1=p1[1]-p2[1],dy2=p3[1]-p2[1],dy3=p0[1]-p1[1]+p2[1]-p3[1],det=dx1*dy2-dx2*dy1;if(Math.abs(det)<1)throw Error('Углы выбраны неверно.');const g=(dx3*dy2-dx2*dy3)/det,h=(dx1*dy3-dx3*dy1)/det,a=p1[0]-p0[0]+g*p1[0],b=p3[0]-p0[0]+h*p3[0],c=p0[0],d=p1[1]-p0[1]+g*p1[1],e=p3[1]-p0[1]+h*p3[1],f=p0[1];return(x,y)=>{const u=(x-28)/664,v=(y-28)/944,z=g*u+h*v+1;return [(a*u+b*v+c)/z,(d*u+e*v+f)/z];};}
function darkness(x,y,map){let sum=0,count=0;for(let dx=-4;dx<=4;dx+=2)for(let dy=-4;dy<=4;dy+=2){if(dx*dx+dy*dy>20)continue;const [xx,yy]=map(x+dx,y+dy).map(Math.round);if(xx<0||yy<0||xx>=image.width||yy>=image.height)continue;const i=(yy*image.width+xx)*4;sum+=(image.data[i]+image.data[i+1]+image.data[i+2])/765;count++;}return count?1-sum/count:0;}
function autoCorners(frame,qr){return typeof PaperScan==='object'?PaperScan.findCorners(frame,qr,homography):null;}
function scan(){const area=corners.reduce((s,p,i)=>s+p[0]*corners[(i+1)%4][1]-p[1]*corners[(i+1)%4][0],0);if(area<image.width*image.height*.12)throw Error('Выберите четыре угла в указанном порядке; бланк должен занимать большую часть фото.');const map=homography(corners);let uncertain=0;rows.forEach((r,i)=>{if(!r.inputs.length)return;let marked=0,ambiguous=false;const values=r.inputs.map((input,j)=>{const [x,y]=center(i,j);const ink=darkness(x,y,map),bg=darkness(x+22,y,map);return ink-bg;});values.forEach((v,j)=>{r.inputs[j].checked=v>=.36;marked+=Number(r.inputs[j].checked);if(v>=.15&&v<.36)ambiguous=true;});if(current.questions[i].type!=='multiple'&&marked>1)ambiguous=true;r.row.classList.toggle('uncertain',ambiguous);if(ambiguous)uncertain++;});$('confirmed').checked=false;updateSave();report('scanState','Предварительно распознано '+rows.filter(r=>r.inputs.length).length+' тестовых вопросов. Сомнительных строк: '+uncertain+'. Сверьте все отметки с бумагой.');}
canvas.addEventListener('click',e=>{if(!image||!current)return;if(corners.length===4){corners=[];ctx.putImageData(image,0,0);}const rect=canvas.getBoundingClientRect();corners.push([(e.clientX-rect.left)*canvas.width/rect.width,(e.clientY-rect.top)*canvas.height/rect.height]);const [x,y]=corners[corners.length-1];ctx.fillStyle='#00c86a';ctx.beginPath();ctx.arc(x,y,8,0,2*Math.PI);ctx.fill();if(corners.length<4){report('scanState',['','Нажмите на верхний правый квадрат.','Нажмите на нижний правый квадрат.','Нажмите на нижний левый квадрат.'][corners.length]);return;}try{scan();}catch(error){report('scanState',error.message+' Нажмите на фото, чтобы начать заново.',true);}});
window.PaperWorkspace={async refresh(id){const a=await request('./api/assignments/list.php?mode=paper');reset();fill(assignment,a.assignments||[],'Выберите бумажное задание',x=>[x.title,x.subject_name].filter(Boolean).join(' · '));if(id)assignment.value=String(id);buttons();},select(id){reset();assignment.value=String(id);buttons();$('paperPrepare').scrollIntoView({behavior:'smooth'});}};
init();
})();