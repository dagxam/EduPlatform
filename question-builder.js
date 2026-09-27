(function () {
  var assignmentId = null;
  var questions = [];
  var editable = false;
  var draggedCard = null;

  var types = [
    { value: 'single', label: 'Один правильный ответ', short: 'Тест · один ответ', icon: 'A' },
    { value: 'multiple', label: 'Несколько правильных ответов', short: 'Тест · несколько ответов', icon: '☑' },
    { value: 'order', label: 'Восстановить порядок', short: 'Перетаскивание · хронология', icon: '↕' },
    { value: 'matching', label: 'Установить соответствия', short: 'Два столбца · пары', icon: '↔' },
    { value: 'text', label: 'Короткий ответ', short: 'Слово, дата или фраза', icon: 'T' },
    { value: 'number', label: 'Числовой ответ', short: 'Число', icon: '#' },
    { value: 'correction', label: 'Найти и исправить ошибку', short: 'Исправление текста', icon: '✎' },
    { value: 'true_false', label: 'Верно / неверно', short: 'Два варианта', icon: '✓' }
  ];

  function canonicalType(value) {
    value = String(value || '');
    if (value === 'ordering') return 'order';
    if (value === 'short_answer' || value === 'image_answer') return 'text';
    return value || 'text';
  }

  function typeInfo(value) {
    value = canonicalType(value);
    return types.find(function (item) { return item.value === value; }) || {
      value: value,
      label: value === 'essay' ? 'Развёрнутый ответ (старый тип)' : 'Вопрос',
      short: value === 'essay' ? 'Проверяется учителем' : value,
      icon: '?'
    };
  }

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

  function label(text, control, help) {
    var node = el('label', 'builder-field');
    node.appendChild(document.createTextNode(text));
    node.appendChild(control);
    if (help) node.appendChild(el('small', '', help));
    return node;
  }

  function typeSelect(question) {
    var select = el('select', 'builder-type-select');
    select.dataset.builderType = '1';
    var selected = canonicalType(question.interaction_type || question.type || 'text');

    types.forEach(function (entry) {
      var option = document.createElement('option');
      option.value = entry.value;
      option.textContent = entry.label;
      option.selected = entry.value === selected;
      select.appendChild(option);
    });

    if (selected === 'essay') {
      var legacy = document.createElement('option');
      legacy.value = 'essay';
      legacy.textContent = 'Развёрнутый ответ (старый тип)';
      legacy.selected = true;
      select.appendChild(legacy);
    }

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
    correct.title = multiple ? 'Отметить как правильный ответ' : 'Выбрать правильный ответ';

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
      remove.addEventListener('click', function () { row.remove(); });
      row.appendChild(remove);
    }
    container.appendChild(row);
  }

  function updateOrderNumbers(container) {
    container.querySelectorAll('.builder-order-row').forEach(function (row, index) {
      var n = row.querySelector('.builder-order-number');
      if (n) n.textContent = String(index + 1);
    });
  }

  function addOrderRow(container, value) {
    var row = el('div', 'builder-order-row');
    row.draggable = editable;

    var handle = el('span', 'builder-order-grip', '⋮⋮');
    handle.title = 'Перетащите элемент';

    var number = el('span', 'builder-order-number', String(container.children.length + 1));

    var input = document.createElement('input');
    input.value = value || '';
    input.placeholder = 'Элемент последовательности';
    input.dataset.orderText = '1';
    input.disabled = !editable;

    row.appendChild(handle);
    row.appendChild(number);
    row.appendChild(input);

    if (editable) {
      var up = button('↑', 'mini-action');
      up.title = 'Выше';
      up.addEventListener('click', function () {
        var prev = row.previousElementSibling;
        if (prev) {
          container.insertBefore(row, prev);
          updateOrderNumbers(container);
        }
      });

      var down = button('↓', 'mini-action');
      down.title = 'Ниже';
      down.addEventListener('click', function () {
        var next = row.nextElementSibling;
        if (next) {
          container.insertBefore(next, row);
          updateOrderNumbers(container);
        }
      });

      var remove = button('×', 'mini-action danger-action');
      remove.title = 'Удалить элемент';
      remove.addEventListener('click', function () {
        row.remove();
        updateOrderNumbers(container);
      });

      row.appendChild(up);
      row.appendChild(down);
      row.appendChild(remove);

      row.addEventListener('dragstart', function (event) {
        row.classList.add('dragging');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', 'order');
      });
      row.addEventListener('dragover', function (event) {
        event.preventDefault();
        var dragging = container.querySelector('.builder-order-row.dragging');
        if (!dragging || dragging === row) return;
        var rect = row.getBoundingClientRect();
        container.insertBefore(dragging, event.clientY < rect.top + rect.height / 2 ? row : row.nextSibling);
      });
      row.addEventListener('dragend', function () {
        row.classList.remove('dragging');
        updateOrderNumbers(container);
      });
    }

    container.appendChild(row);
  }

  function addPairRow(container, pair) {
    pair = pair || { left: '', right: '' };
    var row = el('div', 'builder-match-row');

    var left = document.createElement('input');
    left.value = pair.left || '';
    left.placeholder = 'Левый столбец';
    left.dataset.matchLeft = '1';
    left.disabled = !editable;

    var arrow = el('span', 'builder-match-arrow', '↔');

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
      remove.addEventListener('click', function () { row.remove(); });
      row.appendChild(remove);
    }
    container.appendChild(row);
  }

  function renderTypeFields(card, question, rawInteraction, fresh) {
    var interaction = canonicalType(rawInteraction);
    var target = card.querySelector('[data-builder-fields]');
    target.innerHTML = '';
    var settings = fresh ? {} : (question.settings || {});

    var note = el('div', 'builder-format-note');
    var info = typeInfo(interaction);
    note.innerHTML = '<b>' + info.label + '</b><span>' + info.short + '</span>';
    target.appendChild(note);

    if (interaction === 'single' || interaction === 'multiple') {
      var optionWrap = el('div', 'builder-options');
      optionWrap.dataset.builderOptions = '1';
      var sourceOptions = fresh ? [] : (Array.isArray(question.options) ? question.options : []);
      if (sourceOptions.length < 2) {
        sourceOptions = [
          { text: 'Вариант 1', is_correct: 1 },
          { text: 'Вариант 2', is_correct: 0 }
        ];
      }
      sourceOptions.forEach(function (option) {
        addOptionRow(optionWrap, question.id, interaction === 'multiple', option);
      });
      target.appendChild(label(
        interaction === 'multiple' ? 'Варианты ответа — отметьте все правильные' : 'Варианты ответа — отметьте правильный',
        optionWrap
      ));
      if (editable) {
        var addOption = button('＋ Добавить вариант');
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

    if (interaction === 'order') {
      var orderWrap = el('div', 'builder-order-list');
      orderWrap.dataset.builderOrdering = '1';

      var items = fresh ? {} : (settings.items || {});
      var correctOrder = fresh ? [] : (Array.isArray(settings.correct_order) ? settings.correct_order : Object.keys(items));
      var values = correctOrder.map(function (key) { return items[key] || ''; }).filter(Boolean);
      if (values.length < 2) values = ['Первый элемент', 'Второй элемент'];
      values.forEach(function (value) { addOrderRow(orderWrap, value); });

      target.appendChild(label(
        'Правильная последовательность',
        orderWrap,
        'Расположите элементы в правильном порядке. Ученику они будут перемешаны; он восстановит порядок перетаскиванием.'
      ));

      if (editable) {
        var addOrder = button('＋ Добавить элемент');
        addOrder.addEventListener('click', function () {
          addOrderRow(orderWrap, '');
          updateOrderNumbers(orderWrap);
        });
        target.appendChild(addOrder);
      }
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

      target.appendChild(label(
        'Правильные пары',
        matchWrap,
        'Слева — объект или событие, справа — правильное соответствие. Правый столбец у ученика перемешивается.'
      ));
      if (editable) {
        var addPair = button('＋ Добавить пару');
        addPair.addEventListener('click', function () { addPairRow(matchWrap); });
        target.appendChild(addPair);
      }
      return;
    }

    if (interaction === 'correction') {
      var original = document.createElement('textarea');
      original.rows = 4;
      original.dataset.builderOriginal = '1';
      original.placeholder = 'Например: «Вторая мировая война началась в 1941 году.»';
      original.value = fresh ? '' : (settings.original_text || '');
      original.disabled = !editable;

      var fixed = document.createElement('textarea');
      fixed.rows = 3;
      fixed.dataset.builderCorrect = '1';
      fixed.placeholder = 'Правильный вариант текста';
      fixed.value = fresh ? '' : (question.correct_text || '');
      fixed.disabled = !editable;

      target.appendChild(label('Текст с ошибкой', original));
      target.appendChild(label('Правильный ответ', fixed));
      return;
    }

    if (interaction === 'number') {
      var numberAnswer = document.createElement('input');
      numberAnswer.type = 'number';
      numberAnswer.step = 'any';
      numberAnswer.dataset.builderCorrect = '1';
      numberAnswer.placeholder = 'Например: 1939';
      numberAnswer.value = fresh ? '' : (question.correct_text || '');
      numberAnswer.disabled = !editable;
      target.appendChild(label('Правильный числовой ответ', numberAnswer));
      return;
    }

    if (interaction === 'essay') {
      target.appendChild(el('div', 'builder-info-box', 'Это вопрос старого формата. Он проверяется учителем вручную. Для новых вопросов выберите один из типов шаблона UROVIA.'));
      return;
    }

    var parts = fresh ? [] : String(question.correct_text || '').split('|').map(function (value) {
      return value.trim();
    }).filter(Boolean);

    var primary = document.createElement('input');
    primary.dataset.builderCorrect = '1';
    primary.placeholder = 'Основной правильный ответ';
    primary.value = parts.shift() || '';
    primary.disabled = !editable;

    var alternatives = document.createElement('textarea');
    alternatives.rows = 2;
    alternatives.dataset.builderAlternatives = '1';
    alternatives.placeholder = 'Допустимые варианты через |';
    alternatives.value = parts.join(' | ');
    alternatives.disabled = !editable;

    target.appendChild(label('Правильный ответ', primary));
    target.appendChild(label(
      'Допустимые варианты ответа',
      alternatives,
      'Например: Барбаросса | план Барбаросса. Регистр букв при проверке не учитывается.'
    ));
  }

  function renderAssets(card, question) {
    var block = el('div', 'builder-images-block');
    var head = el('div', 'builder-images-head');
    head.appendChild(el('b', '', 'Изображение к вопросу'));
    head.appendChild(el('span', '', 'Необязательно · можно использовать с любым типом ответа'));
    block.appendChild(head);

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
    var interaction = canonicalType(question.interaction_type || question.type || 'text');
    var info = typeInfo(interaction);

    var card = el('article', 'question-builder-card');
    card.dataset.builderQuestion = String(question.id);
    card.dataset.questionType = interaction;
    card.draggable = editable;

    var head = el('div', 'question-builder-card-head');

    var handle = button('⋮⋮', 'builder-drag-handle');
    handle.title = 'Перетащить всё задание';
    handle.disabled = !editable;
    head.appendChild(handle);

    var number = el('span', 'builder-question-number', 'ЗАДАНИЕ ' + (index + 1));
    head.appendChild(number);

    var title = el('div', 'builder-question-type-copy');
    title.appendChild(el('b', '', info.label));
    title.appendChild(el('span', '', info.short));
    head.appendChild(title);

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
        if (!confirm('Удалить это задание из работы?')) return;
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
    text.placeholder = 'Текст задания, который увидит ученик';
    text.disabled = !editable;
    card.appendChild(label('Текст задания для ученика', text));

    var fields = el('div', 'builder-type-fields');
    fields.dataset.builderFields = '1';
    card.appendChild(fields);
    renderTypeFields(card, question, interaction, false);

    card.appendChild(renderAssets(card, question));

    var state = el('div', 'builder-save-state');
    state.dataset.builderSaveState = '1';
    card.appendChild(state);

    select.addEventListener('change', function () {
      var newType = canonicalType(select.value);
      card.dataset.questionType = newType;
      var newInfo = typeInfo(newType);
      var copy = card.querySelector('.builder-question-type-copy');
      if (copy) {
        copy.innerHTML = '';
        copy.appendChild(el('b', '', newInfo.label));
        copy.appendChild(el('span', '', newInfo.short));
      }
      renderTypeFields(card, question, newType, true);
    });

    if (editable) {
      card.addEventListener('dragstart', function (event) {
        if (event.target && event.target.closest('.builder-order-row')) return;
        draggedCard = card;
        card.classList.add('dragging');
        event.dataTransfer.effectAllowed = 'move';
      });
      card.addEventListener('dragover', function (event) {
        if (!draggedCard || draggedCard === card) return;
        event.preventDefault();
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
    var interaction = canonicalType(card.querySelector('[data-builder-type]').value);
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
    } else if (interaction === 'order') {
      payload.ordering_items = Array.from(card.querySelectorAll('.builder-order-row [data-order-text]'))
        .map(function (input) { return input.value.trim(); })
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
    } else if (interaction === 'text') {
      payload.correct_text = card.querySelector('[data-builder-correct]').value;
      payload.alternatives = card.querySelector('[data-builder-alternatives]').value;
    } else if (interaction === 'number') {
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

    summary.appendChild(el('b', '', questions.length + ' заданий'));
    var counts = {};
    questions.forEach(function (question) {
      var kind = canonicalType(question.interaction_type || question.type);
      counts[kind] = (counts[kind] || 0) + 1;
    });
    Object.keys(counts).forEach(function (kind) {
      summary.appendChild(el('span', '', typeInfo(kind).label + ': ' + counts[kind]));
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
        ? 'Одна работа может содержать разные типы заданий. Перетаскивайте карточки, чтобы менять их порядок.'
        : 'Работа уже опубликована или по ней есть попытки. Конструктор открыт только для просмотра.';
    }

    renderSummary();
    list.innerHTML = '';

    if (!questions.length) {
      var empty = el('div', 'subject-empty-list');
      empty.appendChild(el('b', '', 'Заданий пока нет'));
      empty.appendChild(el('span', '', editable ? 'Нажмите «Добавить задание» и выберите его тип.' : 'В этой работе нет вопросов.'));
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
          ? 'Соберите одну работу из разных типов: тестов, соответствий, перетаскивания, коротких ответов и вопросов с изображениями.'
          : 'Вопросы можно просматривать, но редактирование этой работы уже заблокировано.';
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
    if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось сохранить порядок заданий.');

    questions.sort(function (a, b) {
      return ids.indexOf(Number(a.id)) - ids.indexOf(Number(b.id));
    });
    document.querySelectorAll('#questionPreviewList .builder-question-number').forEach(function (node, index) {
      node.textContent = 'ЗАДАНИЕ ' + (index + 1);
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

  function defaultPayload(type) {
    var base = {
      assignment_id: assignmentId,
      interaction_type: type,
      text: 'Введите текст задания',
      points: 1
    };

    if (type === 'single') {
      base.options = [
        { text: 'Вариант 1', is_correct: true },
        { text: 'Вариант 2', is_correct: false }
      ];
    } else if (type === 'multiple') {
      base.options = [
        { text: 'Вариант 1', is_correct: true },
        { text: 'Вариант 2', is_correct: false }
      ];
    } else if (type === 'true_false') {
      base.true_false_answer = 'true';
    } else if (type === 'order') {
      base.ordering_items = ['Первый элемент', 'Второй элемент'];
    } else if (type === 'matching') {
      base.matching_pairs = [
        { left: 'Элемент 1', right: 'Соответствие 1' },
        { left: 'Элемент 2', right: 'Соответствие 2' }
      ];
    } else if (type === 'correction') {
      base.original_text = 'Текст с ошибкой';
      base.correct_text = 'Правильный вариант';
    } else if (type === 'number') {
      base.correct_text = '0';
    } else {
      base.correct_text = 'Правильный ответ';
      base.alternatives = '';
    }

    return base;
  }

  async function createQuestion(type, picker) {
    type = canonicalType(type);
    var addButton = document.getElementById('builderAddQuestionBtn');
    if (addButton) addButton.disabled = true;

    try {
      var response = await fetch('./api/questions/create.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(defaultPayload(type))
      });
      var data = await response.json();
      if (!response.ok || data.ok === false) throw new Error(data.error || 'Не удалось добавить задание.');
      picker && picker.remove();
      await load(Number(data.question_id));
      if (typeof loadAssignments === 'function') await loadAssignments();
    } catch (error) {
      alert(error.message);
    } finally {
      if (addButton) addButton.disabled = false;
    }
  }

  function openTypePicker() {
    if (!editable || !assignmentId) return;

    document.querySelector('.builder-type-picker-overlay')?.remove();

    var overlay = el('div', 'builder-type-picker-overlay');
    var panel = el('div', 'builder-type-picker');
    var close = button('×', 'builder-type-picker-close');
    close.addEventListener('click', function () { overlay.remove(); });

    panel.appendChild(close);
    panel.appendChild(el('span', 'section-kicker', 'Новое задание'));
    panel.appendChild(el('h3', '', 'Выберите тип вопроса'));
    panel.appendChild(el('p', 'builder-type-picker-lead', 'В одной работе можно свободно сочетать разные типы заданий. Изображение добавляется отдельно к любому типу.'));

    var grid = el('div', 'builder-type-grid');
    types.forEach(function (item) {
      var choice = button('', 'builder-type-choice');
      choice.innerHTML =
        '<span class="builder-type-icon">' + item.icon + '</span>' +
        '<span><b>' + item.label + '</b><small>' + item.short + '</small></span>';
      choice.addEventListener('click', function () { createQuestion(item.value, overlay); });
      grid.appendChild(choice);
    });
    panel.appendChild(grid);
    overlay.appendChild(panel);

    var modal = document.querySelector('#questionPreviewModal .question-builder-modal');
    (modal || document.body).appendChild(overlay);
  }

  var addButton = document.getElementById('builderAddQuestionBtn');
  if (addButton) {
    addButton.textContent = '＋ Добавить задание';
    addButton.addEventListener('click', openTypePicker);
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