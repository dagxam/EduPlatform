const teacherNav = document.querySelector('.teacher-nav');
const studentNav = document.querySelector('.student-nav');
const sidebar = document.getElementById('sidebar');
const menuBtn = document.getElementById('menuBtn');
const sidebarScrim = document.getElementById('sidebarScrim');
const pageTitle = document.getElementById('pageTitle');
const eyebrow = document.getElementById('eyebrow');
const sidebarName = document.getElementById('sidebarName');
const sidebarRole = document.getElementById('sidebarRole');
const sidebarAvatar = document.getElementById('sidebarAvatar');
const taskModal = document.getElementById('taskModal');
const quizModal = document.getElementById('quizModal');
const questionPreviewModal = document.getElementById('questionPreviewModal');
const libraryPreviewModal = document.getElementById('libraryPreviewModal');
const resultEditModal = document.getElementById('resultEditModal');
const attemptReviewModal = document.getElementById('attemptReviewModal');
const duplicateAssignmentModal = document.getElementById('duplicateAssignmentModal');
const assignToClassModal = document.getElementById('assignToClassModal');
const shareSubjectModal = document.getElementById('shareSubjectModal');
const schoolModal = document.getElementById('schoolModal');
const subjectModal = document.getElementById('subjectModal');
const teacherModal = document.getElementById('teacherModal');
const temporaryPasswordModal = document.getElementById('temporaryPasswordModal');
const schoolAdminModal = document.getElementById('schoolAdminModal');
const teacherAssignmentsModal = document.getElementById('teacherAssignmentsModal');
const classModal = document.getElementById('classModal');
const classDetailsModal = document.getElementById('classDetailsModal');
const classEditModal = document.getElementById('classEditModal');
const studentEditModal = document.getElementById('studentEditModal');
let currentUser = null;
let activeSchoolName = '';
let currentBranding = { theme_color: '#1d68f0' };

let appDialogResolver = null;

function ensureAppDialog() {
  let backdrop = document.getElementById('appDialogBackdrop');
  if (backdrop) return backdrop;

  backdrop = document.createElement('div');
  backdrop.id = 'appDialogBackdrop';
  backdrop.className = 'app-dialog-backdrop hidden';
  backdrop.innerHTML = `
    <div class="app-dialog" role="dialog" aria-modal="true" aria-labelledby="appDialogTitle">
      <button class="app-dialog-close" type="button" aria-label="Закрыть">×</button>
      <div class="app-dialog-icon" aria-hidden="true">i</div>
      <div class="app-dialog-copy">
        <span class="section-kicker">UROVIA</span>
        <h3 id="appDialogTitle">Сообщение</h3>
        <p id="appDialogMessage"></p>
      </div>
      <div class="app-dialog-actions">
        <button class="secondary-btn app-dialog-cancel hidden" type="button">Отмена</button>
        <button class="primary-btn app-dialog-ok" type="button">Понятно</button>
      </div>
    </div>`;
  document.body.appendChild(backdrop);

  const finish = value => {
    backdrop.classList.add('hidden');
    document.body.classList.remove('dialog-open');
    const resolve = appDialogResolver;
    appDialogResolver = null;
    if (resolve) resolve(value);
  };

  backdrop.querySelector('.app-dialog-ok').addEventListener('click', () => finish(true));
  backdrop.querySelector('.app-dialog-cancel').addEventListener('click', () => finish(false));
  backdrop.querySelector('.app-dialog-close').addEventListener('click', () => finish(false));
  backdrop.addEventListener('click', event => {
    if (event.target === backdrop) finish(false);
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && !backdrop.classList.contains('hidden')) finish(false);
  });

  return backdrop;
}

function openAppDialog(message, options = {}) {
  const backdrop = ensureAppDialog();
  const dialog = backdrop.querySelector('.app-dialog');
  const title = backdrop.querySelector('#appDialogTitle');
  const body = backdrop.querySelector('#appDialogMessage');
  const icon = backdrop.querySelector('.app-dialog-icon');
  const cancel = backdrop.querySelector('.app-dialog-cancel');
  const ok = backdrop.querySelector('.app-dialog-ok');

  const tone = options.tone || 'info';
  dialog.dataset.tone = tone;
  title.textContent = options.title || (options.confirm ? 'Подтвердите действие' : 'UROVIA');
  body.textContent = String(message || '');
  icon.textContent = tone === 'danger' ? '!' : tone === 'success' ? '✓' : options.confirm ? '?' : 'i';
  cancel.classList.toggle('hidden', !options.confirm);
  cancel.textContent = options.cancelText || 'Отмена';
  ok.textContent = options.okText || (options.confirm ? 'Подтвердить' : 'Понятно');
  ok.classList.toggle('app-dialog-danger', tone === 'danger');

  backdrop.classList.remove('hidden');
  document.body.classList.add('dialog-open');
  requestAnimationFrame(() => ok.focus());

  return new Promise(resolve => {
    if (appDialogResolver) appDialogResolver(false);
    appDialogResolver = resolve;
  });
}

function appAlert(message, options = {}) {
  const text = String(message || '');
  const danger = /ошиб|не удалось|недоступ|невозможно|заблок|не найден/i.test(text);
  return openAppDialog(text, {
    title: options.title || (danger ? 'Что-то пошло не так' : 'UROVIA'),
    tone: options.tone || (danger ? 'danger' : 'info'),
    okText: options.okText || 'Понятно'
  });
}

function appConfirm(message, options = {}) {
  return openAppDialog(message, {
    ...options,
    confirm: true,
    title: options.title || 'Подтвердите действие',
    tone: options.tone || 'info',
    okText: options.okText || 'Подтвердить',
    cancelText: options.cancelText || 'Отмена'
  });
}

window.alert = message => { void appAlert(message); };
window.appAlert = appAlert;
window.appConfirm = appConfirm;

const titles = {
  'teacher-dashboard': ['Кабинет учителя', 'Добрый день!'],
  subjects: ['Учебные направления', 'Предметы'],
  assignments: ['Управление обучением', 'Задания'],
  'uvoria-library': ['Обмен опытом', 'Библиотека UROVIA'],
  'staff-profile': ['Учётная запись', 'Профиль сотрудника'],
  'activity-history': ['Контроль изменений', 'История действий'],
  'system-backups': ['Защита данных', 'Резервные копии'],
  'incoming-materials': ['Обмен между школами', 'Полученные материалы'],
  classes: ['Ученики и группы', 'Классы'],
  'school-management': ['Администрирование', 'Управление школой'],
  journal: ['Успеваемость класса', 'Журнал'],
  results: ['Аналитика успеваемости', 'Результаты'],
  'student-dashboard': ['Кабинет ученика', 'Мои занятия'],
  'student-tasks': ['Учёба', 'Мои задания'],
  'student-results': ['Успеваемость', 'Мои оценки']
};

function setSidebarOpen(open) {
  const next = Boolean(open);
  sidebar?.classList.toggle('open', next);
  document.body.classList.toggle('sidebar-open', next);
  menuBtn?.setAttribute('aria-expanded', next ? 'true' : 'false');
}

document.addEventListener('pointerdown', event => {
  if (!sidebar?.classList.contains('open')) return;
  if (sidebar.contains(event.target) || menuBtn?.contains(event.target)) return;
  setSidebarOpen(false);
});
document.addEventListener('keydown', event => {
  if (event.key === 'Escape' && sidebar?.classList.contains('open')) setSidebarOpen(false);
});

function showView(id) {
  document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));
  const view = document.getElementById(id);
  if (view) view.classList.add('active');

  document.querySelectorAll('.nav-item').forEach(i => i.classList.toggle('active', i.dataset.view === id));
  const [small, title] = titles[id] || ['', 'UROVIA'];
  if (activeSchoolName && id === 'school-management') {
    eyebrow.textContent = activeSchoolName;
    pageTitle.textContent = 'Управление школой';
  } else if (activeSchoolName && id === 'teacher-dashboard' && currentUser?.role === 'teacher') {
    eyebrow.textContent = activeSchoolName;
    pageTitle.textContent = 'Кабинет учителя';
  } else {
    eyebrow.textContent = small;
    pageTitle.textContent = title;
  }
  setSidebarOpen(false);
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

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

function applySchoolBranding(branding = null) {
  const color = normalizeHexColor(branding?.theme_color || '#1d68f0');
  currentBranding = { theme_color: color };

  const root = document.documentElement;
  root.style.setProperty('--brand', color);
  root.style.setProperty('--brand-hover', mixHex(color, '#000000', 0.12));
  root.style.setProperty('--brand-soft', mixHex(color, '#ffffff', 0.90));
  root.style.setProperty('--brand-soft-2', mixHex(color, '#ffffff', 0.82));
  root.style.setProperty('--brand-shadow', mixHex(color, '#ffffff', 0.55));

  applySchoolFavicon(color);
  const themeMeta = document.getElementById('themeColorMeta');
  if (themeMeta) themeMeta.setAttribute('content', color);

  const storedColor = document.getElementById('schoolThemeColor');
  if (storedColor) storedColor.value = color;

  document.querySelectorAll('[data-theme-color]').forEach(button => {
    button.classList.toggle('active', normalizeHexColor(button.dataset.themeColor) === color);
  });
}

async function loadSchoolBranding() {
  try {
    const response = await fetch('./api/school/branding/get.php', {
      credentials: 'same-origin',
      cache: 'no-store'
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось загрузить оформление школы.');
    applySchoolBranding(data.branding);
    return data;
  } catch (error) {
    applySchoolBranding(null);
    throw error;
  }
}

const roleLabels = {
  admin: 'Администратор',
  teacher: 'Учитель',
  student: 'Ученик'
};

function revealAuthenticatedApp() {
  document.documentElement.classList.remove('auth-pending');
}

async function loadSession() {
  try {
    const response = await fetch('./api/auth/me.php', { credentials: 'same-origin', cache: 'no-store' });
    const data = await response.json();
    if (!response.ok || !data.authenticated || !data.user) {
      window.location.replace('./login.html');
      return null;
    }
    applySchoolBranding(data.branding);
    return data.user;
  } catch {
    window.location.replace('./login.html');
    return null;
  }
}

function applyUser(user) {
  currentUser = user;
  const student = user.role === 'student';
  const admin = user.role === 'admin';
  const platformAdmin = admin && Number(user.is_platform_admin) === 1;
  const teacher = !student && (user.role === 'teacher' || Boolean(user.can_teach));

  teacherNav.classList.toggle('hidden', student);
  studentNav.classList.toggle('hidden', !student);

  document.querySelectorAll('.teacher-only').forEach(el => el.classList.toggle('hidden', !teacher));
  document.querySelectorAll('.admin-only').forEach(el => el.classList.toggle('hidden', !admin));
  document.querySelectorAll('.platform-admin-only').forEach(el => el.classList.toggle('hidden', !platformAdmin));
  document.querySelectorAll('.school-staff-only').forEach(el => el.classList.toggle('hidden', student || platformAdmin));

  const fullName = [user.first_name, user.last_name].filter(Boolean).join(' ');
  sidebarName.textContent = fullName || 'Пользователь';
  sidebarRole.textContent = platformAdmin
    ? 'Администратор UROVIA'
    : (admin && teacher
      ? 'Администратор школы · Учитель'
      : (admin ? 'Администратор школы' : (roleLabels[user.role] || user.role)));
  sidebarAvatar.textContent = ((user.first_name || 'П').charAt(0) + (user.last_name || '').charAt(0)).toUpperCase();
  sidebarAvatar.style.backgroundImage = user.avatar_name
    ? `url("./api/profile/avatar.php?user_id=${Number(user.id)}&v=${encodeURIComponent(user.avatar_name)}")`
    : '';
  sidebarAvatar.classList.toggle('has-photo', Boolean(user.avatar_name));
  const roleBadge = document.getElementById('accountRoleBadge');
  if (roleBadge) roleBadge.textContent = sidebarRole.textContent;

  if (admin) eyebrow.textContent = platformAdmin ? 'Администратор UROVIA' : 'Администратор школы';

  showView(student ? 'student-dashboard' : 'teacher-dashboard');

  if (!student && Number(user.must_change_password) === 1 && temporaryPasswordModal) {
    setTimeout(() => openModal(temporaryPasswordModal), 0);
  }
}

async function logout() {
  try {
    await fetch('./api/auth/logout.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' }
    });
  } finally {
    window.location.replace('./login.html');
  }
}

document.getElementById('logoutBtn')?.addEventListener('click', logout);

let schoolsCache = [];

async function loadSchools() {
  const selector = document.getElementById('schoolSelector');
  const fixedSchoolName = document.getElementById('fixedSchoolName');

  try {
    const response = await fetch('./api/schools/list.php', { credentials: 'same-origin', cache: 'no-store' });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось загрузить школы.');

    schoolsCache = data.schools || [];
    const platformAdmin = Boolean(data.is_platform_admin);
    const activeId = Number(data.active_school_id || 0);
    const activeSchool = schoolsCache.find(school => Number(school.id) === activeId) || null;
    activeSchoolName = activeSchool?.name || '';
    if (activeSchool?.theme_color) {
      applySchoolBranding({ theme_color: activeSchool.theme_color });
    } else if (!activeSchool) {
      applySchoolBranding(null);
    }

    if (platformAdmin && selector) {
      selector.innerHTML = '<option value="0">Выберите школу</option>' + schoolsCache.map(school => {
        const city = school.city ? ' · ' + school.city : '';
        return `<option value="${school.id}">${escapeHtml(school.name + city)}</option>`;
      }).join('');
      selector.value = activeId > 0 ? String(activeId) : '0';
    }

    if (!platformAdmin && fixedSchoolName) {
      fixedSchoolName.textContent = activeSchoolName || 'Школа не назначена';
    }

    return data;
  } catch (error) {
    activeSchoolName = '';
    if (selector && Number(currentUser?.is_platform_admin) === 1) {
      selector.innerHTML = '<option value="0">Школы недоступны</option>';
    }
    if (fixedSchoolName && Number(currentUser?.is_platform_admin) !== 1) {
      fixedSchoolName.textContent = 'Школа недоступна';
    }
    throw error;
  }
}

