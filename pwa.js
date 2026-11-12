(() => {
  'use strict';

  // Installation is controlled by the browser/OS, never by the website alone.
  const LEGACY_INSTALLED_KEY = 'urovia-pwa-installed-v1';
  const RELOAD_KEY = 'uvoria-sw-reloading';
  let deferredInstallPrompt = null;
  let registration = null;
  let assessmentActive = false;
  let updatePending = false;
  let reloadPending = false;
  let installedThisSession = false;
  let relatedPwaInstalled = false;

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
  const isFirefox = () => /firefox|fxios/i.test(navigator.userAgent);
  const isMac = () => /macintosh|mac os x/i.test(navigator.userAgent) && !isIOS();
  const isWindows = () => /windows/i.test(navigator.userAgent);

  function clearLegacyInstalledFlag() {
    try { window.localStorage.removeItem(LEGACY_INSTALLED_KEY); } catch {}
  }

  function markInstalled() {
    installedThisSession = true;
    relatedPwaInstalled = true;
    deferredInstallPrompt = null;
    clearLegacyInstalledFlag();
    document.getElementById('pwaInstallGuide')?.classList.add('hidden');
    refreshInstallButton();
  }

  async function detectInstalledPwa() {
    if (isStandalone()) {
      relatedPwaInstalled = true;
      refreshInstallButton();
      return true;
    }
    if (typeof navigator.getInstalledRelatedApps !== 'function') {
      return false;
    }
    try {
      const apps = await navigator.getInstalledRelatedApps();
      relatedPwaInstalled = Array.isArray(apps) && apps.some(app =>
        app?.platform === 'webapp'
        || String(app?.url || '').includes('manifest.webmanifest')
        || String(app?.id || '').includes('urovia.ru')
      );
      refreshInstallButton();
      return relatedPwaInstalled;
    } catch {
      return false;
    }
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

    if (isFirefox()) {
      return {
        title: 'UROVIA на компьютере',
        description: 'Firefox на компьютере не поддерживает системную установку PWA через сайт.',
        steps: [
          'Для отдельного приложения откройте urovia.ru в Chrome, Edge или другом Chromium-браузере.',
          'Нажмите «Установить UROVIA» и подтвердите системную установку.',
          'Если нужен только ярлык Firefox, используйте средства браузера или операционной системы для создания ярлыка сайта.'
        ],
        note: 'Создать системный ярлык на рабочем столе без подтверждения пользователя сайт не может.'
      };
    }

    return {
      title: 'UROVIA на компьютере',
      description: 'Установите UROVIA как отдельное приложение. Если браузер поддерживает системный диалог, кнопка откроет его автоматически.',
      steps: [
        isMac() && isSafari()
          ? 'В Safari откройте «Файл» → «Добавить в Dock».'
          : 'В Chrome / Edge / Яндекс Браузере используйте «Установить приложение» в меню или значок установки в адресной строке.',
        'Подтвердите установку UROVIA.',
        isWindows()
          ? 'После установки UROVIA появится среди приложений Windows. Если браузер предлагает создание ярлыка на рабочем столе — включите эту опцию; иначе ярлык можно создать из установленного приложения средствами Windows.'
          : 'После установки UROVIA появится среди приложений системы; при необходимости закрепите её в Dock/панели или создайте ярлык средствами ОС.'
      ],
      note: 'Браузер и операционная система сами решают, создавать ли ярлык на рабочем столе. Сайт не может сделать это без системного подтверждения.'
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

    // Never trust a persistent localStorage flag here. It can survive uninstall
    // and permanently hide the install action. The button is hidden only when
    // the current app is standalone or this browser reports/just confirmed install.
    const installed = standalone || installedThisSession || relatedPwaInstalled;
    button.classList.toggle('hidden', installed);
    button.disabled = installed;
    if (installed) document.getElementById('pwaInstallGuide')?.classList.add('hidden');
  }

  function ensureUi() {
    if (document.getElementById('pwaInstallButton')) return;

    const install = document.createElement('button');
    install.id = 'pwaInstallButton';
    install.type = 'button';
    install.className = 'pwa-install-button';
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
      if (isStandalone() || installedThisSession || relatedPwaInstalled) {
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
    // A fresh native prompt is authoritative evidence that this browser
    // currently considers the PWA installable (for example after uninstall).
    installedThisSession = false;
    relatedPwaInstalled = false;
    clearLegacyInstalledFlag();
    deferredInstallPrompt = event;
    refreshInstallButton();
  });
  window.addEventListener('appinstalled', markInstalled);
  window.addEventListener('pageshow', () => {
    refreshInstallButton();
    detectInstalledPwa().catch(() => {});
  });
  window.addEventListener('focus', () => {
    refreshInstallButton();
    detectInstalledPwa().catch(() => {});
  });
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) {
      refreshInstallButton();
      detectInstalledPwa().catch(() => {});
    }
  });
  modes.forEach(mode => {
    const query = window.matchMedia('(display-mode: ' + mode + ')');
    if (typeof query.addEventListener === 'function') query.addEventListener('change', refreshInstallButton);
    else if (typeof query.addListener === 'function') query.addListener(refreshInstallButton);
  });

  async function initializePwa() {
    // Remove obsolete v1 persistence so uninstall can never leave the button hidden.
    clearLegacyInstalledFlag();
    ensureUi();
    refreshInstallButton();
    await detectInstalledPwa();
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