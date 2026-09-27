const teacherNav = document.querySelector('.teacher-nav');
const studentNav = document.querySelector('.student-nav');
const sidebar = document.getElementById('sidebar');
const menuBtn = document.getElementById('menuBtn');
const pageTitle = document.getElementById('pageTitle');
const eyebrow = document.getElementById('eyebrow');
const sidebarName = document.getElementById('sidebarName');
const sidebarRole = document.getElementById('sidebarRole');
const sidebarAvatar = document.getElementById('sidebarAvatar');
const taskModal = document.getElementById('taskModal');
const quizModal = document.getElementById('quizModal');
const questionPreviewModal = document.getElementById('questionPreviewModal');
const libraryPreviewModal = document.getElementById('libraryPreviewModal');
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
let currentUser = null;
let activeSchoolName = '';
let currentBranding = { theme_color: '#1d68f0' };

const titles = {
  'teacher-dashboard': ['Кабинет учителя', 'Добрый день!'],
  subjects: ['Учебные направления', 'Предметы'],
  assignments: ['Управление обучением', 'Задания'],
  'uvoria-library': ['Обмен опытом', 'Библиотека UVORIA'],
  'staff-profile': ['Учётная запись', 'Профиль сотрудника'],
  'activity-history': ['Контроль изменений', 'История действий'],
  'system-backups': ['Защита данных', 'Резервные копии'],
  'incoming-materials': ['Обмен между школами', 'Полученные материалы'],
  classes: ['Ученики и группы', 'Классы'],
  'school-management': ['Администрирование', 'Управление школой'],
  results: ['Журнал успеваемости', 'Результаты'],
  'student-dashboard': ['Кабинет ученика', 'Мои занятия'],
  'student-tasks': ['Учёба', 'Мои задания'],
  'student-results': ['Успеваемость', 'Мои оценки']
};

function showView(id) {
  document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));
  const view = document.getElementById(id);
  if (view) view.classList.add('active');

  document.querySelectorAll('.nav-item').forEach(i => i.classList.toggle('active', i.dataset.view === id));
  const [small, title] = titles[id] || ['', 'UVORIA'];
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
  sidebar.classList.remove('open');
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
    ? 'Администратор UVORIA'
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

  if (admin) eyebrow.textContent = platformAdmin ? 'Администратор UVORIA' : 'Администратор школы';

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
          <p>${Number(item.students_count)} учеников</p>
        </div>
        <div class="class-code-mini">
          <span>Код</span>
          <strong>${escapeHtml(item.join_code || '—')}</strong>
        </div>
        <button type="button" data-open-class="${item.id}">Открыть класс</button>
      </article>
    `).join('');

    grid.querySelectorAll('[data-open-class]').forEach(button => button.addEventListener('click', () => {
      const item = classesCache.find(x => Number(x.id) === Number(button.dataset.openClass));
      if (item) openClassDetails(item);
    }));
  } catch (error) {
    grid.innerHTML = `<article class="panel"><p>${escapeHtml(error.message)}</p></article>`;
  }
}

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
    document.getElementById('classStudentsCount').textContent = students.length;
    list.innerHTML = students.length ? students.map(student => `
      <div class="class-student-row">
        <span class="student-row-avatar">${escapeHtml((student.first_name || '?').charAt(0))}</span>
        <span class="student-row-name"><b>${escapeHtml(student.last_name)} ${escapeHtml(student.first_name)}</b><small>${Number(student.activated) ? 'PIN создан' : 'Ещё не входил'}</small></span>
        <span class="student-row-actions">
          <span class="status ${Number(student.activated) ? 'green' : 'blue'}">${Number(student.activated) ? 'Активирован' : 'Ожидает'}</span>
          ${['admin', 'teacher'].includes(currentUser?.role) && Number(student.activated) ? `<button type="button" class="mini-action" data-reset-pin="${student.id}">Сбросить PIN</button>` : ''}
        </span>
      </div>
    `).join('') : '<div class="empty-students">Учеников пока нет. Загрузите DOCX со списком класса.</div>';

    list.querySelectorAll('[data-reset-pin]').forEach(button => button.addEventListener('click', async () => {
      if (!currentClass) return;
      const studentId = Number(button.dataset.resetPin);
      const student = students.find(item => Number(item.id) === studentId);
      if (!student) return;
      if (!confirm(`Сбросить PIN для ${student.last_name} ${student.first_name}?`)) return;

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
    prompt('Код класса:', currentClass.join_code);
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
            : 'UVORIA';
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
            <small>${questions.length} вопросов${Number(assignment.variant_count || 1) > 1 ? ' · варианты ' + ['A','B','C','D'].slice(0, Number(assignment.variant_count)).join('/') : ''}</small>
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
    if (!confirm(`Принять предмет «${item.subject_name}»${count ? ' и ' + count + ' задан.' : ''} из школы «${item.source_school_name}»?\n\nЗадания будут добавлены как черновики без классов и учеников.`)) return;
  } else {
    if (!confirm(`Отклонить материалы из школы «${item.source_school_name}»? Они не будут добавлены в библиотеку.`)) return;
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
  if (!confirm('Удалить эту резервную копию? Восстановить удалённый архив будет невозможно.')) return;

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
  if (Number(data?.profile?.is_platform_admin) === 1) return 'Главный администратор UVORIA';
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
  document.getElementById('profileDisplayName').textContent = fullName || 'Сотрудник UVORIA';
  document.getElementById('profileRoleBadge').textContent = roleText;
  document.getElementById('profileSchoolSummary').textContent = data.active_school?.name || (Number(data.profile.is_platform_admin) === 1 ? 'Платформа UVORIA' : 'Школа не выбрана');

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
  return `<span class="status ${cls}" title="Библиотека UVORIA">${label}</span>`;
}

async function submitAssignmentToLibrary(assignmentId) {
  const item = assignmentsCache.find(row => Number(row.id) === Number(assignmentId));
  if (!item) return;
  if (!confirm(`Отправить «${item.title}» в библиотеку UVORIA? Учителя отправляют материал на согласование администратору школы.`)) return;

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
  if (!confirm('Импортировать материал в выбранную школу? Будет создан независимый черновик без классов, учеников и результатов.')) return;

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
  if (!confirm(`Подтвердить действие: ${labels[action] || action}?`)) return;
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
  mail_test_sent: 'Отправлено тестовое письмо UVORIA'
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
  return match ? match[1] : 'Действие в UVORIA';
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
          <small class="history-event-code">${escapeHtml(item.event_type || '')}</small>
        </div>
      </article>`;
  }).join('');
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