async function selectSchool(schoolId) {
  const selector = document.getElementById('schoolSelector');
  const selectedId = Number(schoolId);
  if (selector) selector.disabled = true;

  try {
    const response = await fetch('./api/schools/select.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ school_id: selectedId })
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось переключить школу.');

    classesCache = [];
    subjectsCache = [];
    assignmentsCache = [];
    incomingMaterialsCache = [];
    teacherOptionsCache = [];
    schoolTeachersCache = [];
    schoolAdminsCache = [];
    currentClass = null;
    if (classDetailsModal) closeModal(classDetailsModal);

    if (selectedId === 0) {
      activeSchoolName = '';
      applySchoolBranding(null);
      showView('teacher-dashboard');
      return;
    }

    const selectedSchool = schoolsCache.find(school => Number(school.id) === selectedId);
    activeSchoolName = selectedSchool?.name || '';
    applySchoolBranding({ theme_color: selectedSchool?.theme_color || '#1d68f0' });

    await Promise.all([loadClasses(), loadSubjects(), loadAssignments(), loadSchoolBranding(), loadTeacherDashboard()]);
    await loadSchoolManagement();
    showView('school-management');
  } catch (error) {
    alert(error.message);
    await loadSchools();
  } finally {
    if (selector) selector.disabled = false;
  }
}

document.getElementById('schoolSelector')?.addEventListener('change', event => selectSchool(event.target.value));
document.getElementById('createSchoolBtn')?.addEventListener('click', () => openModal(schoolModal));

document.getElementById('schoolForm')?.addEventListener('submit', async event => {
  event.preventDefault();
  const form = event.currentTarget;
  const error = document.getElementById('schoolFormError');
  const button = form.querySelector('button[type="submit"]');
  error.classList.add('hidden');
  button.disabled = true;
  button.textContent = 'Создаём...';

  try {
    const payload = Object.fromEntries(new FormData(form).entries());
    const response = await fetch('./api/schools/create.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось создать школу.');

    form.reset();
    closeModal(schoolModal);
    classesCache = [];
    subjectsCache = [];
    assignmentsCache = [];
    teacherOptionsCache = [];
    await loadSchools();
    await Promise.all([loadClasses(), loadSubjects(), loadAssignments(), loadSchoolBranding(), loadTeacherDashboard()]);
    await loadSchoolManagement();
    showView('school-management');
  } catch (e) {
    error.textContent = e.message;
    error.classList.remove('hidden');
  } finally {
    button.disabled = false;
    button.textContent = 'Создать школу и администратора';
  }
});

let classesCache = [];
let currentClass = null;
let currentClassStudentsCache = [];
let editingStudentId = null;
let studentImportPreviewRows = [];

async function loadClasses() {
  const grid = document.getElementById('classesGrid');
  if (!grid) return;
  grid.innerHTML = '<article class="panel"><p>Загрузка классов...</p></article>';

  try {
    const response = await fetch('./api/classes/list.php', { credentials: 'same-origin', cache: 'no-store' });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось загрузить классы.');
    classesCache = data.classes || [];

    if (!classesCache.length) {
      grid.innerHTML = currentUser?.role === 'admin'
        ? '<article class="panel empty-class-card"><h3>Классов пока нет</h3><p>Создайте первый класс и загрузите список учеников из Word.</p><button class="primary-btn" id="emptyCreateClassBtn">＋ Создать класс</button></article>'
        : '<article class="panel empty-class-card"><h3>Классы не назначены</h3><p>Обратитесь к администратору школы.</p></article>';
      document.getElementById('emptyCreateClassBtn')?.addEventListener('click', () => openModal(classModal));
      return;
    }

    grid.innerHTML = classesCache.map(item => `
      <article class="panel class-detail real-class-card">
        <div>
          <span class="class-badge">${escapeHtml(item.name)}</span>
          <h3>${escapeHtml(item.name)}</h3>
          <p>${Number(item.students_count)} учеников${item.academic_year ? ' · ' + escapeHtml(item.academic_year) : ''}</p>
        </div>
        <div class="class-code-mini">
          <span>Код</span>
          <strong>${escapeHtml(item.join_code || '—')}</strong>
        </div>
        <div class="class-card-actions">
          <button type="button" data-open-class="${item.id}">Открыть класс</button>
          ${currentUser?.role === 'admin' ? `<button type="button" class="secondary-btn" data-edit-class="${item.id}">✎ Редактировать</button>` : ''}
        </div>
      </article>
    `).join('');

    grid.querySelectorAll('[data-open-class]').forEach(button => button.addEventListener('click', () => {
      const item = classesCache.find(x => Number(x.id) === Number(button.dataset.openClass));
      if (item) openClassDetails(item);
    }));
    grid.querySelectorAll('[data-edit-class]').forEach(button => button.addEventListener('click', () => {
      const item = classesCache.find(x => Number(x.id) === Number(button.dataset.editClass));
      if (item) openClassEditor(item);
    }));
  } catch (error) {
    grid.innerHTML = `<article class="panel"><p>${escapeHtml(error.message)}</p></article>`;
  }
}

function openClassEditor(item) {
  if (currentUser?.role !== 'admin' || !item || !classEditModal) return;

  currentClass = item;
  document.getElementById('classEditId').value = String(item.id);
  document.getElementById('classEditName').value = item.name || '';
  document.getElementById('classEditAcademicYear').value = item.academic_year || '';
  document.getElementById('classEditTitle').textContent = 'Редактировать: ' + (item.name || 'класс');

  const studentsCount = Number(item.students_count || 0);
  const warning = document.getElementById('classDeleteWarning');
  if (warning) {
    warning.textContent = studentsCount
      ? `В классе ${studentsCount} ученик(ов). При удалении исчезнут их аккаунты, PIN, попытки, ответы, оценки и результаты. Восстановить это действие нельзя.`
      : 'Класс пустой. Будут удалены сам класс, его код, назначения учителей и связи с заданиями. Восстановить это действие нельзя.';
  }

  document.getElementById('classEditError')?.classList.add('hidden');
  document.getElementById('classEditResult')?.classList.add('hidden');
  openModal(classEditModal);
}

document.getElementById('editClassFromDetailsBtn')?.addEventListener('click', () => {
  if (!currentClass) return;
  closeModal(classDetailsModal);
  openClassEditor(currentClass);
});

document.getElementById('classEditForm')?.addEventListener('submit', async event => {
  event.preventDefault();
  if (!currentClass || currentUser?.role !== 'admin') return;

  const form = event.currentTarget;
  const button = document.getElementById('classEditSaveBtn');
  const error = document.getElementById('classEditError');
  const result = document.getElementById('classEditResult');
  error?.classList.add('hidden');
  result?.classList.add('hidden');

  const payload = Object.fromEntries(new FormData(form).entries());
  payload.class_id = Number(currentClass.id);

  button.disabled = true;
  button.textContent = 'Сохраняем...';

  try {
    const response = await fetch('./api/classes/update.php', {
      method:'POST',
      credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify(payload)
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось обновить класс.');

    currentClass = { ...currentClass, ...data.class };
    await Promise.all([
      loadClasses(),
      loadTeacherDashboard().catch(() => {}),
      loadSchoolManagement().catch(() => {})
    ]);

    if (result) {
      result.textContent = data.message || 'Класс обновлён.';
      result.classList.remove('hidden');
    }
    document.getElementById('classEditTitle').textContent = 'Редактировать: ' + currentClass.name;
  } catch (err) {
    if (error) {
      error.textContent = err.message;
      error.classList.remove('hidden');
    }
  } finally {
    button.disabled = false;
    button.textContent = 'Сохранить изменения';
  }
});

document.getElementById('deleteClassBtn')?.addEventListener('click', async () => {
  if (!currentClass || currentUser?.role !== 'admin') return;

  const classId = Number(currentClass.id);
  const className = String(currentClass.name || 'Класс');
  const studentsCount = Number(currentClass.students_count || currentClassStudentsCache.length || 0);

  const message = studentsCount
    ? `Удалить класс «${className}» безвозвратно? Будут удалены ${studentsCount} ученик(ов), их аккаунты, PIN, все попытки, ответы, оценки и результаты. Также исчезнут код класса и все назначения этого класса. Это действие нельзя отменить.`
    : `Удалить пустой класс «${className}» безвозвратно? Код класса, назначения учителей и связи с заданиями также будут удалены. Это действие нельзя отменить.`;

  const confirmed = await appConfirm(message, {
    title:'Полное удаление класса',
    tone:'danger',
    okText:'Удалить класс навсегда',
    cancelText:'Отмена'
  });
  if (!confirmed) return;

  const button = document.getElementById('deleteClassBtn');
  const error = document.getElementById('classEditError');
  button.disabled = true;
  button.textContent = 'Удаляем класс и данные...';
  error?.classList.add('hidden');

  try {
    const response = await fetch('./api/classes/delete.php', {
      method:'POST',
      credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({ class_id: classId, confirm_delete: true })
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось удалить класс.');

    closeModal(classEditModal);
    if (classDetailsModal && !classDetailsModal.classList.contains('hidden')) closeModal(classDetailsModal);
    currentClass = null;
    currentClassStudentsCache = [];
    teacherOptionsCache = [];

    await Promise.all([
      loadClasses(),
      loadAssignments().catch(() => {}),
      loadTeacherDashboard().catch(() => {}),
      loadSchoolManagement().catch(() => {}),
      loadResults().catch(() => {})
    ]);

    await appAlert(
      `Класс «${className}» удалён. Удалено учеников: ${Number(data.deleted?.students || 0)}. Их учебные данные также удалены.`,
      { title:'Класс удалён', tone:'success', okText:'Готово' }
    );
  } catch (err) {
    if (error) {
      error.textContent = err.message;
      error.classList.remove('hidden');
    }
  } finally {
    button.disabled = false;
    button.textContent = 'Удалить класс и всех учеников';
  }
});

async function openClassDetails(item) {
  currentClass = item;
  document.getElementById('classDetailsTitle').textContent = item.name;
  document.getElementById('classJoinCode').textContent = item.join_code || '—';
  updateRegistrationButton();
  document.getElementById('importStudentsResult').classList.add('hidden');
  document.getElementById('importStudentsError').classList.add('hidden');
  studentImportPreviewRows = [];
  document.getElementById('studentImportPreview')?.classList.add('hidden');
  const previewBody = document.getElementById('studentImportPreviewBody');
  if (previewBody) previewBody.innerHTML = '';
  document.getElementById('importStudentsForm')?.reset();
  openModal(classDetailsModal);
  await loadClassStudents();
}

function updateRegistrationButton() {
  const button = document.getElementById('toggleRegistrationBtn');
  if (!button || !currentClass) return;
  const open = Number(currentClass.registration_open) === 1;
  button.textContent = open ? 'Открыта — закрыть' : 'Открыть на 20 минут';
  button.classList.toggle('registration-open', open);
}

async function loadClassStudents() {
  if (!currentClass) return;
  const list = document.getElementById('classStudentsList');
  list.innerHTML = '<div class="class-student-row"><span>Загрузка...</span></div>';
  try {
    const response = await fetch('./api/classes/students.php?class_id=' + encodeURIComponent(currentClass.id), {
      credentials: 'same-origin',
      cache: 'no-store'
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось загрузить учеников.');
    const students = data.students || [];
    currentClassStudentsCache = students;
    document.getElementById('classStudentsCount').textContent = students.length;
    list.innerHTML = students.length ? students.map(student => `
      <div class="class-student-row">
        <span class="student-row-avatar">${escapeHtml((student.first_name || '?').charAt(0))}</span>
        <span class="student-row-name"><b>${escapeHtml(student.last_name)} ${escapeHtml(student.first_name)}${student.middle_name ? ' ' + escapeHtml(student.middle_name) : ''}</b><small>${Number(student.activated) ? 'PIN создан' : 'Ещё не входил'}</small></span>
        <span class="student-row-actions">
          <span class="status ${Number(student.activated) ? 'green' : 'blue'}">${Number(student.activated) ? 'Активирован' : 'Ожидает'}</span>
          <button type="button" class="mini-action student-edit-action" data-edit-student="${student.id}">✎ Редактировать</button>
          ${['admin', 'teacher'].includes(currentUser?.role) && Number(student.activated) ? `<button type="button" class="mini-action" data-reset-pin="${student.id}">Сбросить PIN</button>` : ''}
        </span>
      </div>
    `).join('') : '<div class="empty-students">Учеников пока нет. Загрузите DOCX со списком класса.</div>';

    list.querySelectorAll('[data-edit-student]').forEach(button => button.addEventListener('click', () => {
      openStudentEditor(Number(button.dataset.editStudent));
    }));

    list.querySelectorAll('[data-reset-pin]').forEach(button => button.addEventListener('click', async () => {
      if (!currentClass) return;
      const studentId = Number(button.dataset.resetPin);
      const student = students.find(item => Number(item.id) === studentId);
      if (!student) return;
      if (!(await appConfirm(`Сбросить PIN для ${student.last_name} ${student.first_name}?`))) return;

      button.disabled = true;
      try {
        const response = await fetch('./api/classes/reset-student-pin.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ class_id: currentClass.id, student_id: studentId })
        });
        const data = await response.json();
        if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось сбросить PIN.');
        await loadClassStudents();
      } catch (error) {
        alert(error.message);
        button.disabled = false;
      }
    }));
  } catch (error) {
    list.innerHTML = `<div class="empty-students">${escapeHtml(error.message)}</div>`;
  }
}

function openStudentEditor(studentId) {
  const student = currentClassStudentsCache.find(item => Number(item.id) === Number(studentId));
  if (!student || !currentClass || !studentEditModal) return;

  editingStudentId = Number(student.id);
  document.getElementById('studentEditId').value = String(student.id);
  document.getElementById('studentEditLastName').value = student.last_name || '';
  document.getElementById('studentEditFirstName').value = student.first_name || '';
  document.getElementById('studentEditMiddleName').value = student.middle_name || '';

  const hint = document.getElementById('studentEditHint');
  if (hint) hint.textContent = 'Класс: ' + (currentClass.name || '—') + '. Изменения сохранятся у этого же ученика.';
  document.getElementById('studentEditError')?.classList.add('hidden');
  document.getElementById('studentEditResult')?.classList.add('hidden');
  openModal(studentEditModal);
}

document.getElementById('studentEditForm')?.addEventListener('submit', async event => {
  event.preventDefault();
  if (!currentClass || !editingStudentId) return;

  const form = event.currentTarget;
  const button = document.getElementById('studentEditSaveBtn');
  const error = document.getElementById('studentEditError');
  const result = document.getElementById('studentEditResult');
  error?.classList.add('hidden');
  result?.classList.add('hidden');

  const payload = Object.fromEntries(new FormData(form).entries());
  payload.class_id = Number(currentClass.id);
  payload.student_id = Number(editingStudentId);

  button.disabled = true;
  button.textContent = 'Сохраняем...';

  try {
    const response = await fetch('./api/classes/update-student.php', {
      method:'POST',
      credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify(payload)
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) {
      throw new Error(data.error || 'Не удалось изменить данные ученика.');
    }

    await Promise.all([
      loadClassStudents(),
      loadClasses(),
      loadTeacherDashboard().catch(() => {}),
      loadResults().catch(() => {})
    ]);

    if (result) {
      result.textContent = data.message || 'Данные ученика обновлены.';
      result.classList.remove('hidden');
    }

    setTimeout(() => {
      closeModal(studentEditModal);
      editingStudentId = null;
    }, 450);
  } catch (err) {
    if (error) {
      error.textContent = err.message;
      error.classList.remove('hidden');
    }
  } finally {
    button.disabled = false;
    button.textContent = 'Сохранить изменения';
  }
});

document.getElementById('createClassBtn')?.addEventListener('click', () => openModal(classModal));

document.getElementById('classForm')?.addEventListener('submit', async event => {
  event.preventDefault();
  const form = event.currentTarget;
  const error = document.getElementById('classFormError');
  const button = form.querySelector('button[type="submit"]');
  error.classList.add('hidden');
  button.disabled = true;
  button.textContent = 'Создаём...';
  try {
    const payload = Object.fromEntries(new FormData(form).entries());
    const response = await fetch('./api/classes/create.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось создать класс.');
    form.reset();
    closeModal(classModal);
    await loadClasses();
    const created = classesCache.find(item => Number(item.id) === Number(data.class.id)) || data.class;
    openClassDetails(created);
  } catch (e) {
    error.textContent = e.message;
    error.classList.remove('hidden');
  } finally {
    button.disabled = false;
    button.textContent = 'Создать класс';
  }
});

document.getElementById('copyClassCodeBtn')?.addEventListener('click', async () => {
  if (!currentClass?.join_code) return;
  try {
    await navigator.clipboard.writeText(currentClass.join_code);
    const button = document.getElementById('copyClassCodeBtn');
    const old = button.textContent;
    button.textContent = 'Скопировано ✓';
    setTimeout(() => button.textContent = old, 1200);
  } catch {
    await appAlert('Код класса: ' + currentClass.join_code, { title: 'Код класса', okText: 'Закрыть' });
  }
});

document.getElementById('toggleRegistrationBtn')?.addEventListener('click', async () => {
  if (!currentClass) return;
  const next = Number(currentClass.registration_open) === 1 ? 0 : 1;
  const response = await fetch('./api/classes/toggle-registration.php', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ class_id: currentClass.id, registration_open: next })
  });
  const data = await response.json();
  if (!response.ok || data.ok === false) {
    alert(data.error || 'Не удалось изменить регистрацию.');
    return;
  }
  currentClass.registration_open = data.registration_open;
  currentClass.registration_expires_at = data.registration_expires_at || null;
  const cached = classesCache.find(item => Number(item.id) === Number(currentClass.id));
  if (cached) {
    cached.registration_open = data.registration_open;
    cached.registration_expires_at = data.registration_expires_at || null;
  }
  updateRegistrationButton();
});

function renderStudentImportPreview() {
  const box = document.getElementById('studentImportPreview');
  const body = document.getElementById('studentImportPreviewBody');
  const meta = document.getElementById('studentImportPreviewMeta');
  if (!box || !body || !meta) return;

  if (!studentImportPreviewRows.length) {
    body.innerHTML = '<tr><td colspan="5">Список пуст. Добавьте строку вручную или загрузите другой DOCX.</td></tr>';
    meta.textContent = '0 учеников';
    box.classList.remove('hidden');
    return;
  }

  const duplicates = studentImportPreviewRows.filter(row => row.duplicate).length;
  meta.textContent = `Распознано: ${studentImportPreviewRows.length}${duplicates ? ' · уже есть в классе: ' + duplicates : ''}`;

  body.innerHTML = studentImportPreviewRows.map((row, index) => `
    <tr data-preview-row="${index}" class="${row.duplicate ? 'preview-duplicate-row' : ''}">
      <td>${index + 1}</td>
      <td><input type="text" data-preview-field="last_name" value="${escapeHtml(row.last_name)}" aria-label="Фамилия"></td>
      <td><input type="text" data-preview-field="first_name" value="${escapeHtml(row.first_name)}" aria-label="Имя"></td>
      <td><span class="status ${row.duplicate ? 'amber' : 'green'}">${row.duplicate ? 'Уже есть' : 'Новый'}</span></td>
      <td><button class="mini-action danger-action" type="button" data-remove-preview-row="${index}">Удалить</button></td>
    </tr>
  `).join('');

  body.querySelectorAll('input[data-preview-field]').forEach(input => {
    input.addEventListener('input', () => {
      const rowEl = input.closest('[data-preview-row]');
      const index = Number(rowEl?.dataset.previewRow);
      if (!Number.isInteger(index) || !studentImportPreviewRows[index]) return;
      studentImportPreviewRows[index][input.dataset.previewField] = input.value;
      studentImportPreviewRows[index].duplicate = false;
      rowEl.classList.remove('preview-duplicate-row');
      const status = rowEl.querySelector('.status');
      if (status) {
        status.className = 'status blue';
        status.textContent = 'Изменено';
      }
    });
  });

  body.querySelectorAll('[data-remove-preview-row]').forEach(button => {
    button.addEventListener('click', () => {
      studentImportPreviewRows.splice(Number(button.dataset.removePreviewRow), 1);
      renderStudentImportPreview();
    });
  });

  box.classList.remove('hidden');
}

document.getElementById('addPreviewStudentBtn')?.addEventListener('click', () => {
  studentImportPreviewRows.push({ last_name: '', first_name: '', duplicate: false });
  renderStudentImportPreview();
  const rows = document.querySelectorAll('#studentImportPreviewBody tr');
  const lastRow = rows[rows.length - 1];
  lastRow?.querySelector('input')?.focus();
});

document.getElementById('importStudentsForm')?.addEventListener('submit', async event => {
  event.preventDefault();
  if (!currentClass) return;

  const form = event.currentTarget;
  const fileInput = form.querySelector('input[type="file"]');
  const button = form.querySelector('button[type="submit"]');
  const error = document.getElementById('importStudentsError');
  const result = document.getElementById('importStudentsResult');
  error.classList.add('hidden');
  result.classList.add('hidden');

  if (!fileInput.files?.[0]) return;
  const data = new FormData();
  data.append('class_id', currentClass.id);
  data.append('file', fileInput.files[0]);

  button.disabled = true;
  button.textContent = 'Распознаём...';
  try {
    const response = await fetch('./api/classes/import-students.php', {
      method: 'POST',
      credentials: 'same-origin',
      body: data
    });
    const payload = await response.json();
    if (!response.ok || payload.ok === false) throw new Error(payload.error || 'Не удалось распознать список.');

    studentImportPreviewRows = (payload.students || []).map(row => ({
      last_name: row.last_name || '',
      first_name: row.first_name || '',
      duplicate: Boolean(row.duplicate)
    }));
    renderStudentImportPreview();
    result.textContent = 'Список распознан. Проверьте каждую строку и исправьте ошибки перед добавлением.';
    result.classList.remove('hidden');
  } catch (e) {
    error.textContent = e.message;
    error.classList.remove('hidden');
  } finally {
    button.disabled = false;
    button.textContent = 'Проверить DOCX';
  }
});

document.getElementById('confirmStudentsImportBtn')?.addEventListener('click', async () => {
  if (!currentClass) return;
  const button = document.getElementById('confirmStudentsImportBtn');
  const error = document.getElementById('importStudentsError');
  const result = document.getElementById('importStudentsResult');

  const students = studentImportPreviewRows
    .map(row => ({
      last_name: String(row.last_name || '').trim(),
      first_name: String(row.first_name || '').trim()
    }))
    .filter(row => row.last_name || row.first_name);

  if (!students.length) {
    error.textContent = 'В списке нет учеников для добавления.';
    error.classList.remove('hidden');
    return;
  }

  error.classList.add('hidden');
  button.disabled = true;
  button.textContent = 'Добавляем...';

  try {
    const response = await fetch('./api/classes/import-students-commit.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ class_id: currentClass.id, students })
    });
    const payload = await response.json();
    if (!response.ok || payload.ok === false) throw new Error(payload.error || 'Не удалось добавить учеников.');

    result.textContent = `Добавлено: ${payload.imported_count}. Уже были в классе: ${payload.skipped_count}.`;
    result.classList.remove('hidden');
    studentImportPreviewRows = [];
    document.getElementById('studentImportPreview')?.classList.add('hidden');
    document.getElementById('importStudentsForm')?.reset();

    await loadClassStudents();
    await loadClasses();
    const refreshed = classesCache.find(item => Number(item.id) === Number(currentClass.id));
    if (refreshed) currentClass = refreshed;
  } catch (e) {
    error.textContent = e.message;
    error.classList.remove('hidden');
  } finally {
    button.disabled = false;
    button.textContent = 'Добавить проверенный список';
  }
});

let incomingMaterialsCache = [];
let incomingMaterialsFilter = 'pending';

function formatIncomingDate(value) {
  if (!value) return '—';
  const normalized = String(value).replace(' ', 'T');
  const date = new Date(normalized.endsWith('Z') ? normalized : normalized + 'Z');
  if (Number.isNaN(date.getTime())) return String(value);
  return date.toLocaleString('ru-RU', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  });
}

function incomingStatusLabel(status) {
  if (status === 'accepted') return ['Принято', 'green'];
  if (status === 'rejected') return ['Отклонено', 'red'];
  return ['Ожидает решения', 'amber'];
}

function renderIncomingMaterials() {
  const list = document.getElementById('incomingMaterialsList');
  if (!list) return;

  const pending = incomingMaterialsCache.filter(item => item.status === 'pending');
  const accepted = incomingMaterialsCache.filter(item => item.status === 'accepted');
  const rejected = incomingMaterialsCache.filter(item => item.status === 'rejected');

  const pendingCount = document.getElementById('incomingPendingCount');
  const acceptedCount = document.getElementById('incomingAcceptedCount');
  const rejectedCount = document.getElementById('incomingRejectedCount');
  if (pendingCount) pendingCount.textContent = String(pending.length);
  if (acceptedCount) acceptedCount.textContent = String(accepted.length);
  if (rejectedCount) rejectedCount.textContent = String(rejected.length);

  const badge = document.getElementById('incomingMaterialsBadge');
  if (badge) {
    badge.textContent = String(pending.length);
    badge.classList.toggle('hidden', pending.length === 0);
  }

  let rows = incomingMaterialsCache;
  if (incomingMaterialsFilter === 'pending') rows = pending;
  if (incomingMaterialsFilter === 'history') rows = incomingMaterialsCache.filter(item => item.status !== 'pending');

  if (!rows.length) {
    const message = incomingMaterialsFilter === 'pending'
      ? 'Новых входящих материалов нет.'
      : incomingMaterialsFilter === 'history'
        ? 'История входящих пока пуста.'
        : 'Материалы из других школ пока не поступали.';
    list.innerHTML = `<article class="panel incoming-empty"><div class="incoming-empty-icon">⇩</div><h3>${escapeHtml(message)}</h3><p>Когда другая школа отправит предмет или задания, они появятся здесь.</p></article>`;
    return;
  }

  list.innerHTML = rows.map(item => {
    const [statusText, statusClass] = incomingStatusLabel(item.status);
    const sender = [item.sender_last_name, item.sender_first_name].filter(Boolean).join(' ');
    const assignments = Array.isArray(item.assignments) ? item.assignments : [];

    const assignmentHtml = assignments.length
      ? assignments.map(assignment => {
          const format = assignment.source_format_snapshot
            ? String(assignment.source_format_snapshot).toUpperCase()
            : 'UROVIA';
          return `
            <div class="incoming-assignment-row">
              <div>
                <b>${escapeHtml(assignment.title_snapshot || 'Задание')}</b>
                <small>${escapeHtml(format)} · вопросов: ${Number(assignment.questions_count_snapshot || 0)}</small>
              </div>
              <span class="incoming-copy-note">будет создан черновик</span>
            </div>`;
        }).join('')
      : '<div class="incoming-subject-only">Передан только предмет — без заданий.</div>';

    const actions = item.status === 'pending'
      ? `
        <button class="secondary-btn" type="button" data-incoming-preview="${item.id}">Просмотреть</button>
        <button class="primary-btn" type="button" data-incoming-action="accept" data-transfer-id="${item.id}">Принять</button>
        <button class="secondary-btn" type="button" data-incoming-action="reject" data-transfer-id="${item.id}">Отклонить</button>`
      : `<button class="secondary-btn" type="button" data-incoming-preview="${item.id}">Просмотреть</button>`;

    return `
      <article class="panel incoming-material-card" data-transfer-card="${item.id}">
        <div class="incoming-material-head">
          <div>
            <span class="section-kicker">Из школы</span>
            <h3>${escapeHtml(item.source_school_name || 'Другая школа')}</h3>
            <p>Отправлено: ${escapeHtml(formatIncomingDate(item.created_at))}${sender ? ' · ' + escapeHtml(sender) : ''}</p>
          </div>
          <span class="status ${statusClass}">${statusText}</span>
        </div>

        <div class="incoming-subject-box">
          <span>Предмет</span>
          <strong>${escapeHtml(item.subject_name || 'Предмет')}</strong>
          <small>${assignments.length} задан.${item.status === 'pending' ? ' · пока не добавлено в библиотеку' : ''}</small>
        </div>

        <div class="incoming-assignment-list">${assignmentHtml}</div>

        <div class="incoming-material-footer">
          <span>Классы, ученики, результаты и попытки не передаются.</span>
          <div class="incoming-actions">${actions}</div>
        </div>
      </article>`;
  }).join('');

  list.querySelectorAll('[data-incoming-action]').forEach(button => {
    button.addEventListener('click', () => resolveIncomingMaterial(
      Number(button.dataset.transferId),
      String(button.dataset.incomingAction)
    ));
  });
  list.querySelectorAll('[data-incoming-preview]').forEach(button => {
    button.addEventListener('click', () => openIncomingMaterialPreview(Number(button.dataset.incomingPreview)));
  });
}

async function openIncomingMaterialPreview(transferId) {
  const modal = document.getElementById('incomingMaterialPreviewModal');
  const title = document.getElementById('incomingPreviewTitle');
  const hint = document.getElementById('incomingPreviewHint');
  const content = document.getElementById('incomingPreviewContent');
  const error = document.getElementById('incomingPreviewError');
  if (!modal || !content) return;

  content.innerHTML = '<p>Загрузка содержимого...</p>';
  error?.classList.add('hidden');
  openModal(modal);

  try {
    const response = await fetch(`./api/materials/preview.php?transfer_id=${encodeURIComponent(transferId)}`, {
      credentials: 'same-origin',
      cache: 'no-store'
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось загрузить содержимое.');

    if (title) title.textContent = data.transfer?.subject_name || 'Полученные материалы';
    if (hint) hint.textContent = `Источник: ${data.transfer?.source_school_name || 'другая школа'}. Это только предпросмотр — материалы ещё не добавлены в библиотеку.`;

    const assignments = data.assignments || [];
    if (!assignments.length) {
      content.innerHTML = '<div class="incoming-subject-only">В пакете передан только предмет, без заданий.</div>';
      return;
    }

    content.innerHTML = assignments.map((assignment, assignmentIndex) => {
      const questions = assignment.questions || [];
      return `
        <section class="incoming-preview-assignment">
          <div class="incoming-preview-assignment-head">
            <div>
              <span>Задание ${assignmentIndex + 1}</span>
              <h3>${escapeHtml(assignment.title || 'Задание')}</h3>
            </div>
            <small>${questions.length} вопросов</small>
          </div>
          ${assignment.description ? `<p class="incoming-preview-description">${escapeHtml(assignment.description)}</p>` : ''}
          <div class="incoming-preview-questions">
            ${questions.length ? questions.map((question, index) => {
              const interaction = canonicalQuestionType(question.interaction_type || question.type);
              const options = (question.options || []).length
                ? `<div class="question-preview-options">${question.options.map(option =>
                    `<span class="${Number(option.is_correct) === 1 ? 'correct' : ''}">${escapeHtml(option.text)}</span>`
                  ).join('')}</div>`
                : '';
              const assets = (question.assets || []).map(asset =>
                `<img class="question-preview-image" src="${escapeHtml(asset.url)}" alt="Изображение к вопросу">`
              ).join('');
              return `
                <article class="question-preview-card">
                  <div class="question-preview-head">
                    <span>№ ${index + 1}</span>
                    <b>${escapeHtml(questionTypeLabel(interaction))}</b>
                    <strong>${Number(question.points || 1)} балл.</strong>
                  </div>
                  <h4>${escapeHtml(question.text)}</h4>
                  ${assets}
                  ${options}
                  <div class="question-preview-answer">
                    <span>Правильный ответ</span>
                    <b>${escapeHtml(renderQuestionCorrectAnswer(question))}</b>
                  </div>
                </article>`;
            }).join('') : '<div class="incoming-subject-only">В этом задании пока нет вопросов.</div>'}
          </div>
        </section>`;
    }).join('');
  } catch (e) {
    content.innerHTML = '';
    if (error) {
      error.textContent = e.message;
      error.classList.remove('hidden');
    }
  }
}

async function loadIncomingMaterials() {
  if (currentUser?.role !== 'admin') return;
  const list = document.getElementById('incomingMaterialsList');
  if (list && document.getElementById('incoming-materials')?.classList.contains('active')) {
    list.innerHTML = '<article class="panel"><p>Загрузка входящих материалов...</p></article>';
  }

  try {
    const response = await fetch('./api/materials/incoming.php', {
      credentials: 'same-origin',
      cache: 'no-store'
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось загрузить входящие материалы.');
    incomingMaterialsCache = data.transfers || [];
    renderIncomingMaterials();
    return data;
  } catch (error) {
    if (list) list.innerHTML = `<article class="panel"><p>${escapeHtml(error.message)}</p></article>`;
    throw error;
  }
}

async function resolveIncomingMaterial(transferId, action) {
  const item = incomingMaterialsCache.find(row => Number(row.id) === Number(transferId));
  if (!item) return;

  if (action === 'accept') {
    const count = Number(item.assignment_count || item.assignments?.length || 0);
    if (!(await appConfirm(`Принять предмет «${item.subject_name}»${count ? ' и ' + count + ' задан.' : ''} из школы «${item.source_school_name}»?\n\nЗадания будут добавлены как черновики без классов и учеников.`))) return;
  } else {
    if (!(await appConfirm(`Отклонить материалы из школы «${item.source_school_name}»? Они не будут добавлены в библиотеку.`))) return;
  }

  const buttons = document.querySelectorAll(`[data-transfer-card="${transferId}"] button`);
  buttons.forEach(button => button.disabled = true);

  try {
    const response = await fetch('./api/materials/resolve.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        transfer_id: transferId,
        action
      })
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось обработать материалы.');

    if (action === 'accept') {
      const copied = Number(data.copied_assignments?.length || 0);
      const skipped = Number(data.skipped_assignments?.length || 0);
      alert(`Материалы приняты. Новых заданий: ${copied}${skipped ? ', уже были в школе: ' + skipped : ''}.`);
      subjectsCache = [];
      assignmentsCache = [];
      await Promise.all([loadSubjects(), loadAssignments()]);
    }

    await loadIncomingMaterials();
  } catch (error) {
    alert(error.message);
    buttons.forEach(button => button.disabled = false);
  }
}

document.getElementById('refreshIncomingMaterialsBtn')?.addEventListener('click', () => {
  loadIncomingMaterials().catch(error => alert(error.message));
});

document.querySelectorAll('[data-incoming-filter]').forEach(button => {
  button.addEventListener('click', () => {
    incomingMaterialsFilter = String(button.dataset.incomingFilter || 'pending');
    document.querySelectorAll('[data-incoming-filter]').forEach(item => item.classList.toggle('active', item === button));
    renderIncomingMaterials();
  });
});



let backupsCache = [];

function backupFormatBytes(value) {
  const bytes = Math.max(0, Number(value || 0));
  if (bytes < 1024) return bytes + ' Б';
  const units = ['КБ', 'МБ', 'ГБ', 'ТБ'];
  let size = bytes / 1024;
  let unitIndex = 0;
  while (size >= 1024 && unitIndex < units.length - 1) {
    size /= 1024;
    unitIndex++;
  }
  const digits = size >= 100 ? 0 : (size >= 10 ? 1 : 2);
  return size.toFixed(digits).replace('.', ',') + ' ' + units[unitIndex];
}

function backupCreatedAt(value) {
  if (!value) return '—';
  const date = new Date(String(value));
  if (Number.isNaN(date.getTime())) return String(value);
  return date.toLocaleString('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  });
}

function renderBackups(data) {
  const body = document.getElementById('backupsBody');
  const status = document.getElementById('backupStatus');
  if (!body) return;

  backupsCache = Array.isArray(data?.items) ? data.items : [];
  const automatic = backupsCache.filter(item => item.kind === 'automatic').length;
  const manual = backupsCache.filter(item => item.kind === 'manual').length;

  if (status) {
    status.innerHTML = backupsCache.length
      ? `<span>Всего: <b>${backupsCache.length}</b></span><span>Автоматических: <b>${automatic}</b></span><span>Ручных: <b>${manual}</b></span><span>Хранение: <b>${Number(data?.retention_days || 30)} дней</b></span>`
      : 'Резервных копий пока нет.';
  }

  if (!backupsCache.length) {
    body.innerHTML = '<tr><td colspan="6">Резервных копий пока нет. Нажмите «Создать копию сейчас».</td></tr>';
    return;
  }

  body.innerHTML = backupsCache.map(item => {
    const file = String(item.file || '');
    const checksum = item.sha256 ? String(item.sha256).slice(0, 12) + '…' : '—';
    const filesCount = item.asset_files === null || item.asset_files === undefined
      ? '—'
      : Number(item.asset_files).toLocaleString('ru-RU');
    const assetsSize = item.asset_bytes === null || item.asset_bytes === undefined
      ? ''
      : ' · ' + backupFormatBytes(item.asset_bytes);
    return `
      <tr>
        <td><b>${escapeHtml(backupCreatedAt(item.created_at))}</b><small class="backup-file-name">${escapeHtml(file)}</small></td>
        <td><span class="status ${item.kind === 'automatic' ? 'blue' : 'green'}">${item.kind === 'automatic' ? 'Автоматическая' : 'Ручная'}</span></td>
        <td>${escapeHtml(backupFormatBytes(item.size_bytes))}<small class="backup-file-name">${escapeHtml(String(item.format || '').toUpperCase())}</small></td>
        <td><b>${escapeHtml(filesCount)}</b><small class="backup-file-name">файлов${escapeHtml(assetsSize)}</small></td>
        <td><code class="backup-checksum" title="${escapeHtml(String(item.sha256 || ''))}">${escapeHtml(checksum)}</code></td>
        <td class="row-actions-cell">
          <button class="secondary-btn compact-btn" type="button" data-download-backup="${escapeHtml(file)}">⇩ Скачать</button>
          <button class="mini-action danger-action" type="button" data-delete-backup="${escapeHtml(file)}">Удалить</button>
        </td>
      </tr>`;
  }).join('');

  body.querySelectorAll('[data-download-backup]').forEach(button => {
    button.addEventListener('click', () => {
      const file = String(button.dataset.downloadBackup || '');
      if (!file) return;
      window.location.href = './api/backups/download.php?file=' + encodeURIComponent(file);
    });
  });

  body.querySelectorAll('[data-delete-backup]').forEach(button => {
    button.addEventListener('click', () => deleteBackup(String(button.dataset.deleteBackup || '')));
  });
}

async function loadDatabaseStatus() {
  if (Number(currentUser?.is_platform_admin) !== 1) return;

  const badge = document.getElementById('databaseDriverBadge');
  const box = document.getElementById('databaseStatusBox');
  const form = document.getElementById('databaseMigrationForm');
  const hint = document.getElementById('backupDatabaseHint');

  try {
    const response = await fetch('./api/database/status.php', {
      credentials:'same-origin',
      cache:'no-store'
    });
    const data = await readJsonResponse(response, 'Не удалось проверить базу данных.');
    if (!response.ok || data.ok === false) {
      throw new Error(data.error || 'Не удалось проверить базу данных.');
    }

    if (data.mysql_enabled) {
      if (badge) {
        badge.className = 'status green';
        badge.textContent = 'MySQL';
      }
      if (box) {
        const migrated = data.migration?.completed_at
          ? ' · миграция: ' + escapeHtml(backupCreatedAt(data.migration.completed_at))
          : '';
        const rows = Number(data.migration?.total_rows || 0);
        box.innerHTML = '<b>MySQL активен</b><span>Рабочие данные UROVIA хранятся в MySQL' +
          migrated +
          (rows ? ' · перенесено записей: ' + rows.toLocaleString('ru-RU') : '') +
          '.</span>';
      }
      form?.classList.add('hidden');
      if (hint) hint.textContent = 'MySQL, импортированные задания, изображения и другие материалы storage.';
    } else {
      if (badge) {
        badge.className = 'status amber';
        badge.textContent = 'SQLite';
      }
      if (box) {
        box.innerHTML = '<b>Сейчас используется SQLite</b><span>' +
          (data.sqlite_source_exists
            ? 'Исходная база найдена и готова к безопасному переносу.'
            : 'Файл SQLite не найден.') +
          '</span>';
      }
      form?.classList.toggle('hidden', !data.sqlite_source_exists);
      if (hint) hint.textContent = 'SQLite, импортированные задания, изображения и другие материалы storage.';
    }
  } catch (error) {
    if (badge) {
      badge.className = 'status amber';
      badge.textContent = 'Ошибка';
    }
    if (box) box.textContent = error.message;
    form?.classList.add('hidden');
  }
}

async function migrateDatabaseToMysql(event) {
  event.preventDefault();
  if (Number(currentUser?.is_platform_admin) !== 1) return;

  const form = event.currentTarget;
  const button = document.getElementById('databaseMigrationBtn');
  const errorNode = document.getElementById('databaseMigrationError');
  const resultNode = document.getElementById('databaseMigrationResult');

  errorNode?.classList.add('hidden');
  resultNode?.classList.add('hidden');

  const confirmed = await appConfirm(
    'Перенести текущую SQLite-базу в MySQL? На время проверки и копирования UROVIA кратковременно включит режим обслуживания. Исходная SQLite-база и отдельная резервная копия будут сохранены.',
    {
      title:'Перенос базы данных',
      okText:'Начать безопасный перенос'
    }
  );
  if (!confirmed) return;

  const payload = Object.fromEntries(new FormData(form).entries());
  payload.port = Number(payload.port || 3306);

  if (button) {
    button.disabled = true;
    button.textContent = 'Переносим и проверяем данные...';
  }

  try {
    const response = await fetch('./api/database/migrate.php', {
      method:'POST',
      credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify(payload)
    });
    const data = await readJsonResponse(response, 'Не удалось выполнить миграцию базы.');
    if (!response.ok || data.ok === false) {
      throw new Error(data.error || 'Миграция остановлена.');
    }

    const passwordInput = form.querySelector('input[name="password"]');
    if (passwordInput) passwordInput.value = '';

    if (resultNode) {
      const total = Number(data.summary?.total_rows || 0).toLocaleString('ru-RU');
      const backup = data.summary?.sqlite_backup?.file || 'создана';
      resultNode.innerHTML = '<b>MySQL включён успешно.</b><br>Перенесено записей: ' +
        escapeHtml(total) + '. Резервная копия SQLite: ' + escapeHtml(backup) + '.';
      resultNode.classList.remove('hidden');
    }

    await appAlert(
      'Все проверки пройдены. UROVIA переключена на MySQL, исходная SQLite-база сохранена для аварийного отката.',
      {
        title:'Миграция завершена',
        tone:'success',
        okText:'Перезагрузить UROVIA'
      }
    );
    window.location.reload();
  } catch (error) {
    if (errorNode) {
      errorNode.textContent = error.message;
      errorNode.classList.remove('hidden');
    }
  } finally {
    if (button) {
      button.disabled = false;
      button.textContent = 'Перенести данные и включить MySQL';
    }
  }
}

document.getElementById('databaseMigrationForm')?.addEventListener('submit', migrateDatabaseToMysql);

async function loadBackups() {
  if (Number(currentUser?.is_platform_admin) !== 1) return;
  const body = document.getElementById('backupsBody');
  if (body) body.innerHTML = '<tr><td colspan="6">Загрузка...</td></tr>';

  const response = await fetch('./api/backups/list.php', {
    credentials: 'same-origin',
    cache: 'no-store'
  });
  const data = await response.json();
  if (!response.ok || data.ok === false) {
    if (body) body.innerHTML = `<tr><td colspan="6">${escapeHtml(data.error || 'Не удалось загрузить резервные копии.')}</td></tr>`;
    return;
  }
  renderBackups(data);
}

async function createBackupNow() {
  if (Number(currentUser?.is_platform_admin) !== 1) return;
  const button = document.getElementById('createBackupBtn');
  const original = button?.textContent || '＋ Создать копию сейчас';
  if (button) {
    button.disabled = true;
    button.textContent = 'Создание копии...';
  }

  try {
    const response = await fetch('./api/backups/create.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: '{}'
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) {
      throw new Error(data.error || 'Не удалось создать резервную копию.');
    }
    await loadBackups();
  } catch (error) {
    alert(error.message);
  } finally {
    if (button) {
      button.disabled = false;
      button.textContent = original;
    }
  }
}

async function deleteBackup(file) {
  if (Number(currentUser?.is_platform_admin) !== 1 || !file) return;
  if (!(await appConfirm('Удалить эту резервную копию? Восстановить удалённый архив будет невозможно.'))) return;

  try {
    const response = await fetch('./api/backups/delete.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ file })
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) {
      throw new Error(data.error || 'Не удалось удалить резервную копию.');
    }
    await loadBackups();
  } catch (error) {
    alert(error.message);
  }
}

document.getElementById('createBackupBtn')?.addEventListener('click', createBackupNow);
document.getElementById('refreshBackupsBtn')?.addEventListener('click', () => loadBackups().catch(() => {}));


let activeProfileUserId = null;
let libraryItemsCache = [];
let libraryOwnCache = [];
let libraryCanManage = false;
let libraryActiveSchoolId = 0;

function profileRoleText(data) {
  if (Number(data?.profile?.is_platform_admin) === 1) return 'Главный администратор UROVIA';
  const membership = data?.active_school;
  if (!membership) return roleLabels[data?.profile?.role] || 'Сотрудник';
  const admin = ['owner', 'school_admin'].includes(String(membership.role || ''));
  const teacher = Number(membership.can_teach || 0) === 1;
  if (admin && teacher) return 'Администратор школы · Учитель';
  if (admin) return 'Администратор школы';
  return 'Учитель';
}

function profileInitials(profile) {
  return ((profile?.first_name || 'У').charAt(0) + (profile?.last_name || '').charAt(0)).toUpperCase();
}

function selectProfileTab(name) {
  document.querySelectorAll('[data-profile-tab]').forEach(button => {
    button.classList.toggle('active', button.dataset.profileTab === name);
  });
  document.querySelectorAll('[data-profile-pane]').forEach(pane => {
    pane.classList.toggle('active', pane.dataset.profilePane === name);
  });
}

document.querySelectorAll('[data-profile-tab]').forEach(button => {
  button.addEventListener('click', () => selectProfileTab(String(button.dataset.profileTab || 'main')));
});

async function loadStaffProfile(userId = null, openView = false) {
  const targetId = Number(userId || currentUser?.id || 0);
  if (!targetId) return;

  const response = await fetch('./api/profile/get.php?user_id=' + encodeURIComponent(targetId), {
    credentials: 'same-origin',
    cache: 'no-store'
  });
  const data = await response.json();
  if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось загрузить профиль.');

  activeProfileUserId = Number(data.profile.id);
  const self = Boolean(data.permissions?.self);
  const editable = Boolean(data.permissions?.can_edit_basic);
  const roleText = profileRoleText(data);
  const fullName = [data.profile.last_name, data.profile.first_name, data.profile.middle_name].filter(Boolean).join(' ');

  document.getElementById('profilePageTitle').textContent = self ? 'Мой профиль' : 'Профиль сотрудника';
  document.getElementById('profilePageHint').textContent = self
    ? 'Личные данные, фото, безопасность, роль и учебные назначения.'
    : 'Карточка сотрудника выбранной школы.';
  document.getElementById('profileBackBtn')?.classList.toggle('hidden', self);
  document.getElementById('profileDisplayName').textContent = fullName || 'Сотрудник UROVIA';
  document.getElementById('profileRoleBadge').textContent = roleText;
  document.getElementById('profileSchoolSummary').textContent = data.active_school?.name || (Number(data.profile.is_platform_admin) === 1 ? 'Платформа UROVIA' : 'Школа не выбрана');

  const photo = document.getElementById('profilePhoto');
  const initials = document.getElementById('profilePhotoInitials');
  if (photo) {
    photo.style.backgroundImage = data.profile.avatar_url ? `url("${data.profile.avatar_url}")` : '';
    photo.classList.toggle('has-photo', Boolean(data.profile.avatar_url));
  }
  if (initials) {
    initials.textContent = profileInitials(data.profile);
    initials.classList.toggle('hidden', Boolean(data.profile.avatar_url));
  }

  document.getElementById('profileUserId').value = String(data.profile.id);
  document.getElementById('profileAvatarUserId').value = String(data.profile.id);
  document.getElementById('profileLastName').value = data.profile.last_name || '';
  document.getElementById('profileFirstName').value = data.profile.first_name || '';
  document.getElementById('profileMiddleName').value = data.profile.middle_name || '';
  document.getElementById('profileEmail').value = data.profile.email || '';
  document.getElementById('profilePhone').value = data.profile.phone || '';

  document.querySelectorAll('#profileBasicForm input:not([type="hidden"])').forEach(input => input.disabled = !editable);
  document.getElementById('profileBasicSaveBtn')?.classList.toggle('hidden', !editable);
  document.getElementById('profileAvatarFile').disabled = !editable;
  document.getElementById('profileAvatarSaveBtn')?.classList.toggle('hidden', !editable);

  document.getElementById('profileLoginName').textContent = data.profile.login_name || 'Вход по email';
  const mailTestEmail = document.getElementById('mailTestEmail');
  if (mailTestEmail && Number(currentUser?.is_platform_admin) === 1 && !mailTestEmail.value) {
    mailTestEmail.value = data.profile.email || currentUser?.email || 'info@urovia.ru';
  }
  document.getElementById('profileCredentialsState').textContent = Number(data.profile.must_change_password) === 1
    ? 'Нужно сменить временный пароль'
    : (data.profile.credentials_sent_at ? 'Доступ активирован' : 'Обычный доступ');
  document.getElementById('profilePasswordForm')?.classList.toggle('hidden', !self);
  document.getElementById('profileManagedSecurity')?.classList.toggle('hidden', self);

  const access = document.getElementById('profileAccessInfo');
  if (access) {
    access.innerHTML = `
      <div class="profile-info-card"><span>Роль</span><strong>${escapeHtml(roleText)}</strong></div>
      <div class="profile-info-card"><span>Email входа</span><strong>${escapeHtml(data.profile.email || '—')}</strong></div>
      <div class="profile-info-card"><span>Логин</span><strong>${escapeHtml(data.profile.login_name || 'не задан')}</strong></div>
      <div class="profile-info-card"><span>Аккаунт создан</span><strong>${escapeHtml(historyDateTime(data.profile.created_at))}</strong></div>`;
  }

  const subjects = document.getElementById('profileSubjects');
  if (subjects) {
    subjects.innerHTML = (data.subjects || []).length
      ? data.subjects.map(item => `<span class="subject-admin-chip">${escapeHtml(item.name)}</span>`).join('')
      : '<span class="profile-empty-value">Предметы не назначены</span>';
  }

  const classes = document.getElementById('profileClasses');
  if (classes) {
    classes.innerHTML = (data.classes || []).length
      ? data.classes.map(item => `<div class="profile-assignment-item"><b>${escapeHtml(item.name)}</b><span>${escapeHtml(item.subject_name || '')}${item.academic_year ? ' · ' + escapeHtml(item.academic_year) : ''}</span></div>`).join('')
      : '<span class="profile-empty-value">Классы не назначены</span>';
  }

  const schools = document.getElementById('profileSchools');
  if (schools) {
    schools.innerHTML = (data.schools || []).length
      ? data.schools.map(item => {
          const admin = ['owner', 'school_admin'].includes(String(item.role || ''));
          const teacher = Number(item.can_teach || 0) === 1;
          const label = admin && teacher ? 'Администратор · Учитель' : (admin ? 'Администратор' : 'Учитель');
          return `<div class="profile-school-item"><div><b>${escapeHtml(item.name)}</b><span>${escapeHtml(item.city || '')}</span></div><span class="status blue">${escapeHtml(label)}</span></div>`;
        }).join('')
      : '<span class="profile-empty-value">Нет активных школ</span>';
  }

  selectProfileTab('main');
  if (openView) showView('staff-profile');
}

document.getElementById('profileBackBtn')?.addEventListener('click', () => {
  activeProfileUserId = null;
  showView('school-management');
  loadSchoolManagement().catch(() => {});
});

document.getElementById('profileBasicForm')?.addEventListener('submit', async event => {
  event.preventDefault();
  const form = event.currentTarget;
  const error = document.getElementById('profileBasicError');
  const result = document.getElementById('profileBasicResult');
  const button = document.getElementById('profileBasicSaveBtn');
  error?.classList.add('hidden');
  result?.classList.add('hidden');
  button.disabled = true;

  try {
    const payload = Object.fromEntries(new FormData(form).entries());
    const response = await fetch('./api/profile/update.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось сохранить профиль.');
    if (Number(payload.user_id) === Number(currentUser?.id)) {
      currentUser.first_name = payload.first_name;
      currentUser.last_name = payload.last_name;
      currentUser.middle_name = payload.middle_name;
      currentUser.email = payload.email;
      sidebarName.textContent = [payload.first_name, payload.last_name].filter(Boolean).join(' ');
      sidebarAvatar.textContent = ((payload.first_name || 'П').charAt(0) + (payload.last_name || '').charAt(0)).toUpperCase();
    }
    if (result) {
      result.textContent = 'Профиль сохранён.';
      result.classList.remove('hidden');
    }
    await loadStaffProfile(Number(payload.user_id));
  } catch (e) {
    if (error) {
      error.textContent = e.message;
      error.classList.remove('hidden');
    }
  } finally {
    button.disabled = false;
  }
});

document.getElementById('profileAvatarForm')?.addEventListener('submit', async event => {
  event.preventDefault();
  const form = event.currentTarget;
  const error = document.getElementById('profileAvatarError');
  const button = document.getElementById('profileAvatarSaveBtn');
  error?.classList.add('hidden');
  button.disabled = true;
  button.textContent = 'Загружаем...';

  try {
    const data = new FormData(form);
    const response = await fetch('./api/profile/upload-avatar.php', {
      method: 'POST',
      credentials: 'same-origin',
      body: data
    });
    const payload = await response.json();
    if (!response.ok || payload.ok === false) throw new Error(payload.error || 'Не удалось загрузить фото.');
    form.reset();
    document.getElementById('profileAvatarUserId').value = String(activeProfileUserId || currentUser?.id || '');
    if (Number(activeProfileUserId || currentUser?.id) === Number(currentUser?.id)) {
      sidebarAvatar.style.backgroundImage = `url("${payload.avatar_url}")`;
      sidebarAvatar.classList.add('has-photo');
      sidebarAvatar.textContent = '';
    }
    await loadStaffProfile(activeProfileUserId || currentUser?.id);
  } catch (e) {
    if (error) {
      error.textContent = e.message;
      error.classList.remove('hidden');
    }
  } finally {
    button.disabled = false;
    button.textContent = 'Загрузить фото';
  }
});

document.getElementById('mailTestForm')?.addEventListener('submit', async event => {
  event.preventDefault();
  if (Number(currentUser?.is_platform_admin) !== 1) return;

  const form = event.currentTarget;
  const error = document.getElementById('mailTestError');
  const result = document.getElementById('mailTestResult');
  const button = form.querySelector('button[type="submit"]');
  const email = String(new FormData(form).get('email') || '').trim();

  error?.classList.add('hidden');
  result?.classList.add('hidden');
  button.disabled = true;
  button.textContent = 'Отправляем...';

  try {
    const response = await fetch('./api/mail/test.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email })
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) {
      throw new Error(data.error || 'Не удалось отправить тестовое письмо.');
    }
    if (result) {
      result.textContent = `${data.message || 'Письмо принято на отправку.'} Отправитель: ${data.from || 'info@urovia.ru'}.`;
      result.classList.remove('hidden');
    }
  } catch (e) {
    if (error) {
      error.textContent = e.message;
      error.classList.remove('hidden');
    }
  } finally {
    button.disabled = false;
    button.textContent = 'Отправить тестовое письмо';
  }
});

document.getElementById('profilePasswordForm')?.addEventListener('submit', async event => {
  event.preventDefault();
  const form = event.currentTarget;
  const error = document.getElementById('profilePasswordError');
  const result = document.getElementById('profilePasswordResult');
  const button = form.querySelector('button[type="submit"]');
  const payload = Object.fromEntries(new FormData(form).entries());
  error?.classList.add('hidden');
  result?.classList.add('hidden');

  if (payload.new_password !== payload.confirm_password) {
    error.textContent = 'Новые пароли не совпадают.';
    error.classList.remove('hidden');
    return;
  }

  button.disabled = true;
  button.textContent = 'Сохраняем...';
  try {
    const response = await fetch('./api/auth/change-password.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось изменить пароль.');
    form.reset();
    result.textContent = 'Пароль изменён. Другие старые сессии завершены.';
    result.classList.remove('hidden');
  } catch (e) {
    error.textContent = e.message;
    error.classList.remove('hidden');
  } finally {
    button.disabled = false;
    button.textContent = 'Изменить пароль';
  }
});

function libraryStatusLabel(status) {
  const map = {
    pending: ['amber', 'На согласовании'],
    published: ['green', 'Опубликовано'],
    rejected: ['red', 'Отклонено'],
    withdrawn: ['blue', 'Снято с публикации']
  };
  return map[String(status || '')] || ['blue', String(status || '—')];
}

function libraryAssignmentAction(item) {
  const status = String(item.library_status || '');
  if (!status || ['rejected', 'withdrawn'].includes(status)) {
    return `<button class="secondary-btn compact-btn" type="button" data-library-submit="${item.id}">◇ В библиотеку</button>`;
  }
  const [cls, label] = libraryStatusLabel(status);
  return `<span class="status ${cls}" title="Библиотека UROVIA">${label}</span>`;
}

async function submitAssignmentToLibrary(assignmentId) {
  const item = assignmentsCache.find(row => Number(row.id) === Number(assignmentId));
  if (!item) return;
  if (!(await appConfirm(`Отправить «${item.title}» в библиотеку UROVIA? Учителя отправляют материал на согласование администратору школы.`))) return;

  try {
    const response = await fetch('./api/library/request.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ assignment_id: assignmentId })
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось отправить материал.');
    alert(data.message || 'Материал отправлен.');
    await Promise.all([loadAssignments(), loadLibrary().catch(() => {})]);
  } catch (error) {
    alert(error.message);
  }
}

function renderLibrary() {
  const grid = document.getElementById('libraryGrid');
  const own = document.getElementById('libraryOwnList');
  if (!grid || !own) return;

  const query = (document.getElementById('librarySearch')?.value || '').trim().toLowerCase();
  const subjectId = Number(document.getElementById('librarySubjectFilter')?.value || 0);
  const items = libraryItemsCache.filter(item => {
    const haystack = [item.title_snapshot, item.description_snapshot, item.subject_name, item.school_name].join(' ').toLowerCase();
    return (!query || haystack.includes(query)) && (!subjectId || Number(item.subject_id) === subjectId);
  });

  grid.innerHTML = items.length ? items.map(item => {
    const ownSchool = Number(item.source_school_id) === Number(libraryActiveSchoolId);
    const author = [item.author_last_name, item.author_first_name].filter(Boolean).join(' ');
    return `
      <article class="panel library-card">
        <div class="library-card-head">
          <span class="status blue">${escapeHtml(item.subject_name || 'Без предмета')}</span>
          ${ownSchool ? '<span class="status green">Наша школа</span>' : ''}
        </div>
        <h3>${escapeHtml(item.title_snapshot)}</h3>
        <p>${escapeHtml(item.description_snapshot || 'Описание не указано.')}</p>
        <div class="library-card-meta">
          <span><b>${Number(item.questions_count_snapshot || 0)}</b> вопросов</span>
          <span>${escapeHtml(item.school_name || '')}</span>
          ${author ? `<span>${escapeHtml(author)}</span>` : ''}
        </div>
        <div class="library-card-actions">
          <button class="secondary-btn compact-btn" type="button" data-library-preview="${item.id}">Посмотреть</button>
          ${!ownSchool && libraryCanManage
            ? (Number(item.imported) === 1
              ? '<span class="status green">Уже импортировано</span>'
              : `<button class="primary-btn compact-btn" type="button" data-library-import="${item.id}">＋ В нашу школу</button>`)
            : ''}
        </div>
      </article>`;
  }).join('') : '<div class="history-empty"><b>Материалы не найдены</b><span>Измените поиск или фильтр предмета.</span></div>';

  own.innerHTML = libraryOwnCache.length ? libraryOwnCache.map(item => {
    const [cls, label] = libraryStatusLabel(item.status);
    return `
      <div class="library-own-item">
        <div><b>${escapeHtml(item.title_snapshot)}</b><span>${escapeHtml(item.subject_name || 'Без предмета')} · ${Number(item.questions_count_snapshot || 0)} вопросов</span></div>
        <div class="library-own-actions">
          <span class="status ${cls}">${label}</span>
          ${libraryCanManage && item.status === 'pending' ? `
            <button class="mini-action" type="button" data-library-moderate="${item.id}" data-action="approve">Одобрить</button>
            <button class="mini-action danger-action" type="button" data-library-moderate="${item.id}" data-action="reject">Отклонить</button>` : ''}
          ${libraryCanManage && item.status === 'published' ? `
            <button class="mini-action danger-action" type="button" data-library-moderate="${item.id}" data-action="withdraw">Снять</button>` : ''}
        </div>
      </div>`;
  }).join('') : '<p>Школа пока ничего не отправляла в библиотеку.</p>';

  grid.querySelectorAll('[data-library-preview]').forEach(button => {
    button.addEventListener('click', () => openLibraryPreview(Number(button.dataset.libraryPreview)));
  });
  grid.querySelectorAll('[data-library-import]').forEach(button => {
    button.addEventListener('click', () => importLibraryItem(Number(button.dataset.libraryImport)));
  });
  own.querySelectorAll('[data-library-moderate]').forEach(button => {
    button.addEventListener('click', () => moderateLibraryItem(
      Number(button.dataset.libraryModerate),
      String(button.dataset.action || '')
    ));
  });
}

async function loadLibrary() {
  const response = await fetch('./api/library/list.php', {
    credentials: 'same-origin',
    cache: 'no-store'
  });
  const data = await response.json();
  if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось загрузить библиотеку.');

  libraryItemsCache = data.items || [];
  libraryOwnCache = data.own_items || [];
  libraryCanManage = Boolean(data.can_manage);
  libraryActiveSchoolId = Number(data.active_school_id || 0);

  const select = document.getElementById('librarySubjectFilter');
  if (select) {
    const current = select.value;
    const subjectMap = new Map();
    [...libraryItemsCache, ...libraryOwnCache].forEach(item => {
      if (item.subject_id && item.subject_name) subjectMap.set(Number(item.subject_id), item.subject_name);
    });
    select.innerHTML = '<option value="">Все предметы</option>' + [...subjectMap.entries()]
      .sort((a, b) => String(a[1]).localeCompare(String(b[1]), 'ru'))
      .map(([id, name]) => `<option value="${id}">${escapeHtml(name)}</option>`).join('');
    if ([...subjectMap.keys()].includes(Number(current))) select.value = current;
  }
  renderLibrary();
}

async function openLibraryPreview(itemId) {
  const response = await fetch('./api/library/preview.php?item_id=' + encodeURIComponent(itemId), {
    credentials: 'same-origin',
    cache: 'no-store'
  });
  const data = await response.json();
  if (!response.ok || data.ok === false) {
    alert(data.error || 'Не удалось открыть материал.');
    return;
  }

  document.getElementById('libraryPreviewTitle').textContent = data.item.title;
  document.getElementById('libraryPreviewMeta').textContent =
    [data.item.subject_name, data.item.school_name, data.item.questions_count + ' вопросов'].filter(Boolean).join(' · ');

  const list = document.getElementById('libraryPreviewQuestions');
  list.innerHTML = (data.questions || []).length ? data.questions.map((q, index) => `
    <article class="library-preview-question">
      <span>${index + 1}</span>
      <div>
        <b>${escapeHtml(q.text)}</b>
        ${(q.options || []).length ? `<ul>${q.options.map(opt => `<li>${escapeHtml(opt.text)}</li>`).join('')}</ul>` : '<small>Открытый ответ</small>'}
      </div>
    </article>`).join('') : '<div class="history-empty">Вопросы не распознаны. Материал может содержаться в исходном файле.</div>';

  const item = libraryItemsCache.find(row => Number(row.id) === Number(itemId));
  const actions = document.getElementById('libraryPreviewActions');
  const ownSchool = Number(item?.source_school_id) === Number(libraryActiveSchoolId);
  actions.innerHTML = !ownSchool && libraryCanManage && Number(item?.imported) !== 1
    ? `<button class="primary-btn" type="button" id="libraryPreviewImportBtn">＋ Добавить в нашу школу</button>`
    : '';
  document.getElementById('libraryPreviewImportBtn')?.addEventListener('click', () => importLibraryItem(itemId));
  openModal(libraryPreviewModal);
}

async function importLibraryItem(itemId) {
  if (!libraryCanManage) return;
  if (!(await appConfirm('Импортировать материал в выбранную школу? Будет создан независимый черновик без классов, учеников и результатов.'))) return;

  try {
    const response = await fetch('./api/library/import.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ item_id: itemId })
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось импортировать материал.');
    closeModal(libraryPreviewModal);
    alert(data.message || 'Материал импортирован.');
    await Promise.all([loadLibrary(), loadAssignments(), loadSubjects()]);
  } catch (error) {
    alert(error.message);
  }
}

async function moderateLibraryItem(itemId, action) {
  const labels = { approve: 'опубликовать', reject: 'отклонить', withdraw: 'снять с публикации' };
  if (!(await appConfirm(`Подтвердить действие: ${labels[action] || action}?`))) return;
  try {
    const response = await fetch('./api/library/moderate.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ item_id: itemId, action })
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось изменить публикацию.');
    await Promise.all([loadLibrary(), loadAssignments()]);
  } catch (error) {
    alert(error.message);
  }
}

document.getElementById('librarySearch')?.addEventListener('input', renderLibrary);
document.getElementById('librarySubjectFilter')?.addEventListener('change', renderLibrary);
document.getElementById('refreshLibraryBtn')?.addEventListener('click', () => loadLibrary().catch(error => alert(error.message)));

const historyEventLabels = {
  assignment_created: 'Задание создано',
  assignment_imported: 'Задание импортировано',
  assignment_file_imported: 'Задание импортировано из файла',
  assignment_duplicated: 'Задание продублировано',
  assignment_assigned: 'Задание назначено классу',
  assignment_assigned_to_class: 'Задание назначено классу',
  assignment_updated: 'Задание изменено',
  assignment_deleted: 'Задание удалено',
  assignment_submitted_for_review: 'Задание отправлено на проверку',
  assignment_approved: 'Задание одобрено',
  assignment_review_approved: 'Задание одобрено администратором',
  assignment_review_returned: 'Задание возвращено на доработку',
  assignment_review_withdrawn: 'Задание отозвано с проверки',
  assignment_marked_ready: 'Задание подготовлено к назначению',
  assignment_reopened_as_draft: 'Задание возвращено в черновик',
  assignment_completed: 'Задание завершено',
  assignment_returned: 'Задание возвращено на доработку',
  question_created: 'Вопрос добавлен',
  question_updated: 'Вопрос изменён',
  question_deleted: 'Вопрос удалён',
  question_reordered: 'Изменён порядок вопросов',
  questions_reordered: 'Изменён порядок вопросов',
  question_image_uploaded: 'Изображение вопроса загружено',
  question_image_deleted: 'Изображение вопроса удалено',
  subject_created: 'Предмет создан',
  school_subject_added: 'Предмет добавлен в школу',
  subject_shared: 'Материалы предмета отправлены',
  school_material_transfer_sent: 'Материалы отправлены в другую школу',
  school_material_transfer_accepted: 'Полученные материалы приняты',
  school_material_transfer_rejected: 'Полученные материалы отклонены',
  material_accepted: 'Полученные материалы приняты',
  material_rejected: 'Полученные материалы отклонены',
  class_created: 'Класс создан',
  students_imported: 'Ученики импортированы',
  student_pin_reset: 'PIN ученика сброшен',
  student_profile_updated: 'Данные ученика изменены',
  class_updated: 'Данные класса изменены',
  class_deleted: 'Класс удалён полностью',
  password_recovery_requested: 'Запрошено восстановление пароля',
  password_recovery_completed: 'Пароль восстановлен по email',
  teacher_created: 'Сотрудник добавлен',
  teacher_updated: 'Профиль сотрудника изменён',
  teacher_removed: 'Сотрудник удалён из школы',
  teacher_promoted: 'Сотруднику выданы права администратора',
  teacher_granted_school_admin: 'Сотруднику выданы права администратора',
  teacher_credentials_sent: 'Доступ сотруднику отправлен',
  school_admin_updated: 'Профиль администратора изменён',
  school_admin_removed: 'Администратор удалён из школы',
  school_admin_role_removed_keep_teacher: 'Права администратора сняты',
  school_admin_granted_teacher: 'Администратору добавлена роль учителя',
  school_admin_teacher_removed: 'У администратора снята роль учителя',
  teacher_demoted: 'Права администратора сняты',
  teacher_assignments_updated: 'Назначения учителя изменены',
  teacher_access_sent: 'Доступ сотруднику отправлен',
  school_created: 'Школа создана',
  school_selected: 'Выбрана активная школа',
  school_settings_updated: 'Настройки школы изменены',
  school_branding_updated: 'Оформление школы изменено',
  school_theme_updated: 'Оформление школы изменено',
  school_assignment_review_setting_changed: 'Настройка проверки заданий изменена',
  staff_profile_updated: 'Профиль сотрудника изменён',
  staff_avatar_updated: 'Фото сотрудника изменено',
  library_item_submitted: 'Материал отправлен в библиотеку',
  library_item_published: 'Материал опубликован в библиотеке',
  library_item_rejected: 'Публикация в библиотеке отклонена',
  library_item_withdrawn: 'Материал снят с публикации',
  library_item_imported: 'Материал импортирован из библиотеки',
  assignment_deleted: 'Задание удалено',
  attempt_result_draft_updated: 'Результат ученика скорректирован',
  attempt_result_published: 'Обновлённая оценка опубликована',
  attempt_result_reset: 'Результат ученика сброшен',
  mail_test_sent: 'Отправлено тестовое письмо UROVIA'
};

const historyEntityLabels = {
  assignment: 'Задание',
  question: 'Вопрос',
  subject: 'Предмет',
  class: 'Класс',
  student: 'Ученик',
  teacher: 'Сотрудник',
  user: 'Пользователь',
  school: 'Школа',
  material: 'Материал',
  material_transfer: 'Передача материалов'
};

const historyMetadataLabels = {
  source_assignment_id: 'Исходное задание',
  assignment_id: 'Задание',
  subject_id: 'Предмет',
  class_id: 'Класс',
  teacher_id: 'Сотрудник',
  student_id: 'Ученик',
  source_school_id: 'Школа-источник',
  target_school_id: 'Школа-получатель',
  questions_count: 'Вопросов',
  students_count: 'Учеников',
  status: 'Статус',
  from_status: 'Было',
  to_status: 'Стало',
  from: 'Было',
  to: 'Стало',
  comment: 'Комментарий',
  review_required: 'Проверка администратором',
  action: 'Действие',
  title: 'Название',
  name: 'Название'
};

function historyEventLabel(eventType) {
  const type = String(eventType || '');
  if (historyEventLabels[type]) return historyEventLabels[type];

  const patterns = [
    [/assignment.*creat/i, 'Задание создано'],
    [/assignment.*duplicat/i, 'Задание продублировано'],
    [/assignment.*assign/i, 'Задание назначено'],
    [/assignment.*review/i, 'Изменён статус проверки задания'],
    [/assignment.*status|workflow/i, 'Изменён статус задания'],
    [/question.*creat/i, 'Вопрос добавлен'],
    [/question.*updat|edit/i, 'Вопрос изменён'],
    [/question.*delet/i, 'Вопрос удалён'],
    [/subject.*shar|material.*send/i, 'Материалы отправлены'],
    [/material.*accept/i, 'Материалы приняты'],
    [/material.*reject/i, 'Материалы отклонены'],
    [/class.*creat/i, 'Класс создан'],
    [/student.*import/i, 'Ученики импортированы'],
    [/teacher.*creat|user.*creat/i, 'Сотрудник добавлен'],
    [/school.*brand/i, 'Оформление школы изменено']
  ];
  const match = patterns.find(([pattern]) => pattern.test(type));
  return match ? match[1] : 'Действие в UROVIA';
}

function historyDateTime(value) {
  if (!value) return '—';
  const raw = String(value).trim();
  const date = new Date(raw.includes('T') ? raw : raw.replace(' ', 'T') + 'Z');
  if (Number.isNaN(date.getTime())) return raw;
  return date.toLocaleString('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  });
}

function historyMetadataSummary(metadata) {
  if (!metadata || typeof metadata !== 'object' || Array.isArray(metadata)) return '';
  const parts = [];
  Object.entries(metadata).forEach(([key, value]) => {
    if (value === null || value === '' || typeof value === 'object') return;
    const label = historyMetadataLabels[key] || key.replaceAll('_', ' ');
    parts.push(`<span><b>${escapeHtml(label)}:</b> ${escapeHtml(String(value))}</span>`);
  });
  return parts.slice(0, 6).join('');
}

function renderActivityHistory(data) {
  const list = document.getElementById('activityHistoryList');
  const total = document.getElementById('historyTotal');
  const scope = document.getElementById('historyScopeHint');
  if (!list) return;

  const items = Array.isArray(data?.items) ? data.items : [];
  if (total) total.textContent = `Записей: ${Number(data?.total || 0)}`;
  if (scope) {
    scope.textContent = data?.scope === 'school'
      ? 'Показаны действия сотрудников выбранной школы.'
      : 'Показаны только ваши действия.';
  }

  if (!items.length) {
    list.innerHTML = '<div class="history-empty"><b>История пока пуста</b><span>Для выбранных условий действий не найдено.</span></div>';
    return;
  }

  list.innerHTML = items.map(item => {
    const entity = item.entity_type
      ? (historyEntityLabels[item.entity_type] || item.entity_type) + (item.entity_id ? ' #' + item.entity_id : '')
      : 'Система';
    const role = roleLabels[item.actor?.role] || '';
    const details = historyMetadataSummary(item.metadata);
    return `
      <article class="history-item">
        <div class="history-marker" aria-hidden="true"></div>
        <div class="history-item-main">
          <div class="history-item-head">
            <div>
              <strong>${escapeHtml(historyEventLabel(item.event_type))}</strong>
              <span>${escapeHtml(entity)}</span>
            </div>
            <time>${escapeHtml(historyDateTime(item.created_at))}</time>
          </div>
          <div class="history-actor">
            <b>${escapeHtml(item.actor?.name || 'Системное действие')}</b>
            ${role ? `<span>${escapeHtml(role)}</span>` : ''}
          </div>
          ${details ? `<div class="history-details">${details}</div>` : ''}
          <div class="history-item-footer">
            <small class="history-event-code">${escapeHtml(item.event_type || '')}</small>
            <button class="history-delete-btn" type="button" data-delete-history="${Number(item.id)}">Удалить</button>
          </div>
        </div>
      </article>`;
  }).join('');

  list.querySelectorAll('[data-delete-history]').forEach(button => {
    button.addEventListener('click', () => deleteHistoryRecord(Number(button.dataset.deleteHistory)));
  });
}

async function deleteHistoryRecord(historyId) {
  const confirmed = await appConfirm('Удалить эту запись из истории действий?', {
    title:'Удалить запись истории', tone:'danger', okText:'Удалить'
  });
  if (!confirmed) return;
  try {
    const response = await fetch('./api/history/delete.php', {
      method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'},
      body:JSON.stringify({ history_id: historyId })
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось удалить запись.');
    await loadActivityHistory();
  } catch (error) {
    await appAlert(error.message, { title:'Не удалось удалить запись', tone:'danger' });
  }
}

async function clearActivityHistory() {
  const confirmed = await appConfirm('Очистить доступную историю действий? Восстановить её через интерфейс будет нельзя.', {
    title:'Очистить историю', tone:'danger', okText:'Очистить историю'
  });
  if (!confirmed) return;
  try {
    const response = await fetch('./api/history/clear.php', {
      method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body:'{}'
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось очистить историю.');
    await loadActivityHistory();
    await appAlert('Удалено записей: ' + Number(data.deleted || 0) + '.', { title:'История очищена', tone:'success', okText:'Готово' });
  } catch (error) {
    await appAlert(error.message, { title:'Не удалось очистить историю', tone:'danger' });
  }
}

async function loadActivityHistory() {
  const list = document.getElementById('activityHistoryList');
  if (!list) return;

  list.innerHTML = '<div class="history-empty">Загрузка истории действий...</div>';
  const params = new URLSearchParams({ limit: '100' });
  const search = document.getElementById('historySearch')?.value.trim() || '';
  const entityType = document.getElementById('historyEntityFilter')?.value || '';
  if (search) params.set('q', search);
  if (entityType) params.set('entity_type', entityType);

  const response = await fetch('./api/history/list.php?' + params.toString(), {
    credentials: 'same-origin',
    cache: 'no-store'
  });
  const data = await response.json();
  if (!response.ok || data.ok === false) {
    list.innerHTML = `<div class="history-empty"><b>Не удалось загрузить историю</b><span>${escapeHtml(data.error || 'Попробуйте ещё раз.')}</span></div>`;
    return;
  }
  renderActivityHistory(data);
}

document.getElementById('historyFilters')?.addEventListener('submit', event => {
  event.preventDefault();
  loadActivityHistory().catch(() => {});
});

document.getElementById('historyEntityFilter')?.addEventListener('change', () => {
  loadActivityHistory().catch(() => {});
});

document.getElementById('refreshHistoryBtn')?.addEventListener('click', () => {
  loadActivityHistory().catch(() => {});
});
document.getElementById('clearHistoryBtn')?.addEventListener('click', () => {
  clearActivityHistory().catch(() => {});
});


function dashboardDueLabel(value) {
  if (!value) return ['Без срока', 'blue'];
  const date = new Date(String(value).replace(' ', 'T') + (String(value).includes('Z') ? '' : 'Z'));
  if (Number.isNaN(date.getTime())) return [String(value), 'blue'];
  const now = new Date();
  const diff = date.getTime() - now.getTime();
  const cls = diff <= 48 * 60 * 60 * 1000 ? 'amber' : 'blue';
  return ['до ' + date.toLocaleDateString('ru-RU', { day: '2-digit', month: 'short' }), cls];
}

function renderTeacherDashboardEmpty(message = 'Нет данных') {
  const tasks = document.getElementById('teacherDashboardTasks');
  const classes = document.getElementById('teacherDashboardClasses');
  if (tasks) tasks.innerHTML = '<div class="dashboard-empty">' + escapeHtml(message) + '</div>';
  if (classes) classes.innerHTML = '<div class="dashboard-empty">' + escapeHtml(message) + '</div>';
}

async function loadTeacherDashboard() {
  if (currentUser?.role === 'student') return;

  const tasks = document.getElementById('teacherDashboardTasks');
  const classes = document.getElementById('teacherDashboardClasses');
  if (tasks) tasks.innerHTML = '<div class="dashboard-empty">Загрузка активных заданий...</div>';
  if (classes) classes.innerHTML = '<div class="dashboard-empty">Загрузка классов...</div>';

  try {
    const response = await fetch('./api/teacher/dashboard.php', {
      credentials: 'same-origin',
      cache: 'no-store'
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось загрузить главную страницу.');

    const stats = data.stats || {};
    const students = Number(stats.students || 0);
    const classesCount = Number(stats.classes || 0);
    const activeAssignments = Number(stats.active_assignments || 0);
    const dueThisWeek = Number(stats.due_this_week || 0);
    const submitted = Number(stats.submitted || 0);
    const last7 = Number(stats.submitted_last_7_days || 0);
    const avgPercent = stats.average_percent === null || stats.average_percent === undefined
      ? null
      : Number(stats.average_percent);
    const avgGrade = stats.average_grade === null || stats.average_grade === undefined
      ? null
      : Number(stats.average_grade);

    const year = document.getElementById('dashboardAcademicYear');
    if (year) year.textContent = data.academic_year || 'Учебный год';
    document.getElementById('dashboardStudents').textContent = String(students);
    document.getElementById('dashboardStudentsHint').textContent = 'в ' + classesCount + ' классах';
    document.getElementById('dashboardActiveAssignments').textContent = String(activeAssignments);
    document.getElementById('dashboardActiveHint').textContent = dueThisWeek
      ? dueThisWeek + ' со сроком в ближайшие 7 дней'
      : 'нет сроков на ближайшие 7 дней';
    document.getElementById('dashboardSubmitted').textContent = String(submitted);
    document.getElementById('dashboardSubmittedHint').textContent = last7
      ? '+' + last7 + ' за последние 7 дней'
      : 'за последние 7 дней новых нет';
    document.getElementById('dashboardAverage').textContent = avgPercent === null ? '—' : Math.round(avgPercent) + '%';
    document.getElementById('dashboardAverageHint').textContent = avgGrade === null
      ? 'пока нет оценённых работ'
      : 'средняя оценка ' + avgGrade.toLocaleString('ru-RU', { maximumFractionDigits: 1 });

    const rows = data.active_assignments || [];
    if (tasks) {
      tasks.innerHTML = rows.length ? rows.map(item => {
        const [deadline, deadlineClass] = dashboardDueLabel(item.due_at);
        const initial = String(item.subject_name || 'З').trim().charAt(0).toUpperCase() || 'З';
        return `
          <div class="task-row">
            <div class="subject-icon">${escapeHtml(initial)}</div>
            <div class="task-main">
              <strong>${escapeHtml(item.title)}</strong>
              <span>${escapeHtml(item.subject_name || 'Без предмета')}${item.class_names ? ' · ' + escapeHtml(item.class_names) : ''}</span>
            </div>
            <div class="task-progress">
              <strong>${Number(item.submitted_students || 0)}/${Number(item.target_students || 0)}</strong>
              <span>сдали</span>
            </div>
            <span class="status ${deadlineClass}">${escapeHtml(deadline)}</span>
          </div>`;
      }).join('') : '<div class="dashboard-empty"><b>Активных заданий нет</b><span>После назначения работы классу она появится здесь.</span></div>';
    }

    const classRows = data.classes || [];
    if (classes) {
      classes.innerHTML = classRows.length ? classRows.map(item => {
        const average = item.average_percent === null || item.average_percent === undefined
          ? '—'
          : Math.round(Number(item.average_percent)) + '%';
        return `
          <button class="class-card" type="button" data-dashboard-class="${Number(item.id)}">
            <strong>${escapeHtml(item.name)}</strong>
            <span>${Number(item.students_count || 0)} учеников</span>
            <b>${escapeHtml(average)}</b>
          </button>`;
      }).join('') : '<div class="dashboard-empty"><b>Классов пока нет</b><span>Создайте класс или назначьте его учителю.</span></div>';

      classes.querySelectorAll('[data-dashboard-class]').forEach(button => {
        button.addEventListener('click', () => {
          showView('classes');
          loadClasses();
        });
      });
    }
  } catch (error) {
    ['dashboardStudents','dashboardActiveAssignments','dashboardSubmitted','dashboardAverage'].forEach(id => {
      const node = document.getElementById(id);
      if (node) node.textContent = '—';
    });
    renderTeacherDashboardEmpty(error.message);
  }
}


function resultDateTime(value) {
  if (!value) return '—';
  const date = new Date(String(value).replace(' ', 'T') + (String(value).includes('Z') ? '' : 'Z'));
  return Number.isNaN(date.getTime())
    ? String(value)
    : date.toLocaleString('ru-RU', { day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit' });
}

function resultGradeClass(grade) {
  const value = String(grade || '');
  return ['2','3','4','5'].includes(value) ? 'grade-' + value : '';
}

function resultGradeFromPercent(percent) {
  const value = Math.max(0, Math.min(100, Number(percent || 0)));
  if (value >= 90) return '5';
  if (value >= 75) return '4';
  if (value >= 50) return '3';
  return '2';
}

function resultPercentClass(percent) {
  const value = Math.max(0, Math.min(100, Math.round(Number(percent || 0))));
  if (value < 50) return 'result-percent-low';
  if (value < 60) return 'result-percent-warn';
  if (value < 75) return 'result-percent-mid';
  return 'result-percent-high';
}

function journalFormatNumber(value, digits = 1) {
  const number = Number(value);
  if (!Number.isFinite(number)) return '—';
  return number.toLocaleString('ru-RU', {
    minimumFractionDigits: Number.isInteger(number) ? 0 : digits,
    maximumFractionDigits: digits
  });
}

function journalShortDate(value) {
  if (!value) return '';
  const date = new Date(String(value).replace(' ', 'T'));
  if (Number.isNaN(date.getTime())) return '';
  return date.toLocaleDateString('ru-RU', { day:'2-digit', month:'2-digit' });
}

function journalAvailableClasses() {
  const map = new Map();
  teacherOptionsCache.forEach(item => {
    const id = Number(item.class_id || 0);
    if (id > 0 && !map.has(id)) map.set(id, { id, name:String(item.class_name || '') });
  });
  return [...map.values()].sort((a,b) => a.name.localeCompare(b.name, 'ru'));
}

function journalAvailableSubjects(classId) {
  const map = new Map();
  teacherOptionsCache
    .filter(item => Number(item.class_id) === Number(classId))
    .forEach(item => {
      const id = Number(item.subject_id || 0);
      if (id > 0 && !map.has(id)) map.set(id, { id, name:String(item.subject_name || '') });
    });
  return [...map.values()].sort((a,b) => a.name.localeCompare(b.name, 'ru'));
}

function populateJournalClassFilter() {
  const select = document.getElementById('journalClassFilter');
  if (!select) return;
  const previous = Number(select.value || 0);
  const classes = journalAvailableClasses();
  select.innerHTML = classes.length
    ? classes.map(item => `<option value="${item.id}">${escapeHtml(item.name)}</option>`).join('')
    : '<option value="">Нет доступных классов</option>';
  select.value = classes.some(item => item.id === previous)
    ? String(previous)
    : String(classes[0]?.id || '');
}

function populateJournalSubjectFilter() {
  const classId = Number(document.getElementById('journalClassFilter')?.value || 0);
  const select = document.getElementById('journalSubjectFilter');
  if (!select) return;
  const previous = Number(select.value || 0);
  const subjects = journalAvailableSubjects(classId);
  select.innerHTML = subjects.length
    ? subjects.map(item => `<option value="${item.id}">${escapeHtml(item.name)}</option>`).join('')
    : '<option value="">Нет доступных предметов</option>';
  select.value = subjects.some(item => item.id === previous)
    ? String(previous)
    : String(subjects[0]?.id || '');
}

function renderJournal() {
  const wrap = document.getElementById('journalTableWrap');
  const summary = document.getElementById('journalSummary');
  if (!wrap || !journalDataCache) return;

  const assignmentFilter = Number(document.getElementById('journalAssignmentFilter')?.value || 0);
  const assignments = (journalDataCache.assignments || []).filter(item =>
    !assignmentFilter || Number(item.id) === assignmentFilter
  );
  const students = journalDataCache.students || [];

  let completed = 0;
  let expected = students.length * assignments.length;
  let percentSum = 0;
  let gradeSum = 0;

  students.forEach(student => {
    assignments.forEach(assignment => {
      const cell = student.cells?.[String(assignment.id)] || null;
      if (!cell) return;
      completed++;
      percentSum += Number(cell.percent || 0);
      gradeSum += Number(cell.grade || 0);
    });
  });

  const avgPercent = completed ? percentSum / completed : null;
  const avgGrade = completed ? gradeSum / completed : null;
  const completion = expected ? (completed / expected) * 100 : 0;

  const studentsNode = document.getElementById('journalStudentsCount');
  const assignmentsNode = document.getElementById('journalAssignmentsCount');
  const averageNode = document.getElementById('journalAveragePercent');
  const completionNode = document.getElementById('journalCompletion');
  if (studentsNode) studentsNode.textContent = String(students.length);
  if (assignmentsNode) assignmentsNode.textContent = String(assignments.length);
  if (averageNode) averageNode.textContent = avgPercent === null ? '—' : Math.round(avgPercent) + '%';
  if (completionNode) completionNode.textContent = Math.round(completion) + '%';

  const className = journalDataCache.class?.name || 'Класс';
  const subjectName = journalDataCache.subject?.name || 'Предмет';
  const period = Number(journalDataCache.period || 0);
  if (summary) {
    summary.innerHTML = '<b>' + escapeHtml(className) + '</b> · ' + escapeHtml(subjectName)
      + ' · <span>' + assignments.length + ' работ</span>'
      + ' · <span>' + students.length + ' учеников</span>'
      + (period ? ' · <span>за ' + period + ' дней</span>' : ' · <span>за всё время</span>')
      + (avgGrade !== null ? ' · <span>средняя оценка ' + journalFormatNumber(avgGrade) + '</span>' : '');
  }

  if (!students.length) {
    wrap.innerHTML = '<div class="history-empty"><b>В классе нет учеников</b><span>Добавьте учеников в класс, чтобы вести журнал.</span></div>';
    return;
  }
  if (!assignments.length) {
    wrap.innerHTML = '<div class="history-empty"><b>Нет работ за выбранный период</b><span>Измените период или назначьте классу задание по этому предмету.</span></div>';
    return;
  }

  const headerCells = assignments.map(item => {
    const date = journalShortDate(item.due_at || item.created_at);
    return `<th class="journal-assignment-head" title="${escapeHtml(item.title)}">
      <span>${escapeHtml(item.title)}</span>
      <small>${escapeHtml(date)}</small>
    </th>`;
  }).join('');

  const rows = students.map(student => {
    const visibleCells = assignments.map(assignment => student.cells?.[String(assignment.id)] || null);
    const percents = visibleCells.filter(Boolean).map(cell => Number(cell.percent || 0));
    const grades = visibleCells.filter(Boolean).map(cell => Number(cell.grade || 0));
    const rowAvgPercent = percents.length ? percents.reduce((sum,v) => sum + v, 0) / percents.length : null;
    const rowAvgGrade = grades.length ? grades.reduce((sum,v) => sum + v, 0) / grades.length : null;
    const actionCell = visibleCells.find(Boolean) || null;
    const actionScope = assignmentFilter ? 'работы' : 'последней работы';
    const actionButtons = actionCell
      ? '<div class="journal-row-actions">'
        + '<button class="result-icon-btn" type="button" data-journal-action-review="' + Number(actionCell.attempt_id) + '" data-tooltip="Разбор ' + actionScope + '" title="Разбор ' + actionScope + '" aria-label="Разбор ' + actionScope + '">' + resultActionIcon('review') + '</button>'
        + '<button class="result-icon-btn" type="button" data-journal-action-edit="' + Number(actionCell.attempt_id) + '" data-tooltip="Редактировать ' + actionScope + '" title="Редактировать ' + actionScope + '" aria-label="Редактировать ' + actionScope + '">' + resultActionIcon('edit') + '</button>'
        + '<button class="result-icon-btn" type="button" data-journal-action-reset="' + Number(actionCell.attempt_id) + '" data-tooltip="Сбросить ' + actionScope + '" title="Сбросить ' + actionScope + '" aria-label="Сбросить ' + actionScope + '">' + resultActionIcon('reset') + '</button>'
        + '</div>'
      : '<span class="journal-actions-empty">—</span>';
    const browserClosedCount = visibleCells.filter(cell => Boolean(cell?.closed_by_browser)).length;
    const browserClosedMarker = browserClosedCount
      ? '<span class="journal-browser-closed-marker" title="Закрытие или скрытие браузера: ' + browserClosedCount + '" aria-label="Закрытие браузера: ' + browserClosedCount + '"></span>'
      : '<span class="journal-status-empty" title="Закрытий браузера нет">—</span>';

    const cells = visibleCells.map(cell => {
      if (!cell) return '<td class="journal-result-cell"><span class="journal-missing" title="Работа не сдана">—</span></td>';
      const grade = String(cell.grade || resultGradeFromPercent(cell.percent));
      const flags = cell.adjusted ? '<i title="Оценка скорректирована">●</i>' : '';
      return `<td class="journal-result-cell">
        <button class="journal-grade-cell ${resultGradeClass(grade)}" type="button"
          data-journal-review="${Number(cell.attempt_id)}"
          title="${escapeHtml(journalFormatNumber(cell.score,2))} / ${escapeHtml(journalFormatNumber(cell.max_score,2))} балл. · ${Math.round(Number(cell.percent || 0))}%">
          <strong>${escapeHtml(grade)}</strong>
          <small>${Math.round(Number(cell.percent || 0))}%</small>
          <span class="journal-cell-flags">${flags}</span>
        </button>
      </td>`;
    }).join('');

    return `<tr>
      <td class="journal-student-cell"><b>${escapeHtml(student.student_name || 'Ученик')}</b><small>${visibleCells.filter(Boolean).length} из ${assignments.length} работ</small></td>
      ${cells}
      <td class="journal-average-cell"><span class="grade ${rowAvgGrade === null ? '' : resultGradeClass(Math.round(rowAvgGrade))}">${rowAvgGrade === null ? '—' : journalFormatNumber(rowAvgGrade)}</span></td>
      <td class="journal-average-percent">${rowAvgPercent === null ? '—' : Math.round(rowAvgPercent) + '%'}</td>
      <td class="journal-browser-column">${browserClosedMarker}</td>
      <td class="journal-actions-column">${actionButtons}</td>
    </tr>`;
  }).join('');

  const mobileRows = students.map(student => {
    const visibleCells = assignments.map(assignment => ({
      assignment,
      cell: student.cells?.[String(assignment.id)] || null
    }));
    const completedCells = visibleCells.filter(item => Boolean(item.cell));
    const percents = completedCells.map(item => Number(item.cell.percent || 0));
    const grades = completedCells.map(item => Number(item.cell.grade || 0));
    const avgPercent = percents.length ? percents.reduce((sum,value) => sum + value, 0) / percents.length : null;
    const avgGrade = grades.length ? grades.reduce((sum,value) => sum + value, 0) / grades.length : null;
    const browserClosedCount = completedCells.filter(item => Boolean(item.cell.closed_by_browser)).length;

    const workItems = visibleCells.map(({ assignment, cell }) => {
      const date = journalShortDate(assignment.due_at || assignment.created_at);
      if (!cell) {
        return '<article class="journal-mobile-work is-missing">'
          + '<div class="journal-mobile-work-main">'
          + '<div class="journal-mobile-work-copy"><b>' + escapeHtml(assignment.title) + '</b><small>' + escapeHtml(date) + '</small></div>'
          + '<span class="journal-mobile-not-done">Не сдано</span>'
          + '</div>'
          + '</article>';
      }

      const grade = String(cell.grade || resultGradeFromPercent(cell.percent));
      const percent = Math.round(Number(cell.percent || 0));
      const browserMarker = cell.closed_by_browser
        ? '<span class="journal-mobile-browser-marker" title="Закрытие или скрытие браузера" aria-label="Закрытие браузера"></span>'
        : '';
      const adjustedMarker = cell.adjusted
        ? '<span class="journal-mobile-adjusted" title="Оценка скорректирована">●</span>'
        : '';

      return '<article class="journal-mobile-work">'
        + '<div class="journal-mobile-work-main">'
        + '<div class="journal-mobile-work-copy"><b>' + escapeHtml(assignment.title) + '</b><small>' + escapeHtml(date) + '</small></div>'
        + '<button class="journal-mobile-grade ' + resultGradeClass(grade) + '" type="button" data-journal-review="' + Number(cell.attempt_id) + '" title="Открыть разбор">'
        + '<strong>' + escapeHtml(grade) + '</strong><span>' + percent + '%</span>'
        + '</button>'
        + '</div>'
        + '<div class="journal-mobile-work-meta">'
        + '<span><b>' + escapeHtml(journalFormatNumber(cell.score, 2)) + '</b> / ' + escapeHtml(journalFormatNumber(cell.max_score, 2)) + ' балл.</span>'
        + (browserMarker ? '<span class="journal-mobile-browser-label">' + browserMarker + ' выход из браузера</span>' : '<span class="journal-mobile-browser-ok">Без выхода</span>')
        + adjustedMarker
        + '</div>'
        + '<div class="journal-mobile-actions">'
        + '<button class="result-icon-btn" type="button" data-journal-action-review="' + Number(cell.attempt_id) + '" data-tooltip="Разбор" title="Разбор работы" aria-label="Разбор работы">' + resultActionIcon('review') + '</button>'
        + '<button class="result-icon-btn" type="button" data-journal-action-edit="' + Number(cell.attempt_id) + '" data-tooltip="Редактировать" title="Редактировать результат" aria-label="Редактировать результат">' + resultActionIcon('edit') + '</button>'
        + '<button class="result-icon-btn" type="button" data-journal-action-reset="' + Number(cell.attempt_id) + '" data-tooltip="Сбросить" title="Сбросить результат" aria-label="Сбросить результат">' + resultActionIcon('reset') + '</button>'
        + '</div>'
        + '</article>';
    }).join('');

    const avgGradeText = avgGrade === null ? '—' : journalFormatNumber(avgGrade);
    const avgPercentText = avgPercent === null ? '—' : Math.round(avgPercent) + '%';
    const avgGradeClass = avgGrade === null ? '' : resultGradeClass(Math.round(avgGrade));

    return '<details class="journal-mobile-student">'
      + '<summary>'
      + '<div class="journal-mobile-student-copy"><b>' + escapeHtml(student.student_name || 'Ученик') + '</b><small>' + completedCells.length + ' из ' + assignments.length + ' работ</small></div>'
      + '<div class="journal-mobile-student-stats">'
      + '<span class="grade ' + avgGradeClass + '">' + escapeHtml(avgGradeText) + '</span>'
      + '<strong>' + escapeHtml(avgPercentText) + '</strong>'
      + (browserClosedCount ? '<span class="journal-mobile-exit-count" title="Выходы из браузера">' + browserClosedCount + '</span>' : '')
      + '<span class="journal-mobile-chevron" aria-hidden="true"></span>'
      + '</div>'
      + '</summary>'
      + '<div class="journal-mobile-student-body">' + workItems + '</div>'
      + '</details>';
  }).join('');

    wrap.innerHTML = `
    <div class="journal-desktop-view">
      <table class="journal-table">
        <thead><tr>
          <th class="journal-student-head">Ученик</th>
          ${headerCells}
          <th class="journal-average-head">Ср. оценка</th>
          <th class="journal-average-head">Ср. %</th>
          <th class="journal-browser-head" title="Закрытие или скрытие браузера во время теста">Выход</th>
          <th class="journal-actions-head">Действия</th>
        </tr></thead>
        <tbody>${rows}</tbody>
      </table>
    </div>
    <div class="journal-mobile-view">${mobileRows}</div>`;

  wrap.querySelectorAll('[data-journal-review]').forEach(button => {
    button.addEventListener('click', () => openAttemptReview(Number(button.dataset.journalReview)));
  });
  wrap.querySelectorAll('[data-journal-action-review]').forEach(button => {
    button.addEventListener('click', () => openAttemptReview(Number(button.dataset.journalActionReview)));
  });
  wrap.querySelectorAll('[data-journal-action-edit]').forEach(button => {
    button.addEventListener('click', async () => {
      const attemptId = Number(button.dataset.journalActionEdit || 0);
      if (!resultsCache.some(item => Number(item.attempt_id) === attemptId)) {
        await loadResults().catch(() => {});
      }
      openResultEditor(attemptId);
    });
  });
  wrap.querySelectorAll('[data-journal-action-reset]').forEach(button => {
    button.addEventListener('click', async () => {
      const attemptId = Number(button.dataset.journalActionReset || 0);
      if (!resultsCache.some(item => Number(item.attempt_id) === attemptId)) {
        await loadResults().catch(() => {});
      }
      await resetStudentResult(attemptId);
    });
  });
  wrap.querySelectorAll('.journal-mobile-student').forEach(details => {
    details.addEventListener('toggle', () => {
      if (!details.open) return;
      wrap.querySelectorAll('.journal-mobile-student[open]').forEach(other => {
        if (other !== details) other.open = false;
      });
      requestAnimationFrame(() => details.scrollIntoView({ block:'nearest', behavior:'smooth' }));
    });
  });
}

async function loadJournal() {
  const wrap = document.getElementById('journalTableWrap');
  const summary = document.getElementById('journalSummary');
  if (!wrap) return;

  try {
    if (!teacherOptionsCache.length) await loadTeacherOptions();
    populateJournalClassFilter();
    populateJournalSubjectFilter();

    const classId = Number(document.getElementById('journalClassFilter')?.value || 0);
    const subjectId = Number(document.getElementById('journalSubjectFilter')?.value || 0);
    const period = Number(document.getElementById('journalPeriodFilter')?.value || 90);

    if (!classId || !subjectId) {
      journalDataCache = null;
      if (summary) summary.textContent = 'Для журнала нужен назначенный вам класс и предмет.';
      wrap.innerHTML = '<div class="history-empty"><b>Нет доступных назначений</b><span>Проверьте предметы и классы сотрудника.</span></div>';
      return;
    }

    if (summary) summary.textContent = 'Загрузка журнала...';
    wrap.innerHTML = '<div class="history-empty"><span>Загружаем оценки класса...</span></div>';

    const params = new URLSearchParams({
      class_id:String(classId),
      subject_id:String(subjectId),
      period:String(period)
    });
    const response = await fetch('./api/journal/list.php?' + params.toString(), {
      credentials:'same-origin',
      cache:'no-store'
    });
    const data = await readJsonResponse(response, 'Не удалось загрузить журнал.');
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось загрузить журнал.');

    journalDataCache = data;
    const assignmentSelect = document.getElementById('journalAssignmentFilter');
    if (assignmentSelect) {
      const previous = Number(assignmentSelect.value || 0);
      const assignments = data.assignments || [];
      assignmentSelect.innerHTML = '<option value="">Все работы</option>' + assignments.map(item =>
        `<option value="${Number(item.id)}">${escapeHtml(item.title)}</option>`
      ).join('');
      assignmentSelect.value = assignments.some(item => Number(item.id) === previous) ? String(previous) : '';
    }
    renderJournal();
  } catch (error) {
    journalDataCache = null;
    if (summary) summary.textContent = error.message;
    wrap.innerHTML = '<div class="history-empty"><b>Не удалось загрузить журнал</b><span>' + escapeHtml(error.message) + '</span></div>';
  }
}

function exportJournalCsv() {
  if (!journalDataCache) return;
  const assignmentFilter = Number(document.getElementById('journalAssignmentFilter')?.value || 0);
  const assignments = (journalDataCache.assignments || []).filter(item =>
    !assignmentFilter || Number(item.id) === assignmentFilter
  );
  const header = ['Ученик', ...assignments.map(item => item.title), 'Средняя оценка', 'Средний %', 'Закрытие браузера'];
  const lines = [header];

  (journalDataCache.students || []).forEach(student => {
    const cells = assignments.map(assignment => student.cells?.[String(assignment.id)] || null);
    const grades = cells.map(cell => cell ? String(cell.grade) : '');
    const valid = cells.filter(Boolean);
    const avgGrade = valid.length
      ? valid.reduce((sum,cell) => sum + Number(cell.grade || 0),0) / valid.length
      : '';
    const avgPercent = valid.length
      ? valid.reduce((sum,cell) => sum + Number(cell.percent || 0),0) / valid.length
      : '';
    const browserClosedCount = cells.filter(cell => Boolean(cell?.closed_by_browser)).length;
    lines.push([
      student.student_name || '',
      ...grades,
      avgGrade === '' ? '' : journalFormatNumber(avgGrade),
      avgPercent === '' ? '' : Math.round(avgPercent) + '%',
      browserClosedCount ? String(browserClosedCount) : ''
    ]);
  });

  const csv = lines.map(row => row.map(value =>
    '"' + String(value ?? '').replace(/"/g,'""') + '"'
  ).join(';')).join('\n');
  const blob = new Blob(['\ufeff' + csv], { type:'text/csv;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = 'urovia-journal.csv';
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

function resultClosedByBrowser(item) {
  return Boolean(item?.closed_by_browser)
    || ['page_hidden', 'page_closed', 'browser_closed'].includes(String(item?.termination_reason || ''));
}

function resultBrowserCloseBadge() {
  return '<span class="result-close-badge" title="З/Б · попытка завершена из-за закрытия или скрытия браузера/вкладки" aria-label="Завершено браузером">' +
    '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="5" width="17" height="14" rx="2.5"/><path d="M3.5 9h17M9 12.2l6 5.6M15 12.2l-6 5.6"/></svg>' +
    '<span>З/Б</span>' +
    '</span>';
}

function resultStatusAutoBadge() {
  return '<span class="result-status-icon result-status-auto" title="Автоматически проверено" aria-label="Автоматически проверено">A</span>';
}

function resultStatusPublishedBadge(revision = 1) {
  const version = Math.max(1, Number(revision || 1));
  return '<span class="result-status-icon result-status-published" title="Опубликовано · версия ' + version + '" aria-label="Опубликовано">' +
    '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.2 4.2L19 7"/></svg>' +
    '</span>';
}

function resultActionIcon(type) {
  const icons = {
    review: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.3"/><path d="M15.6 15.6L21 21"/></svg>',
    edit: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13.5 5H6.8A2.8 2.8 0 004 7.8v9.4A2.8 2.8 0 006.8 20h9.4a2.8 2.8 0 002.8-2.8v-6.7"/><path d="M14.8 4.2l5 5-8.9 8.9-5.8 1.2 1.2-5.8 8.5-9.3z"/><path d="M13.2 5.9l5 5"/></svg>',
    reset: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4.7 8.2A8 8 0 1112 20a8 8 0 01-7.4-5"/><path d="M4.5 3.8v5h5"/></svg>'
  };
  return icons[type] || '';
}

function attemptReviewStatusMeta(status) {
  const map = {
    correct: { label:'Правильно', icon:'✓', className:'correct' },
    incorrect: { label:'Неправильно', icon:'×', className:'incorrect' },
    unanswered: { label:'Нет ответа', icon:'—', className:'unanswered' },
    review: { label:'Нужна проверка', icon:'?', className:'review' }
  };
  return map[String(status || '')] || map.unanswered;
}

function attemptReviewAnswerList(values, emptyText = 'Нет ответа') {
  const list = Array.isArray(values) ? values.filter(value => String(value || '').trim() !== '') : [];
  if (!list.length) return `<span class="attempt-review-empty">${escapeHtml(emptyText)}</span>`;
  return `<ol class="attempt-review-answer-list">${list.map(value => `<li>${escapeHtml(String(value))}</li>`).join('')}</ol>`;
}

function renderAttemptReviewQuestion(question, index) {
  const meta = attemptReviewStatusMeta(question.status);
  const earned = Number(question.earned_points || 0).toLocaleString('ru-RU');
  const max = Number(question.points || 0).toLocaleString('ru-RU');
  const assets = Array.isArray(question.assets) ? question.assets : [];
  const assetHtml = assets.map(asset =>
    `<img class="attempt-review-image" src="${escapeHtml(asset.url || '')}" alt="${escapeHtml(asset.original_name || 'Изображение к вопросу')}" loading="lazy">`
  ).join('');

  let answerHtml = '';

  if (['single','multiple','true_false'].includes(String(question.type))) {
    const options = Array.isArray(question.options) ? question.options : [];
    answerHtml = `
      <div class="attempt-review-options">
        ${options.map(option => {
          const selected = Boolean(option.selected);
          const correct = Boolean(option.correct);
          const classes = [
            'attempt-review-option',
            selected ? 'selected' : '',
            correct ? 'correct' : '',
            selected && !correct ? 'wrong-selected' : ''
          ].filter(Boolean).join(' ');
          const badges = [
            selected ? '<span class="attempt-review-option-badge student">Ответ ученика</span>' : '',
            correct ? '<span class="attempt-review-option-badge correct">Правильный</span>' : ''
          ].join('');
          return `<div class="${classes}">
            <span class="attempt-review-option-mark">${correct ? '✓' : (selected ? '×' : '')}</span>
            <span class="attempt-review-option-text">${escapeHtml(option.text || '')}</span>
            <span class="attempt-review-option-badges">${badges}</span>
          </div>`;
        }).join('')}
      </div>`;
  } else if (String(question.type) === 'matching') {
    const rows = Array.isArray(question.matching) ? question.matching : [];
    answerHtml = `
      <div class="attempt-review-matching">
        ${rows.map(row => `
          <div class="attempt-review-match-row ${row.is_correct ? 'correct' : 'incorrect'}">
            <div><span>Элемент</span><b>${escapeHtml(row.left || '')}</b></div>
            <div><span>Ответ ученика</span><b>${escapeHtml(row.student || 'Нет ответа')}</b></div>
            <div><span>Правильно</span><b>${escapeHtml(row.correct || '—')}</b></div>
          </div>`).join('')}
      </div>`;
  } else if (String(question.type) === 'order') {
    answerHtml = `
      <div class="attempt-review-compare">
        <div>
          <span class="attempt-review-compare-label">Ответ ученика</span>
          ${attemptReviewAnswerList(question.student_answer)}
        </div>
        <div>
          <span class="attempt-review-compare-label">Правильный порядок</span>
          ${attemptReviewAnswerList(question.correct_answer, 'Ключ не задан')}
        </div>
      </div>`;
  } else {
    const studentText = Array.isArray(question.student_answer)
      ? question.student_answer.join(' · ')
      : String(question.student_answer || '');
    const correctValues = Array.isArray(question.correct_answer) ? question.correct_answer : [];
    answerHtml = `
      <div class="attempt-review-compare">
        <div>
          <span class="attempt-review-compare-label">Ответ ученика</span>
          <div class="attempt-review-text-answer ${studentText ? '' : 'empty'}">${escapeHtml(studentText || 'Нет ответа')}</div>
        </div>
        <div>
          <span class="attempt-review-compare-label">${question.needs_review ? 'Ориентир / ключ' : 'Правильный ответ'}</span>
          ${correctValues.length
            ? `<div class="attempt-review-text-answer correct">${correctValues.map(value => escapeHtml(String(value))).join('<br>')}</div>`
            : '<div class="attempt-review-text-answer neutral">Проверяется учителем</div>'}
        </div>
      </div>`;
  }

  return `
    <article class="attempt-review-question ${meta.className}">
      <div class="attempt-review-question-head">
        <div>
          <span class="attempt-review-number">Вопрос ${index + 1}</span>
          <span class="attempt-review-type">${escapeHtml(question.type_label || 'Вопрос')}</span>
        </div>
        <div class="attempt-review-question-result">
          <span class="attempt-review-status ${meta.className}">${meta.icon} ${meta.label}</span>
          <b>${earned} / ${max} балл.</b>
        </div>
      </div>
      <h3>${escapeHtml(question.text || '')}</h3>
      ${question.original_text ? `<div class="attempt-review-original"><span>Исходный текст</span>${escapeHtml(question.original_text)}</div>` : ''}
      ${assetHtml}
      ${answerHtml}
    </article>`;
}

async function openAttemptReview(attemptId) {
  if (currentUser?.role === 'student') {
    return;
  }
  const id = Number(attemptId || 0);
  if (!id || !attemptReviewModal) return;

  const content = document.getElementById('attemptReviewContent');
  if (!content) return;
  content.innerHTML = '<div class="attempt-review-loading">Загрузка разбора работы...</div>';
  openModal(attemptReviewModal);

  try {
    const response = await fetch('./api/results/review.php?attempt_id=' + encodeURIComponent(id), {
      credentials:'same-origin',
      cache:'no-store'
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) {
      throw new Error(data.error || 'Не удалось открыть разбор работы.');
    }

    const attempt = data.attempt || {};
    const summary = data.summary || {};
    const questions = Array.isArray(data.questions) ? data.questions : [];
    const studentMeta = attempt.student_name
      ? `<span>${escapeHtml(attempt.student_name)}${attempt.class_name ? ' · ' + escapeHtml(attempt.class_name) : ''}</span>`
      : '';
    const adjusted = attempt.adjusted
      ? '<span class="attempt-review-adjusted">Оценка скорректирована учителем</span>'
      : '';
    const browserClosed = resultClosedByBrowser(attempt)
      ? resultBrowserCloseBadge()
      : '';
    const staffActions = currentUser?.role !== 'student' && resultsCache.some(item => Number(item.attempt_id) === id)
      ? `<button class="secondary-btn" type="button" data-review-edit="${id}">Редактировать оценку</button>`
      : '';

    content.innerHTML = `
      <div class="attempt-review-head">
        <span class="section-kicker">Разбор выполненной работы</span>
        <h2>${escapeHtml(attempt.assignment_title || 'Результат')}</h2>
        <div class="attempt-review-meta">
          <span>${escapeHtml(attempt.subject_name || 'Предмет')}</span>
          ${studentMeta}
          <span>${escapeHtml(resultDateTime(attempt.submitted_at))}</span>
          ${browserClosed}
          ${adjusted}
        </div>
        <div class="attempt-review-scoreboard">
          <div><span>Результат</span><strong>${Math.round(Number(attempt.percent || 0))}%</strong></div>
          <div><span>Баллы</span><strong>${Number(attempt.score || 0).toLocaleString('ru-RU')} / ${Number(attempt.max_score || 0).toLocaleString('ru-RU')}</strong></div>
          <div><span>Оценка</span><strong class="grade ${resultGradeClass(attempt.grade)}">${escapeHtml(attempt.grade || '—')}</strong></div>
          <div><span>Правильно</span><strong>${Number(summary.correct || 0)} / ${Number(summary.total || questions.length)}</strong></div>
        </div>
        <div class="attempt-review-summary-pills">
          <span class="correct">✓ Правильно: ${Number(summary.correct || 0)}</span>
          <span class="incorrect">× Неправильно: ${Number(summary.incorrect || 0)}</span>
          <span class="unanswered">— Без ответа: ${Number(summary.unanswered || 0)}</span>
          ${Number(summary.needs_review || 0) ? `<span class="review">? На проверке: ${Number(summary.needs_review || 0)}</span>` : ''}
        </div>
        ${attempt.comment ? `<div class="attempt-review-comment"><b>Комментарий учителя</b><span>${escapeHtml(attempt.comment)}</span></div>` : ''}
        <div class="attempt-review-actions">
          ${staffActions}
          <button class="primary-btn" type="button" data-review-close>Закрыть разбор</button>
        </div>
      </div>
      <div class="attempt-review-list">
        ${questions.map((question,index) => renderAttemptReviewQuestion(question,index)).join('')}
      </div>`;

    content.querySelector('[data-review-close]')?.addEventListener('click', () => closeModal(attemptReviewModal));
    content.querySelector('[data-review-edit]')?.addEventListener('click', event => {
      const editId = Number(event.currentTarget.dataset.reviewEdit || 0);
      closeModal(attemptReviewModal);
      openResultEditor(editId);
    });
  } catch (error) {
    content.innerHTML = `<div class="attempt-review-error"><b>Не удалось открыть разбор</b><span>${escapeHtml(error.message)}</span><button class="secondary-btn" type="button" data-review-close>Закрыть</button></div>`;
    content.querySelector('[data-review-close]')?.addEventListener('click', () => closeModal(attemptReviewModal));
  }
}

function renderResults() {
  const rows = resultsCache.slice();

  const percents = rows.map(item => Number(item.display?.percent)).filter(Number.isFinite);
  const grades = rows.map(item => Number(item.display?.grade)).filter(value => Number.isFinite(value) && value >= 2 && value <= 5);
  const attention = rows.filter(item => item.has_unpublished_draft || item.status === 'needs_review').length;
  const overviewCount = document.getElementById('resultsOverviewCount');
  const overviewAverage = document.getElementById('resultsOverviewAverage');
  const overviewGrade = document.getElementById('resultsOverviewGrade');
  const overviewAttention = document.getElementById('resultsOverviewAttention');

  if (overviewCount) overviewCount.textContent = String(rows.length);
  if (overviewAverage) {
    overviewAverage.textContent = percents.length
      ? Math.round(percents.reduce((a,b) => a + b, 0) / percents.length) + '%'
      : '—';
  }
  if (overviewGrade) {
    overviewGrade.textContent = grades.length
      ? (grades.reduce((a,b) => a + b, 0) / grades.length).toLocaleString('ru-RU', { maximumFractionDigits:1 })
      : '—';
  }
  if (overviewAttention) overviewAttention.textContent = String(attention);
}

async function resetStudentResult(attemptId) {
  const item = resultsCache.find(row => Number(row.attempt_id) === Number(attemptId));
  if (!item) return;

  const studentName = [item.student_last_name, item.student_first_name].filter(Boolean).join(' ') || 'ученика';
  const confirmed = await appConfirm(
    `Сбросить результат для ${studentName}? Текущая попытка, ответы и опубликованные корректировки будут удалены. После этого ученик сможет выполнить задание заново с чистого листа.`,
    { title:'Сбросить результат', tone:'danger', okText:'Сбросить и разрешить заново', cancelText:'Отмена' }
  );
  if (!confirmed) return;

  try {
    const response = await fetch('./api/results/reset.php', {
      method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'},
      body:JSON.stringify({ attempt_id: attemptId })
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось сбросить результат.');
    await Promise.all([
      loadResults(),
      loadJournal().catch(() => {}),
      loadTeacherDashboard().catch(() => {}),
      loadActivityHistory().catch(() => {})
    ]);
    await appAlert(data.message || 'Результат сброшен.', { title:'Результат сброшен', tone:'success', okText:'Готово' });
  } catch (error) {
    await appAlert(error.message, { title:'Не удалось сбросить результат', tone:'danger' });
  }
}

async function readJsonResponse(response, fallbackMessage = 'Сервер вернул некорректный ответ.') {
  const raw = await response.text();
  if (!raw.trim()) {
    throw new Error(
      response.ok
        ? 'Сервер вернул пустой ответ.'
        : `${fallbackMessage} Код HTTP: ${response.status}.`
    );
  }

  try {
    return JSON.parse(raw);
  } catch {
    throw new Error(
      response.ok
        ? fallbackMessage
        : `${fallbackMessage} Код HTTP: ${response.status}.`
    );
  }
}

function renderResultsLeaderList(targetId, items, type) {
  const target = document.getElementById(targetId);
  if (!target) return;
  if (!Array.isArray(items) || !items.length) {
    const message = type === 'student'
      ? 'Нужно минимум 3 работы на ученика.'
      : 'Пока недостаточно выполненных работ для рейтинга.';
    target.innerHTML = '<div class="history-empty"><b>Рейтинг ещё формируется</b><span>' + escapeHtml(message) + '</span></div>';
    return;
  }

  target.innerHTML = items.slice(0,3).map((item,index) => {
    const percent = Math.round(Number(item.average_percent || 0));
    const grade = String(item.average_grade || resultGradeFromPercent(percent));
    let meta = '';
    if (type === 'student') {
      meta = [item.class_name || '', Number(item.works_count || 0) + ' работ'].filter(Boolean).join(' · ');
    } else {
      meta = percent + '% средний · ' + Math.round(Number(item.completion_percent || 0)) + '% выполнено';
    }
    return `
      <div class="result-leader-row ${index === 0 ? 'winner' : ''}">
        <span class="result-leader-rank">${index + 1}</span>
        <div class="result-leader-copy">
          <b>${escapeHtml(item.name || '—')}</b>
          <small>${escapeHtml(meta)}</small>
        </div>
        <div class="result-leader-score">
          <strong>${percent}%</strong>
          <span class="grade ${resultGradeClass(grade)}">${escapeHtml(grade)}</span>
        </div>
      </div>`;
  }).join('');
}

async function loadResultsAnalytics() {
  const students = document.getElementById('resultsStudentLeaders');
  const classes = document.getElementById('resultsClassLeaders');
  const schools = document.getElementById('resultsSchoolLeaders');
  const schoolCard = document.getElementById('resultsSchoolLeaderCard');

  if (students) students.innerHTML = '<div class="history-empty">Загрузка рейтинга...</div>';
  if (classes) classes.innerHTML = '<div class="history-empty">Загрузка рейтинга...</div>';

  try {
    const response = await fetch('./api/results/analytics.php', {
      credentials:'same-origin',
      cache:'no-store'
    });
    const data = await readJsonResponse(response, 'Не удалось загрузить аналитику.');
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось загрузить аналитику.');

    renderResultsLeaderList('resultsStudentLeaders', data.students || [], 'student');
    renderResultsLeaderList('resultsClassLeaders', data.classes || [], 'class');

    const showSchools = Boolean(data.school_ranking_available) && Array.isArray(data.schools) && data.schools.length > 0;
    schoolCard?.classList.toggle('hidden', !showSchools);
    if (showSchools) renderResultsLeaderList('resultsSchoolLeaders', data.schools || [], 'school');
  } catch (error) {
    if (students) students.innerHTML = '<div class="history-empty"><span>' + escapeHtml(error.message) + '</span></div>';
    if (classes) classes.innerHTML = '<div class="history-empty"><span>' + escapeHtml(error.message) + '</span></div>';
    schoolCard?.classList.add('hidden');
    if (schools) schools.innerHTML = '';
  }
}

async function loadResults() {
  try {
    const response = await fetch('./api/results/list.php', { credentials:'same-origin', cache:'no-store' });
    const data = await readJsonResponse(response, 'Не удалось загрузить результаты.');
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось загрузить результаты.');

    resultsCache = data.items || [];

    const classSelect = document.getElementById('resultsClassFilter');
    const assignmentSelect = document.getElementById('resultsAssignmentFilter');
    const previousClass = classSelect?.value || '';
    const previousAssignment = assignmentSelect?.value || '';

    if (classSelect) {
      classSelect.innerHTML = '<option value="">Все классы</option>' + (data.filters?.classes || [])
        .map(item => `<option value="${Number(item.id)}">${escapeHtml(item.name)}</option>`).join('');
      classSelect.value = previousClass;
    }
    if (assignmentSelect) {
      assignmentSelect.innerHTML = '<option value="">Все задания</option>' + (data.filters?.assignments || [])
        .map(item => `<option value="${Number(item.id)}">${escapeHtml(item.title)}</option>`).join('');
      assignmentSelect.value = previousAssignment;
    }

    renderResults();
    loadResultsAnalytics().catch(() => {});
  } catch (error) {
    resultsCache = [];
    renderResults();
  }
}

function openResultEditor(attemptId) {
  const item = resultsCache.find(row => Number(row.attempt_id) === Number(attemptId));
  if (!item || !resultEditModal) return;

  activeResultEdit = item;
  const auto = item.automatic || {};
  const published = item.display || {};
  const draft = item.draft;
  const edit = item.has_unpublished_draft && draft ? draft : published;

  document.getElementById('resultAttemptId').value = String(item.attempt_id);
  document.getElementById('resultEditTitle').textContent =
    [item.student_last_name, item.student_first_name].filter(Boolean).join(' ') || 'Результат ученика';
  document.getElementById('resultEditMeta').textContent =
    [item.assignment_title, item.subject_name, item.class_name].filter(Boolean).join(' · ');

  document.getElementById('resultAutoScore').textContent =
    Number(auto.score || 0).toLocaleString('ru-RU') + ' / ' + Number(auto.max_score || 0).toLocaleString('ru-RU');
  document.getElementById('resultAutoGrade').textContent =
    Math.round(Number(auto.percent || 0)) + '% · оценка ' + (auto.grade || '—');

  document.getElementById('resultPublishedScore').textContent =
    Number(published.score || 0).toLocaleString('ru-RU') + ' / ' + Number(published.max_score || 0).toLocaleString('ru-RU');
  document.getElementById('resultPublishedGrade').textContent =
    Math.round(Number(published.percent || 0)) + '% · оценка ' + (published.grade || '—');

  document.getElementById('resultRevision').textContent = String(Number(published.revision || 0));
  document.getElementById('resultPublishedAt').textContent = published.published_override
    ? 'Опубликовано ' + resultDateTime(published.published_at)
    : 'Исходный автоматический результат';

  const score = document.getElementById('resultEditScore');
  score.max = String(Number(auto.max_score || 0));
  score.value = String(Number(edit.score ?? auto.score ?? 0));
  document.getElementById('resultEditGrade').value = resultGradeFromPercent(Number(edit.percent ?? auto.percent ?? 0));
  document.getElementById('resultEditComment').value = String(
    item.has_unpublished_draft && draft ? (draft.comment || '') : (published.comment || '')
  );
  document.getElementById('resultScoreLimit').textContent =
    'Максимум: ' + Number(auto.max_score || 0).toLocaleString('ru-RU') + ' балл.';
  updateResultEditorPercent();

  document.getElementById('resultEditError')?.classList.add('hidden');
  document.getElementById('resultEditSuccess')?.classList.add('hidden');
  document.getElementById('resultPublishBtn').textContent = item.has_unpublished_draft
    ? 'Опубликовать обновлённую оценку'
    : 'Опубликовать текущую корректировку';

  openModal(resultEditModal);
}

function updateResultEditorPercent() {
  if (!activeResultEdit) return;
  const max = Number(activeResultEdit.automatic?.max_score || 0);
  const score = Number(document.getElementById('resultEditScore')?.value || 0);
  const percent = max > 0 ? Math.max(0, Math.min(100, (score / max) * 100)) : 0;
  const roundedPercent = Math.round(percent * 100) / 100;
  const grade = resultGradeFromPercent(roundedPercent);
  const node = document.getElementById('resultCalculatedPercent');
  if (node) node.textContent = 'Процент: ' + roundedPercent + '%';
  const gradeNode = document.getElementById('resultEditGrade');
  if (gradeNode) gradeNode.value = grade;
}

async function saveResultDraft(showFeedback = true) {
  if (!activeResultEdit) throw new Error('Результат не выбран.');

  const error = document.getElementById('resultEditError');
  const success = document.getElementById('resultEditSuccess');
  error?.classList.add('hidden');
  if (showFeedback) success?.classList.add('hidden');

  const payload = {
    attempt_id: Number(activeResultEdit.attempt_id),
    score: Number(document.getElementById('resultEditScore').value),
    comment: String(document.getElementById('resultEditComment').value || '').trim()
  };

  const response = await fetch('./api/results/save.php', {
    method:'POST',
    credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify(payload)
  });
  const data = await response.json();
  if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось сохранить корректировку.');

  if (showFeedback && success) {
    success.textContent = data.message || 'Черновик сохранён.';
    success.classList.remove('hidden');
  }

  await loadResults();
  activeResultEdit = resultsCache.find(row => Number(row.attempt_id) === Number(payload.attempt_id)) || activeResultEdit;
  return data;
}

async function publishResultRevision() {
  if (!activeResultEdit) return;
  const attemptId = Number(activeResultEdit.attempt_id);
  const publishButton = document.getElementById('resultPublishBtn');
  const error = document.getElementById('resultEditError');
  error?.classList.add('hidden');

  publishButton.disabled = true;
  publishButton.textContent = 'Публикуем...';

  try {
    await saveResultDraft(false);
    const confirmed = await appConfirm(
      'Опубликовать скорректированную оценку ученику? После публикации она сразу появится в разделе «Мои оценки».',
      { title:'Опубликовать новую оценку', okText:'Опубликовать' }
    );
    if (!confirmed) return;

    const response = await fetch('./api/results/publish.php', {
      method:'POST',
      credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({ attempt_id: attemptId })
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось опубликовать оценку.');

    await Promise.all([
      loadResults(),
      loadTeacherDashboard().catch(() => {})
    ]);
    openResultEditor(attemptId);
    const success = document.getElementById('resultEditSuccess');
    if (success) {
      success.textContent = data.message || 'Обновлённая оценка опубликована.';
      success.classList.remove('hidden');
    }
  } catch (errorValue) {
    if (error) {
      error.textContent = errorValue.message;
      error.classList.remove('hidden');
    }
  } finally {
    publishButton.disabled = false;
    publishButton.textContent = 'Опубликовать обновлённую оценку';
  }
}

async function loadStudentResults() {
  const body = document.getElementById('studentResultsBody');
  const cards = document.getElementById('studentResultsCards');
  const recent = document.getElementById('studentRecentGrades');

  if (body) body.innerHTML = '<tr><td colspan="7">Загрузка реальных оценок...</td></tr>';
  if (cards) cards.innerHTML = '<article class="student-result-card loading-card">Загрузка результатов...</article>';
  if (recent) recent.innerHTML = '<div class="dashboard-empty">Загрузка оценок...</div>';

  try {
    const response = await fetch('./api/student/results.php', { credentials:'same-origin', cache:'no-store' });
    const data = await readJsonResponse(response, 'Не удалось загрузить оценки.');
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось загрузить оценки.');
    const items = data.items || [];

    const percents = items.map(item => Number(item.percent || 0)).filter(Number.isFinite);
    const grades = items.map(item => Number(item.grade)).filter(value => Number.isFinite(value) && value >= 2 && value <= 5);
    const averagePercent = percents.length ? Math.round(percents.reduce((sum, value) => sum + value, 0) / percents.length) : null;
    const averageGrade = grades.length ? (grades.reduce((sum, value) => sum + value, 0) / grades.length).toFixed(1).replace('.', ',') : null;
    const bestPercent = percents.length ? Math.round(Math.max(...percents)) : null;

    const countNode = document.getElementById('studentResultsCount');
    const averageNode = document.getElementById('studentResultsAverage');
    const gradeAverageNode = document.getElementById('studentResultsGradeAverage');
    const bestNode = document.getElementById('studentResultsBest');
    if (countNode) countNode.textContent = String(items.length);
    if (averageNode) averageNode.textContent = averagePercent === null ? '—' : averagePercent + '%';
    if (gradeAverageNode) gradeAverageNode.textContent = averageGrade ?? '—';
    if (bestNode) bestNode.textContent = bestPercent === null ? '—' : bestPercent + '%';

    if (body) {
      body.innerHTML = items.length ? items.map(item => `
        <tr>
          <td>${escapeHtml(resultDateTime(item.submitted_at))}</td>
          <td>${escapeHtml(item.subject_name || '—')}</td>
          <td><b>${escapeHtml(item.assignment_title)}</b>${item.adjusted ? '<small class="results-cell-sub">Оценка скорректирована учителем</small>' : ''}</td>
          <td>${Number(item.score || 0).toLocaleString('ru-RU')} / ${Number(item.max_score || 0).toLocaleString('ru-RU')}</td>
          <td><b>${Math.round(Number(item.percent || 0))}%</b></td>
          <td><span class="result-grade-wrap"><span class="grade ${resultGradeClass(item.grade)}">${escapeHtml(item.grade || '—')}</span>${resultClosedByBrowser(item) ? resultBrowserCloseBadge() : ''}</span></td>
          <td>${item.comment ? '<span class="student-result-comment">' + escapeHtml(item.comment) + '</span>' : '—'}</td>
        </tr>`).join('') : '<tr><td colspan="7"><div class="history-empty"><b>Оценок пока нет</b><span>После выполнения задания результат появится здесь.</span></div></td></tr>';
    }

    if (cards) {
      cards.innerHTML = items.length ? items.map(item => {
        const percent = Math.round(Number(item.percent || 0));
        const score = Number(item.score || 0).toLocaleString('ru-RU');
        const maxScore = Number(item.max_score || 0).toLocaleString('ru-RU');
        return `
          <article class="student-result-card">
            <div class="student-result-card-head">
              <div>
                <span class="student-result-subject">${escapeHtml(item.subject_name || 'Предмет')}</span>
                <h3>${escapeHtml(item.assignment_title)}</h3>
                <small>${escapeHtml(resultDateTime(item.submitted_at))}</small>
              </div>
              <span class="student-result-grade-wrap"><span class="student-result-grade grade ${resultGradeClass(item.grade)}">${escapeHtml(item.grade || '—')}</span>${resultClosedByBrowser(item) ? resultBrowserCloseBadge() : ''}</span>
            </div>
            <div class="student-result-metrics">
              <div><span>Результат</span><strong>${percent}%</strong></div>
              <div><span>Баллы</span><strong>${score} / ${maxScore}</strong></div>
              <div><span>Версия</span><strong>${item.adjusted ? 'Исправлена' : 'Автоматическая'}</strong></div>
            </div>
            <div class="student-result-progress"><span style="width:${Math.max(0, Math.min(100, percent))}%"></span></div>
            ${item.comment ? `<div class="student-result-message"><b>Комментарий учителя</b><span>${escapeHtml(item.comment)}</span></div>` : ''}
            ${resultClosedByBrowser(item) ? '<div class="student-result-browser-close"><b>З/Б</b><span>Тест завершён из-за закрытия или скрытия браузера/вкладки. Засчитаны ответы, сохранённые к этому моменту.</span></div>' : ''}
            ${item.adjusted ? '<div class="student-result-adjusted">Оценка была пересмотрена и опубликована учителем.</div>' : ''}
          </article>`;
      }).join('') : '<article class="student-result-card student-results-empty"><b>Оценок пока нет</b><span>После выполнения задания результат появится здесь.</span></article>';
    }

    if (recent) {
      recent.innerHTML = items.length ? items.slice(0, 4).map(item => `
        <div class="grade-row">
          <div><strong>${escapeHtml(item.subject_name || 'Без предмета')}</strong><span>${escapeHtml(item.assignment_title)} · ${Math.round(Number(item.percent || 0))}%</span></div>
          <span class="result-grade-wrap"><span class="grade ${resultGradeClass(item.grade)}">${escapeHtml(item.grade || '—')}</span>${resultClosedByBrowser(item) ? resultBrowserCloseBadge() : ''}</span>
        </div>`).join('') : '<div class="dashboard-empty"><span>Выполненных работ пока нет.</span></div>';
    }

  } catch (error) {
    if (body) body.innerHTML = '<tr><td colspan="7">' + escapeHtml(error.message) + '</td></tr>';
    if (cards) cards.innerHTML = '<article class="student-result-card student-results-empty">' + escapeHtml(error.message) + '</article>';
    if (recent) recent.innerHTML = '<div class="dashboard-empty">' + escapeHtml(error.message) + '</div>';
  }
}

document.getElementById('resultsSearch')?.addEventListener('input', renderResults);
document.getElementById('resultsClassFilter')?.addEventListener('change', renderResults);
document.getElementById('resultsAssignmentFilter')?.addEventListener('change', renderResults);
document.getElementById('journalClassFilter')?.addEventListener('change', () => {
  populateJournalSubjectFilter();
  const assignmentSelect = document.getElementById('journalAssignmentFilter');
  if (assignmentSelect) assignmentSelect.value = '';
  loadJournal().catch(() => {});
});
document.getElementById('journalSubjectFilter')?.addEventListener('change', () => {
  const assignmentSelect = document.getElementById('journalAssignmentFilter');
  if (assignmentSelect) assignmentSelect.value = '';
  loadJournal().catch(() => {});
});
document.getElementById('journalPeriodFilter')?.addEventListener('change', () => {
  const assignmentSelect = document.getElementById('journalAssignmentFilter');
  if (assignmentSelect) assignmentSelect.value = '';
  loadJournal().catch(() => {});
});
document.getElementById('journalAssignmentFilter')?.addEventListener('change', renderJournal);
document.getElementById('exportJournalBtn')?.addEventListener('click', exportJournalCsv);
document.getElementById('resultEditScore')?.addEventListener('input', updateResultEditorPercent);
document.getElementById('resultResetAutoBtn')?.addEventListener('click', () => {
  if (!activeResultEdit) return;
  document.getElementById('resultEditScore').value = String(Number(activeResultEdit.automatic?.score || 0));
  document.getElementById('resultEditComment').value = '';
  updateResultEditorPercent();
});
document.getElementById('resultEditForm')?.addEventListener('submit', async event => {
  event.preventDefault();
  const button = document.getElementById('resultSaveDraftBtn');
  const attemptId = Number(activeResultEdit?.attempt_id || 0);
  button.disabled = true;
  button.textContent = 'Сохраняем...';
  try {
    await saveResultDraft(true);
    if (attemptId) openResultEditor(attemptId);
    const success = document.getElementById('resultEditSuccess');
    if (success) {
      success.textContent = 'Черновик сохранён. Ученик пока видит предыдущий опубликованный результат.';
      success.classList.remove('hidden');
    }
  } catch (error) {
    const node = document.getElementById('resultEditError');
    if (node) {
      node.textContent = error.message;
      node.classList.remove('hidden');
    }
  } finally {
    button.disabled = false;
    button.textContent = 'Сохранить черновик';
  }
});
document.getElementById('resultPublishBtn')?.addEventListener('click', publishResultRevision);

document.querySelectorAll('.nav-item').forEach(btn => btn.addEventListener('click', () => {
  showView(btn.dataset.view);
  if (btn.dataset.view === 'teacher-dashboard') loadTeacherDashboard().catch(() => {});
  if (btn.dataset.view === 'classes') loadClasses();
  if (btn.dataset.view === 'subjects') loadSubjectsWorkspace();
  if (btn.dataset.view === 'assignments') loadAssignments();
  if (btn.dataset.view === 'journal') loadJournal().catch(() => {});
  if (btn.dataset.view === 'results') loadResults().catch(() => {});
  if (btn.dataset.view === 'student-results') loadStudentResults().catch(() => {});
  if (btn.dataset.view === 'uvoria-library') loadLibrary().catch(() => {});
  if (btn.dataset.view === 'staff-profile') loadStaffProfile().catch(() => {});
  if (btn.dataset.view === 'activity-history') loadActivityHistory().catch(() => {});
  if (btn.dataset.view === 'system-backups') {
    loadBackups().catch(() => {});
    loadDatabaseStatus().catch(() => {});
  }
  if (btn.dataset.view === 'incoming-materials') loadIncomingMaterials().catch(() => {});
  if (btn.dataset.view === 'school-management') loadSchoolManagement();
}));
document.querySelectorAll('[data-view-jump]').forEach(btn => btn.addEventListener('click', () => {
  const target = btn.dataset.viewJump;
  showView(target);
  if (target === 'results') loadResults().catch(() => {});
  if (target === 'student-results') loadStudentResults().catch(() => {});
  if (target === 'assignments') loadAssignments();
  if (target === 'classes') loadClasses();
}));
menuBtn?.addEventListener('click', event => {
  event.stopPropagation();
  setSidebarOpen(!sidebar.classList.contains('open'));
});
sidebarScrim?.addEventListener('click', () => setSidebarOpen(false));
document.addEventListener('keydown', event => {
  if (event.key === 'Escape' && sidebar?.classList.contains('open')) {
    setSidebarOpen(false);
  }
});
document.addEventListener('pointerdown', event => {
  if (!sidebar?.classList.contains('open')) return;
  if (window.matchMedia('(min-width: 1051px) and (orientation: landscape)').matches) return;
  if (sidebar.contains(event.target) || menuBtn?.contains(event.target)) return;
  setSidebarOpen(false);
});

document.getElementById('notificationsBtn')?.addEventListener('click', () => {
  alert('Новых уведомлений пока нет.');
});

document.getElementById('openHistoryTaskBtn')?.addEventListener('click', () => showView('student-tasks'));
document.getElementById('viewResultBtn')?.addEventListener('click', () => {
  showView('student-results');
  loadStudentResults().catch(() => {});
});

document.getElementById('exportResultsBtn')?.addEventListener('click', () => {
  const table = document.querySelector('#results table');
  if (!table) return;
  const rows = [...table.querySelectorAll('tr')].map(row =>
    [...row.querySelectorAll('th,td')].map(cell =>
      '"' + cell.innerText.replace(/"/g, '""').replace(/\s+/g, ' ').trim() + '"'
    ).join(';')
  );
  const blob = new Blob(['\ufeff' + rows.join('\n')], { type: 'text/csv;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = 'urovia-results.csv';
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
});

function openModal(modal) {
  modal.classList.remove('hidden');
  document.body.style.overflow = 'hidden';
}
function closeModal(modal) {
  modal.classList.add('hidden');
  document.body.style.overflow = '';
}

async function requestModalClose(modal) {
  if (!modal) return;
  if (modal === quizModal && activeStudentAttempt?.id) {
    await finishActiveStudentAttemptFromClose();
    return;
  }
  closeModal(modal);
}

['createTaskBtn', 'createTaskBtn2', 'heroCreateBtn'].forEach(id => {
  document.getElementById(id)?.addEventListener('click', () => openModal(taskModal));
});
document.querySelectorAll('[data-close-modal]').forEach(btn => {
  btn.addEventListener('click', () => {
    void requestModalClose(document.getElementById(btn.dataset.closeModal));
  });
});
document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
  backdrop.addEventListener('click', e => {
    if (e.target === backdrop && backdrop.dataset.locked !== '1') {
      void requestModalClose(backdrop);
    }
  });
});

let subjectsCache = [];
let assignmentsCache = [];
let resultsCache = [];
let journalDataCache = null;
let activeResultEdit = null;
let assignmentWorkflowContext = { review_required: false, can_manage: false };
let teacherOptionsCache = [];
let selectedSubjectId = null;

async function loadSubjects() {
  const select = document.getElementById('taskSubject');
  try {
    const response = await fetch('./api/subjects/list.php', { credentials: 'same-origin', cache: 'no-store' });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось загрузить предметы.');
    subjectsCache = data.subjects || [];

    if (select) {
      select.innerHTML = '<option value="">Выберите предмет</option>' + subjectsCache.map(item =>
        `<option value="${item.id}">${escapeHtml(item.name)}</option>`
      ).join('');
    }

    renderSubjectsPage();
    return subjectsCache;
  } catch (error) {
    if (select) select.innerHTML = '<option value="">Предметы недоступны</option>';
    const grid = document.getElementById('subjectsPageGrid');
    if (grid) grid.innerHTML = `<p>${escapeHtml(error.message)}</p>`;
    throw error;
  }
}

function renderSubjectsPage() {
  const grid = document.getElementById('subjectsPageGrid');
  if (!grid) return;

  if (!subjectsCache.length) {
    grid.innerHTML = '<div class="subject-empty-list"><b>Предметов пока нет</b><span>Добавьте первый предмет школы.</span></div>';
    return;
  }

  grid.innerHTML = subjectsCache.map(subject => {
    const active = Number(subject.id) === Number(selectedSubjectId);
    return `
      <button class="subject-page-card ${active ? 'active' : ''}" type="button" data-open-subject="${subject.id}">
        <span class="subject-page-icon">${escapeHtml(String(subject.name || '?').charAt(0).toUpperCase())}</span>
        <span><b>${escapeHtml(subject.name)}</b><small>Открыть задания</small></span>
        <span class="subject-page-arrow">→</span>
      </button>`;
  }).join('');

  grid.querySelectorAll('[data-open-subject]').forEach(button => {
    button.addEventListener('click', () => openSubjectDetails(Number(button.dataset.openSubject)));
  });
}

function subjectAvailableClasses(subjectId) {
  const unique = [];
  const seen = new Set();
  teacherOptionsCache
    .filter(item => Number(item.subject_id) === Number(subjectId))
    .forEach(item => {
      const id = Number(item.class_id);
      if (seen.has(id)) return;
      seen.add(id);
      unique.push({ id, name: item.class_name });
    });
  return unique;
}

function renderSubjectAssignments() {
  const list = document.getElementById('subjectAssignmentsList');
  if (!list || !selectedSubjectId) return;

  const rows = assignmentsCache.filter(item => Number(item.subject_id) === Number(selectedSubjectId));
  list.innerHTML = rows.length ? rows.map(item => {
    const [statusText, statusClass] = assignmentStatusLabel(item);
    const questionsCount = Number(item.questions_count || item.parsed_question_count || 0);
    const formatLabel = item.source_format ? String(item.source_format).toUpperCase() : 'UROVIA';
    const parseLabel = item.source_format
      ? (item.parse_status === 'questions_parsed'
          ? 'Файл распознан'
          : item.parse_status === 'text_extracted'
            ? 'Текст извлечён'
            : 'Файл загружен')
      : 'Создано в UROVIA';
    const reviewNote = item.review_comment
      ? `<div class="subject-assignment-alert"><b>Комментарий администратора</b><span>${escapeHtml(item.review_comment)}</span></div>`
      : '';

    return `
      <article class="subject-assignment-row subject-assignment-card">
        <div class="subject-assignment-copy">
          <div class="subject-assignment-heading">
            <div class="subject-assignment-title-wrap">
              <span class="subject-assignment-kicker">Учебное задание</span>
              <h3>${escapeHtml(item.title)}</h3>
            </div>
            <span class="status ${statusClass} subject-assignment-status">${statusText}</span>
          </div>

          <div class="subject-assignment-meta">
            <span class="assignment-meta-chip">
              <span class="assignment-meta-icon">⌂</span>
              ${escapeHtml(item.class_names || 'Без класса')}
            </span>
            <span class="assignment-meta-chip">
              <span class="assignment-meta-icon">▤</span>
              ${escapeHtml(formatLabel)}
            </span>
            <span class="assignment-meta-chip">
              <span class="assignment-meta-icon">?</span>
              ${questionsCount} ${questionsCount === 1 ? 'вопрос' : (questionsCount >= 2 && questionsCount <= 4 ? 'вопроса' : 'вопросов')}
            </span>
          </div>

          <div class="subject-assignment-info">
            <span class="subject-assignment-parse-state">${escapeHtml(parseLabel)}</span>
            ${item.parser_message ? `<span>${escapeHtml(item.parser_message)}</span>` : ''}
          </div>
          ${reviewNote}
        </div>

        <div class="subject-assignment-footer">
          <div class="subject-assignment-actions">
            <button class="secondary-btn compact-btn test-run-btn" type="button" data-test-assignment="${item.id}">▶ Пройти как ученик</button>
            <button class="secondary-btn compact-btn" type="button" data-preview-questions="${item.id}">Конструктор</button>
            <button class="secondary-btn compact-btn duplicate-btn" type="button" data-duplicate-assignment="${item.id}">⧉ Дублировать</button>
            ${libraryAssignmentAction(item)}
            ${assignmentDeleteButton(item)}
          </div>
          <div class="subject-assignment-workflow-actions">
            ${assignmentWorkflowActionButtons(item)}
          </div>
        </div>
      </article>`;
  }).join('') : '<div class="subject-empty-list"><b>Заданий пока нет</b><span>Создайте задание вручную или импортируйте файл.</span></div>';

  wireAssignmentWorkflowButtons(list);
}

function canonicalQuestionType(type) {
  const value = String(type || '');
  if (value === 'ordering') return 'order';
  if (value === 'short_answer' || value === 'image_answer') return 'text';
  return value || 'text';
}

function questionTypeLabel(type) {
  const canonical = canonicalQuestionType(type);
  const labels = {
    single: 'Один правильный ответ',
    multiple: 'Несколько правильных ответов',
    true_false: 'Верно / неверно',
    order: 'Восстановить порядок',
    matching: 'Установить соответствия',
    text: 'Короткий ответ',
    number: 'Числовой ответ',
    correction: 'Найти и исправить ошибку',
    essay: 'Развёрнутый ответ'
  };
  return labels[canonical] || canonical || 'Вопрос';
}

function renderQuestionCorrectAnswer(question) {
  const interaction = canonicalQuestionType(question.interaction_type || question.type);
  if (interaction === 'single' || interaction === 'multiple' || interaction === 'true_false') {
    const correct = (question.options || []).filter(option => Number(option.is_correct) === 1).map(option => option.text);
    return correct.length ? correct.join(', ') : 'Не указан';
  }
  if (interaction === 'order') {
    const settings = question.settings || {};
    const items = settings.items || {};
    const order = settings.correct_order || [];
    return order.map(key => items[key] || key).join(' → ') || 'Не указан';
  }
  if (interaction === 'matching') {
    const settings = question.settings || {};
    const left = settings.left || {};
    const right = settings.right || {};
    const pairs = settings.pairs || {};
    const result = Object.entries(pairs).map(([l, r]) => `${left[l] || l} — ${right[r] || r}`);
    return result.join('; ') || 'Не указан';
  }
  return question.correct_text || 'Проверяется учителем';
}

async function openQuestionPreview(assignmentId) {
  if (!questionPreviewModal) return;

  const list = document.getElementById('questionPreviewList');
  const summary = document.getElementById('questionPreviewSummary');
  const error = document.getElementById('questionPreviewError');
  const title = document.getElementById('questionPreviewTitle');

  if (list) list.innerHTML = '<p>Загрузка вопросов...</p>';
  if (summary) summary.innerHTML = '';
  error?.classList.add('hidden');
  openModal(questionPreviewModal);

  try {
    const response = await fetch(`./api/assignments/questions.php?assignment_id=${encodeURIComponent(assignmentId)}`, {
      credentials: 'same-origin',
      cache: 'no-store'
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось загрузить вопросы.');

    const questions = data.questions || [];
    if (title) title.textContent = data.assignment?.title || 'Конструктор задания';

    const counts = {};
    questions.forEach(question => {
      const kind = question.interaction_type || question.type;
      counts[kind] = (counts[kind] || 0) + 1;
    });
    if (summary) {
      summary.innerHTML = `<b>${questions.length} вопросов</b>` +
        Object.entries(counts).map(([kind, count]) => `<span>${escapeHtml(questionTypeLabel(kind))}: ${count}</span>`).join('');
    }

    if (!questions.length) {
      if (list) list.innerHTML = '<div class="subject-empty-list"><b>Вопросы пока не распознаны</b><span>Проверьте структуру исходного файла.</span></div>';
      return;
    }

    if (list) {
      list.innerHTML = questions.map((question, index) => {
        const interaction = canonicalQuestionType(question.interaction_type || question.type);
        const options = (question.options || []).length
          ? `<div class="question-preview-options">${question.options.map(option =>
              `<span class="${Number(option.is_correct) === 1 ? 'correct' : ''}">${escapeHtml(option.text)}</span>`
            ).join('')}</div>`
          : '';

        const settings = question.settings || {};
        let structured = '';
        if (interaction === 'matching') {
          structured = `<div class="question-preview-structured"><b>Левый столбец:</b> ${escapeHtml(Object.values(settings.left || {}).join(' · '))}<br><b>Правый столбец:</b> ${escapeHtml(Object.values(settings.right || {}).join(' · '))}</div>`;
        } else if (interaction === 'order') {
          structured = `<div class="question-preview-structured"><b>Элементы:</b> ${escapeHtml(Object.values(settings.items || {}).join(' · '))}</div>`;
        } else if (interaction === 'correction' && settings.original_text) {
          structured = `<div class="question-preview-structured"><b>Текст с ошибкой:</b> ${escapeHtml(settings.original_text)}</div>`;
        }

        const images = (question.assets || []).map(asset =>
          `<img class="question-preview-image" src="${escapeHtml(asset.url)}" alt="Изображение к вопросу">`
        ).join('');

        return `
          <article class="question-preview-card">
            <div class="question-preview-head">
              <span>№ ${index + 1}</span>
              <b>${escapeHtml(questionTypeLabel(interaction))}</b>
              <strong>${Number(question.points || 1)} балл.</strong>
            </div>
            <h4>${escapeHtml(question.text)}</h4>
            ${images}
            ${structured}
            ${options}
            <div class="question-preview-answer"><span>Правильный ответ</span><b>${escapeHtml(renderQuestionCorrectAnswer(question))}</b></div>
          </article>`;
      }).join('');
    }
  } catch (e) {
    if (error) {
      error.textContent = e.message;
      error.classList.remove('hidden');
    }
    if (list) list.innerHTML = '';
  }
}

async function openSubjectDetails(subjectId) {
  const subject = subjectsCache.find(item => Number(item.id) === Number(subjectId));
  if (!subject) return;

  selectedSubjectId = Number(subject.id);
  renderSubjectsPage();

  document.getElementById('subjectEmptyState')?.classList.add('hidden');
  document.getElementById('subjectDetailContent')?.classList.remove('hidden');
  const title = document.getElementById('subjectDetailTitle');
  if (title) title.textContent = subject.name;

  try {
    await Promise.all([loadTeacherOptions(), loadAssignments()]);
  } catch {}

  const createButton = document.getElementById('subjectCreateTaskBtn');
  if (createButton) {
    createButton.disabled = false;
    createButton.title = '';
  }

  renderSubjectAssignments();
}

async function loadSubjectsWorkspace() {
  const grid = document.getElementById('subjectsPageGrid');
  if (grid) grid.innerHTML = '<p>Загрузка предметов...</p>';

  try {
    await Promise.all([loadSubjects(), loadTeacherOptions(), loadAssignments()]);
    if (selectedSubjectId && subjectsCache.some(item => Number(item.id) === Number(selectedSubjectId))) {
      await openSubjectDetails(selectedSubjectId);
    } else {
      selectedSubjectId = null;
      document.getElementById('subjectDetailContent')?.classList.add('hidden');
      document.getElementById('subjectEmptyState')?.classList.remove('hidden');
      renderSubjectsPage();
    }
  } catch {}
}

async function loadTeacherOptions() {
  const response = await fetch('./api/teacher/options.php', { credentials: 'same-origin', cache: 'no-store' });
  const data = await response.json();
  if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось загрузить назначения учителя.');
  teacherOptionsCache = data.options || [];

  const subjectSelect = document.getElementById('taskSubject');
  const uniqueSubjects = [];
  const seen = new Set();
  teacherOptionsCache.forEach(item => {
    const id = Number(item.subject_id);
    if (seen.has(id)) return;
    seen.add(id);
    uniqueSubjects.push({ id, name: item.subject_name });
  });
  if (subjectSelect) {
    subjectSelect.innerHTML = '<option value="">Выберите предмет</option>' + uniqueSubjects.map(item =>
      `<option value="${item.id}">${escapeHtml(item.name)}</option>`
    ).join('');
  }
}

async function prepareTaskForm(subjectId = null) {
  await loadSubjects();
  const subjectSelect = document.getElementById('taskSubject');
  if (subjectId && subjectSelect) {
    subjectSelect.value = String(subjectId);
  }
}

['createTaskBtn', 'createTaskBtn2', 'heroCreateBtn'].forEach(id => {
  const button = document.getElementById(id);
  if (!button) return;
  const clone = button.cloneNode(true);
  button.replaceWith(clone);
  clone.addEventListener('click', async () => {
    await prepareTaskForm();
    openModal(taskModal);
  });
});

document.getElementById('addSubjectPageBtn')?.addEventListener('click', () => openModal(subjectModal));

document.getElementById('subjectCreateTaskBtn')?.addEventListener('click', async () => {
  if (!selectedSubjectId) return;
  await prepareTaskForm(selectedSubjectId);
  openModal(taskModal);
});

document.getElementById('subjectImportForm')?.addEventListener('submit', async event => {
  event.preventDefault();
  if (!selectedSubjectId) return;

  const form = event.currentTarget;
  const error = document.getElementById('subjectImportError');
  const result = document.getElementById('subjectImportResult');
  const button = form.querySelector('button[type="submit"]');
  const file = document.getElementById('subjectImportFile')?.files?.[0];

  error?.classList.add('hidden');
  result?.classList.add('hidden');

  if (!file) {
    if (error) {
      error.textContent = 'Выберите файл задания.';
      error.classList.remove('hidden');
    }
    return;
  }

  const data = new FormData();
  data.append('subject_id', String(selectedSubjectId));
  data.append('title', document.getElementById('subjectImportTitle')?.value || '');
  data.append('focus_policy', document.getElementById('subjectImportFocus')?.value || 'allow');
  data.append('file', file);

  button.disabled = true;
  button.textContent = 'Загружаем...';

  try {
    const response = await fetch('./api/assignments/import-file.php', {
      method: 'POST',
      credentials: 'same-origin',
      body: data
    });
    const payload = await response.json();
    if (!response.ok || payload.ok === false) throw new Error(payload.error || 'Не удалось импортировать задание.');

    const parsedCount = Number(payload.import?.parsed_question_count || 0);
    const status = payload.import?.parse_status === 'questions_parsed'
      ? `UROVIA распознала ${parsedCount} вопросов. Откройте «Конструктор» и проверьте вопросы перед публикацией.`
      : payload.import?.parse_status === 'text_extracted'
        ? 'Текст извлечён, но вопросы по шаблону не распознаны. Проверьте структуру файла.'
        : 'Файл сохранён в черновике. Для этого файла автоматическое извлечение текста ограничено.';
    if (result) {
      result.textContent = `${payload.import?.format || 'Файл'} принят. ${status}`;
      result.classList.remove('hidden');
    }

    form.reset();
    await loadAssignments();
    renderSubjectAssignments();
  } catch (e) {
    if (error) {
      error.textContent = e.message;
      error.classList.remove('hidden');
    }
  } finally {
    button.disabled = false;
    button.textContent = 'Загрузить и создать черновик';
  }
});

document.getElementById('focusPolicy')?.addEventListener('change', event => {
  document.getElementById('strictWarning')?.classList.toggle('hidden', event.target.value !== 'strict');
});

function normalizedAssignmentWorkflowStatus(itemOrStatus) {
  if (typeof itemOrStatus === 'string') return itemOrStatus;
  const item = itemOrStatus || {};
  if (item.workflow_status) return String(item.workflow_status);
  if (item.status === 'published') return 'assigned';
  if (item.status === 'closed') return 'completed';
  return 'draft';
}

function assignmentStatusLabel(itemOrStatus) {
  const status = normalizedAssignmentWorkflowStatus(itemOrStatus);
  const labels = {
    draft: ['Черновик', 'amber'],
    review: ['На проверке', 'blue'],
    ready: ['Готово', 'violet'],
    assigned: ['Назначено', 'green'],
    completed: ['Завершено', 'blue']
  };
  return labels[status] || ['Черновик', 'amber'];
}

function assignmentDeleteButton(item) {
  if (!item) return '';

  const attempts = Number(item.all_attempts_count ?? item.attempts_count ?? 0);
  if (attempts > 0) {
    return '<button class="secondary-btn compact-btn danger-action assignment-delete-btn" type="button" disabled ' +
      'title="Удаление недоступно: по заданию уже есть попытки учеников.">Удалить</button>';
  }

  return '<button class="secondary-btn compact-btn danger-action assignment-delete-btn" type="button" data-delete-assignment="' +
    Number(item.id) + '" title="Удалить задание целиком">Удалить</button>';
}

function assignmentWorkflowActionButtons(item) {
  const status = normalizedAssignmentWorkflowStatus(item);
  const reviewRequired = Boolean(assignmentWorkflowContext.review_required);
  const manager = Boolean(assignmentWorkflowContext.can_manage);

  if (status === 'draft') {
    const label = reviewRequired && !manager ? 'Отправить на проверку' : 'Готово к назначению';
    return `<button class="primary-btn compact-btn" type="button" data-workflow-action="prepare" data-assignment-id="${item.id}">${label}</button>`;
  }
  if (status === 'review') {
    if (manager) {
      return `
        <button class="primary-btn compact-btn" type="button" data-workflow-action="approve" data-assignment-id="${item.id}">Одобрить</button>
        <button class="secondary-btn compact-btn" type="button" data-workflow-action="return" data-assignment-id="${item.id}">На доработку</button>`;
    }
    return `<button class="secondary-btn compact-btn" type="button" data-workflow-action="withdraw" data-assignment-id="${item.id}">Отозвать</button>`;
  }
  if (status === 'ready') {
    return `
      <button class="primary-btn compact-btn" type="button" data-assign-class="${item.id}">Назначить классу</button>
      <button class="secondary-btn compact-btn" type="button" data-workflow-action="reopen" data-assignment-id="${item.id}">В черновик</button>`;
  }
  if (status === 'assigned') {
    return `
      <button class="secondary-btn compact-btn" type="button" data-assign-class="${item.id}">＋ Ещё классу</button>
      <button class="secondary-btn compact-btn" type="button" data-workflow-action="complete" data-assignment-id="${item.id}">Завершить</button>`;
  }
  return '';
}

async function transitionAssignmentWorkflow(assignmentId, action) {
  const assignment = assignmentsCache.find(item => Number(item.id) === Number(assignmentId));
  if (!assignment) return;

  let comment = '';
  if (action === 'return') {
    const value = prompt('Комментарий учителю: что нужно исправить?');
    if (value === null) return;
    comment = value.trim();
  } else if (action === 'complete') {
    if (!(await appConfirm('Завершить это задание? Новые попытки учеников будут закрыты.'))) return;
  } else if (action === 'withdraw') {
    if (!(await appConfirm('Отозвать задание с проверки и вернуть в черновик?'))) return;
  } else if (action === 'reopen') {
    if (!(await appConfirm('Вернуть готовое задание в черновик для редактирования?'))) return;
  }

  try {
    const response = await fetch('./api/assignments/workflow.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ assignment_id: assignmentId, action, comment })
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось изменить статус задания.');
    await loadAssignments();
  } catch (error) {
    alert(error.message);
  }
}

function wireAssignmentWorkflowButtons(root) {
  if (!root) return;
  root.querySelectorAll('[data-workflow-action]').forEach(button => {
    button.addEventListener('click', () => transitionAssignmentWorkflow(
      Number(button.dataset.assignmentId),
      String(button.dataset.workflowAction || '')
    ));
  });
  root.querySelectorAll('[data-assign-class]').forEach(button => {
    button.addEventListener('click', () => openAssignToClass(Number(button.dataset.assignClass)));
  });
  root.querySelectorAll('[data-duplicate-assignment]').forEach(button => {
    button.addEventListener('click', () => openDuplicateAssignment(Number(button.dataset.duplicateAssignment)));
  });
  root.querySelectorAll('[data-delete-assignment]').forEach(button => {
    button.addEventListener('click', () => deleteAssignment(Number(button.dataset.deleteAssignment), button));
  });
  root.querySelectorAll('[data-library-submit]').forEach(button => {
    button.addEventListener('click', () => submitAssignmentToLibrary(Number(button.dataset.librarySubmit)));
  });
  root.querySelectorAll('[data-preview-questions]').forEach(button => {
    button.addEventListener('click', () => openQuestionPreview(Number(button.dataset.previewQuestions)));
  });
  root.querySelectorAll('[data-test-assignment]').forEach(button => {
    button.addEventListener('click', () => openAssignmentTestPreview(Number(button.dataset.testAssignment)));
  });
}


function assignmentTableActionIcon(type) {
  const icons = {
    test: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M10 8.7l5.3 3.3-5.3 3.3z"/></svg>',
    builder: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 4h9l3 3v13H6z"/><path d="M15 4v4h4M9 12h3M9 15h2"/><path d="M14.5 12.5l2 2-3.8 3.8-2.4.5.5-2.4z"/></svg>',
    duplicate: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="8" y="8" width="11" height="11" rx="2"/><path d="M16 8V6a2 2 0 00-2-2H6a2 2 0 00-2 2v8a2 2 0 002 2h2"/></svg>',
    library: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 5.5A2.5 2.5 0 017.5 3H19v16H7.5A2.5 2.5 0 015 16.5z"/><path d="M5 5.5v11M9 7h6M9 10.5h6"/></svg>',
    delete: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4.5 7h15M9 4h6l1 3H8zM7 7l.8 13h8.4L17 7M10 11v5.5M14 11v5.5"/></svg>',
    assign: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path d="M4 19c.5-4 2.2-6 5-6s4.5 2 5 6M18 8v6M15 11h6"/></svg>',
    complete: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M7.8 12.2l2.8 2.8 5.8-6"/></svg>',
    prepare: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 4h9l3 3v13H6z"/><path d="M15 4v4h4M9 14l2 2 4-4"/></svg>',
    approve: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.2 4.2L19 7"/></svg>',
    back: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 7L4.5 11.5 9 16"/><path d="M5 11.5h8.5a5 5 0 010 10H11"/></svg>',
    reopen: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 8.5A8 8 0 1112 20a8 8 0 01-7.2-4.5"/><path d="M4.5 4.5v5h5"/></svg>'
  };
  return icons[type] || '';
}

function assignmentTableIconButton(type, label, attrs = '', tone = '') {
  return '<button class="assignment-icon-btn' + (tone ? ' assignment-icon-' + tone : '') +
    '" type="button" ' + attrs + ' data-tooltip="' + escapeHtml(label) +
    '" title="' + escapeHtml(label) + '" aria-label="' + escapeHtml(label) + '">' +
    assignmentTableActionIcon(type) + '</button>';
}

function assignmentTableDeleteButton(item) {
  const attempts = Number(item.all_attempts_count ?? item.attempts_count ?? 0);
  if (attempts > 0) {
    return assignmentTableIconButton('delete', 'Удаление недоступно: уже есть попытки учеников', 'disabled', 'danger');
  }
  return assignmentTableIconButton('delete', 'Удалить задание', 'data-delete-assignment="' + Number(item.id) + '"', 'danger');
}

function assignmentTableLibraryAction(item) {
  const status = String(item.library_status || '');
  if (!status || ['rejected', 'withdrawn'].includes(status)) {
    return assignmentTableIconButton('library', 'Отправить в библиотеку UROVIA', 'data-library-submit="' + Number(item.id) + '"');
  }
  const [cls, label] = libraryStatusLabel(status);
  return '<span class="status ' + cls + ' assignment-library-status" title="Библиотека UROVIA">' + escapeHtml(label) + '</span>';
}

function assignmentTableWorkflowActions(item) {
  const status = normalizedAssignmentWorkflowStatus(item);
  const reviewRequired = Boolean(assignmentWorkflowContext.review_required);
  const manager = Boolean(assignmentWorkflowContext.can_manage);
  const id = Number(item.id);
  if (status === 'draft') {
    const label = reviewRequired && !manager ? 'Отправить на проверку' : 'Готово к назначению';
    return assignmentTableIconButton('prepare', label, 'data-workflow-action="prepare" data-assignment-id="' + id + '"', 'primary');
  }
  if (status === 'review') {
    if (manager) {
      return assignmentTableIconButton('approve', 'Одобрить', 'data-workflow-action="approve" data-assignment-id="' + id + '"', 'success') +
        assignmentTableIconButton('back', 'На доработку', 'data-workflow-action="return" data-assignment-id="' + id + '"', 'warning');
    }
    return assignmentTableIconButton('back', 'Отозвать с проверки', 'data-workflow-action="withdraw" data-assignment-id="' + id + '"', 'warning');
  }
  if (status === 'ready') {
    return assignmentTableIconButton('assign', 'Назначить классу', 'data-assign-class="' + id + '"', 'primary') +
      assignmentTableIconButton('reopen', 'Вернуть в черновик', 'data-workflow-action="reopen" data-assignment-id="' + id + '"');
  }
  if (status === 'assigned') {
    return assignmentTableIconButton('assign', 'Назначить ещё классу', 'data-assign-class="' + id + '"') +
      assignmentTableIconButton('complete', 'Завершить задание', 'data-workflow-action="complete" data-assignment-id="' + id + '"', 'success');
  }
  return '';
}

function assignmentClassTimeLabel(item, detail = {}) {
  const classMinutes = Number(detail.time_limit_minutes || 0);
  const defaultMinutes = Number(item.time_limit_minutes || 0);
  const minutes = classMinutes > 0 ? classMinutes : defaultMinutes;
  return minutes > 0 ? minutes + ' мин' : 'Без лимита';
}

function assignmentClassTimesHtml(item) {
  const details = Array.isArray(item.class_assignments) ? item.class_assignments : [];
  if (details.length) {
    return '<div class="assignment-class-time-list">' + details.map(detail =>
      '<span class="assignment-class-time-chip">'
        + '<b>' + escapeHtml(detail.class_name || 'Класс') + '</b>'
        + '<span><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>'
        + escapeHtml(assignmentClassTimeLabel(item, detail)) + '</span>'
        + '</span>'
    ).join('') + '</div>';
  }

  if (item.class_names) {
    const fallbackTime = assignmentClassTimeLabel(item);
    return '<div class="assignment-class-time-list"><span class="assignment-class-time-chip"><b>'
      + escapeHtml(item.class_names)
      + '</b><span><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>'
      + escapeHtml(fallbackTime) + '</span></span></div>';
  }

  return '<span class="assignment-class-empty">Ещё не назначено</span>';
}

function renderAssignments() {
  const body = document.getElementById('assignmentsTableBody');
  if (!body) return;
  const query = (document.getElementById('assignmentSearch')?.value || '').trim().toLowerCase();
  const statusFilter = document.getElementById('assignmentStatusFilter')?.value || '';

  const rows = assignmentsCache.filter(item => {
    const classSearch = Array.isArray(item.class_assignments)
      ? item.class_assignments.map(detail => detail.class_name).join(' ')
      : item.class_names;
    const haystack = [item.title, item.subject_name, classSearch, item.source_school_name].join(' ').toLowerCase();
    const workflowStatus = normalizedAssignmentWorkflowStatus(item);
    return (!query || haystack.includes(query)) && (!statusFilter || workflowStatus === statusFilter);
  });

  body.innerHTML = rows.length ? rows.map(item => {
    const [statusText, statusClass] = assignmentStatusLabel(item);
    const strict = item.focus_policy === 'strict';
    const source = item.source_school_name ? ` · получено из «${escapeHtml(item.source_school_name)}»` : '';
    const reviewNote = item.review_comment
      ? `<small class="workflow-review-note">Комментарий: ${escapeHtml(item.review_comment)}</small>`
      : '';
    return `
      <tr class="assignment-table-row">
        <td class="assignment-table-main" data-label="Задание">
          <b>${escapeHtml(item.title)}</b>
          <small>${escapeHtml(item.subject_name || 'Без предмета')}${source}</small>
          ${reviewNote}
        </td>
        <td class="assignment-classes-cell" data-label="Классы и время">${assignmentClassTimesHtml(item)}</td>
        <td class="assignment-mode-cell" data-label="Режим"><span class="status ${strict ? 'amber' : 'blue'}">${strict ? 'Строгий' : 'Обычный'}</span></td>
        <td class="assignment-submitted-cell" data-label="Сдано"><span class="assignment-submitted-count">${Number(item.attempts_count || 0)}</span></td>
        <td class="assignment-status-cell" data-label="Статус"><span class="status ${statusClass}">${statusText}</span></td>
        <td class="row-actions-cell assignment-table-actions" data-label="Действия">
          <div class="assignment-action-group assignment-action-tools">
            ${assignmentTableIconButton('test', 'Пройти как ученик', 'data-test-assignment="' + Number(item.id) + '"', 'primary')}
            ${assignmentTableIconButton('builder', 'Конструктор задания', 'data-preview-questions="' + Number(item.id) + '"')}
            ${assignmentTableIconButton('duplicate', 'Дублировать задание', 'data-duplicate-assignment="' + Number(item.id) + '"')}
            ${assignmentTableLibraryAction(item)}
            ${assignmentTableDeleteButton(item)}
          </div>
          <div class="assignment-action-group assignment-action-workflow">
            ${assignmentTableWorkflowActions(item)}
          </div>
        </td>
      </tr>`;
  }).join('') : '<tr><td colspan="6">Задания не найдены.</td></tr>';

  wireAssignmentWorkflowButtons(body);
}

function assignmentDateTimeLocal(value) {
  if (!value) return '';
  return String(value).replace(' ', 'T').slice(0, 16);
}

async function deleteAssignment(assignmentId, button) {
  const assignment = assignmentsCache.find(item => Number(item.id) === Number(assignmentId));
  if (!assignment) return;

  if (Number(assignment.all_attempts_count ?? assignment.attempts_count ?? 0) > 0) {
    alert('Удаление недоступно: по этому заданию уже есть попытки учеников.');
    return;
  }

  const classesText = assignment.class_names
    ? '\nЗадание также исчезнет у назначенных классов.'
    : '';
  const importText = assignment.source_format
    ? '\nИсходный ' + String(assignment.source_format).toUpperCase() + '-файл и изображения этого задания тоже будут удалены.'
    : '\nВсе вопросы и изображения этого задания тоже будут удалены.';

  if (!(await appConfirm(
    'Удалить задание «' + assignment.title + '»?' +
    classesText +
    importText +
    '\n\nЭто действие нельзя отменить.'
  ))) return;

  const iconButton = Boolean(button?.classList.contains('assignment-icon-btn'));
  const oldText = button?.textContent || 'Удалить';
  const oldTitle = button?.getAttribute('title') || 'Удалить задание';
  if (button) {
    button.disabled = true;
    if (iconButton) {
      button.classList.add('is-loading');
      button.setAttribute('aria-busy', 'true');
      button.setAttribute('title', 'Удаляем...');
    } else {
      button.textContent = 'Удаляем...';
    }
  }

  try {
    const response = await fetch('./api/assignments/delete.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ assignment_id: assignmentId })
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) {
      throw new Error(data.error || 'Не удалось удалить задание.');
    }

    await loadAssignments();
    if (selectedSubjectId) renderSubjectAssignments();
  } catch (error) {
    alert(error.message);
    if (button) {
      button.disabled = false;
      if (iconButton) {
        button.classList.remove('is-loading');
        button.removeAttribute('aria-busy');
        button.setAttribute('title', oldTitle);
      } else {
        button.textContent = oldText;
      }
    }
  }
}

function openDuplicateAssignment(assignmentId) {
  const assignment = assignmentsCache.find(item => Number(item.id) === Number(assignmentId));
  if (!assignment || !duplicateAssignmentModal) return;

  const source = document.getElementById('duplicateAssignmentSourceId');
  const name = document.getElementById('duplicateAssignmentName');
  const startsAt = document.getElementById('duplicateAssignmentStartsAt');
  const dueAt = document.getElementById('duplicateAssignmentDueAt');
  const timeLimit = document.getElementById('duplicateAssignmentTimeLimit');
  const maxAttempts = document.getElementById('duplicateAssignmentMaxAttempts');
  const focus = document.getElementById('duplicateAssignmentFocusPolicy');
  const title = document.getElementById('duplicateAssignmentTitle');
  const summary = document.getElementById('duplicateCopySummary');
  const error = document.getElementById('duplicateAssignmentError');

  error?.classList.add('hidden');
  if (source) source.value = String(assignment.id);
  if (name) name.value = `Копия — ${assignment.title}`;
  if (startsAt) startsAt.value = '';
  if (dueAt) dueAt.value = '';
  if (timeLimit) timeLimit.value = assignment.time_limit_minutes ?? '';
  if (maxAttempts) maxAttempts.value = String(assignment.max_attempts || 1);
  if (focus) focus.value = assignment.focus_policy === 'strict' ? 'strict' : 'allow';
  if (title) title.textContent = `Дублировать: ${assignment.title}`;

  if (summary) {
    summary.innerHTML = `
      <span><b>Предмет:</b> ${escapeHtml(assignment.subject_name || '—')}</span>
      <span><b>Вопросов:</b> ${Number(assignment.questions_count || assignment.parsed_question_count || 0)}</span>
      <span><b>Классы:</b> не копируются</span>`;
  }

  openModal(duplicateAssignmentModal);
}

document.getElementById('duplicateAssignmentForm')?.addEventListener('submit', async event => {
  event.preventDefault();
  const form = event.currentTarget;
  const error = document.getElementById('duplicateAssignmentError');
  const button = form.querySelector('button[type="submit"]');
  const assignmentId = Number(document.getElementById('duplicateAssignmentSourceId')?.value || 0);
  const title = document.getElementById('duplicateAssignmentName')?.value?.trim() || '';
  const startsAt = document.getElementById('duplicateAssignmentStartsAt')?.value || '';
  const dueAt = document.getElementById('duplicateAssignmentDueAt')?.value || '';
  const timeLimit = document.getElementById('duplicateAssignmentTimeLimit')?.value || '';
  const maxAttempts = document.getElementById('duplicateAssignmentMaxAttempts')?.value || '1';
  const focusPolicy = document.getElementById('duplicateAssignmentFocusPolicy')?.value || 'allow';

  error?.classList.add('hidden');

  if (!assignmentId || !title) {
    if (error) {
      error.textContent = 'Введите название новой копии.';
      error.classList.remove('hidden');
    }
    return;
  }
  if (startsAt && dueAt && new Date(dueAt).getTime() <= new Date(startsAt).getTime()) {
    if (error) {
      error.textContent = 'Дедлайн должен быть позже даты открытия.';
      error.classList.remove('hidden');
    }
    return;
  }

  button.disabled = true;
  button.textContent = 'Копируем...';

  try {
    const response = await fetch('./api/assignments/duplicate.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        assignment_id: assignmentId,
        title,
        starts_at: startsAt,
        due_at: dueAt,
        time_limit_minutes: timeLimit,
        max_attempts: maxAttempts,
        focus_policy: focusPolicy
      })
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) {
      throw new Error(data.error || 'Не удалось создать копию задания.');
    }

    closeModal(duplicateAssignmentModal);
    form.reset();
    await loadAssignments();
    showView('assignments');

    const newId = Number(data.assignment?.id || 0);
    if (newId) {
      setTimeout(() => openQuestionPreview(newId), 80);
    }
  } catch (e) {
    if (error) {
      error.textContent = e.message;
      error.classList.remove('hidden');
    }
  } finally {
    button.disabled = false;
    button.textContent = 'Создать копию и открыть конструктор';
  }
});

async function openAssignToClass(assignmentId) {
  const assignment = assignmentsCache.find(item => Number(item.id) === Number(assignmentId));
  if (!assignment || !assignToClassModal) return;

  const error = document.getElementById('assignToClassError');
  const select = document.getElementById('assignToClassSelect');
  const title = document.getElementById('assignToClassTitle');
  const hidden = document.getElementById('assignToClassAssignmentId');
  const timeInputs = [...document.querySelectorAll('input[name="assign_time_limit"]')];

  error?.classList.add('hidden');
  if (hidden) hidden.value = String(assignment.id);
  if (title) title.textContent = `Назначить: ${assignment.title}`;

  const legacyLimit = Number(assignment.time_limit_minutes || 0);
  const allowedPreset = [10,15,20,25,30,35,40,45,50,55,60].includes(legacyLimit)
    ? String(legacyLimit)
    : '';
  timeInputs.forEach(input => {
    input.checked = String(input.value) === allowedPreset;
  });

  try {
    await loadTeacherOptions();
    const available = subjectAvailableClasses(Number(assignment.subject_id));
    const alreadyAssigned = new Set(
      String(assignment.class_ids || '')
        .split(',')
        .map(value => Number(value))
        .filter(Boolean)
    );
    const choices = available.filter(item => !alreadyAssigned.has(Number(item.id)));

    if (select) {
      select.innerHTML = '<option value="">Выберите класс</option>' + choices.map(item =>
        `<option value="${item.id}">${escapeHtml(item.name)}</option>`
      ).join('');
    }

    if (!choices.length && error) {
      error.textContent = available.length
        ? 'Это задание уже назначено всем доступным вам классам.'
        : 'Для этого предмета вам пока не назначен ни один класс.';
      error.classList.remove('hidden');
    }
  } catch (e) {
    if (error) {
      error.textContent = e.message;
      error.classList.remove('hidden');
    }
  }

  openModal(assignToClassModal);
}

document.getElementById('assignToClassForm')?.addEventListener('submit', async event => {
  event.preventDefault();

  const assignmentId = Number(document.getElementById('assignToClassAssignmentId')?.value || 0);
  const classId = Number(document.getElementById('assignToClassSelect')?.value || 0);
  const timeLimitRaw = document.querySelector('input[name="assign_time_limit"]:checked')?.value ?? '';
  const timeLimit = timeLimitRaw === '' ? null : Number(timeLimitRaw);
  const error = document.getElementById('assignToClassError');
  const button = event.currentTarget.querySelector('button[type="submit"]');

  error?.classList.add('hidden');
  if (assignmentId < 1 || classId < 1) {
    if (error) {
      error.textContent = 'Выберите класс.';
      error.classList.remove('hidden');
    }
    return;
  }

  button.disabled = true;
  button.textContent = 'Назначаем...';

  try {
    const response = await fetch('./api/assignments/assign.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        assignment_id: assignmentId,
        class_id: classId,
        time_limit_minutes: timeLimit
      })
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось назначить задание.');

    closeModal(assignToClassModal);
    await loadAssignments();
  } catch (e) {
    if (error) {
      error.textContent = e.message;
      error.classList.remove('hidden');
    }
  } finally {
    button.disabled = false;
    button.textContent = 'Назначить классу';
  }
});

async function openShareSubject() {
  if (currentUser?.role !== 'admin' || !selectedSubjectId || !shareSubjectModal) return;

  const subject = subjectsCache.find(item => Number(item.id) === Number(selectedSubjectId));
  if (!subject) return;

  const schoolSelect = document.getElementById('shareTargetSchool');
  const list = document.getElementById('shareAssignmentsList');
  const error = document.getElementById('shareSubjectError');
  const result = document.getElementById('shareSubjectResult');
  const title = document.getElementById('shareSubjectTitle');

  error?.classList.add('hidden');
  result?.classList.add('hidden');
  if (title) title.textContent = `Отправить «${subject.name}» в другую школу`;
  if (schoolSelect) schoolSelect.innerHTML = '<option value="">Загрузка школ...</option>';
  if (list) list.innerHTML = '<p>Загрузка заданий...</p>';
  openModal(shareSubjectModal);

  try {
    await loadAssignments();

    const response = await fetch('./api/schools/share-targets.php', {
      credentials: 'same-origin',
      cache: 'no-store'
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось загрузить список школ.');

    if (schoolSelect) {
      schoolSelect.innerHTML = '<option value="">Выберите школу</option>' + (data.schools || []).map(school => {
        const city = school.city ? ' · ' + school.city : '';
        return `<option value="${school.id}">${escapeHtml(school.name + city)}</option>`;
      }).join('');
    }

    const subjectAssignments = assignmentsCache.filter(item => Number(item.subject_id) === Number(selectedSubjectId));
    if (list) {
      list.innerHTML = subjectAssignments.length
        ? subjectAssignments.map(item => `
            <label class="share-assignment-option">
              <input type="checkbox" value="${item.id}" checked>
              <span>
                <b>${escapeHtml(item.title)}</b>
                <small>${escapeHtml(item.class_names || 'Задание из библиотеки')} — классы и ученики не передаются</small>
              </span>
            </label>
          `).join('')
        : '<div class="subject-empty-list"><b>У предмета пока нет заданий</b><span>Можно отправить только сам предмет.</span></div>';
    }
  } catch (e) {
    if (error) {
      error.textContent = e.message;
      error.classList.remove('hidden');
    }
  }
}

document.getElementById('shareSubjectBtn')?.addEventListener('click', openShareSubject);

document.getElementById('shareSubjectForm')?.addEventListener('submit', async event => {
  event.preventDefault();
  if (currentUser?.role !== 'admin' || !selectedSubjectId) return;

  const targetSchoolId = Number(document.getElementById('shareTargetSchool')?.value || 0);
  const assignmentIds = [...document.querySelectorAll('#shareAssignmentsList input[type="checkbox"]:checked')]
    .map(input => Number(input.value))
    .filter(Boolean);
  const error = document.getElementById('shareSubjectError');
  const result = document.getElementById('shareSubjectResult');
  const button = event.currentTarget.querySelector('button[type="submit"]');

  error?.classList.add('hidden');
  result?.classList.add('hidden');

  if (targetSchoolId < 1) {
    if (error) {
      error.textContent = 'Выберите школу-получателя.';
      error.classList.remove('hidden');
    }
    return;
  }

  button.disabled = true;
  button.textContent = 'Отправляем...';

  try {
    const response = await fetch('./api/subjects/share.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        subject_id: selectedSubjectId,
        target_school_id: targetSchoolId,
        assignment_ids: assignmentIds
      })
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось отправить предмет.');

    if (result) {
      result.textContent = `Материалы отправлены во входящие школы «${data.target_school?.name || 'школы'}». Заданий в пакете: ${Number(data.transfer?.assignment_count || 0)}. Они появятся в библиотеке только после принятия администратором.`;
      result.classList.remove('hidden');
    }
  } catch (e) {
    if (error) {
      error.textContent = e.message;
      error.classList.remove('hidden');
    }
  } finally {
    button.disabled = false;
    button.textContent = 'Отправить во входящие';
  }
});

async function loadAssignments() {
  const body = document.getElementById('assignmentsTableBody');
  if (!body) return;
  body.innerHTML = '<tr><td colspan="6">Загрузка...</td></tr>';
  try {
    const response = await fetch('./api/assignments/list.php', { credentials: 'same-origin', cache: 'no-store' });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось загрузить задания.');
    assignmentsCache = data.assignments || [];
    assignmentWorkflowContext = {
      review_required: Boolean(data.workflow?.review_required),
      can_manage: Boolean(data.workflow?.can_manage)
    };
    renderAssignments();
    renderSubjectAssignments();
  } catch (error) {
    body.innerHTML = `<tr><td colspan="6">${escapeHtml(error.message)}</td></tr>`;
  }
}

document.getElementById('assignmentSearch')?.addEventListener('input', renderAssignments);
document.getElementById('assignmentStatusFilter')?.addEventListener('change', renderAssignments);

document.getElementById('taskForm')?.addEventListener('submit', async event => {
  event.preventDefault();
  const form = event.currentTarget;
  const error = document.getElementById('taskFormError');
  const submit = form.querySelector('button[type="submit"]');
  error.classList.add('hidden');
  submit.disabled = true;
  submit.textContent = 'Создаём...';

  try {
    const payload = Object.fromEntries(new FormData(form).entries());
    payload.variant_count = '1';
    delete payload.shuffle_questions;
    delete payload.shuffle_options;
    delete payload.shuffle_structured;
    const response = await fetch('./api/assignments/create.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось создать задание.');

    form.reset();
    document.getElementById('strictWarning')?.classList.add('hidden');
    closeModal(taskModal);
    await loadAssignments();
    const createdSubjectId = Number(payload.subject_id || 0);
    if (selectedSubjectId && createdSubjectId === Number(selectedSubjectId)) {
      showView('subjects');
      renderSubjectAssignments();
    } else {
      showView('assignments');
    }
  } catch (e) {
    error.textContent = e.message;
    error.classList.remove('hidden');
  } finally {
    submit.disabled = false;
    submit.textContent = 'Создать черновик';
  }
});

const quiz = [
  {
    q: 'Сколько будет 8 × 7?',
    options: ['54', '56', '64', '49'],
    correct: 1
  },
  {
    q: 'Какое число является результатом 144 ÷ 12?',
    options: ['10', '11', '12', '14'],
    correct: 2
  },
  {
    q: 'Чему равен периметр квадрата со стороной 5 см?',
    options: ['10 см', '15 см', '20 см', '25 см'],
    correct: 2
  },
  {
    q: 'Какое из чисел является простым?',
    options: ['21', '27', '29', '33'],
    correct: 2
  },
  {
    q: 'Чему равно 25% от 80?',
    options: ['15', '20', '25', '30'],
    correct: 1
  }
];

function renderQuiz() {
  const html = quiz.map((item, index) => `
    <div class="question">
      <h4>${index + 1}. ${item.q}</h4>
      <div class="answers">
        ${item.options.map((option, oi) => `
          <label class="answer">
            <input type="radio" name="q${index}" value="${oi}">
            <span>${option}</span>
          </label>`).join('')}
      </div>
    </div>`).join('');

  document.getElementById('quizContent').innerHTML = `
    <div class="quiz-head">
      <span class="section-kicker">Математика · 7А</span>
      <h2>Контрольная работа №2</h2>
      <div class="quiz-meta"><span>5 демо-вопросов</span><span>1 попытка</span><span>Автопроверка</span></div>
    </div>
    <form id="quizForm">${html}<button class="primary-btn full" type="submit">Завершить работу</button></form>`;

  document.getElementById('quizForm').addEventListener('submit', submitQuiz);
}

function submitQuiz(e) {
  e.preventDefault();
  let correct = 0;
  quiz.forEach((item, index) => {
    const selected = e.currentTarget.querySelector(`input[name="q${index}"]:checked`);
    if (selected && Number(selected.value) === item.correct) correct++;
  });

  const percent = Math.round((correct / quiz.length) * 100);
  let grade = 2;
  if (percent >= 90) grade = 5;
  else if (percent >= 75) grade = 4;
  else if (percent >= 50) grade = 3;

  document.getElementById('quizContent').innerHTML = `
    <div class="result-card">
      <span class="section-kicker">Работа завершена</span>
      <h2>Твой результат</h2>
      <div class="result-circle" style="--score:${percent}%"><strong>${percent}%</strong></div>
      <p>Правильных ответов: <b>${correct} из ${quiz.length}</b></p>
      <div class="result-grade">${grade}</div>
      <p>Оценка по текущей шкале</p>
      <button class="primary-btn" id="finishResultBtn">Вернуться к заданиям</button>
    </div>`;

  document.getElementById('finishResultBtn').addEventListener('click', () => {
    closeModal(quizModal);
    showView('student-results');
  });
}

function startQuiz() {
  renderQuiz();
  openModal(quizModal);
}

document.getElementById('startQuizBtn')?.addEventListener('click', startQuiz);
document.querySelectorAll('.start-quiz').forEach(btn => btn.addEventListener('click', startQuiz));

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, char => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
  })[char]);
}

let schoolTeachersCache = [];
let schoolAdminsCache = [];
let selectedTeacherForAssignments = null;
let editingSchoolAdminId = null;

async function loadAssignmentReviewSettings() {
  if (currentUser?.role !== 'admin') return;
  const checkbox = document.getElementById('assignmentReviewRequired');
  try {
    const response = await fetch('./api/school/settings.php', {
      credentials: 'same-origin',
      cache: 'no-store'
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось загрузить настройки школы.');
    if (checkbox) checkbox.checked = Boolean(data.settings?.assignment_review_required);
  } catch (error) {
    const node = document.getElementById('assignmentReviewSettingsError');
    if (node) {
      node.textContent = error.message;
      node.classList.remove('hidden');
    }
  }
}

document.getElementById('assignmentReviewSettingsForm')?.addEventListener('submit', async event => {
  event.preventDefault();
  const checkbox = document.getElementById('assignmentReviewRequired');
  const error = document.getElementById('assignmentReviewSettingsError');
  const result = document.getElementById('assignmentReviewSettingsResult');
  const button = event.currentTarget.querySelector('button[type="submit"]');

  error?.classList.add('hidden');
  result?.classList.add('hidden');
  button.disabled = true;
  button.textContent = 'Сохраняем...';

  try {
    const response = await fetch('./api/school/settings.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ assignment_review_required: Boolean(checkbox?.checked) })
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось сохранить настройку.');

    assignmentWorkflowContext.review_required = Boolean(data.settings?.assignment_review_required);
    if (result) {
      result.textContent = assignmentWorkflowContext.review_required
        ? 'Проверка заданий администратором включена.'
        : 'Учителя могут готовить задания к назначению без обязательной проверки.';
      result.classList.remove('hidden');
    }
    await loadAssignments();
  } catch (e) {
    if (error) {
      error.textContent = e.message;
      error.classList.remove('hidden');
    }
  } finally {
    button.disabled = false;
    button.textContent = 'Сохранить настройку';
  }
});

async function loadSchoolManagement() {
  if (currentUser?.role !== 'admin') return;
  loadAssignmentReviewSettings().catch(() => {});
  loadIncomingMaterials().catch(() => {});
  const subjectList = document.getElementById('schoolSubjectsList');
  const teacherBody = document.getElementById('schoolTeachersBody');
  const adminsBody = document.getElementById('schoolAdminsBody');
  if (!subjectList || !teacherBody) return;

  subjectList.innerHTML = '<p>Загрузка предметов...</p>';
  teacherBody.innerHTML = '<tr><td colspan="4">Загрузка...</td></tr>';
  if (adminsBody) {
    adminsBody.innerHTML = '<tr><td colspan="4">Загрузка...</td></tr>';
  }

  try {
    const requests = [
      fetch('./api/subjects/list.php', { credentials: 'same-origin', cache: 'no-store' }),
      fetch('./api/school/teachers/list.php', { credentials: 'same-origin', cache: 'no-store' })
    ];
    requests.push(fetch('./api/school/admins/list.php', { credentials: 'same-origin', cache: 'no-store' }));

    const responses = await Promise.all(requests);
    const subjectsData = await responses[0].json();
    const teachersData = await responses[1].json();
    if (!responses[0].ok || subjectsData.ok === false) throw new Error(subjectsData.error || 'Не удалось загрузить предметы.');
    if (!responses[1].ok || teachersData.ok === false) throw new Error(teachersData.error || 'Не удалось загрузить учителей.');

    subjectsCache = subjectsData.subjects || [];
    schoolTeachersCache = teachersData.teachers || [];

    subjectList.innerHTML = subjectsCache.length
      ? subjectsCache.map(subject => `<span class="subject-admin-chip">${escapeHtml(subject.name)}</span>`).join('')
      : '<p>Предметов пока нет. Добавьте первый предмет.</p>';

    teacherBody.innerHTML = schoolTeachersCache.length ? schoolTeachersCache.map(teacher => {
      const assignmentText = (teacher.assignments || []).length
        ? teacher.assignments.map(item => `${item.subject_name} — ${item.class_name}`).join(', ')
        : 'Не назначены';
      const alsoAdmin = ['school_admin', 'owner'].includes(String(teacher.school_role || ''));
      const canManageAdmins = Number(currentUser?.is_platform_admin) === 1;
      const accessSent = Boolean(teacher.credentials_sent_at);
      const loginText = teacher.login_name ? `Логин: ${escapeHtml(teacher.login_name)}` : 'Логин будет создан при отправке';
      return `
        <tr>
          <td>
            <b>${escapeHtml(teacher.last_name)} ${escapeHtml(teacher.first_name)}</b>
            ${alsoAdmin ? '<small class="role-note">Администратор + учитель</small>' : ''}
          </td>
          <td>
            <span class="teacher-email">${escapeHtml(teacher.email)}</span>
            <small class="teacher-login">${loginText}</small>
            <small class="access-state ${accessSent ? 'sent' : 'pending'}">${accessSent ? 'Доступ отправлен' : 'Доступ ещё не отправлен'}</small>
          </td>
          <td><small>${escapeHtml(assignmentText)}</small></td>
          <td class="row-actions-cell">
            <button class="secondary-btn compact-btn" type="button" data-staff-profile="${teacher.id}">Профиль</button>
            <button class="secondary-btn compact-btn" type="button" data-teacher-assign="${teacher.id}">Назначить</button>
            <button class="primary-btn compact-btn" type="button" data-send-teacher-access="${teacher.id}">${accessSent ? 'Отправить новый доступ' : 'Отправить доступ'}</button>
            ${canManageAdmins && !alsoAdmin ? `<button class="secondary-btn compact-btn" type="button" data-promote-teacher="${teacher.id}">＋ Права администратора</button>` : ''}
          </td>
        </tr>`;
    }).join('') : '<tr><td colspan="4">Учителей пока нет.</td></tr>';

    teacherBody.querySelectorAll('[data-staff-profile]').forEach(button => {
      button.addEventListener('click', () => loadStaffProfile(Number(button.dataset.staffProfile), true).catch(error => alert(error.message)));
    });
    teacherBody.querySelectorAll('[data-teacher-assign]').forEach(button => {
      button.addEventListener('click', () => openTeacherAssignments(Number(button.dataset.teacherAssign)));
    });
    teacherBody.querySelectorAll('[data-send-teacher-access]').forEach(button => {
      button.addEventListener('click', () => sendTeacherAccess(Number(button.dataset.sendTeacherAccess), button));
    });
    teacherBody.querySelectorAll('[data-promote-teacher]').forEach(button => {
      button.addEventListener('click', () => promoteTeacherToAdmin(Number(button.dataset.promoteTeacher)));
    });

    if (adminsBody) {
      const adminsData = await responses[2].json();
      if (!responses[2].ok || adminsData.ok === false) throw new Error(adminsData.error || 'Не удалось загрузить администраторов.');
      schoolAdminsCache = adminsData.admins || [];
      const canEditAdminAccounts = Boolean(adminsData.can_edit_admin_accounts);

      adminsBody.innerHTML = schoolAdminsCache.length ? schoolAdminsCache.map(admin => {
        const teaches = Number(admin.can_teach) === 1;
        return `
        <tr>
          <td>
            <b>${escapeHtml(admin.last_name)} ${escapeHtml(admin.first_name)}</b>
            ${teaches ? '<small class="role-note">Администратор + учитель</small>' : ''}
          </td>
          <td>${escapeHtml(admin.email)}</td>
          <td>
            <span class="status green">Администратор</span>
            ${teaches ? '<span class="status blue">Учитель</span>' : ''}
          </td>
          <td class="row-actions-cell">
            <button class="secondary-btn compact-btn" type="button" data-staff-profile="${admin.id}">Профиль</button>
            ${canEditAdminAccounts ? `<button class="secondary-btn compact-btn" type="button" data-edit-school-admin="${admin.id}">Изменить</button>` : ''}
            ${canEditAdminAccounts ? `<button class="secondary-btn compact-btn" type="button" data-toggle-admin-teacher="${admin.id}" data-enabled="${teaches ? '0' : '1'}">${teaches ? 'Убрать роль учителя' : '＋ Сделать также учителем'}</button>` : ''}
            ${canEditAdminAccounts ? `<button class="secondary-btn compact-btn" type="button" data-demote-admin="${admin.id}">Оставить только учителем</button>` : ''}
            ${canEditAdminAccounts ? `<button class="mini-action danger-action" type="button" data-remove-school-admin="${admin.id}">Удалить из школы</button>` : ''}
          </td>
        </tr>`;
      }).join('') : '<tr><td colspan="4">Администраторы не назначены.</td></tr>';

      adminsBody.querySelectorAll('[data-staff-profile]').forEach(button => {
        button.addEventListener('click', () => loadStaffProfile(Number(button.dataset.staffProfile), true).catch(error => alert(error.message)));
      });
      adminsBody.querySelectorAll('[data-edit-school-admin]').forEach(button => {
        button.addEventListener('click', () => openSchoolAdminEditor(Number(button.dataset.editSchoolAdmin)));
      });
      adminsBody.querySelectorAll('[data-remove-school-admin]').forEach(button => {
        button.addEventListener('click', () => removeSchoolAdmin(Number(button.dataset.removeSchoolAdmin)));
      });
      adminsBody.querySelectorAll('[data-demote-admin]').forEach(button => {
        button.addEventListener('click', () => demoteAdminToTeacher(Number(button.dataset.demoteAdmin)));
      });
      adminsBody.querySelectorAll('[data-toggle-admin-teacher]').forEach(button => {
        button.addEventListener('click', () => setAdminTeacherRole(
          Number(button.dataset.toggleAdminTeacher),
          button.dataset.enabled === '1'
        ));
      });
    }
  } catch (error) {
    subjectList.innerHTML = `<p>${escapeHtml(error.message)}</p>`;
    teacherBody.innerHTML = `<tr><td colspan="4">${escapeHtml(error.message)}</td></tr>`;
    if (adminsBody) {
      adminsBody.innerHTML = `<tr><td colspan="4">${escapeHtml(error.message)}</td></tr>`;
    }
  }
}

document.getElementById('addSubjectBtn')?.addEventListener('click', () => openModal(subjectModal));
document.getElementById('addTeacherBtn')?.addEventListener('click', () => openModal(teacherModal));

function openSchoolAdminEditor(adminId = null) {
  if (Number(currentUser?.is_platform_admin) !== 1) return;

  const form = document.getElementById('schoolAdminForm');
  const title = document.getElementById('schoolAdminModalTitle');
  const password = document.getElementById('schoolAdminPassword');
  const hint = document.getElementById('schoolAdminPasswordHint');
  const save = document.getElementById('saveSchoolAdminBtn');
  const error = document.getElementById('schoolAdminFormError');

  form.reset();
  error.classList.add('hidden');
  editingSchoolAdminId = adminId ? Number(adminId) : null;
  document.getElementById('schoolAdminId').value = editingSchoolAdminId || '';

  if (editingSchoolAdminId) {
    const admin = schoolAdminsCache.find(item => Number(item.id) === editingSchoolAdminId);
    if (!admin) return;
    document.getElementById('schoolAdminFirstName').value = admin.first_name || '';
    document.getElementById('schoolAdminLastName').value = admin.last_name || '';
    document.getElementById('schoolAdminEmail').value = admin.email || '';
    title.textContent = 'Изменить администратора';
    password.required = false;
    hint.textContent = 'Оставьте пустым, если пароль менять не нужно.';
    save.textContent = 'Сохранить изменения';
  } else {
    title.textContent = 'Назначить администратора';
    password.required = true;
    hint.textContent = 'Для нового администратора — минимум 8 символов.';
    save.textContent = 'Назначить администратора';
  }

  openModal(schoolAdminModal);
}

document.getElementById('addSchoolAdminBtn')?.addEventListener('click', () => openSchoolAdminEditor());

document.getElementById('schoolAdminForm')?.addEventListener('submit', async event => {
  event.preventDefault();
  if (Number(currentUser?.is_platform_admin) !== 1) return;

  const form = event.currentTarget;
  const error = document.getElementById('schoolAdminFormError');
  const button = document.getElementById('saveSchoolAdminBtn');
  const payload = Object.fromEntries(new FormData(form).entries());
  const editing = Boolean(editingSchoolAdminId);

  error.classList.add('hidden');
  button.disabled = true;
  button.textContent = editing ? 'Сохраняем...' : 'Назначаем...';

  try {
    const response = await fetch(editing ? './api/school/admins/update.php' : './api/school/admins/create.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось сохранить администратора.');

    closeModal(schoolAdminModal);
    editingSchoolAdminId = null;
    await loadSchoolManagement();
    await loadSchools();
  } catch (e) {
    error.textContent = e.message;
    error.classList.remove('hidden');
  } finally {
    button.disabled = false;
    button.textContent = editing ? 'Сохранить изменения' : 'Назначить администратора';
  }
});

async function removeSchoolAdmin(adminId) {
  const admin = schoolAdminsCache.find(item => Number(item.id) === Number(adminId));
  if (!admin) return;
  if (!(await appConfirm(`Снять права администратора у ${admin.last_name} ${admin.first_name}?`))) return;

  try {
    const response = await fetch('./api/school/admins/remove.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ admin_id: adminId })
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось снять администратора.');
    await loadSchoolManagement();
    await loadSchools();
  } catch (error) {
    alert(error.message);
  }
}

async function promoteTeacherToAdmin(teacherId) {
  if (Number(currentUser?.is_platform_admin) !== 1) return;
  const teacher = schoolTeachersCache.find(item => Number(item.id) === Number(teacherId));
  if (!teacher) return;
  if (!(await appConfirm(`Добавить ${teacher.last_name} ${teacher.first_name} права администратора школы? Права учителя и текущие назначения сохранятся.`))) return;

  try {
    const response = await fetch('./api/school/teachers/promote.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ teacher_id: teacherId })
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось изменить роль учителя.');
    await loadSchoolManagement();
  } catch (error) {
    alert(error.message);
  }
}

async function setAdminTeacherRole(adminId, enabled) {
  if (Number(currentUser?.is_platform_admin) !== 1) return;
  const admin = schoolAdminsCache.find(item => Number(item.id) === Number(adminId));
  if (!admin) return;

  const action = enabled ? 'добавить роль учителя' : 'убрать роль учителя';
  if (!(await appConfirm(`${enabled ? 'Добавить' : 'Убрать'} роль учителя для ${admin.last_name} ${admin.first_name}?`))) return;

  try {
    const response = await fetch('./api/school/admins/set-teacher.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ admin_id: adminId, enabled })
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || `Не удалось ${action}.`);
    await loadSchoolManagement();
  } catch (error) {
    alert(error.message);
  }
}

async function demoteAdminToTeacher(adminId) {
  if (Number(currentUser?.is_platform_admin) !== 1) return;
  const admin = schoolAdminsCache.find(item => Number(item.id) === Number(adminId));
  if (!admin) return;
  if (!(await appConfirm(`Снять у ${admin.last_name} ${admin.first_name} права администратора и оставить только роль учителя?`))) return;

  try {
    const response = await fetch('./api/school/admins/demote.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ admin_id: adminId })
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось изменить роль администратора.');
    await loadSchoolManagement();
  } catch (error) {
    alert(error.message);
  }
}

document.querySelectorAll('[data-theme-color]').forEach(button => {
  button.addEventListener('click', () => {
    const color = normalizeHexColor(button.dataset.themeColor);
    const storedColor = document.getElementById('schoolThemeColor');
    if (storedColor) storedColor.value = color;
    applySchoolBranding({ theme_color: color });
    document.querySelectorAll('[data-theme-color]').forEach(item => item.classList.toggle('active', item === button));
  });
});

document.getElementById('schoolBrandingForm')?.addEventListener('submit', async event => {
  event.preventDefault();
  if (Number(currentUser?.is_platform_admin) !== 1) return;

  const form = event.currentTarget;
  const error = document.getElementById('schoolBrandingError');
  const result = document.getElementById('schoolBrandingResult');
  const button = form.querySelector('button[type="submit"]');
  const color = String(document.getElementById('schoolThemeColor')?.value || '').trim().toLowerCase();

  error?.classList.add('hidden');
  result?.classList.add('hidden');

  if (!/^#[0-9a-f]{6}$/.test(color)) {
    if (error) {
      error.textContent = 'Укажите цвет в формате #RRGGBB.';
      error.classList.remove('hidden');
    }
    return;
  }

  button.disabled = true;
  button.textContent = 'Сохраняем...';

  try {
    const response = await fetch('./api/school/branding/update.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ theme_color: color })
    });
    const payload = await response.json();
    if (!response.ok || payload.ok === false) throw new Error(payload.error || 'Не удалось сохранить цвет школы.');

    applySchoolBranding(payload.branding);
    const activeSchool = schoolsCache.find(school => Number(school.id) === Number(document.getElementById('schoolSelector')?.value || 0));
    if (activeSchool) activeSchool.theme_color = payload.branding?.theme_color || color;
    if (result) {
      result.textContent = 'Цвет школы сохранён.';
      result.classList.remove('hidden');
    }
  } catch (e) {
    if (error) {
      error.textContent = e.message;
      error.classList.remove('hidden');
    }
  } finally {
    button.disabled = false;
    button.textContent = 'Сохранить цвет школы';
  }
});

document.getElementById('subjectForm')?.addEventListener('submit', async event => {
  event.preventDefault();
  const form = event.currentTarget;
  const error = document.getElementById('subjectFormError');
  const button = form.querySelector('button[type="submit"]');
  error.classList.add('hidden');
  button.disabled = true;
  try {
    const response = await fetch('./api/subjects/create.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(Object.fromEntries(new FormData(form).entries()))
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось добавить предмет.');
    form.reset();
    closeModal(subjectModal);
    subjectsCache = [];
    await Promise.all([loadSubjects(), currentUser?.role === 'admin' ? loadSchoolManagement() : Promise.resolve()]);
    if (data.subject?.id) {
      showView('subjects');
      await openSubjectDetails(Number(data.subject.id));
    }
  } catch (e) {
    error.textContent = e.message;
    error.classList.remove('hidden');
  } finally {
    button.disabled = false;
  }
});

document.getElementById('teacherForm')?.addEventListener('submit', async event => {
  event.preventDefault();
  const form = event.currentTarget;
  const error = document.getElementById('teacherFormError');
  const button = form.querySelector('button[type="submit"]');
  error.classList.add('hidden');
  button.disabled = true;
  try {
    const response = await fetch('./api/school/teachers/create.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(Object.fromEntries(new FormData(form).entries()))
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось создать учителя.');
    form.reset();
    closeModal(teacherModal);
    await loadSchoolManagement();
  } catch (e) {
    error.textContent = e.message;
    error.classList.remove('hidden');
  } finally {
    button.disabled = false;
  }
});

async function sendTeacherAccess(teacherId, button) {
  const teacher = schoolTeachersCache.find(item => Number(item.id) === Number(teacherId));
  if (!teacher) return;

  const repeated = Boolean(teacher.credentials_sent_at);
  const message = repeated
    ? `Отправить новые данные доступа на ${teacher.email}? Старые логин и пароль перестанут работать.`
    : `Отправить логин и временный пароль на ${teacher.email}?`;
  if (!(await appConfirm(message))) return;

  const originalText = button?.textContent || 'Отправить доступ';
  if (button) {
    button.disabled = true;
    button.textContent = 'Отправляем...';
  }

  try {
    const response = await fetch('./api/school/teachers/send-access.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ teacher_id: teacherId })
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) {
      throw new Error(data.error || 'Не удалось отправить данные доступа.');
    }

    alert(`Данные доступа отправлены на ${data.email}. Учитель должен войти и сменить временный пароль.`);
    await loadSchoolManagement();
  } catch (error) {
    alert(error.message);
  } finally {
    if (button && document.body.contains(button)) {
      button.disabled = false;
      button.textContent = originalText;
    }
  }
}

async function openTeacherAssignments(teacherId) {
  const teacher = schoolTeachersCache.find(item => Number(item.id) === Number(teacherId));
  if (!teacher) return;
  selectedTeacherForAssignments = teacher;
  document.getElementById('teacherAssignmentsTitle').textContent = `Назначения — ${teacher.last_name} ${teacher.first_name}`;

  if (!classesCache.length) await loadClasses();
  if (!subjectsCache.length) await loadSubjects();

  const selected = new Set((teacher.assignments || []).map(item => `${item.subject_id}:${item.class_id}`));
  const matrix = document.getElementById('teacherAssignmentsMatrix');

  if (!subjectsCache.length || !classesCache.length) {
    matrix.innerHTML = '<p>Сначала администратор должен добавить предметы и классы.</p>';
  } else {
    matrix.innerHTML = subjectsCache.map(subject => `
      <div class="assignment-matrix-row">
        <strong>${escapeHtml(subject.name)}</strong>
        <div class="assignment-class-options">
          ${classesCache.map(cls => {
            const key = `${subject.id}:${cls.id}`;
            return `<label><input type="checkbox" data-subject-id="${subject.id}" data-class-id="${cls.id}" ${selected.has(key) ? 'checked' : ''}><span>${escapeHtml(cls.name)}</span></label>`;
          }).join('')}
        </div>
      </div>
    `).join('');
  }

  document.getElementById('teacherAssignmentsError').classList.add('hidden');
  openModal(teacherAssignmentsModal);
}

document.getElementById('saveTeacherAssignmentsBtn')?.addEventListener('click', async () => {
  if (!selectedTeacherForAssignments) return;
  const button = document.getElementById('saveTeacherAssignmentsBtn');
  const error = document.getElementById('teacherAssignmentsError');
  error.classList.add('hidden');
  const assignments = [...document.querySelectorAll('#teacherAssignmentsMatrix input[type="checkbox"]:checked')].map(input => ({
    subject_id: Number(input.dataset.subjectId),
    class_id: Number(input.dataset.classId)
  }));

  button.disabled = true;
  button.textContent = 'Сохраняем...';
  try {
    const response = await fetch('./api/school/teachers/assign.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ teacher_id: selectedTeacherForAssignments.id, assignments })
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось сохранить назначения.');
    closeModal(teacherAssignmentsModal);
    selectedTeacherForAssignments = null;
    await loadSchoolManagement();
  } catch (e) {
    error.textContent = e.message;
    error.classList.remove('hidden');
  } finally {
    button.disabled = false;
    button.textContent = 'Сохранить назначения';
  }
});

document.querySelectorAll('[data-app-password-target]').forEach(button => {
  button.addEventListener('click', () => {
    const input = document.getElementById(button.dataset.appPasswordTarget);
    if (!input) return;
    const visible = input.type === 'text';
    input.type = visible ? 'password' : 'text';
    button.textContent = visible ? 'Показать' : 'Скрыть';
  });
});

document.getElementById('temporaryPasswordForm')?.addEventListener('submit', async event => {
  event.preventDefault();
  const form = event.currentTarget;
  const error = document.getElementById('temporaryPasswordError');
  const button = document.getElementById('temporaryPasswordSaveBtn');
  const payload = Object.fromEntries(new FormData(form).entries());

  error?.classList.add('hidden');
  if (payload.new_password !== payload.confirm_password) {
    if (error) {
      error.textContent = 'Пароли не совпадают.';
      error.classList.remove('hidden');
    }
    return;
  }

  button.disabled = true;
  button.textContent = 'Сохраняем...';

  try {
    const response = await fetch('./api/auth/change-password.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось изменить пароль.');

    if (currentUser) currentUser.must_change_password = 0;
    form.reset();
    closeModal(temporaryPasswordModal);
  } catch (e) {
    if (error) {
      error.textContent = e.message;
      error.classList.remove('hidden');
    }
  } finally {
    button.disabled = false;
    button.textContent = 'Сохранить новый пароль';
  }
});


let studentAssignmentsCache = [];
let activeStudentAssignment = null;
let activeStudentAttempt = null;
let activeStudentQuestions = [];
let activeStaffPreview = null;
let quizCountdownTimer = null;
let quizTimeoutHandled = false;
let quizSubmitting = false;
let quizExitBeaconSent = false;

function stopQuizCountdown() {
  if (quizCountdownTimer) {
    window.clearInterval(quizCountdownTimer);
    quizCountdownTimer = null;
  }
  quizTimeoutHandled = false;
}

function formatQuizRemaining(totalSeconds) {
  const safe = Math.max(0, Math.floor(totalSeconds));
  const minutes = Math.floor(safe / 60);
  const seconds = safe % 60;
  return String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
}

async function handleQuizTimeExpired() {
  if (quizTimeoutHandled || !activeStudentAttempt?.id) return;
  quizTimeoutHandled = true;

  try {
    const response = await fetch('./api/attempts/heartbeat.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ attempt_id: activeStudentAttempt.id })
    });
    const data = await response.json().catch(() => ({}));

    if (data?.active === false && data?.result) {
      renderRealAttemptResult(data.result, 'Время выполнения закончилось. Работа завершена автоматически.');
      return;
    }
  } catch {}

  window.setTimeout(() => {
    quizTimeoutHandled = false;
  }, 2500);
}

function startQuizCountdown(startedAt, timeLimitMinutes) {
  stopQuizCountdown();
  const node = document.getElementById('quizTimeRemaining');
  const limit = Number(timeLimitMinutes || 0);
  if (!node || limit <= 0) return;

  const normalized = String(startedAt || '').replace(' ', 'T') + (String(startedAt || '').includes('Z') ? '' : 'Z');
  const startMs = Date.parse(normalized);
  if (!Number.isFinite(startMs)) return;

  const deadlineMs = startMs + (limit * 60 * 1000);
  const tick = () => {
    const remaining = Math.ceil((deadlineMs - Date.now()) / 1000);
    node.textContent = remaining > 0 ? formatQuizRemaining(remaining) : '00:00';
    node.classList.toggle('urgent', remaining > 0 && remaining <= 300);
    node.classList.toggle('expired', remaining <= 0);

    if (remaining <= 0) {
      if (quizCountdownTimer) {
        window.clearInterval(quizCountdownTimer);
        quizCountdownTimer = null;
      }
      void handleQuizTimeExpired();
    }
  };

  tick();
  quizCountdownTimer = window.setInterval(tick, 1000);
}

function formatStudentDeadline(value) {
  if (!value) return 'без срока';
  const date = new Date(String(value).replace(' ', 'T') + (String(value).includes('Z') ? '' : 'Z'));
  if (Number.isNaN(date.getTime())) return String(value);
  return date.toLocaleString('ru-RU', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
}

function renderStudentAssignmentCards(target, rows) {
  if (!target) return;
  if (!rows.length) {
    target.innerHTML = '<article class="student-task"><h3>Заданий пока нет</h3><p>Когда учитель назначит работу вашему классу, она появится здесь.</p></article>';
    return;
  }

  target.innerHTML = rows.map(item => {
    const completed = Number(item.completed_attempts || 0);
    const maxAttempts = Number(item.max_attempts || 1);
    const activeAttempt = Number(item.active_attempt_id || 0);
    const exhausted = completed >= maxAttempts && !activeAttempt;
    const timeText = item.time_limit_minutes ? `${Number(item.time_limit_minutes)} мин.` : 'без ограничения';
    const state = activeAttempt
      ? 'В процессе'
      : exhausted
        ? `Завершено${item.last_percent !== null ? ' · ' + Math.round(Number(item.last_percent)) + '%' : ''}`
        : `Попыток: ${completed}/${maxAttempts}`;

    return `
      <article class="student-task" data-student-assignment-card="${item.id}">
        <div class="student-task-top">
          <span class="subject-pill">${escapeHtml(item.subject_name || 'Предмет')}</span>
          <span class="status ${activeAttempt ? 'blue' : exhausted ? 'green' : 'amber'}">${escapeHtml(formatStudentDeadline(item.due_at))}</span>
        </div>
        <h3>${escapeHtml(item.title)}</h3>
        <p>${Number(item.questions_count || 0)} вопросов · ${timeText} · ${maxAttempts} попыт.</p>
        <div class="student-task-bottom">
          <span>${state}</span>
          ${exhausted
            ? '<button class="secondary-btn" type="button" disabled>Выполнено</button>'
            : `<button class="${activeAttempt ? 'secondary-btn' : 'primary-btn'}" type="button" data-start-real-assignment="${item.id}">${activeAttempt ? 'Продолжить' : 'Начать'}</button>`}
        </div>
      </article>`;
  }).join('');

  target.querySelectorAll('[data-start-real-assignment]').forEach(button => {
    button.addEventListener('click', () => startRealStudentAssignment(Number(button.dataset.startRealAssignment)));
  });
}

async function loadStudentAssignments() {
  const dashboard = document.getElementById('studentDashboardTasks');
  const allTasks = document.getElementById('studentTasksList');
  if (dashboard) dashboard.innerHTML = '<article class="student-task"><p>Загрузка заданий...</p></article>';
  if (allTasks) allTasks.innerHTML = '<article class="student-task"><p>Загрузка заданий...</p></article>';

  try {
    const response = await fetch('./api/student/assignments.php', { credentials: 'same-origin', cache: 'no-store' });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось загрузить задания.');

    studentAssignmentsCache = data.assignments || [];
    const activeRows = studentAssignmentsCache.filter(item =>
      Number(item.active_attempt_id || 0) > 0 || Number(item.completed_attempts || 0) < Number(item.max_attempts || 1)
    );

    renderStudentAssignmentCards(dashboard, activeRows.slice(0, 4));
    renderStudentAssignmentCards(allTasks, studentAssignmentsCache);

    const classBadge = document.getElementById('studentClassBadge');
    const welcome = document.getElementById('studentWelcomeTitle');
    const welcomeText = document.getElementById('studentWelcomeText');
    const count = document.getElementById('studentActiveTasksCount');
    if (classBadge) classBadge.textContent = data.class?.name ? data.class.name + ' класс' : 'Мой класс';
    if (welcome) welcome.textContent = `Привет, ${currentUser?.first_name || 'ученик'}!`;
    if (welcomeText) welcomeText.textContent = activeRows.length
      ? `У тебя ${activeRows.length} текущих заданий.`
      : 'Сейчас нет заданий, которые нужно выполнить.';
    if (count) count.textContent = String(activeRows.length);
  } catch (error) {
    const html = `<article class="student-task"><p>${escapeHtml(error.message)}</p></article>`;
    if (dashboard) dashboard.innerHTML = html;
    if (allTasks) allTasks.innerHTML = html;
  }
}

function parseSavedAnswer(raw, fallback) {
  if (!raw) return fallback;
  const value = String(raw).trim();
  if (!value.startsWith('[') && !value.startsWith('{')) return raw;
  try { return JSON.parse(value); } catch { return raw; }
}

async function saveRealStudentAnswer(questionId, payload) {
  if (activeStaffPreview) return { preview: true };
  if (!activeStudentAttempt) {
    throw new Error('Попытка не активна. Ответ не сохранён.');
  }
  if (AttemptSecurity.isLocked()) {
    throw new Error('Попытка заблокирована системой контроля. Ответ не сохранён.');
  }
  const response = await fetch('./api/attempts/answer.php', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      attempt_id: activeStudentAttempt.id,
      question_id: questionId,
      ...payload
    })
  });
  const data = await response.json();
  if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось сохранить ответ.');
}

function renderRealStudentQuestion(question, index, savedRaw) {
  const interaction = canonicalQuestionType(question.interaction_type || question.type);
  const saved = parseSavedAnswer(savedRaw, interaction === 'matching' ? {} : interaction === 'order' ? [] : '');
  const assets = (question.assets || []).map(asset =>
    `<img class="real-question-image" src="${escapeHtml(asset.url)}" alt="Изображение к вопросу">`
  ).join('');

  let controls = '';
  if (['single', 'true_false'].includes(interaction)) {
    const selected = Array.isArray(saved) ? saved.map(Number) : [];
    controls = `<div class="answers real-answer-options">${(question.options || []).map(option => `
      <label class="answer">
        <input type="radio" name="real-q-${question.id}" value="${option.id}" ${selected.includes(Number(option.id)) ? 'checked' : ''}>
        <span>${escapeHtml(option.text)}</span>
      </label>`).join('')}</div>`;
  } else if (interaction === 'multiple') {
    const selected = Array.isArray(saved) ? saved.map(Number) : [];
    controls = `<div class="answers real-answer-options">${(question.options || []).map(option => `
      <label class="answer">
        <input type="checkbox" name="real-q-${question.id}" value="${option.id}" ${selected.includes(Number(option.id)) ? 'checked' : ''}>
        <span>${escapeHtml(option.text)}</span>
      </label>`).join('')}</div>`;
  } else if (interaction === 'order') {
    const items = question.structured?.items || [];
    const byKey = Object.fromEntries(items.map(item => [String(item.key), item]));
    const initialKeys = Array.isArray(saved) && saved.length
      ? saved.map(String).filter(key => byKey[key])
      : items.map(item => String(item.key));
    const normalized = [...initialKeys, ...items.map(item => String(item.key)).filter(key => !initialKeys.includes(key))];
    controls = `<div class="ordering-list" data-ordering-question="${question.id}" data-order-touched="${Array.isArray(saved) && saved.length ? '1' : '0'}">
      <div class="ordering-help">Перетащите элементы в правильном порядке</div>
      ${normalized.map((key, pos) => {
        const item = byKey[key];
        return `<div class="ordering-item" draggable="true" data-order-key="${escapeHtml(key)}"><span class="ordering-grip" aria-hidden="true">⋮⋮</span><span class="ordering-number">${pos + 1}</span><b>${escapeHtml(item?.text || key)}</b><span class="ordering-buttons"><button type="button" data-order-move="-1" aria-label="Переместить выше">↑</button><button type="button" data-order-move="1" aria-label="Переместить ниже">↓</button></span></div>`;
      }).join('')}
    </div>`;
  } else if (interaction === 'matching') {
    const left = question.structured?.left || [];
    const right = question.structured?.right || [];
    const matches = saved && typeof saved === 'object' && !Array.isArray(saved) ? saved : {};
    controls = `<div class="matching-list" data-matching-question="${question.id}">${left.map(item => `
      <label class="matching-row"><span>${escapeHtml(item.text)}</span><select data-match-left="${escapeHtml(item.key)}"><option value="">Выберите соответствие</option>${right.map(rightItem => `<option value="${escapeHtml(rightItem.key)}" ${String(matches[item.key] || '') === String(rightItem.key) ? 'selected' : ''}>${escapeHtml(rightItem.text)}</option>`).join('')}</select></label>
    `).join('')}</div>`;
  } else {
    const value = typeof saved === 'string' ? saved : '';
    const originalText = interaction === 'correction' && question.structured?.original_text
      ? `<div class="question-source-text">${escapeHtml(question.structured.original_text)}</div>`
      : '';
    controls = `${originalText}<textarea class="real-text-answer" data-text-question="${question.id}" rows="${interaction === 'essay' ? 6 : 3}" placeholder="Введите ответ">${escapeHtml(value)}</textarea>`;
  }

  return `
    <section class="question real-question" data-real-question="${question.id}" data-interaction="${escapeHtml(interaction)}">
      <div class="real-question-head">
        <div class="real-question-index"><span>Вопрос ${index + 1}</span></div>
      </div>
      <h4>${escapeHtml(question.text)}</h4>
      ${assets}
      ${controls}
      <small class="answer-save-state" data-save-state="${question.id}"></small>
    </section>`;
}

function setAnswerSaveState(questionId, text, isError = false) {
  const node = document.querySelector(`[data-save-state="${questionId}"]`);
  if (!node) return;
  if (activeStaffPreview) {
    node.textContent = '';
    node.classList.remove('error');
    return;
  }
  node.textContent = text;
  node.classList.toggle('error', isError);
}

function updateQuizProgress() {
  const form = document.querySelector('#realQuizForm, #staffTestForm');
  if (!form) return;

  const questions = [...form.querySelectorAll('.real-question')];
  let answered = 0;

  questions.forEach(question => {
    const interaction = String(question.dataset.interaction || '');
    const complete = quizQuestionIsComplete(question, interaction);
    question.classList.toggle('answered', complete);
    if (complete) answered++;
  });

  const total = questions.length;
  const percent = total ? Math.round((answered / total) * 100) : 0;
  const text = document.getElementById('quizProgressText');
  const bar = document.getElementById('quizProgressBar');
  if (text) text.textContent = answered + ' из ' + total + ' отвечено';
  if (bar) bar.style.width = percent + '%';
}

function wireRealStudentQuestionControls() {
  document.querySelectorAll('.real-answer-options input').forEach(input => {
    input.addEventListener('change', async () => {
      const question = input.closest('[data-real-question]');
      if (!question) return;
      const questionId = Number(question.dataset.realQuestion);
      const selected = [...question.querySelectorAll('.real-answer-options input:checked')].map(item => Number(item.value));
      updateQuizProgress();
      try {
        setAnswerSaveState(questionId, 'Сохраняем...');
        await saveRealStudentAnswer(questionId, { option_ids: selected });
        setAnswerSaveState(questionId, 'Сохранено');
      } catch (e) {
        setAnswerSaveState(questionId, e.message, true);
      }
    });
  });

  document.querySelectorAll('[data-ordering-question]').forEach(list => {
    let draggedItem = null;

    const updateNumbers = () => {
      list.querySelectorAll('.ordering-item').forEach((item, index) => {
        const number = item.querySelector('.ordering-number');
        if (number) number.textContent = String(index + 1);
      });
    };

    const persistOrder = async () => {
      const questionId = Number(list.dataset.orderingQuestion);
      list.dataset.orderTouched = '1';
      updateQuizProgress();
      const order = [...list.querySelectorAll('.ordering-item')].map(row => row.dataset.orderKey);
      try {
        setAnswerSaveState(questionId, 'Сохраняем...');
        await saveRealStudentAnswer(questionId, { order });
        setAnswerSaveState(questionId, 'Сохранено');
      } catch (e) {
        setAnswerSaveState(questionId, e.message, true);
      }
    };

    list.querySelectorAll('.ordering-item').forEach(item => {
      item.addEventListener('dragstart', event => {
        draggedItem = item;
        item.classList.add('dragging');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', item.dataset.orderKey || '');
      });

      item.addEventListener('dragover', event => {
        event.preventDefault();
        if (!draggedItem || draggedItem === item) return;
        const rect = item.getBoundingClientRect();
        list.insertBefore(draggedItem, event.clientY < rect.top + rect.height / 2 ? item : item.nextSibling);
        updateNumbers();
      });

      item.addEventListener('dragend', async () => {
        item.classList.remove('dragging');
        draggedItem = null;
        updateNumbers();
        await persistOrder();
      });

      const grip = item.querySelector('.ordering-grip');
      if (grip) {
        let touchDragging = false;

        grip.addEventListener('pointerdown', event => {
          if (event.pointerType === 'mouse') return;
          touchDragging = true;
          draggedItem = item;
          item.classList.add('dragging');
          grip.setPointerCapture?.(event.pointerId);
          event.preventDefault();
        });

        grip.addEventListener('pointermove', event => {
          if (!touchDragging || draggedItem !== item) return;
          const target = document.elementFromPoint(event.clientX, event.clientY)?.closest('.ordering-item');
          if (!target || target === item || target.closest('[data-ordering-question]') !== list) return;
          const rect = target.getBoundingClientRect();
          list.insertBefore(item, event.clientY < rect.top + rect.height / 2 ? target : target.nextSibling);
          updateNumbers();
          event.preventDefault();
        });

        const finishTouchDrag = async event => {
          if (!touchDragging) return;
          touchDragging = false;
          item.classList.remove('dragging');
          draggedItem = null;
          try { grip.releasePointerCapture?.(event.pointerId); } catch {}
          updateNumbers();
          await persistOrder();
        };

        grip.addEventListener('pointerup', finishTouchDrag);
        grip.addEventListener('pointercancel', finishTouchDrag);
      }
    });

    list.querySelectorAll('[data-order-move]').forEach(button => {
      button.addEventListener('click', async () => {
        const item = button.closest('.ordering-item');
        const direction = Number(button.dataset.orderMove);
        if (!item) return;
        const sibling = direction < 0 ? item.previousElementSibling : item.nextElementSibling;
        if (!sibling || sibling.classList.contains('ordering-help')) return;
        if (direction < 0) list.insertBefore(item, sibling);
        else list.insertBefore(sibling, item);
        updateNumbers();
        await persistOrder();
      });
    });
  });

  document.querySelectorAll('[data-matching-question]').forEach(list => {
    list.querySelectorAll('select[data-match-left]').forEach(select => {
      select.addEventListener('change', async () => {
        const questionId = Number(list.dataset.matchingQuestion);
        const matches = {};
        list.querySelectorAll('select[data-match-left]').forEach(item => {
          if (item.value) matches[item.dataset.matchLeft] = item.value;
        });
        updateQuizProgress();
        try {
          setAnswerSaveState(questionId, 'Сохраняем...');
          await saveRealStudentAnswer(questionId, { matches });
          setAnswerSaveState(questionId, 'Сохранено');
        } catch (e) {
          setAnswerSaveState(questionId, e.message, true);
        }
      });
    });
  });

  document.querySelectorAll('[data-text-question]').forEach(input => {
    let timer = null;
    input.addEventListener('input', () => {
      clearTimeout(timer);
      updateQuizProgress();
      timer = setTimeout(async () => {
        const questionId = Number(input.dataset.textQuestion);
        try {
          setAnswerSaveState(questionId, 'Сохраняем...');
          await saveRealStudentAnswer(questionId, { answer_text: input.value });
          setAnswerSaveState(questionId, 'Сохранено');
        } catch (e) {
          setAnswerSaveState(questionId, e.message, true);
        }
      }, 500);
    });
    input.addEventListener('blur', async () => {
      clearTimeout(timer);
      const questionId = Number(input.dataset.textQuestion);
      try {
        await saveRealStudentAnswer(questionId, { answer_text: input.value });
        setAnswerSaveState(questionId, 'Сохранено');
      } catch (e) {
        setAnswerSaveState(questionId, e.message, true);
      }
    });
  });

  updateQuizProgress();
}

function renderRealAttemptResult(result, note = '') {
  AttemptSecurity.stop();
  stopQuizCountdown();
  activeStudentAttempt = null;
  activeStudentAssignment = null;
  activeStudentQuestions = [];
  quizSubmitting = false;
  quizExitBeaconSent = true;
  quizModal.dataset.locked = '0';
  quizModal.querySelector('.modal-close')?.classList.remove('hidden');

  const percent = Math.round(Number(result?.percent || 0));
  const grade = result?.grade ?? '—';
  document.getElementById('quizContent').innerHTML = `
    <div class="result-card student-finish-result">
      <span class="section-kicker">Работа завершена</span>
      <h2>Готово! Результат сохранён</h2>
      ${note ? `<p class="strict-result-note">${escapeHtml(note)}</p>` : ''}
      <div class="student-finish-hero">
        <div class="result-circle" style="--score:${percent}%"><strong>${percent}%</strong></div>
        <div class="student-finish-grade">
          <span>Оценка</span>
          <strong class="${resultGradeClass(grade)}">${escapeHtml(grade)}</strong>
          <small>${Number(result?.score || 0).toLocaleString('ru-RU')} из ${Number(result?.max_score || 0).toLocaleString('ru-RU')} баллов</small>
        </div>
      </div>
      <div class="student-finish-message">
        <b>${percent >= 90 ? 'Отличный результат' : percent >= 75 ? 'Хорошая работа' : percent >= 50 ? 'Работа зачтена' : 'Результат сохранён'}</b>
        <span>Оценку и историю выполненных работ всегда можно посмотреть в разделе «Мои оценки».</span>
      </div>
      <div class="student-finish-actions">
        <button class="secondary-btn" id="finishOpenGradesBtn" type="button">Мои оценки</button>
        <button class="primary-btn" id="finishRealResultBtn" type="button">К заданиям</button>
      </div>
    </div>`;
  document.getElementById('finishOpenGradesBtn')?.addEventListener('click', async () => {
    closeModal(quizModal);
    await Promise.all([loadStudentAssignments(), loadStudentResults()]);
    showView('student-results');
  });
  document.getElementById('finishRealResultBtn')?.addEventListener('click', async () => {
    closeModal(quizModal);
    await Promise.all([loadStudentAssignments(), loadStudentResults()]);
    showView('student-tasks');
  });
}

async function startRealStudentAssignment(assignmentId) {
  activeStaffPreview = null;
  const assignment = studentAssignmentsCache.find(item => Number(item.id) === Number(assignmentId));
  if (!assignment) return;

  try {
    const startResponse = await fetch('./api/attempts/start.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ assignment_id: assignmentId })
    });
    const startData = await startResponse.json();
    if (!startResponse.ok || startData.ok === false) throw new Error(startData.error || 'Не удалось начать работу.');

    const attempt = startData.attempt;
    const questionResponse = await fetch(`./api/attempts/questions.php?attempt_id=${encodeURIComponent(attempt.id)}`, {
      credentials: 'same-origin',
      cache: 'no-store'
    });
    const questionData = await questionResponse.json();
    if (!questionResponse.ok || questionData.ok === false) throw new Error(questionData.error || 'Не удалось загрузить вопросы.');

    activeStudentAssignment = assignment;
    activeStudentAttempt = questionData.attempt;
    const questions = questionData.questions || [];
    activeStudentQuestions = questions;
    quizSubmitting = false;
    quizExitBeaconSent = false;
    const savedAnswers = questionData.saved_answers || {};

    // Closing the test is allowed, but it is not a pause: closing submits the
    // current attempt with only the answers the student actually entered.
    quizModal.dataset.locked = '0';
    quizModal.querySelector('.modal-close')?.classList.remove('hidden');

    document.getElementById('quizContent').innerHTML = `
      <div class="quiz-head real-quiz-head">
        <span class="section-kicker">${escapeHtml(assignment.subject_name || 'Предмет')} · ${escapeHtml(assignment.class_name || '')}</span>
        <h2>${escapeHtml(assignment.title)}</h2>
        <div class="quiz-meta">
          <span>${questions.length} вопросов</span>
          <span>${questionData.attempt.time_limit_minutes ? Number(questionData.attempt.time_limit_minutes) + ' мин.' : 'Без ограничения времени'}</span>
          ${questionData.attempt.time_limit_minutes ? '<span class="quiz-time-chip">Осталось <b id="quizTimeRemaining">--:--</b></span>' : ''}
          <span>${assignment.focus_policy === 'strict' ? 'Строгий режим' : 'Обычный режим'}</span>
        </div>
        <div class="quiz-progress-card">
          <div><b>Прогресс</b><span id="quizProgressText">0 из ${questions.length} отвечено</span></div>
          <div class="quiz-progress-track"><span id="quizProgressBar"></span></div>
        </div>
      </div>
      <form id="realQuizForm">
        ${questions.map((question, index) => renderRealStudentQuestion(question, index, savedAnswers[String(question.id)] || '')).join('')}
        <button class="primary-btn full" type="submit">Завершить и сдать работу</button>
      </form>`;

    wireRealStudentQuestionControls();
    openModal(quizModal);
    startQuizCountdown(questionData.attempt.started_at, questionData.attempt.time_limit_minutes);

    AttemptSecurity.start({
      attemptId: questionData.attempt.id,
      focusPolicy: assignment.focus_policy || 'allow',
      onLocked: () => {
        document.querySelectorAll('#realQuizForm input,#realQuizForm textarea,#realQuizForm select,#realQuizForm button')
          .forEach(el => el.disabled = true);
        const content = document.getElementById('quizContent');
        if (content && !content.querySelector('.strict-lock-overlay')) {
          const warning = document.createElement('div');
          warning.className = 'strict-lock-overlay';
          warning.textContent = 'Вкладка была скрыта. Тест завершён — учитываются только ответы, отмеченные до этого момента.';
          content.prepend(warning);
        }
      },
      onHidden: () => {
        sendActiveAttemptCloseBeacon('page_hidden');
      },
      onTerminated: (result, reason) => {
        const finalReason = reason || result?.termination_reason || '';
        const note = finalReason === 'time_limit'
          ? 'Время выполнения закончилось. Работа завершена автоматически.'
          : finalReason === 'page_hidden'
            ? 'Вкладка была скрыта. Тест завершён автоматически; учтены ответы, отмеченные до этого момента.'
            : 'Попытка завершена системой контроля.';
        renderRealAttemptResult(result || {}, note);
      }
    });

    document.getElementById('realQuizForm')?.addEventListener('submit', async event => {
      event.preventDefault();
      if (!activeStudentAttempt) {
        await appAlert('Попытка не активна. Обновите список заданий и откройте работу заново.', { tone:'danger' });
        return;
      }
      if (AttemptSecurity.isLocked()) {
        await appAlert('Попытка уже заблокирована системой контроля. Будут учтены только реально сохранённые ответы.', { tone:'danger' });
        return;
      }
      const button = event.currentTarget.querySelector('button[type="submit"]');
      if (!(await appConfirm('Завершить работу? После сдачи изменить ответы нельзя.'))) return;
      button.disabled = true;
      button.textContent = 'Сдаём...';
      try {
        await submitActiveStudentAttempt('student_submit', true);
      } catch (e) {
        await appAlert(
          e?.message || 'Не удалось завершить работу. Нажмите кнопку сдачи ещё раз.',
          {
            title: 'Не удалось завершить тест',
            tone: 'danger',
            okText: 'Вернуться к тесту'
          }
        );
        button.disabled = false;
        button.textContent = 'Завершить и сдать работу';
      }
    });
  } catch (error) {
    alert(error.message);
  }
}


function quizQuestionHasResponse(section, interaction) {
  if (!section) return false;
  if (['single', 'multiple', 'true_false'].includes(interaction)) {
    return Boolean(section.querySelector('.real-answer-options input:checked'));
  }
  if (interaction === 'order') {
    return section.querySelector('[data-ordering-question]')?.dataset.orderTouched === '1';
  }
  if (interaction === 'matching') {
    return [...section.querySelectorAll('select[data-match-left]')].some(select => Boolean(select.value));
  }
  return Boolean(section.querySelector('[data-text-question]')?.value.trim());
}

function quizQuestionIsComplete(section, interaction) {
  if (!section) return false;
  if (interaction === 'matching') {
    const selects = [...section.querySelectorAll('select[data-match-left]')];
    return selects.length > 0 && selects.every(select => Boolean(select.value));
  }
  return quizQuestionHasResponse(section, interaction);
}

function collectQuizAnswerSnapshot(questions = [], options = {}) {
  const onlyAnswered = Boolean(options.onlyAnswered);
  return (questions || []).map(question => {
    const questionId = Number(question.id);
    const interaction = canonicalQuestionType(question.interaction_type || question.type);
    const section = document.querySelector(`[data-real-question="${questionId}"]`);
    let payload = {};

    if (!section) {
      return null;
    }
    if (onlyAnswered && !quizQuestionHasResponse(section, interaction)) {
      return null;
    }

    if (['single', 'multiple', 'true_false'].includes(interaction)) {
      payload.option_ids = [...section.querySelectorAll('.real-answer-options input:checked')]
        .map(input => Number(input.value));
    } else if (interaction === 'order') {
      payload.order = [...section.querySelectorAll('.ordering-item')]
        .map(item => String(item.dataset.orderKey || ''))
        .filter(Boolean);
    } else if (interaction === 'matching') {
      const matches = {};
      section.querySelectorAll('select[data-match-left]').forEach(select => {
        if (select.value) matches[String(select.dataset.matchLeft)] = String(select.value);
      });
      payload.matches = matches;
    } else {
      payload.answer_text = section.querySelector('[data-text-question]')?.value || '';
    }

    return { question_id: questionId, payload };
  }).filter(Boolean);
}

async function submitActiveStudentAttempt(reason = 'student_submit', renderResult = true) {
  if (!activeStudentAttempt?.id) {
    throw new Error('Попытка не активна.');
  }
  if (quizSubmitting) {
    throw new Error('Работа уже завершается.');
  }

  quizSubmitting = true;
  const attemptId = Number(activeStudentAttempt.id);
  const answers = collectQuizAnswerSnapshot(activeStudentQuestions, { onlyAnswered: true });

  try {
    const response = await fetch('./api/attempts/submit.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        attempt_id: attemptId,
        finish_reason: reason,
        answers
      })
    });
    const data = await readJsonResponse(
      response,
      'Не удалось завершить работу. Проверьте соединение и нажмите «Завершить» ещё раз.'
    );
    if (!response.ok || data.ok === false) {
      throw new Error(data.error || 'Не удалось завершить работу. Попробуйте ещё раз.');
    }

    quizExitBeaconSent = true;

    if (renderResult) {
      const note = reason === 'window_closed'
        ? 'Тест был закрыт. Учтены только ответы, которые вы успели дать.'
        : '';
      renderRealAttemptResult(data.result || {}, note);
    } else {
      AttemptSecurity.stop();
      stopQuizCountdown();
      activeStudentAttempt = null;
      activeStudentAssignment = null;
      activeStudentQuestions = [];
      quizExitBeaconSent = true;
      quizModal.dataset.locked = '0';
      quizModal.querySelector('.modal-close')?.classList.remove('hidden');
      closeModal(quizModal);
      await Promise.all([
        loadStudentAssignments().catch(() => {}),
        loadStudentResults().catch(() => {})
      ]);
    }

    return data.result || {};
  } finally {
    quizSubmitting = false;
  }
}

async function finishActiveStudentAttemptFromClose() {
  if (!activeStudentAttempt?.id || quizSubmitting) return;

  const confirmed = await appConfirm(
    'Закрыть тест? Попытка будет завершена сразу. В результат попадут только ответы, которые вы успели дать; вернуться к этой попытке после закрытия нельзя.',
    {
      title: 'Закрыть и завершить тест',
      tone: 'danger',
      okText: 'Закрыть и завершить',
      cancelText: 'Продолжить тест'
    }
  );
  if (!confirmed) return;

  try {
    await submitActiveStudentAttempt('window_closed', false);
  } catch (error) {
    await appAlert(error.message, {
      title: 'Не удалось завершить тест',
      tone: 'danger'
    });
  }
}

function sendActiveAttemptCloseBeacon(reason = 'page_closed') {
  if (!activeStudentAttempt?.id || quizExitBeaconSent) return false;

  const payload = JSON.stringify({
    attempt_id: Number(activeStudentAttempt.id),
    finish_reason: reason,
    answers: collectQuizAnswerSnapshot(activeStudentQuestions, { onlyAnswered: true })
  });
  quizExitBeaconSent = true;

  try {
    const blob = new Blob([payload], { type: 'application/json' });
    if (navigator.sendBeacon?.('./api/attempts/close.php', blob)) {
      return true;
    }
  } catch {}

  // Fallback for browsers where sendBeacon is unavailable/rejected.
  try {
    fetch('./api/attempts/close.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: payload,
      keepalive: true
    }).catch(() => {});
    return true;
  } catch {
    quizExitBeaconSent = false;
    return false;
  }
}

window.addEventListener('pagehide', () => {
  sendActiveAttemptCloseBeacon('browser_closed');
}, { capture: true });

window.addEventListener('beforeunload', () => {
  sendActiveAttemptCloseBeacon('browser_closed');
}, { capture: true });

function collectStaffTestAnswers() {
  if (!activeStaffPreview) return [];
  return collectQuizAnswerSnapshot(activeStaffPreview.questions || []);
}

function renderStaffTestResult(result) {
  const percent = Math.round(Number(result?.percent || 0));
  const grade = result?.grade ?? '—';
  const needsReview = Boolean(result?.needs_review);

  quizModal.dataset.locked = '0';
  quizModal.querySelector('.modal-close')?.classList.remove('hidden');

  document.getElementById('quizContent').innerHTML = `
    <div class="result-card staff-test-result">
      <span class="section-kicker">Тестовый режим</span>
      <h2>Проверка завершена</h2>
      <div class="test-mode-banner compact">
        Этот результат не записан ученику, не создаёт попытку и не влияет на статистику школы.
      </div>
      <div class="result-circle" style="--score:${percent}%"><strong>${percent}%</strong></div>
      <p>Баллы: <b>${Number(result?.score || 0)} из ${Number(result?.max_score || 0)}</b></p>
      <div class="result-grade">${escapeHtml(grade)}</div>
      <p>${needsReview ? 'Есть ответы, которые в реальной работе потребуют ручной проверки учителем.' : 'Оценка рассчитана по той же шкале и правилам, что и у ученика.'}</p>
      <div class="staff-test-result-actions">
        <button class="secondary-btn" id="staffTestAgainBtn" type="button">↻ Пройти ещё раз</button>
        <button class="primary-btn" id="staffTestCloseBtn" type="button">Закрыть</button>
      </div>
    </div>`;

  document.getElementById('staffTestAgainBtn')?.addEventListener('click', () => {
    const assignmentId = Number(activeStaffPreview?.assignment?.id || 0);
    if (assignmentId) openAssignmentTestPreview(assignmentId);
  });
  document.getElementById('staffTestCloseBtn')?.addEventListener('click', () => {
    activeStaffPreview = null;
    closeModal(quizModal);
  });
}

async function openAssignmentTestPreview(assignmentId) {
  try {
    AttemptSecurity.stop();
    activeStudentAttempt = null;
    activeStudentAssignment = null;

    const response = await fetch(
      './api/assignments/test-preview.php?assignment_id=' + encodeURIComponent(assignmentId),
      { credentials: 'same-origin', cache: 'no-store' }
    );
    const data = await response.json();
    if (!response.ok || data.ok === false) {
      throw new Error(data.error || 'Не удалось запустить тестовый режим.');
    }

    activeStaffPreview = data;
    const assignment = data.assignment || {};
    const questions = data.questions || [];
    quizModal.dataset.locked = '0';
    quizModal.querySelector('.modal-close')?.classList.remove('hidden');

    document.getElementById('quizContent').innerHTML = `
      <div class="test-mode-banner">
        <b>Тестовый запуск — вид ученика</b>
        <span>Можно нажимать ответы, перетаскивать элементы и завершить работу. Ничего не попадёт в журнал и статистику.</span>
      </div>
      <div class="quiz-head real-quiz-head">
        <span class="section-kicker">${escapeHtml(assignment.subject_name || 'Предмет')} · предпросмотр</span>
        <h2>${escapeHtml(assignment.title || 'Задание')}</h2>
        <div class="quiz-meta">
          <span>${questions.length} вопросов</span>
          <span>${assignment.time_limit_minutes ? Number(assignment.time_limit_minutes) + ' мин.' : 'Без ограничения времени'}</span>
          <span>${assignment.focus_policy === 'strict' ? 'Строгий режим у ученика' : 'Обычный режим'}</span>
        </div>
        <div class="quiz-progress-card">
          <div><b>Прогресс</b><span id="quizProgressText">0 из ${questions.length} отвечено</span></div>
          <div class="quiz-progress-track"><span id="quizProgressBar"></span></div>
        </div>
      </div>
      <form id="staffTestForm">
        ${questions.map((question, index) => renderRealStudentQuestion(question, index, '')).join('')}
        <button class="primary-btn full" type="submit">Завершить тестовую проверку</button>
      </form>`;

    wireRealStudentQuestionControls();
    openModal(quizModal);

    document.getElementById('staffTestForm')?.addEventListener('submit', async event => {
      event.preventDefault();
      const button = event.currentTarget.querySelector('button[type="submit"]');
      button.disabled = true;
      button.textContent = 'Проверяем...';

      try {
        const gradeResponse = await fetch('./api/assignments/test-grade.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            assignment_id: assignmentId,
            answers: collectStaffTestAnswers()
          })
        });
        const gradeData = await gradeResponse.json();
        if (!gradeResponse.ok || gradeData.ok === false) {
          throw new Error(gradeData.error || 'Не удалось проверить тест.');
        }
        renderStaffTestResult(gradeData.result || {});
      } catch (error) {
        alert(error.message);
        button.disabled = false;
        button.textContent = 'Завершить тестовую проверку';
      }
    });
  } catch (error) {
    activeStaffPreview = null;
    alert(error.message);
  }
}

function requestedInitialView(user) {
  const requested = new URLSearchParams(window.location.search).get('view');
  if (!requested) return null;

  const studentViews = new Set(['student-dashboard', 'student-tasks', 'student-results']);
  const staffViews = new Set(['teacher-dashboard', 'subjects', 'assignments', 'uvoria-library', 'activity-history', 'classes', 'results', 'staff-profile']);
  if (user?.role === 'student' && studentViews.has(requested)) return requested;
  if (user?.role !== 'student' && staffViews.has(requested)) return requested;
  return null;
}

loadSession().then(async user => {
  if (!user) return;
  applyUser(user);

  if (user.role === 'student') {
    try {
      await Promise.all([loadSchoolBranding(), loadStudentAssignments(), loadStudentResults()]);
      const requested = requestedInitialView(user);
      if (requested) showView(requested);
    } catch {}
    finally {
      revealAuthenticatedApp();
    }
    return;
  }

  try {
    const schoolsData = await loadSchools();
    const activeSchool = Number(schoolsData?.active_school_id || 0) > 0;

    if (!activeSchool) {
      if (Number(user.is_platform_admin) === 1) showView('teacher-dashboard');
      return;
    }

    await Promise.all([loadClasses(), loadSubjects(), loadAssignments(), loadSchoolBranding(), loadTeacherDashboard()]);

    const requested = requestedInitialView(user);
    if (requested) {
      showView(requested);
      if (requested === 'classes') await loadClasses();
      if (requested === 'subjects') await loadSubjectsWorkspace();
      if (requested === 'assignments') await loadAssignments();
      if (requested === 'results') await loadResults().catch(() => {});
      if (requested === 'uvoria-library') await loadLibrary().catch(() => {});
      if (requested === 'activity-history') await loadActivityHistory().catch(() => {});
      if (requested === 'staff-profile') await loadStaffProfile().catch(() => {});
    } else if (user.role === 'admin') {
      await loadSchoolManagement();
      showView('school-management');
    } else {
      showView('teacher-dashboard');
    }
  } catch (error) {
    alert(error.message);
  } finally {
    revealAuthenticatedApp();
  }
});

