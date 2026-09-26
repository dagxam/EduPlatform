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
const userModal = document.getElementById('userModal');
const schoolModal = document.getElementById('schoolModal');
const classModal = document.getElementById('classModal');
const classDetailsModal = document.getElementById('classDetailsModal');

const titles = {
  'teacher-dashboard': ['Кабинет учителя', 'Добрый день!'],
  assignments: ['Управление обучением', 'Задания'],
  classes: ['Ученики и группы', 'Классы'],
  users: ['Администрирование', 'Пользователи'],
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
  const [small, title] = titles[id] || ['', 'EduPlatform'];
  eyebrow.textContent = small;
  pageTitle.textContent = title;
  sidebar.classList.remove('open');
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

const roleLabels = {
  admin: 'Администратор',
  teacher: 'Учитель',
  student: 'Ученик'
};

async function loadSession() {
  try {
    const response = await fetch('./api/auth/me.php', { credentials: 'same-origin', cache: 'no-store' });
    const data = await response.json();
    if (!response.ok || !data.authenticated || !data.user) {
      window.location.replace('./login.html');
      return null;
    }
    return data.user;
  } catch {
    window.location.replace('./login.html');
    return null;
  }
}

function applyUser(user) {
  const student = user.role === 'student';
  teacherNav.classList.toggle('hidden', student);
  studentNav.classList.toggle('hidden', !student);
  document.querySelectorAll('.teacher-only').forEach(el => el.classList.toggle('hidden', student));

  const fullName = [user.first_name, user.last_name].filter(Boolean).join(' ');
  sidebarName.textContent = fullName || 'Пользователь';
  sidebarRole.textContent = roleLabels[user.role] || user.role;
  sidebarAvatar.textContent = ((user.first_name || 'П').charAt(0) + (user.last_name || '').charAt(0)).toUpperCase();
  const roleBadge = document.getElementById('accountRoleBadge');
  if (roleBadge) roleBadge.textContent = roleLabels[user.role] || user.role;

  document.querySelectorAll('.admin-only').forEach(el => el.classList.toggle('hidden', user.role !== 'admin'));

  if (user.role === 'admin') {
    eyebrow.textContent = 'Кабинет администратора';
  }

  showView(student ? 'student-dashboard' : 'teacher-dashboard');
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
  if (!selector) return;

  try {
    const response = await fetch('./api/schools/list.php', { credentials: 'same-origin', cache: 'no-store' });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось загрузить школы.');

    schoolsCache = data.schools || [];
    selector.innerHTML = '<option value="0">Личное пространство</option>' + schoolsCache.map(school => {
      const city = school.city ? ' · ' + school.city : '';
      return `<option value="${school.id}">${escapeHtml(school.name + city)}</option>`;
    }).join('');
    selector.value = data.active_school_id ? String(data.active_school_id) : '0';
  } catch (error) {
    selector.innerHTML = '<option value="0">Личное пространство</option>';
  }
}

async function selectSchool(schoolId) {
  const selector = document.getElementById('schoolSelector');
  if (selector) selector.disabled = true;
  try {
    const response = await fetch('./api/schools/select.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ school_id: Number(schoolId) })
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось переключить школу.');

    classesCache = [];
    subjectsCache = [];
    assignmentsCache = [];
    currentClass = null;
    closeModal(classDetailsModal);
    await Promise.all([loadClasses(), loadSubjects(), loadAssignments()]);
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
    await loadSchools();
    await Promise.all([loadClasses(), loadSubjects(), loadAssignments()]);
  } catch (e) {
    error.textContent = e.message;
    error.classList.remove('hidden');
  } finally {
    button.disabled = false;
    button.textContent = 'Создать школу';
  }
});

let classesCache = [];
let currentClass = null;

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
      grid.innerHTML = '<article class="panel empty-class-card"><h3>Классов пока нет</h3><p>Создайте первый класс и загрузите список учеников из Word.</p><button class="primary-btn" id="emptyCreateClassBtn">＋ Создать класс</button></article>';
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
        <span class="status ${Number(student.activated) ? 'green' : 'blue'}">${Number(student.activated) ? 'Активирован' : 'Ожидает'}</span>
      </div>
    `).join('') : '<div class="empty-students">Учеников пока нет. Загрузите DOCX со списком класса.</div>';
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
  button.textContent = 'Импортируем...';
  try {
    const response = await fetch('./api/classes/import-students.php', {
      method: 'POST',
      credentials: 'same-origin',
      body: data
    });
    const payload = await response.json();
    if (!response.ok || payload.ok === false) throw new Error(payload.error || 'Не удалось импортировать список.');
    result.textContent = `Добавлено: ${payload.imported_count}. Пропущено повторов: ${payload.skipped_count}.`;
    result.classList.remove('hidden');
    form.reset();
    await loadClassStudents();
    await loadClasses();
    const refreshed = classesCache.find(item => Number(item.id) === Number(currentClass.id));
    if (refreshed) currentClass = refreshed;
  } catch (e) {
    error.textContent = e.message;
    error.classList.remove('hidden');
  } finally {
    button.disabled = false;
    button.textContent = 'Импортировать DOCX';
  }
});
document.querySelectorAll('.nav-item').forEach(btn => btn.addEventListener('click', () => {
  showView(btn.dataset.view);
  if (btn.dataset.view === 'classes') loadClasses();
  if (btn.dataset.view === 'assignments') loadAssignments();
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
  link.download = 'eduplatform-results.csv';
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
    if (e.target === backdrop) closeModal(backdrop);
  });
});

let subjectsCache = [];
let assignmentsCache = [];

async function loadSubjects() {
  const select = document.getElementById('taskSubject');
  if (!select) return;
  try {
    const response = await fetch('./api/subjects/list.php', { credentials: 'same-origin', cache: 'no-store' });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось загрузить предметы.');
    subjectsCache = data.subjects || [];
    select.innerHTML = '<option value="">Выберите предмет</option>' + subjectsCache.map(item =>
      `<option value="${item.id}">${escapeHtml(item.name)}</option>`
    ).join('');
  } catch (error) {
    select.innerHTML = '<option value="">Предметы недоступны</option>';
  }
}

function fillTaskClasses() {
  const select = document.getElementById('taskClass');
  if (!select) return;
  select.innerHTML = '<option value="">Выберите класс</option>' + classesCache.map(item =>
    `<option value="${item.id}">${escapeHtml(item.name)}</option>`
  ).join('');
}

async function prepareTaskForm() {
  if (!classesCache.length) await loadClasses();
  if (!subjectsCache.length) await loadSubjects();
  fillTaskClasses();
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

document.getElementById('focusPolicy')?.addEventListener('change', event => {
  document.getElementById('strictWarning')?.classList.toggle('hidden', event.target.value !== 'strict');
});

function assignmentStatusLabel(status) {
  return status === 'published' ? ['Опубликовано', 'green'] : status === 'closed' ? ['Завершено', 'blue'] : ['Черновик', 'amber'];
}

function renderAssignments() {
  const body = document.getElementById('assignmentsTableBody');
  if (!body) return;
  const query = (document.getElementById('assignmentSearch')?.value || '').trim().toLowerCase();
  const statusFilter = document.getElementById('assignmentStatusFilter')?.value || '';

  const rows = assignmentsCache.filter(item => {
    const haystack = [item.title, item.subject_name, item.class_names].join(' ').toLowerCase();
    return (!query || haystack.includes(query)) && (!statusFilter || item.status === statusFilter);
  });

  body.innerHTML = rows.length ? rows.map(item => {
    const [statusText, statusClass] = assignmentStatusLabel(item.status);
    const strict = item.focus_policy === 'strict';
    return `
      <tr>
        <td><b>${escapeHtml(item.title)}</b><small>${escapeHtml(item.subject_name || 'Без предмета')}${item.time_limit_minutes ? ' · ' + Number(item.time_limit_minutes) + ' мин.' : ''}</small></td>
        <td>${escapeHtml(item.class_names || '—')}</td>
        <td><span class="status ${strict ? 'amber' : 'blue'}">${strict ? 'Строгий' : 'Обычный'}</span></td>
        <td>${Number(item.attempts_count || 0)}</td>
        <td><span class="status ${statusClass}">${statusText}</span></td>
      </tr>`;
  }).join('') : '<tr><td colspan="5">Задания не найдены.</td></tr>';
}

async function loadAssignments() {
  const body = document.getElementById('assignmentsTableBody');
  if (!body) return;
  body.innerHTML = '<tr><td colspan="5">Загрузка...</td></tr>';
  try {
    const response = await fetch('./api/assignments/list.php', { credentials: 'same-origin', cache: 'no-store' });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось загрузить задания.');
    assignmentsCache = data.assignments || [];
    renderAssignments();
  } catch (error) {
    body.innerHTML = `<tr><td colspan="5">${escapeHtml(error.message)}</td></tr>`;
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
    showView('assignments');
    await loadAssignments();
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

let usersCache = [];

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, char => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
  })[char]);
}

function roleLabel(role) {
  return roleLabels[role] || role;
}

function renderUsers() {
  const body = document.getElementById('usersTableBody');
  if (!body) return;

  const query = (document.getElementById('userSearch')?.value || '').trim().toLowerCase();
  const role = document.getElementById('userRoleFilter')?.value || '';

  const filtered = usersCache.filter(user => {
    const haystack = [user.first_name, user.last_name, user.email, user.class_name].join(' ').toLowerCase();
    return (!query || haystack.includes(query)) && (!role || user.role === role);
  });

  body.innerHTML = filtered.length ? filtered.map(user => `
    <tr>
      <td><b>${escapeHtml(user.last_name)} ${escapeHtml(user.first_name)}</b></td>
      <td>${escapeHtml(user.email)}</td>
      <td><span class="role-chip role-${escapeHtml(user.role)}">${escapeHtml(roleLabel(user.role))}</span></td>
      <td>${escapeHtml(user.class_name || '—')}</td>
      <td><span class="status ${Number(user.is_active) ? 'green' : 'amber'}">${Number(user.is_active) ? 'Активен' : 'Отключён'}</span></td>
    </tr>
  `).join('') : '<tr><td colspan="5">Пользователи не найдены.</td></tr>';
}

async function loadUsers() {
  const body = document.getElementById('usersTableBody');
  if (!body || document.querySelector('.admin-only:not(.hidden)') === null) return;
  body.innerHTML = '<tr><td colspan="5">Загрузка...</td></tr>';

  try {
    const response = await fetch('./api/users/list.php', { credentials: 'same-origin', cache: 'no-store' });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось загрузить пользователей.');
    usersCache = data.users || [];

    document.getElementById('usersTotal').textContent = usersCache.length;
    document.getElementById('teachersTotal').textContent = usersCache.filter(u => u.role === 'teacher').length;
    document.getElementById('studentsTotal').textContent = usersCache.filter(u => u.role === 'student').length;
    document.getElementById('adminsTotal').textContent = usersCache.filter(u => u.role === 'admin').length;
    renderUsers();
  } catch (error) {
    body.innerHTML = `<tr><td colspan="5">${escapeHtml(error.message)}</td></tr>`;
  }
}

document.getElementById('createUserBtn')?.addEventListener('click', () => openModal(userModal));
document.getElementById('userSearch')?.addEventListener('input', renderUsers);
document.getElementById('userRoleFilter')?.addEventListener('change', renderUsers);

document.getElementById('newUserRole')?.addEventListener('change', event => {
  const student = event.target.value === 'student';
  const field = document.getElementById('classNameField');
  field.classList.toggle('hidden', !student);
  field.querySelector('input').required = student;
});

document.getElementById('userForm')?.addEventListener('submit', async event => {
  event.preventDefault();
  const form = event.currentTarget;
  const errorEl = document.getElementById('userFormError');
  const button = form.querySelector('button[type="submit"]');
  errorEl.classList.add('hidden');
  button.disabled = true;
  button.textContent = 'Создаём...';

  try {
    const payload = Object.fromEntries(new FormData(form).entries());
    const response = await fetch('./api/users/create.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось создать пользователя.');

    form.reset();
    document.getElementById('classNameField').classList.add('hidden');
    document.getElementById('classNameField').querySelector('input').required = false;
    closeModal(userModal);
    await loadUsers();
  } catch (error) {
    errorEl.textContent = error.message;
    errorEl.classList.remove('hidden');
  } finally {
    button.disabled = false;
    button.textContent = 'Создать пользователя';
  }
});

document.querySelector('[data-view="users"]')?.addEventListener('click', loadUsers);

loadSession().then(async user => {
  if (user) {
    applyUser(user);
    if (user.role !== 'student') {
      await loadSchools();
      await Promise.all([loadClasses(), loadSubjects(), loadAssignments()]);
    }
  }
});

if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => navigator.serviceWorker.register('./sw.js').catch(() => {}));
}