document.querySelectorAll('.nav-item').forEach(btn => btn.addEventListener('click', () => {
  showView(btn.dataset.view);
  if (btn.dataset.view === 'teacher-dashboard') loadTeacherDashboard().catch(() => {});
  if (btn.dataset.view === 'classes') loadClasses();
  if (btn.dataset.view === 'subjects') loadSubjectsWorkspace();
  if (btn.dataset.view === 'assignments') loadAssignments();
  if (btn.dataset.view === 'uvoria-library') loadLibrary().catch(() => {});
  if (btn.dataset.view === 'staff-profile') loadStaffProfile().catch(() => {});
  if (btn.dataset.view === 'activity-history') loadActivityHistory().catch(() => {});
  if (btn.dataset.view === 'system-backups') loadBackups().catch(() => {});
  if (btn.dataset.view === 'incoming-materials') loadIncomingMaterials().catch(() => {});
  if (btn.dataset.view === 'school-management') loadSchoolManagement();
}));
document.querySelectorAll('[data-view-jump]').forEach(btn => btn.addEventListener('click', () => showView(btn.dataset.viewJump)));
menuBtn?.addEventListener('click', () => sidebar.classList.toggle('open'));

document.getElementById('notificationsBtn')?.addEventListener('click', () => {
  alert('Новых уведомлений пока нет.');
});

document.getElementById('openHistoryTaskBtn')?.addEventListener('click', () => showView('student-tasks'));
document.getElementById('viewResultBtn')?.addEventListener('click', () => showView('student-results'));

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
  link.download = 'uvoria-results.csv';
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

['createTaskBtn', 'createTaskBtn2', 'heroCreateBtn'].forEach(id => {
  document.getElementById(id)?.addEventListener('click', () => openModal(taskModal));
});
document.querySelectorAll('[data-close-modal]').forEach(btn => {
  btn.addEventListener('click', () => closeModal(document.getElementById(btn.dataset.closeModal)));
});
document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
  backdrop.addEventListener('click', e => {
    if (e.target === backdrop && backdrop.dataset.locked !== '1') closeModal(backdrop);
  });
});

