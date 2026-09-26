const states={
  loading:document.getElementById('loadingState'),
  login:document.getElementById('loginState'),
  studentCode:document.getElementById('studentCodeState'),
  studentSelect:document.getElementById('studentSelectState'),
  studentPin:document.getElementById('studentPinState'),
  setup:document.getElementById('setupState'),
  error:document.getElementById('serverErrorState')
};

let studentSession={code:'',className:'',students:[],selected:null};

function normalizeHexColor(value){
  const color=String(value||'').trim().toLowerCase();
  return /^#[0-9a-f]{6}$/.test(color)?color:'#1d68f0';
}
function mixHex(colorA,colorB,weight=.5){
  const a=normalizeHexColor(colorA).slice(1),b=normalizeHexColor(colorB).slice(1);
  const w=Math.max(0,Math.min(1,Number(weight)));
  const ch=i=>Math.round(parseInt(a.slice(i,i+2),16)*(1-w)+parseInt(b.slice(i,i+2),16)*w).toString(16).padStart(2,'0');
  return '#'+ch(0)+ch(2)+ch(4);
}
function defaultFaviconData(color='#1d68f0'){
  const safe=normalizeHexColor(color);
  const svg=`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" rx="16" fill="${safe}"/><text x="32" y="43" text-anchor="middle" font-family="Arial,sans-serif" font-size="34" font-weight="800" fill="white">U</text></svg>`;
  return 'data:image/svg+xml;charset=utf-8,'+encodeURIComponent(svg);
}
function applyBranding(branding=null){
  const color=normalizeHexColor(branding?.theme_color||'#1d68f0');
  document.documentElement.style.setProperty('--brand',color);
  document.documentElement.style.setProperty('--brand-hover',mixHex(color,'#000000',.12));
  document.documentElement.style.setProperty('--brand-soft',mixHex(color,'#ffffff',.90));
  document.getElementById('themeColorMeta')?.setAttribute('content',color);
  document.getElementById('dynamicFavicon')?.setAttribute('href',branding?.favicon_data||defaultFaviconData(color));
}
applyBranding();

function showState(name){
  Object.values(states).forEach(el=>el?.classList.add('hidden'));
  states[name]?.classList.remove('hidden');
}
function showError(el,message){el.textContent=message;el.classList.remove('hidden')}
function clearError(el){el?.classList.add('hidden');if(el)el.textContent=''}

async function api(url,options={}){
  const response=await fetch(url,{
    credentials:'same-origin',
    headers:{'Content-Type':'application/json',...(options.headers||{})},
    ...options
  });
  let data;
  try{data=await response.json()}catch{throw new Error('Сервер вернул некорректный ответ.')}
  if(!response.ok||data.ok===false)throw new Error(data.error||'Не удалось выполнить запрос.');
  return data;
}

async function initialize(){
  showState('loading');
  try{
    const me=await api('./api/auth/me.php');
    if(me.authenticated){window.location.replace('./index.html');return}
    const setup=await api('./api/setup/status.php');
    showState(setup.needs_setup?'setup':'login');
  }catch(error){
    document.getElementById('serverErrorText').textContent=error.message+' Проверьте поддержку PHP и SQLite на хостинге.';
    showState('error');
  }
}

document.getElementById('studentModeBtn')?.addEventListener('click',()=>showState('studentCode'));
document.getElementById('staffModeBtn')?.addEventListener('click',()=>showState('login'));
document.getElementById('backToStaffLogin')?.addEventListener('click',()=>showState('login'));
document.getElementById('backToCode')?.addEventListener('click',()=>showState('studentCode'));
document.getElementById('backToStudentList')?.addEventListener('click',()=>showState('studentSelect'));

document.getElementById('studentCodeForm')?.addEventListener('submit',async event=>{
  event.preventDefault();
  const form=event.currentTarget;
  const error=document.getElementById('studentCodeError');
  const button=form.querySelector('button[type="submit"]');
  clearError(error);button.disabled=true;button.textContent='Ищем класс...';

  try{
    const code=String(new FormData(form).get('code')||'').trim().toUpperCase().replace(/\s+/g,'');
    const data=await api('./api/student/class.php',{method:'POST',body:JSON.stringify({code})});
    studentSession={code,className:data.class.name,students:data.students||[],selected:null};
    applyBranding(data.branding);
    document.getElementById('studentClassTitle').textContent=data.class.name+' — выберите себя';
    document.getElementById('studentNameSearch').value='';
    renderStudents();
    showState('studentSelect');
  }catch(e){
    showError(error,e.message);
  }finally{
    button.disabled=false;button.textContent='Продолжить';
  }
});

