
(function () {
  var assignmentId = null;
  var questions = [];
  var editable = false;
  var draggedCard = null;

  var types = [
    ['single', 'Один вариант ответа'],
    ['multiple', 'Несколько вариантов ответа'],
    ['true_false', 'Верно / неверно'],
    ['ordering', 'Хронология / порядок'],
    ['matching', 'Соответствия'],
    ['short_answer', 'Короткий ответ / дата / слово'],
    ['number', 'Числовой ответ'],
    ['correction', 'Найти и исправить ошибку'],
    ['image_answer', 'Ответ по изображению'],
    ['essay', 'Развёрнутый ответ']
  ];

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = String(text);
    return node;
  }

  function button(text, className) {
    var node = el('button', className || 'secondary-btn compact-btn', text);
    node.type = 'button';
    return node;
  }

  function label(text, control) {
    var node = el('label', 'builder-field');
    node.appendChild(document.createTextNode(text));
    node.appendChild(control);
    return node;
  }

  function typeSelect(question) {
    var select = el('select', 'builder-type-select');
    select.dataset.builderType = '1';
    var selected = question.interaction_type || question.type || 'short_answer';
    types.forEach(function (entry) {
      var option = document.createElement('option');
      option.value = entry[0];
      option.textContent = entry[1];
      option.selected = entry[0] === selected;
      select.appendChild(option);
    });
    select.disabled = !editable;
    return select;
  }

  function addOptionRow(container, questionId, multiple, option) {
    option = option || { text: '', is_correct: 0 };
    var row = el('div', 'builder-option-row');
    var correct = document.createElement('input');
    correct.type = multiple ? 'checkbox' : 'radio';
    correct.name = 'builder-correct-' + questionId;
    correct.checked = Number(option.is_correct || 0) === 1;
    correct.dataset.optionCorrect = '1';
    correct.disabled = !editable;

    var input = document.createElement('input');
    input.value = option.text || '';
    input.placeholder = 'Вариант ответа';
    input.dataset.optionText = '1';
    input.disabled = !editable;

    row.appendChild(correct);
    row.appendChild(input);

    if (editable) {
      var remove = button('×', 'mini-action danger-action');
      remove.title = 'Удалить вариант';
      remove.addEventListener('click', function () {
        row.remove();
      });
      row.appendChild(remove);
    }
    container.appendChild(row);
  }

  function addPairRow(container, pair) {
    pair = pair || { left: '', right: '' };
    var row = el('div', 'builder-match-row');

    var left = document.createElement('input');
    left.value = pair.left || '';
    left.placeholder = 'Левый столбик';
    left.dataset.matchLeft = '1';
    left.disabled = !editable;

    var arrow = el('span', '', '↔');

    var right = document.createElement('input');
    right.value = pair.right || '';
    right.placeholder = 'Правильное соответствие';
    right.dataset.matchRight = '1';
    right.disabled = !editable;

    row.appendChild(left);
    row.appendChild(arrow);
    row.appendChild(right);

    if (editable) {
      var remove = button('×', 'mini-action danger-action');
      remove.title = 'Удалить пару';
      remove.addEventListener('click', function () {
        row.remove();
      });
      row.appendChild(remove);
    }
    container.appendChild(row);
  }

  function renderTypeFields(card, question, interaction, fresh) {
    var target = card.querySelector('[data-builder-fields]');
    target.innerHTML = '';
    var settings = fresh ? {} : (question.settings || {});

    if (interaction === 'single' || interaction === 'multiple') {
      var optionWrap = el('div', 'builder-options');
      optionWrap.dataset.builderOptions = '1';
      var sourceOptions = fresh ? [] : (Array.isArray(question.options) ? question.options : []);
      if (sourceOptions.length < 2) {
        sourceOptions = [
          { text: '', is_correct: 1 },
          { text: '', is_correct: 0 }
        ];
      }
      sourceOptions.forEach(function (option) {
        addOptionRow(optionWrap, question.id, interaction === 'multiple', option);
      });
      target.appendChild(optionWrap);
      if (editable) {
        var addOption = button('＋ Вариант ответа');
        addOption.addEventListener('click', function () {
          addOptionRow(optionWrap, question.id, interaction === 'multiple');
        });
        target.appendChild(addOption);
      }
      return;
    }

    if (interaction === 'true_false') {
      var tf = document.createElement('select');
      tf.dataset.builderTrueFalse = '1';
      tf.disabled = !editable;
      var correct = fresh ? null : (question.options || []).find(function (item) {
        return Number(item.is_correct || 0) === 1;
      });
      var answer = String(correct && correct.text || '').toLowerCase().indexOf('невер') >= 0 ? 'false' : 'true';
      [['true', 'Верно'], ['false', 'Неверно']].forEach(function (entry) {
        var option = document.createElement('option');
        option.value = entry[0];
        option.textContent = entry[1];
        option.selected = entry[0] === answer;
        tf.appendChild(option);
      });
      target.appendChild(label('Правильный ответ', tf));
      return;
    }

    if (interaction === 'ordering') {
      var textarea = document.createElement('textarea');
      textarea.rows = 6;
      textarea.dataset.builderOrdering = '1';
      textarea.placeholder = 'Каждый элемент правильного порядка с новой строки';
      textarea.disabled = !editable;
      if (!fresh) {
        var items = settings.items || {};
        var order = Array.isArray(settings.correct_order) ? settings.correct_order : Object.keys(items);
        textarea.value = order.map(function (key) { return items[key] || ''; }).filter(Boolean).join('\n');
      }
      var field = label('Правильная последовательность', textarea);
      var small = el('small', '', 'Ученику UVORIA автоматически перемешает эти элементы.');
      field.appendChild(small);
      target.appendChild(field);
      return;
    }

    if (interaction === 'matching') {
      var matchWrap = el('div', 'builder-matching');
      matchWrap.dataset.builderMatching = '1';
      var rows = [];
      if (!fresh) {
        var left = settings.left || {};
        var right = settings.right || {};
        var pairs = settings.pairs || {};
        Object.keys(pairs).forEach(function (lk) {
          rows.push({ left: left[lk] || '', right: right[pairs[lk]] || '' });
        });
      }
      if (rows.length < 2) rows = [{ left: '', right: '' }, { left: '', right: '' }];
      rows.forEach(function (pair) { addPairRow(matchWrap, pair); });
      target.appendChild(matchWrap);
      if (editable) {
        var addPair = button('＋ Пара соответствий');
        addPair.addEventListener('click', function () { addPairRow(matchWrap); });
        target.appendChild(addPair);
      }
      return;
    }

    if (interaction === 'correction') {
      var original = document.createElement('textarea');
      original.rows = 4;
      original.dataset.builderOriginal = '1';
      original.placeholder = 'Текст, содержащий ошибку';
      original.value = fresh ? '' : (settings.original_text || '');
      original.disabled = !editable;

      var fixed = document.createElement('textarea');
      fixed.rows = 3;
      fixed.dataset.builderCorrect = '1';
      fixed.placeholder = 'Исправленный текст или правильный ответ';
      fixed.value = fresh ? '' : (question.correct_text || '');
      fixed.disabled = !editable;

      target.appendChild(label('Текст с ошибкой', original));
      target.appendChild(label('Правильный вариант', fixed));
      return;
    }

    if (interaction === 'image_answer') {
      var imageAnswer = document.createElement('textarea');
      imageAnswer.rows = 3;
      imageAnswer.dataset.builderCorrect = '1';
      imageAnswer.placeholder = 'Например: Битва за Москву | 1941';
      imageAnswer.value = fresh ? '' : (question.correct_text || '');
      imageAnswer.disabled = !editable;

      var hint = document.createElement('input');
      hint.dataset.builderAnswerHint = '1';
      hint.placeholder = 'Например: событие, год и место';
      hint.value = fresh ? 'Событие / год / место / объект' : (settings.answer_hint || 'Событие / год / место / объект');
      hint.disabled = !editable;

      target.appendChild(label('Правильный ответ', imageAnswer));
      target.appendChild(label('Подсказка формата ответа', hint));
      return;
    }

    if (interaction === 'essay') {
      target.appendChild(el('div', 'builder-info-box', 'Развёрнутый ответ проверяется учителем вручную. Правильный ответ для автопроверки не нужен.'));
      return;
    }

    var answerInput = document.createElement('textarea');
    answerInput.rows = 3;
    answerInput.dataset.builderCorrect = '1';
    answerInput.placeholder = interaction === 'number'
      ? 'Например: 1939'
      : 'Допустимые ответы можно разделить символом |';
    answerInput.value = fresh ? '' : (question.correct_text || '');
    answerInput.disabled = !editable;
    target.appendChild(label('Правильный ответ', answerInput));
  }

  function renderAssets(card, question) {
    var block = el('div', 'builder-images-block');
    block.appendChild(el('b', '', 'Изображения'));

    var list = el('div', 'builder-assets-list');
    (question.assets || []).forEach(function (asset) {
      var figure = el('figure', 'builder-asset');
      var img = document.createElement('img');
      img.src = asset.url;
      img.alt = 'Изображение вопроса';
      figure.appendChild(img);
      figure.appendChild(el('figcaption', '', asset.original_name || 'Изображение'));

      if (editable) {
        var remove = button('Удалить', 'mini-action danger-action');
        remove.addEventListener('click', async function () {
          if (!confirm('Удалить изображение из вопроса?')) return;
          try {
            var response = await fetch('./api/questions/delete-image.php', {
              method: 'POST',
              credentials: 'same-origin',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ asset_id: Number(asset.id) })
            });
            var data = await response.json();
            if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось удалить изображение.');
            await load(question.id);
          } catch (error) {
            alert(error.message);
          }
        });
        figure.appendChild(remove);
      }
      list.appendChild(figure);
    });
    block.appendChild(list);

    if (editable) {
      var uploadLabel = el('label', 'builder-upload');
      uploadLabel.appendChild(el('span', '', '＋ Добавить изображение'));
      var file = document.createElement('input');
      file.type = 'file';
      file.accept = 'image/png,image/jpeg,image/webp,image/gif';
      file.addEventListener('change', async function () {
        var selected = file.files && file.files[0];
        if (!selected) return;
        var formData = new FormData();
        formData.append('question_id', String(question.id));
        formData.append('file', selected);
        setState(card, 'Загружаем изображение...');
        try {
          var response = await fetch('./api/questions/upload-image.php', {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
          });
          var data = await response.json();
          if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось загрузить изображение.');
          await load(question.id);
        } catch (error) {
          setState(card, error.message, true);
        }
      });
      uploadLabel.appendChild(file);
      block.appendChild(uploadLabel);
    }

    return block;
  }

  function renderCard(question, index) {
    var card = el('article', 'question-builder-card');
    card.dataset.builderQuestion = String(question.id);
    card.draggable = editable;

    var head = el('div', 'question-builder-card-head');

    var handle = button('⋮⋮', 'builder-drag-handle');
    handle.title = 'Перетащить вопрос';
    handle.disabled = !editable;
    head.appendChild(handle);

    var number = el('span', 'builder-question-number', '№ ' + (index + 1));
    head.appendChild(number);

    var select = typeSelect(question);
    head.appendChild(select);

    var pointsLabel = el('label', 'builder-points');
    pointsLabel.appendChild(document.createTextNode('Баллы '));
    var points = document.createElement('input');
    points.type = 'number';
    points.min = '0.1';
    points.max = '1000';
    points.step = '0.1';
    points.value = Number(question.points || 1);
    points.dataset.builderPoints = '1';
    points.disabled = !editable;
    pointsLabel.appendChild(points);
    head.appendChild(pointsLabel);

    var actions = el('div', 'builder-card-actions');
    if (editable) {
      var up = button('↑');
      up.title = 'Поднять выше';
      up.addEventListener('click', async function () {
        var prev = card.previousElementSibling;
        if (!prev) return;
        card.parentElement.insertBefore(card, prev);
        await saveOrderSafe();
      });
      actions.appendChild(up);

      var down = button('↓');
      down.title = 'Опустить ниже';
      down.addEventListener('click', async function () {
        var next = card.nextElementSibling;
        if (!next) return;
        card.parentElement.insertBefore(next, card);
        await saveOrderSafe();
      });
      actions.appendChild(down);

      var save = button('Сохранить', 'primary-btn compact-btn');
      save.addEventListener('click', async function () {
        save.disabled = true;
        setState(card, 'Сохраняем...');
        try {
          var response = await fetch('./api/questions/update.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(collect(card))
          });
          var data = await response.json();
          if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось сохранить вопрос.');
          setState(card, 'Сохранено ✓');
          await load(question.id);
          if (typeof loadAssignments === 'function') await loadAssignments();
        } catch (error) {
          setState(card, error.message, true);
        } finally {
          save.disabled = false;
        }
      });
      actions.appendChild(save);

      var remove = button('Удалить', 'mini-action danger-action');
      remove.addEventListener('click', async function () {
        if (!confirm('Удалить этот вопрос из задания?')) return;
        try {
          var response = await fetch('./api/questions/delete.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ question_id: Number(question.id) })
          });
          var data = await response.json();
          if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось удалить вопрос.');
          await load();
          if (typeof loadAssignments === 'function') await loadAssignments();
        } catch (error) {
          alert(error.message);
        }
      });
      actions.appendChild(remove);
    }
    head.appendChild(actions);
    card.appendChild(head);

    var text = document.createElement('textarea');
    text.rows = 3;
    text.value = question.text || '';
    text.dataset.builderText = '1';
    text.disabled = !editable;
    card.appendChild(label('Текст вопроса', text));

    var fields = el('div', 'builder-type-fields');
    fields.dataset.builderFields = '1';
    card.appendChild(fields);
    renderTypeFields(card, question, question.interaction_type || question.type || 'short_answer', false);

    card.appendChild(renderAssets(card, question));

    var state = el('div', 'builder-save-state');
    state.dataset.builderSaveState = '1';
    card.appendChild(state);

    select.addEventListener('change', function () {
      renderTypeFields(card, question, select.value, true);
    });

    if (editable) {
      card.addEventListener('dragstart', function (event) {
        draggedCard = card;
        card.classList.add('dragging');
        event.dataTransfer.effectAllowed = 'move';
      });
      card.addEventListener('dragover', function (event) {
        event.preventDefault();
        if (!draggedCard || draggedCard === card) return;
        var rect = card.getBoundingClientRect();
        var before = event.clientY < rect.top + rect.height / 2;
        card.parentElement.insertBefore(draggedCard, before ? card : card.nextSibling);
      });
      card.addEventListener('dragend', async function () {
        card.classList.remove('dragging');
        draggedCard = null;
        await saveOrderSafe();
      });
    }

    return card;
  }

  function collect(card) {
    var interaction = card.querySelector('[data-builder-type]').value;
    var payload = {
      question_id: Number(card.dataset.builderQuestion),
      interaction_type: interaction,
      text: card.querySelector('[data-builder-text]').value,
      points: Number(card.querySelector('[data-builder-points]').value || 1)
    };

    if (interaction === 'single' || interaction === 'multiple') {
      payload.options = Array.from(card.querySelectorAll('.builder-option-row')).map(function (row) {
        return {
          text: row.querySelector('[data-option-text]').value,
          is_correct: row.querySelector('[data-option-correct]').checked
        };
      });
    } else if (interaction === 'true_false') {
      payload.true_false_answer = card.querySelector('[data-builder-true-false]').value;
    } else if (interaction === 'ordering') {
      payload.ordering_items = card.querySelector('[data-builder-ordering]').value
        .split(/\n+/)
        .map(function (value) { return value.trim(); })
        .filter(Boolean);
    } else if (interaction === 'matching') {
      payload.matching_pairs = Array.from(card.querySelectorAll('.builder-match-row')).map(function (row) {
        return {
          left: row.querySelector('[data-match-left]').value,
          right: row.querySelector('[data-match-right]').value
        };
      });
    } else if (interaction === 'correction') {
      payload.original_text = card.querySelector('[data-builder-original]').value;
      payload.correct_text = card.querySelector('[data-builder-correct]').value;
    } else if (interaction === 'image_answer') {
      payload.correct_text = card.querySelector('[data-builder-correct]').value;
      payload.answer_hint = card.querySelector('[data-builder-answer-hint]').value;
    } else if (interaction !== 'essay') {
      payload.correct_text = card.querySelector('[data-builder-correct]').value;
    }

    return payload;
  }

  function setState(card, text, isError) {
    var node = card.querySelector('[data-builder-save-state]');
    if (!node) return;
    node.textContent = text || '';
    node.classList.toggle('error', Boolean(isError));
  }

  function renderSummary() {
    var summary = document.getElementById('questionPreviewSummary');
    if (!summary) return;
    summary.innerHTML = '';

    summary.appendChild(el('b', '', questions.length + ' вопросов'));
    var counts = {};
    questions.forEach(function (question) {
      var kind = question.interaction_type || question.type;
      counts[kind] = (counts[kind] || 0) + 1;
    });
    Object.keys(counts).forEach(function (kind) {
      summary.appendChild(el('span', '', (typeof questionTypeLabel === 'function' ? questionTypeLabel(kind) : kind) + ': ' + counts[kind]));
    });
  }

  function render() {
    var list = document.getElementById('questionPreviewList');
    var addButton = document.getElementById('builderAddQuestionBtn');
    var help = document.getElementById('questionBuilderHelp');
    if (!list) return;

    if (addButton) addButton.classList.toggle('hidden', !editable);
    if (help) {
      help.textContent = editable
        ? 'Перетаскивайте карточки за значок ⋮⋮ или используйте стрелки, чтобы изменить порядок вопросов.'
        : 'Задание уже опубликовано или по нему есть попытки. Конструктор открыт только для просмотра.';
    }

    renderSummary();
    list.innerHTML = '';

    if (!questions.length) {
      var empty = el('div', 'subject-empty-list');
      empty.appendChild(el('b', '', 'Вопросов пока нет'));
      empty.appendChild(el('span', '', editable ? 'Нажмите «Добавить вопрос», чтобы создать первый.' : 'В этом задании нет вопросов.'));
      list.appendChild(empty);
      return;
    }

    questions.forEach(function (question, index) {
      list.appendChild(renderCard(question, index));
    });
  }

  async function load(scrollQuestionId) {
    if (!assignmentId) return;
    var list = document.getElementById('questionPreviewList');
    var error = document.getElementById('questionPreviewError');
    if (error) error.classList.add('hidden');
    if (list) list.innerHTML = '<p>Загрузка конструктора...</p>';

    try {
      var response = await fetch('./api/assignments/questions.php?assignment_id=' + encodeURIComponent(assignmentId), {
        credentials: 'same-origin',
        cache: 'no-store'
      });
      var data = await response.json();
      if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось загрузить вопросы.');

      questions = data.questions || [];
      editable = Boolean(data.assignment && data.assignment.editable);

      var title = document.getElementById('questionPreviewTitle');
      if (title) title.textContent = data.assignment && data.assignment.title || 'Конструктор задания';

      var hint = document.getElementById('questionBuilderHint');
      if (hint) {
        hint.textContent = editable
          ? 'Проверьте импорт и отредактируйте вопросы перед назначением задания классу.'
          : 'Вопросы можно просматривать, но редактирование этого задания уже заблокировано.';
      }

      render();

      if (scrollQuestionId) {
        requestAnimationFrame(function () {
          var node = document.querySelector('[data-builder-question="' + Number(scrollQuestionId) + '"]');
          if (node) node.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
      }
    } catch (errorValue) {
      if (error) {
        error.textContent = errorValue.message;
        error.classList.remove('hidden');
      }
      if (list) list.innerHTML = '';
    }
  }

  async function saveOrder() {
    if (!editable || !assignmentId) return;
    var ids = Array.from(document.querySelectorAll('#questionPreviewList [data-builder-question]'))
      .map(function (card) { return Number(card.dataset.builderQuestion); });

    var response = await fetch('./api/questions/reorder.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ assignment_id: assignmentId, question_ids: ids })
    });
    var data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось сохранить порядок вопросов.');

    questions.sort(function (a, b) {
      return ids.indexOf(Number(a.id)) - ids.indexOf(Number(b.id));
    });
    document.querySelectorAll('#questionPreviewList .builder-question-number').forEach(function (node, index) {
      node.textContent = '№ ' + (index + 1);
    });
  }

  async function saveOrderSafe() {
    try {
      await saveOrder();
    } catch (error) {
      alert(error.message);
      await load();
    }
  }

  var addButton = document.getElementById('builderAddQuestionBtn');
  if (addButton) {
    addButton.addEventListener('click', async function () {
      if (!editable || !assignmentId) return;
      addButton.disabled = true;
      addButton.textContent = 'Добавляем...';
      try {
        var response = await fetch('./api/questions/create.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            assignment_id: assignmentId,
            interaction_type: 'essay',
            text: 'Новый вопрос',
            points: 1
          })
        });
        var data = await response.json();
        if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось добавить вопрос.');
        await load(Number(data.question_id));
        if (typeof loadAssignments === 'function') await loadAssignments();
      } catch (error) {
        alert(error.message);
      } finally {
        addButton.disabled = false;
        addButton.textContent = '＋ Добавить вопрос';
      }
    });
  }

  openQuestionPreview = async function (newAssignmentId) {
    assignmentId = Number(newAssignmentId);
    questions = [];
    editable = false;
    var error = document.getElementById('questionPreviewError');
    if (error) error.classList.add('hidden');
    if (typeof openModal === 'function') openModal(document.getElementById('questionPreviewModal'));
    await load();
  };
})();