let subjectsCache = [];
let assignmentsCache = [];
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
    const importInfo = item.source_format
      ? escapeHtml(String(item.source_format).toUpperCase()) + (
          item.parse_status === 'questions_parsed'
            ? ` · распознано вопросов: ${questionsCount}`
            : item.parse_status === 'text_extracted'
              ? ' · текст извлечён'
              : ' · файл принят'
        )
      : `Задание UVORIA${questionsCount ? ' · вопросов: ' + questionsCount : ''}`;

    const reviewNote = item.review_comment
      ? `<em class="workflow-review-note">Комментарий администратора: ${escapeHtml(item.review_comment)}</em>`
      : '';

    return `
      <article class="subject-assignment-row">
        <div class="subject-assignment-copy">
          <b>${escapeHtml(item.title)}</b>
          <small>${escapeHtml(item.class_names || 'Без класса')} · ${importInfo}${Number(item.variant_count || 1) > 1 ? ' · варианты ' + ['A','B','C','D'].slice(0, Number(item.variant_count)).join('/') : ''}</small>
          ${item.parser_message ? `<em>${escapeHtml(item.parser_message)}</em>` : ''}
          ${reviewNote}
        </div>
        <div class="subject-assignment-actions">
          <button class="secondary-btn compact-btn test-run-btn" type="button" data-test-assignment="${item.id}">▶ Пройти как ученик</button>
          <button class="secondary-btn compact-btn" type="button" data-preview-questions="${item.id}">Конструктор</button>
          <button class="secondary-btn compact-btn duplicate-btn" type="button" data-duplicate-assignment="${item.id}">⧉ Дублировать</button>
          ${assignmentDeleteButton(item)}
          ${libraryAssignmentAction(item)}
          ${assignmentWorkflowActionButtons(item)}
          <span class="status ${statusClass}">${statusText}</span>
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
  const importVariantCount = Number(document.getElementById('subjectImportVariantCount')?.value || 1);
  data.append('variant_count', String(importVariantCount));
  if (importVariantCount > 1) {
    if (document.getElementById('subjectImportShuffleQuestions')?.checked) data.append('shuffle_questions', '1');
    if (document.getElementById('subjectImportShuffleOptions')?.checked) data.append('shuffle_options', '1');
    if (document.getElementById('subjectImportShuffleStructured')?.checked) data.append('shuffle_structured', '1');
  }
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
      ? `UVORIA распознала ${parsedCount} вопросов. Откройте «Конструктор» и проверьте вопросы перед публикацией.`
      : payload.import?.parse_status === 'text_extracted'
        ? 'Текст извлечён, но вопросы по шаблону не распознаны. Проверьте структуру файла.'
        : 'Файл сохранён в черновике. Для этого файла автоматическое извлечение текста ограничено.';
    if (result) {
      result.textContent = `${payload.import?.format || 'Файл'} принят. ${status}`;
      result.classList.remove('hidden');
    }

    form.reset();
    document.getElementById('subjectImportVariantChecks')?.classList.add('hidden');
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

function syncTaskVariantSettings() {
  const enabled = Boolean(document.getElementById('taskVariantsEnabled')?.checked);
  document.getElementById('taskVariantSettingsBody')?.classList.toggle('hidden', !enabled);
}

document.getElementById('taskVariantsEnabled')?.addEventListener('change', syncTaskVariantSettings);

document.getElementById('subjectImportVariantCount')?.addEventListener('change', event => {
  document.getElementById('subjectImportVariantChecks')?.classList.toggle('hidden', Number(event.target.value || 1) < 2);
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
    if (!confirm('Завершить это задание? Новые попытки учеников будут закрыты.')) return;
  } else if (action === 'withdraw') {
    if (!confirm('Отозвать задание с проверки и вернуть в черновик?')) return;
  } else if (action === 'reopen') {
    if (!confirm('Вернуть готовое задание в черновик для редактирования?')) return;
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

function renderAssignments() {
  const body = document.getElementById('assignmentsTableBody');
  if (!body) return;
  const query = (document.getElementById('assignmentSearch')?.value || '').trim().toLowerCase();
  const statusFilter = document.getElementById('assignmentStatusFilter')?.value || '';

  const rows = assignmentsCache.filter(item => {
    const haystack = [item.title, item.subject_name, item.class_names, item.source_school_name].join(' ').toLowerCase();
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
      <tr>
        <td>
          <b>${escapeHtml(item.title)}</b>
          <small>${escapeHtml(item.subject_name || 'Без предмета')}${item.time_limit_minutes ? ' · ' + Number(item.time_limit_minutes) + ' мин.' : ''}${source}</small>
          ${Number(item.variant_count || 1) > 1 ? `<small class="variant-badge">Варианты: ${['A','B','C','D'].slice(0, Number(item.variant_count)).join(' / ')}</small>` : ''}
          ${reviewNote}
        </td>
        <td>${escapeHtml(item.class_names || 'Ещё не назначено')}</td>
        <td><span class="status ${strict ? 'amber' : 'blue'}">${strict ? 'Строгий' : 'Обычный'}</span></td>
        <td>${Number(item.attempts_count || 0)}</td>
        <td><span class="status ${statusClass}">${statusText}</span></td>
        <td class="row-actions-cell">
          <button class="secondary-btn compact-btn test-run-btn" type="button" data-test-assignment="${item.id}">▶ Пройти как ученик</button>
          <button class="secondary-btn compact-btn" type="button" data-preview-questions="${item.id}">Конструктор</button>
          <button class="secondary-btn compact-btn duplicate-btn" type="button" data-duplicate-assignment="${item.id}">⧉ Дублировать</button>
          ${assignmentDeleteButton(item)}
          ${libraryAssignmentAction(item)}
          ${assignmentWorkflowActionButtons(item)}
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

  if (!confirm(
    'Удалить задание «' + assignment.title + '»?' +
    classesText +
    importText +
    '\n\nЭто действие нельзя отменить.'
  )) return;

  const oldText = button?.textContent || 'Удалить';
  if (button) {
    button.disabled = true;
    button.textContent = 'Удаляем...';
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
      button.textContent = oldText;
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
    const variants = Number(assignment.variant_count || 1);
    summary.innerHTML = `
      <span><b>Предмет:</b> ${escapeHtml(assignment.subject_name || '—')}</span>
      <span><b>Вопросов:</b> ${Number(assignment.questions_count || assignment.parsed_question_count || 0)}</span>
      <span><b>Варианты:</b> ${variants > 1 ? ['A','B','C','D'].slice(0, variants).join(' / ') : 'один'}</span>
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

  error?.classList.add('hidden');
  if (hidden) hidden.value = String(assignment.id);
  if (title) title.textContent = `Назначить: ${assignment.title}`;

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
      body: JSON.stringify({ assignment_id: assignmentId, class_id: classId })
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
    const variantsEnabled = Boolean(document.getElementById('taskVariantsEnabled')?.checked);
    if (!variantsEnabled) {
      payload.variant_count = '1';
      delete payload.shuffle_questions;
      delete payload.shuffle_options;
      delete payload.shuffle_structured;
    } else {
      payload.variant_count = String(document.getElementById('taskVariantCount')?.value || '4');
    }
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
    document.getElementById('taskVariantSettingsBody')?.classList.add('hidden');
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
  if (!confirm(`Снять права администратора у ${admin.last_name} ${admin.first_name}?`)) return;

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
  if (!confirm(`Добавить ${teacher.last_name} ${teacher.first_name} права администратора школы? Права учителя и текущие назначения сохранятся.`)) return;

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
  if (!confirm(`${enabled ? 'Добавить' : 'Убрать'} роль учителя для ${admin.last_name} ${admin.first_name}?`)) return;

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
  if (!confirm(`Снять у ${admin.last_name} ${admin.first_name} права администратора и оставить только роль учителя?`)) return;

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
  if (!confirm(message)) return;

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
    const variantCount = Number(item.variant_count || 1);
    const variantText = variantCount > 1 ? ` · варианты ${['A','B','C','D'].slice(0, variantCount).join('/')}` : '';
    const timeText = item.time_limit_minutes ? `${Number(item.time_limit_minutes)} мин.` : 'без ограничения';
    const state = activeAttempt
      ? `В процессе · вариант ${escapeHtml(item.active_variant_label || 'A')}`
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
        <p>${Number(item.questions_count || 0)} вопросов · ${timeText} · ${maxAttempts} попыт.${variantText}</p>
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
  if (!activeStudentAttempt || AttemptSecurity.isLocked()) return;
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
    controls = `<div class="ordering-list" data-ordering-question="${question.id}">
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
    <section class="question real-question" data-real-question="${question.id}">
      <div class="real-question-head"><span>Вопрос ${index + 1}</span><b>${Number(question.points || 1)} балл.</b></div>
      <h4>${escapeHtml(question.text)}</h4>
      ${assets}
      ${controls}
      <small class="answer-save-state" data-save-state="${question.id}"></small>
    </section>`;
}

