'use strict';
(() => {
  const sheet = document.getElementById('sheet');
  const status = document.getElementById('status');
  const print = document.getElementById('print');
  const points = document.getElementById('points');
  const make = (tag, text, cls) => {
    const node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text !== null && text !== undefined) node.textContent = String(text);
    return node;
  };
  const letter = i => i < 26 ? String.fromCharCode(65 + i) : String(i + 1);
  const array = value => Array.isArray(value) ? value : [];
  const line = (parent, count = 1) => {
    for (let i = 0; i < count; i++) parent.append(make('div', null, 'writing-line'));
  };
  function listItems(items, type) {
    const list = make('ol');
    if (type) list.type = type;
    for (const item of items) list.append(make('li', item.text || ''));
    return list;
  }
  function questionNode(q, index) {
    const box = make('section', null, 'question');
    const heading = make('h2', (index + 1) + '. ' + (q.text || ''));
    heading.append(make('span', '(' + Number(q.points || 0) + ' балл.)', 'qpoints'));
    box.append(heading);
    for (const asset of array(q.assets)) {
      const url = new URL(asset.url || '', location.href);
      if (url.origin !== location.origin || !/\/api\/questions\/asset\.php$/.test(url.pathname)) {
        throw new Error('Не удалось безопасно загрузить изображение вопроса.');
      }
      const image = make('img', null, 'asset');
      image.alt = asset.original_name || 'Изображение к вопросу';
      image.src = url.href;
      box.append(image);
    }
    const options = array(q.options);
    const type = q.interaction_type || q.type || 'text';
    if (['single', 'multiple', 'true_false'].includes(type) && options.length) {
      box.append(make('p', type === 'multiple' ? 'Отметьте все подходящие ответы.' : 'Отметьте один ответ.', 'instruction'));
      const ul = make('ul', null, 'options');
      options.forEach((opt, i) => {
        const li = make('li');
        const bubble = make('span', null, 'bubble');
        bubble.setAttribute('aria-hidden', 'true');
        li.append(bubble, make('span', letter(i) + '. ' + (opt.text || '')));
        ul.append(li);
      });
      box.append(ul);
    } else if (type === 'order' && array(q.structured?.items).length) {
      box.append(make('p', 'Запишите буквы элементов в правильной последовательности.', 'instruction'), listItems(q.structured.items, 'A'));
      box.append(make('p', 'Ответ:', 'answer-line'));
      line(box);
    } else if (type === 'matching' && array(q.structured?.left).length && array(q.structured?.right).length) {
      box.append(make('p', 'Установите соответствия между числами и буквами.', 'instruction'));
      const grid = make('div', null, 'match-grid');
      grid.append(listItems(q.structured.left), listItems(q.structured.right, 'A'));
      box.append(grid, make('p', 'Ответ (например, 1–A):', 'answer-line'));
      line(box, 2);
    } else {
      if (type === 'correction' && q.structured?.original_text) {
        box.append(make('p', q.structured.original_text, 'original'));
      }
      box.append(make('p', 'Запишите ответ.', 'instruction'));
      line(box, type === 'essay' ? 7 : type === 'correction' ? 3 : 2);
    }
    return box;
  }
  function render(data) {
    const questions = array(data.questions);
    if (!questions.length) throw new Error('В задании нет активных вопросов. Добавьте вопросы в конструкторе.');
    const assignment = data.assignment || {};
    const header = make('header', null, 'sheet-head');
    header.append(make('div', 'UROVIA PAPER · БУМАЖНАЯ РАБОТА', 'stamp'), make('h1', assignment.title || 'Учебное задание'));
    const meta = [assignment.subject_name, questions.length + ' вопр.', assignment.time_limit_minutes ? assignment.time_limit_minutes + ' мин' : ''].filter(Boolean).join(' · ');
    header.append(make('p', meta, 'meta'));
    if (assignment.description) header.append(make('p', assignment.description, 'original'));
    const identity = make('div', null, 'identity');
    for (const label of ['ФИО:', 'Класс:', 'Дата:']) identity.append(make('span', label));
    header.append(identity);
    sheet.replaceChildren(header);
    questions.forEach((q, i) => sheet.append(questionNode(q, i)));
    sheet.append(make('footer', 'UROVIA · urovia.ru · Задание № ' + assignment.id, 'footer'));
  }
  points.addEventListener('change', () => sheet.classList.toggle('no-points', !points.checked));
  print.addEventListener('click', () => { if (!print.disabled) window.print(); });
  async function load() {
    try {
      const id = Number(new URLSearchParams(location.search).get('assignment_id'));
      if (!Number.isSafeInteger(id) || id < 1) throw new Error('Откройте печать из списка заданий UROVIA.');
      const response = await fetch('./api/assignments/test-preview.php?assignment_id=' + encodeURIComponent(id), {credentials:'same-origin', cache:'no-store'});
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.error || 'Не удалось загрузить задание.');
      render(data);
      sheet.hidden = false;
      status.textContent = 'Загрузка изображений…';
      await Promise.all(Array.from(sheet.querySelectorAll('img'), img => img.decode()));
      status.hidden = true;
      print.disabled = false;
      document.title = 'UROVIA PAPER — ' + (data.assignment?.title || 'печать');
    } catch (error) {
      sheet.hidden = true;
      sheet.replaceChildren();
      status.hidden = false;
      status.classList.add('error');
      status.textContent = (error?.message || 'Ошибка подготовки печати.') + ' Вернитесь к заданию или обновите страницу.';
    }
  }
  load();
})();