const roleButtons = document.querySelectorAll('.role-btn');
const teacherNav = document.querySelector('.teacher-nav');
const studentNav = document.querySelector('.student-nav');
const sidebar = document.getElementById('sidebar');
const menuBtn = document.getElementById('menuBtn');
const pageTitle = document.getElementById('pageTitle');
const eyebrow = document.getElementById('eyebrow');
const sidebarName = document.getElementById('sidebarName');
const sidebarAvatar = document.getElementById('sidebarAvatar');
const taskModal = document.getElementById('taskModal');
const quizModal = document.getElementById('quizModal');

const titles = {
  'teacher-dashboard': ['Кабинет учителя', 'Добрый день!'],
  assignments: ['Управление обучением', 'Задания'],
  classes: ['Ученики и группы', 'Классы'],
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

function setRole(role) {
  const teacher = role === 'teacher';
  roleButtons.forEach(b => b.classList.toggle('active', b.dataset.role === role));
  teacherNav.classList.toggle('hidden', !teacher);
  studentNav.classList.toggle('hidden', teacher);
  document.querySelectorAll('.teacher-only').forEach(el => el.classList.toggle('hidden', !teacher));
  sidebarName.textContent = teacher ? 'Учитель' : 'Магомед';
  sidebarAvatar.textContent = teacher ? 'У' : 'М';
  showView(teacher ? 'teacher-dashboard' : 'student-dashboard');
}

roleButtons.forEach(btn => btn.addEventListener('click', () => setRole(btn.dataset.role)));
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

if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => navigator.serviceWorker.register('./sw.js').catch(() => {}));
}