function renderStudents(){
  const list=document.getElementById('studentList');
  const query=(document.getElementById('studentNameSearch')?.value||'').trim().toLowerCase();
  const students=studentSession.students.filter(student=>
    (student.last_name+' '+student.first_name).toLowerCase().includes(query)
  );

  if(!students.length){
    list.innerHTML='<div class="empty-picker">Ученик не найден.</div>';
    return;
  }

  list.innerHTML=students.map(student=>`
    <button class="student-option" type="button" data-student-id="${student.id}">
      <span class="student-option-avatar">${escapeHtml((student.first_name||'?').charAt(0))}</span>
      <span><b>${escapeHtml(student.last_name)} ${escapeHtml(student.first_name)}</b><small>${Number(student.activated)?'PIN уже создан':'Первый вход'}</small></span>
      <span class="student-option-arrow">›</span>
    </button>
  `).join('');

  list.querySelectorAll('[data-student-id]').forEach(button=>button.addEventListener('click',()=>{
    const id=Number(button.dataset.studentId);
    const student=studentSession.students.find(item=>Number(item.id)===id);
    if(!student)return;
    studentSession.selected=student;
    document.getElementById('selectedStudentName').textContent=student.last_name+' '+student.first_name;
    document.getElementById('studentPinHint').textContent=Number(student.activated)
      ? 'Введите PIN, который вы создали при первом входе.'
      : 'Первый вход: придумайте PIN из 4–6 цифр. Он понадобится при следующих входах.';
    document.getElementById('studentPin').value='';
    clearError(document.getElementById('studentPinError'));
    showState('studentPin');
    setTimeout(()=>document.getElementById('studentPin')?.focus(),50);
  }));
}

function escapeHtml(value){
  return String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[char]);
}

document.getElementById('studentNameSearch')?.addEventListener('input',renderStudents);

document.getElementById('studentPinForm')?.addEventListener('submit',async event=>{
  event.preventDefault();
  const form=event.currentTarget;
  const error=document.getElementById('studentPinError');
  const button=form.querySelector('button[type="submit"]');
  clearError(error);

  if(!studentSession.selected){showState('studentSelect');return}

  button.disabled=true;button.textContent='Входим...';
  try{
    const pin=String(new FormData(form).get('pin')||'').trim();
    await api('./api/student/login.php',{
      method:'POST',
      body:JSON.stringify({
        code:studentSession.code,
        student_id:studentSession.selected.id,
        pin
      })
    });
    window.location.replace('./index.html');
  }catch(e){
    showError(error,e.message);
  }finally{
    button.disabled=false;button.textContent='Войти';
  }
});

document.getElementById('setupForm').addEventListener('submit',async event=>{
  event.preventDefault();
  const form=event.currentTarget,error=document.getElementById('setupError'),button=form.querySelector('button[type="submit"]');
  error.classList.add('hidden');button.disabled=true;button.textContent='Создаём...';
  try{
    await api('./api/setup/create-admin.php',{method:'POST',body:JSON.stringify(Object.fromEntries(new FormData(form).entries()))});
    window.location.replace('./index.html');
  }catch(e){showError(error,e.message);button.disabled=false;button.textContent='Создать администратора'}
});

document.getElementById('loginForm').addEventListener('submit',async event=>{
  event.preventDefault();
  const form=event.currentTarget,error=document.getElementById('loginError'),button=form.querySelector('button[type="submit"]');
  error.classList.add('hidden');button.disabled=true;button.textContent='Входим...';
  try{
    await api('./api/auth/login.php',{method:'POST',body:JSON.stringify(Object.fromEntries(new FormData(form).entries()))});
    window.location.replace('./index.html');
  }catch(e){showError(error,e.message);button.disabled=false;button.textContent='Войти'}
});

document.querySelectorAll('[data-password-target]').forEach(button=>button.addEventListener('click',()=>{
  const input=document.getElementById(button.dataset.passwordTarget),visible=input.type==='text';
  input.type=visible?'password':'text';button.textContent=visible?'Показать':'Скрыть';
}));

document.getElementById('retryBtn').addEventListener('click',initialize);
initialize();
