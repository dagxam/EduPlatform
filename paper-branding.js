'use strict';
(() => {
function normalizeHexColor(value) {
  const color = String(value || '').trim().toLowerCase();
  return /^#[0-9a-f]{6}$/.test(color) ? color : '#1d68f0';
}

function mixHex(colorA, colorB, weight = 0.5) {
  const a = normalizeHexColor(colorA).slice(1);
  const b = normalizeHexColor(colorB).slice(1);
  const w = Math.max(0, Math.min(1, Number(weight)));
  const channel = index => Math.round(
    parseInt(a.slice(index, index + 2), 16) * (1 - w) +
    parseInt(b.slice(index, index + 2), 16) * w
  ).toString(16).padStart(2, '0');
  return '#' + channel(0) + channel(2) + channel(4);
}

let faviconBaseImagePromise = null;

function faviconBaseImage() {
  if (!faviconBaseImagePromise) {
    faviconBaseImagePromise = new Promise((resolve, reject) => {
      const image = new Image();
      image.onload = () => resolve(image);
      image.onerror = reject;
      image.src = './favicon.png?v=3';
    });
  }
  return faviconBaseImagePromise;
}

function rgbToHsl(r, g, b) {
  r /= 255; g /= 255; b /= 255;
  const max = Math.max(r, g, b);
  const min = Math.min(r, g, b);
  let h = 0;
  let s = 0;
  const l = (max + min) / 2;
  const d = max - min;

  if (d !== 0) {
    s = d / (1 - Math.abs(2 * l - 1));
    switch (max) {
      case r: h = ((g - b) / d) % 6; break;
      case g: h = (b - r) / d + 2; break;
      default: h = (r - g) / d + 4;
    }
    h *= 60;
    if (h < 0) h += 360;
  }
  return [h, s, l];
}

function hslToRgb(h, s, l) {
  const c = (1 - Math.abs(2 * l - 1)) * s;
  const x = c * (1 - Math.abs(((h / 60) % 2) - 1));
  const m = l - c / 2;
  let r = 0, g = 0, b = 0;

  if (h < 60) [r, g, b] = [c, x, 0];
  else if (h < 120) [r, g, b] = [x, c, 0];
  else if (h < 180) [r, g, b] = [0, c, x];
  else if (h < 240) [r, g, b] = [0, x, c];
  else if (h < 300) [r, g, b] = [x, 0, c];
  else [r, g, b] = [c, 0, x];

  return [
    Math.round((r + m) * 255),
    Math.round((g + m) * 255),
    Math.round((b + m) * 255)
  ];
}

function themeHsl(color) {
  const hex = normalizeHexColor(color).slice(1);
  return rgbToHsl(
    parseInt(hex.slice(0, 2), 16),
    parseInt(hex.slice(2, 4), 16),
    parseInt(hex.slice(4, 6), 16)
  );
}

async function applySchoolFavicon(color) {
  const favicon = document.getElementById('siteFavicon');
  if (!favicon) return;

  favicon.setAttribute('href', './favicon.png?v=3');
  favicon.setAttribute('type', 'image/png');

  try {
    const image = await faviconBaseImage();
    const canvas = document.createElement('canvas');
    canvas.width = 128;
    canvas.height = 128;
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    if (!ctx) return;

    ctx.clearRect(0, 0, 128, 128);
    ctx.drawImage(image, 0, 0, 128, 128);

    const frame = ctx.getImageData(0, 0, 128, 128);
    const pixels = frame.data;
    const [targetHue, targetSat, targetLight] = themeHsl(color);

    for (let i = 0; i < pixels.length; i += 4) {
      const alpha = pixels[i + 3];
      if (alpha < 8) continue;

      const r = pixels[i];
      const g = pixels[i + 1];
      const b = pixels[i + 2];

      // Белая U остаётся белой. Перекрашивается только цветная подложка.
      if (r > 220 && g > 220 && b > 220) {
        pixels[i] = 255;
        pixels[i + 1] = 255;
        pixels[i + 2] = 255;
        continue;
      }

      const [, sourceSat, sourceLight] = rgbToHsl(r, g, b);
      const light = Math.max(0.12, Math.min(0.88, targetLight + (sourceLight - 0.52) * 1.18));
      const sat = Math.max(0.25, Math.min(1, targetSat * 0.92 + sourceSat * 0.08));
      const [nr, ng, nb] = hslToRgb(targetHue, sat, light);

      pixels[i] = nr;
      pixels[i + 1] = ng;
      pixels[i + 2] = nb;
    }

    ctx.putImageData(frame, 0, 0);
    favicon.setAttribute('href', canvas.toDataURL('image/png'));
  } catch {
    // При ошибке остаётся исходный favicon.png.
  }
}


function luminance(color) {
 const rgb=normalizeHexColor(color).slice(1).match(/../g).map(x=>parseInt(x,16)/255).map(x=>x<=.04045?x/12.92:((x+.055)/1.055)**2.4);
 return rgb[0]*.2126+rgb[1]*.7152+rgb[2]*.0722;
}
function readable(color) {
 let c=normalizeHexColor(color);
 for(let i=0;i<40&&1.05/(luminance(c)+.05)<4.5;i++)c=mixHex(c,'#000000',.07);
 return c;
}
function palette(value) {
 const brand=normalizeHexColor(value),ink=readable(brand);
 return {'--paper-brand':brand,'--paper-blue':ink,'--paper-hover':mixHex(ink,'#000000',.12),'--paper-tint':mixHex(brand,'#ffffff',.90),'--paper-tint-hover':mixHex(brand,'#ffffff',.82),'--paper-subtle':mixHex(brand,'#ffffff',.96),'--paper-border':mixHex(brand,'#ffffff',.74),'--paper-focus':mixHex(ink,'#ffffff',.44),'--paper-hero-start':mixHex(ink,'#000000',.08),'--paper-hero-end':readable(mixHex(ink,'#ffffff',.13)),'--paper-shadow':brand+'26','--paper-focus-shadow':brand+'18'};
}
if(typeof module==='object'&&module.exports){module.exports={palette,luminance};return;}
let loading=false,lastColor=null,lastSchool=null,initialized=false;
const sidebar=document.getElementById('paperMainSidebar'),toggle=document.getElementById('paperMenuToggle'),scrim=document.getElementById('paperMenuScrim');
function setMenu(open){if(!sidebar)return;sidebar.classList.toggle('is-open',open);toggle?.setAttribute('aria-expanded',String(open));if(scrim)scrim.hidden=!open;}
toggle?.addEventListener('click',()=>setMenu(!sidebar.classList.contains('is-open')));
scrim?.addEventListener('click',()=>setMenu(false));
document.addEventListener('keydown',e=>{if(e.key==='Escape')setMenu(false);});
function applyUser(user){
 if(!sidebar)return;
 const admin=user.role==='admin',platform=admin&&Number(user.is_platform_admin)===1;
 const role=platform?'Администратор UROVIA':admin?(user.can_teach?'Администратор школы · Учитель':'Администратор школы'):'Учитель';
 document.getElementById('paperAccountRole').textContent=role;
 for(const a of sidebar.querySelectorAll('[data-paper-role]'))a.hidden=a.dataset.paperRole==='platform'?!platform:a.dataset.paperRole==='admin'?!admin:false;
}
async function schoolDetails(id){
 if(!sidebar||!id)return;
 try{const r=await fetch('./api/schools/list.php',{credentials:'same-origin',cache:'no-store'}),d=await r.json();
 if(!r.ok||!d.ok||String(d.active_school_id)!==id||lastSchool!==id)return;
 const school=(d.schools||[]).find(s=>String(s.id)===id);if(school)document.getElementById('paperSchoolName').textContent=school.name;
 }catch{}
}
async function refresh(){
 if(loading)return;loading=true;
 try{
  const response=await fetch('./api/auth/me.php',{credentials:'same-origin',cache:'no-store'});
  const data=await response.json();
  if(!response.ok||!data.ok)return;if(!data.authenticated){if(sidebar)location.replace('./login.html');return;}if(!['admin','teacher'].includes(data.user?.role)){if(sidebar)location.replace('./index.html');return;}
  const school=data.active_school_id==null?null:String(data.active_school_id);
  if(initialized&&school!==lastSchool){location.reload();return;}
  initialized=true;lastSchool=school;applyUser(data.user);
  let branding=data.branding;
  try{const r=await fetch('./api/school/branding/get.php',{credentials:'same-origin',cache:'no-store'}),d=await r.json();if(r.ok&&d.ok&&String(d.school_id??'')===String(school??''))branding=d.branding;}catch{}
  const color=normalizeHexColor(branding?.theme_color);
  schoolDetails(school);
  for(const [key,value] of Object.entries(palette(color)))document.documentElement.style.setProperty(key,value);
  document.querySelector('meta[name="theme-color"]')?.setAttribute('content',color);
  if(color!==lastColor){lastColor=color;applySchoolFavicon(color);}
 }catch{/* Preserve last valid theme on temporary network failure. */}
 finally{loading=false;}
}
refresh();window.addEventListener('focus',refresh);window.addEventListener('pageshow',refresh);
document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh();});
})();