function setAnswerSaveState(questionId, text, isError = false) {
  const node = document.querySelector(`[data-save-state="${questionId}"]`);
  if (!node) return;
  node.textContent = text;
  node.classList.toggle('error', isError);
}

function wireRealStudentQuestionControls() {
  document.querySelectorAll('.real-answer-options input').forEach(input => {
    input.addEventListener('change', async () => {
      const question = input.closest('[data-real-question]');
      if (!question) return;
      const questionId = Number(question.dataset.realQuestion);
      const selected = [...question.querySelectorAll('.real-answer-options input:checked')].map(item => Number(item.value));
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
}

function renderRealAttemptResult(result, note = '') {
  AttemptSecurity.stop();
  activeStudentAttempt = null;
  quizModal.dataset.locked = '0';
  quizModal.querySelector('.modal-close')?.classList.remove('hidden');

  const percent = Math.round(Number(result?.percent || 0));
  const grade = result?.grade ?? '—';
  document.getElementById('quizContent').innerHTML = `
    <div class="result-card">
      <span class="section-kicker">Работа завершена</span>
      <h2>Результат</h2>
      ${note ? `<p class="strict-result-note">${escapeHtml(note)}</p>` : ''}
      <div class="result-circle" style="--score:${percent}%"><strong>${percent}%</strong></div>
      <p>Баллы: <b>${Number(result?.score || 0)} из ${Number(result?.max_score || 0)}</b></p>
      <div class="result-grade">${escapeHtml(grade)}</div>
      <button class="primary-btn" id="finishRealResultBtn" type="button">Вернуться к заданиям</button>
    </div>`;
  document.getElementById('finishRealResultBtn')?.addEventListener('click', async () => {
    closeModal(quizModal);
    await loadStudentAssignments();
    showView('student-tasks');
  });
}

async function startRealStudentAssignment(assignmentId) {
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
    const savedAnswers = questionData.saved_answers || {};

    quizModal.dataset.locked = '1';
    quizModal.querySelector('.modal-close')?.classList.add('hidden');

    const variantBadge = Number(assignment.variant_count || 1) > 1
      ? `<span class="variant-pill">Вариант ${escapeHtml(questionData.attempt.variant_label || 'A')}</span>`
      : '';

    document.getElementById('quizContent').innerHTML = `
      <div class="quiz-head real-quiz-head">
        <span class="section-kicker">${escapeHtml(assignment.subject_name || 'Предмет')} · ${escapeHtml(assignment.class_name || '')}</span>
        <h2>${escapeHtml(assignment.title)}</h2>
        <div class="quiz-meta">
          ${variantBadge}
          <span>${questions.length} вопросов</span>
          <span>${assignment.time_limit_minutes ? Number(assignment.time_limit_minutes) + ' мин.' : 'Без ограничения времени'}</span>
          <span>${assignment.focus_policy === 'strict' ? 'Строгий режим' : 'Обычный режим'}</span>
        </div>
      </div>
      <form id="realQuizForm">
        ${questions.map((question, index) => renderRealStudentQuestion(question, index, savedAnswers[String(question.id)] || '')).join('')}
        <button class="primary-btn full" type="submit">Завершить и сдать работу</button>
      </form>`;

    wireRealStudentQuestionControls();
    openModal(quizModal);

    AttemptSecurity.start({
      attemptId: questionData.attempt.id,
      focusPolicy: assignment.focus_policy || 'allow',
      onLocked: () => {
        document.querySelectorAll('#realQuizForm input,#realQuizForm textarea,#realQuizForm select,#realQuizForm button')
          .forEach(el => el.disabled = true);
        const content = document.getElementById('quizContent');
        if (content) {
          const warning = document.createElement('div');
          warning.className = 'strict-lock-overlay';
          warning.textContent = 'Страница была скрыта. Строгая работа завершается с уже сохранёнными ответами.';
          content.prepend(warning);
        }
      },
      onTerminated: result => {
        renderRealAttemptResult(result || {}, 'Попытка завершена системой контроля.');
      }
    });

    document.getElementById('realQuizForm')?.addEventListener('submit', async event => {
      event.preventDefault();
      if (!activeStudentAttempt || AttemptSecurity.isLocked()) return;
      const button = event.currentTarget.querySelector('button[type="submit"]');
      if (!confirm('Завершить работу? После сдачи изменить ответы нельзя.')) return;
      button.disabled = true;
      button.textContent = 'Сдаём...';
      try {
        const response = await fetch('./api/attempts/submit.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ attempt_id: activeStudentAttempt.id })
        });
        const data = await response.json();
        if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось сдать работу.');
        renderRealAttemptResult(data.result || {});
      } catch (e) {
        alert(e.message);
        button.disabled = false;
        button.textContent = 'Завершить и сдать работу';
      }
    });
  } catch (error) {
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
      await Promise.all([loadSchoolBranding(), loadStudentAssignments()]);
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

