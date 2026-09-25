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
document.querySelectorAll('.nav-item').forEach(btn => btn.addEventListener('click', () => showView(btn.dataset.view)));
document.querySelectorAll('[data-view-jump]').forEach(btn => btn.addEventListener('click', () => showView(btn.dataset.viewJump)));
menuBtn?.addEventListener('click', () => sidebar.classList.toggle('open'));

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

document.getElementById('taskForm')?.addEventListener('submit', e => {
  e.preventDefault();
  const submit = e.currentTarget.querySelector('button[type="submit"]');
  submit.textContent = 'Черновик создан ✓';
  submit.disabled = true;
  setTimeout(() => {
    submit.textContent = 'Продолжить';
    submit.disabled = false;
    closeModal(taskModal);
    showView('assignments');
  }, 900);
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

loadSession().then(user => {
  if (user) applyUser(user);
});

if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => navigator.serviceWorker.register('./sw.js').catch(() => {}));
}