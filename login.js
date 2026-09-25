const states={loading:document.getElementById('loadingState'),login:document.getElementById('loginState'),setup:document.getElementById('setupState'),error:document.getElementById('serverErrorState')};

function showState(name){Object.values(states).forEach(el=>el.classList.add('hidden'));states[name].classList.remove('hidden')}
function showError(el,message){el.textContent=message;el.classList.remove('hidden')}

async function api(url,options={}){
  const response=await fetch(url,{credentials:'same-origin',headers:{'Content-Type':'application/json',...(options.headers||{})},...options});
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