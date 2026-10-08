(() => {
  'use strict';

  // Installation is controlled by the browser/OS, never by the website alone.
  const INSTALLED_KEY = 'urovia-pwa-installed-v1';
  const RELOAD_KEY = 'uvoria-sw-reloading';
  let deferredInstallPrompt = null;
  let registration = null;
  let assessmentActive = false;
  let updatePending = false;
  let reloadPending = false;

  const modes = ['standalone'];
  const isStandalone = () =>
    modes.some(mode => window.matchMedia('(display-mode: ' + mode + ')').matches) ||
    window.navigator.standalone === true;

  const isIOS = () =>
    /iphone|ipad|ipod/i.test(navigator.userAgent) ||
    (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

  const isSafari = () =>
    /safari/i.test(navigator.userAgent) &&
    !/chrome|crios|fxios|edgios|yabrowser|opr|opera|android/i.test(navigator.userAgent);

  const isAndroid = () => /android/i.test(navigator.userAgent);
  const isYandex = () => /yabrowser/i.test(navigator.userAgent);
  const isMac = () => /macintosh|mac os x/i.test(navigator.userAgent) && !isIOS();

  function installedFlag() {
    try { return window.localStorage.getItem(INSTALLED_KEY) === '1'; }
    catch { return false; }
  }

  function markInstalled() {
    try { window.localStorage.setItem(INSTALLED_KEY, '1'); } catch {}
    deferredInstallPrompt = null;
    document.getElementById('pwaInstallGuide')?.classList.add('hidden');
    refreshInstallButton();
  }

  function clearInstalledFlag() {
    try { window.localStorage.removeItem(INSTALLED_KEY); } catch {}
  }

  function guideCopy() {
    if (isIOS()) {
      return {
        title: 'UROVIA на iPhone и iPad',
        description: isSafari()
          ? 'iPhone и iPad требуют вашего подтверждения установки через системное меню Safari.'
          : 'Попробуйте меню «Поделиться» в текущем браузере. Если пункта «На экран Домой» нет, откройте urovia.ru в Safari.',
        steps: [
          'Откройте меню «Поделиться» (значок со стрелкой вверх).',
          'Выберите «На экран Домой» или «Добавить на экран Домой».',
          'Подтвердите «Добавить». На экране Домой появится иконка UROVIA.'
        ],
        note: 'Автоматически установить приложение без вашего действия iOS не разрешает.'
      };
    }
    if (isAndroid()) {
      return {
        title: 'UROVIA на Android',
        description: isYandex()
          ? 'Яндекс Браузер может не показывать системное окно установки. Попробуйте его меню.'
          : 'Если системное окно не открылось, установите UROVIA через меню браузера.',
        steps: [
          'Откройте меню браузера (⋮ или ≡).',
          'Найдите «Установить приложение» или «Добавить на главный экран».',
          'Подтвердите установку. Значок появится на главном экране или в списке приложений.'
        ],
        note: 'Если браузер умеет создавать только обычный ярлык, для установки в отдельном окне используйте актуальный Chrome.'
      };
    }

    return {
      title: 'UROVIA на компьютере',
      description: 'Установите платформу как приложение из меню браузера, если системное окно установки недоступно.',
      steps: [
        isMac() && isSafari()
          ? 'В Safari откройте меню «Файл» → «Добавить в Dock» (если доступно).'
          : 'В Chrome / Edge / Яндекс Браузере откройте меню (⋮ / ≡) или значок установки в адресной строке.',
        'Выберите «Установить приложение», «Установить эту страницу как приложение» либо аналогичный пункт.',
        'Подтвердите установку. Если ярлык не появился на рабочем столе, найдите UROVIA в установленных приложениях браузера / системы и создайте ярлык через ОС.'
      ],
      note: 'Сайт не может сам создать ярлык на рабочем столе: его размещением управляют браузер и операционная система.'
    };
  }

  function showInstallGuide() {
    const guide = document.getElementById('pwaInstallGuide');
    if (!guide) return;
    const copy = guideCopy();
    const title = document.getElementById('pwaGuideTitle');
    const description = document.getElementById('pwaGuideDescription');
    const steps = document.getElementById('pwaGuideSteps');
    const note = document.getElementById('pwaGuideNote');
    title.textContent = copy.title;
    description.textContent = copy.description;
    steps.replaceChildren();
    copy.steps.forEach((line, index) => {
      const item = document.createElement('li');
      const marker = document.createElement('span');
      marker.textContent = String(index + 1);
      item.append(marker, document.createTextNode(line));
      steps.append(item);
    });
    note.textContent = copy.note;
    guide.classList.remove('hidden');
    document.getElementById('pwaGuideClose')?.focus();
  }

  function refreshInstallButton() {
    const button = document.getElementById('pwaInstallButton');
    if (!button) return;
    const standalone = isStandalone();
    document.documentElement.classList.toggle('pwa-standalone', standalone);
    if (standalone) {
      // Some installed applications have separate local browser storage.
      try { window.localStorage.setItem(INSTALLED_KEY, '1'); } catch {}
    }
    const installed = standalone || installedFlag();
    button.classList.toggle('hidden', installed);
    button.disabled = installed;
    if (installed) document.getElementById('pwaInstallGuide')?.classList.add('hidden');
  }

  function ensureUi() {
    if (document.getElementById('pwaInstallButton')) return;

    const install = document.createElement('button');
    install.id = 'pwaInstallButton';
    install.type = 'button';
    install.className = 'pwa-install-button hidden';
    install.innerHTML = '<span class="pwa-install-icon" aria-hidden="true">↓</span><span>Установить UROVIA</span>';
    install.setAttribute('aria-label', 'Установить UROVIA как приложение');

    const update = document.createElement('div');
    update.id = 'pwaUpdateToast';
    update.className = 'pwa-update-toast hidden';
    update.innerHTML = '<div><b>Доступно обновление UROVIA</b><span>Новая версия готова к установке.</span></div>' +
      '<button type="button" id="pwaUpdateButton">Обновить UROVIA</button>';

    const guide = document.createElement('div');
    guide.id = 'pwaInstallGuide';
    guide.className = 'pwa-ios-modal hidden';
    guide.innerHTML = '<div class="pwa-ios-card" role="dialog" aria-modal="true" aria-labelledby="pwaGuideTitle">' +
      '<button type="button" class="pwa-ios-close" id="pwaGuideClose" aria-label="Закрыть">×</button>' +
      '<img class="pwa-guide-icon" src="./icons/icon-192.png" width="64" height="64" alt="Иконка UROVIA">' +
      '<h2 id="pwaGuideTitle">Установить UROVIA</h2>' +
      '<p id="pwaGuideDescription"></p><ol id="pwaGuideSteps"></ol>' +
      '<p class="pwa-guide-note" id="pwaGuideNote"></p>' +
      '<div class="pwa-guide-actions">' +
      '<button class="pwa-guide-confirm" type="button" id="pwaInstallConfirmed">Уже установлено ✓</button>' +
      '<button class="pwa-ios-ok" type="button" id="pwaGuideDone">Закрыть</button>' +
      '</div></div>';

    document.body.append(install, update, guide);

    install.addEventListener('click', async () => {
      if (isStandalone() || installedFlag()) {
        refreshInstallButton();
        return;
      }
      if (deferredInstallPrompt) {
        // prompt() must be initiated from a direct user interaction.
        const prompt = deferredInstallPrompt;
        deferredInstallPrompt = null;
        try {
          await prompt.prompt();
          const choice = await prompt.userChoice;
          if (choice?.outcome === 'accepted') {
            // Fallback for browsers that do not dispatch appinstalled.
            markInstalled();
          } else {
            refreshInstallButton();
          }
        } catch {
          showInstallGuide();
        }
        return;
      }
      showInstallGuide();
    });

    const closeGuide = () => guide.classList.add('hidden');
    document.getElementById('pwaGuideClose')?.addEventListener('click', closeGuide);
    document.getElementById('pwaGuideDone')?.addEventListener('click', closeGuide);
    document.getElementById('pwaInstallConfirmed')?.addEventListener('click', markInstalled);
    guide.addEventListener('click', event => {
      if (event.target === guide) closeGuide();
    });
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape' && !guide.classList.contains('hidden')) closeGuide();
    });
    document.getElementById('pwaUpdateButton')?.addEventListener('click', () => {
      if (assessmentActive || assessmentUiVisible()) {
        updatePending = true;
        hideUpdateReady();
        return;
      }
      if (!registration?.waiting) return;
      registration.waiting.postMessage({ type: 'SKIP_WAITING' });
    });
  }

  function assessmentUiVisible() {
    const quizModal = document.getElementById('quizModal');
    if (!quizModal || quizModal.classList.contains('hidden')) return false;
    return Boolean(
      quizModal.querySelector('#realQuizForm')
      || quizModal.querySelector('.student-finish-result')
    );
  }

  function hideUpdateReady() {
    document.getElementById('pwaUpdateToast')?.classList.add('hidden');
  }

  function showUpdateReady() {
    if (assessmentActive || assessmentUiVisible()) {
      updatePending = true;
      hideUpdateReady();
      return;
    }
    updatePending = false;
    document.getElementById('pwaUpdateToast')?.classList.remove('hidden');
  }

  window.addEventListener('urovia:assessment-state', event => {
    assessmentActive = Boolean(event.detail?.active);
    if (assessmentActive) {
      hideUpdateReady();
      return;
    }

    if (reloadPending) {
      reloadPending = false;
      if (sessionStorage.getItem(RELOAD_KEY) !== '1') {
        sessionStorage.setItem(RELOAD_KEY, '1');
        window.location.reload();
      }
      return;
    }

    if (updatePending && registration?.waiting && navigator.serviceWorker.controller) {
      window.setTimeout(showUpdateReady, 700);
    }
  });

  function watchRegistration(reg) {
    registration = reg;
    if (reg.waiting && navigator.serviceWorker.controller) showUpdateReady();
    reg.addEventListener('updatefound', () => {
      const installing = reg.installing;
      if (!installing) return;
      installing.addEventListener('statechange', () => {
        if (installing.state === 'installed' && navigator.serviceWorker.controller) showUpdateReady();
      });
    });
  }

  window.addEventListener('beforeinstallprompt', event => {
    event.preventDefault();
    if (isStandalone()) return;
    // A fresh native prompt means installation is available again (e.g. after uninstall).
    clearInstalledFlag();
    deferredInstallPrompt = event;
    refreshInstallButton();
  });
  window.addEventListener('appinstalled', markInstalled);
  window.addEventListener('pageshow', refreshInstallButton);
  window.addEventListener('focus', refreshInstallButton);
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) refreshInstallButton();
  });
  window.addEventListener('storage', event => {
    if (event.key === INSTALLED_KEY) refreshInstallButton();
  });
  modes.forEach(mode => {
    const query = window.matchMedia('(display-mode: ' + mode + ')');
    if (typeof query.addEventListener === 'function') query.addEventListener('change', refreshInstallButton);
    else if (typeof query.addListener === 'function') query.addListener(refreshInstallButton);
  });

  async function initializePwa() {
    ensureUi();
    refreshInstallButton();
    if (!('serviceWorker' in navigator)) return;
    try {
      const reg = await navigator.serviceWorker.register('./sw.js');
      watchRegistration(reg);
      navigator.serviceWorker.addEventListener('controllerchange', () => {
        if (sessionStorage.getItem(RELOAD_KEY) === '1') return;
        if (assessmentActive || assessmentUiVisible()) {
          reloadPending = true;
          hideUpdateReady();
          return;
        }
        sessionStorage.setItem(RELOAD_KEY, '1');
        window.location.reload();
      });
      window.addEventListener('load', () => reg.update().catch(() => {}));
    } catch {
      // Web access remains available if the browser disables service workers.
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializePwa, { once: true });
  } else {
    initializePwa();
  }
})();