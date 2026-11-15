(() => {
  'use strict';

  // Installation is controlled by the browser/OS, never by the website alone.
  const LEGACY_INSTALLED_KEY = 'urovia-pwa-installed-v1';
  const INSTALLED_HINT_KEY = 'urovia-pwa-installed-v2';
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

  function readInstalledHint() {
    try { return window.localStorage.getItem(INSTALLED_HINT_KEY) === '1'; } catch { return false; }
  }

  function saveInstalledHint() {
    try { window.localStorage.setItem(INSTALLED_HINT_KEY, '1'); } catch {}
  }

  function clearInstalledHint() {
    try { window.localStorage.removeItem(INSTALLED_HINT_KEY); } catch {}
  }

  function markInstalled({ persist = true } = {}) {
    installedThisSession = true;
    relatedPwaInstalled = true;
    deferredInstallPrompt = null;
    clearLegacyInstalledFlag();
    if (persist) saveInstalledHint();
    document.getElementById('pwaInstallGuide')?.classList.add('hidden');
    refreshInstallButton();
  }

  async function detectInstalledPwa() {
    if (isStandalone()) {
      relatedPwaInstalled = true;
      saveInstalledHint();
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
      if (relatedPwaInstalled) saveInstalledHint();
      refreshInstallButton();
      return relatedPwaInstalled;
    } catch {
      return false;
    }
  }

  function installButtonCopy() {
    if (isIOS()) {
      return {
        label: 'Установить UROVIA',
        hint: 'iPhone / iPad'
      };
    }
    if (isAndroid()) {
      return {
        label: 'Установить UROVIA',
        hint: 'Android · как приложение'
      };
    }
    return {
      label: 'Установить UROVIA',
      hint: 'Приложение'
    };
  }

  function guideCopy() {
    if (isIOS()) {
      return {
        title: 'UROVIA на iPhone и iPad',
        description: isSafari()
          ? 'На iPhone и iPad установка выполняется через системное меню Safari.'
          : 'Для установки откройте urovia.ru в Safari: другие браузеры iOS могут не показывать пункт добавления на экран Домой.',
        steps: [
          'Нажмите «Поделиться» — значок квадрата со стрелкой вверх.',
          'Прокрутите меню и выберите «На экран Домой» или «Добавить на экран Домой».',
          'Нажмите «Добавить». На экране Домой появится UROVIA как отдельное приложение.',
          'Вернитесь на эту страницу и нажмите «Готово, установлено ✓».'
        ],
        note: 'iOS не разрешает сайту установить приложение автоматически. После подтверждения ниже кнопка «Установить UROVIA» исчезнет.'
      };
    }
    if (isAndroid()) {
      return {
        title: 'UROVIA на Android',
        description: isYandex()
          ? 'Если системное окно не появилось, установите UROVIA через меню Яндекс Браузера.'
          : 'UROVIA устанавливается прямо из браузера как отдельное приложение. Отдельный APK-файл скачивать не нужно.',
        steps: [
          'Если появилось системное окно, нажмите «Установить».',
          'Если окна нет, откройте меню браузера (⋮ или ≡).',
          'Выберите «Установить приложение» или «Добавить на главный экран» и подтвердите.',
          'После установки UROVIA появится среди приложений Android и кнопка установки исчезнет.'
        ],
        note: 'Лучше использовать актуальный Chrome или другой Chromium-браузер, который поддерживает установку PWA.'
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
    const confirm = document.getElementById('pwaInstallConfirmed');
    if (confirm) {
      confirm.textContent = isIOS() ? 'Готово, установлено ✓' : 'Уже установлено ✓';
    }
    guide.classList.remove('hidden');
    document.getElementById('pwaGuideClose')?.focus();
  }

  function refreshInstallButton() {
    const button = document.getElementById('pwaInstallButton');
    if (!button) return;
    const standalone = isStandalone();
    const installed = standalone || installedThisSession || relatedPwaInstalled;

    document.documentElement.classList.toggle('pwa-standalone', standalone);
    const copy = installButtonCopy();
    button.classList.toggle('hidden', installed);
    button.disabled = installed;
    button.classList.remove('pwa-shortcut-helper', 'pwa-installing');
    button.classList.toggle('pwa-install-android', isAndroid());
    button.classList.toggle('pwa-install-ios', isIOS());
    button.innerHTML =
      '<span class="pwa-install-icon" aria-hidden="true">↓</span>' +
      '<span class="pwa-install-copy"><span>' + copy.label + '</span><small>' + copy.hint + '</small></span>';
    button.setAttribute('aria-label', copy.label + ' как приложение');

    if (installed) {
      document.getElementById('pwaInstallGuide')?.classList.add('hidden');
    }
  }

  function ensureUi() {
    if (document.getElementById('pwaInstallButton')) return;

    const install = document.createElement('button');
    install.id = 'pwaInstallButton';
    install.type = 'button';
    install.className = 'pwa-install-button';
    install.innerHTML = '<span class="pwa-install-icon" aria-hidden="true">↓</span><span class="pwa-install-copy"><span>Установить UROVIA</span><small>Приложение</small></span>';
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
      if (isStandalone()) {
        refreshInstallButton();
        return;
      }
      if (installedThisSession || relatedPwaInstalled) {
        refreshInstallButton();
        return;
      }
      if (isIOS()) {
        showInstallGuide();
        return;
      }
      if (deferredInstallPrompt) {
        // prompt() must be initiated from a direct user interaction.
        const prompt = deferredInstallPrompt;
        deferredInstallPrompt = null;
        try {
          install.disabled = true;
          install.classList.add('pwa-installing');
          install.innerHTML =
            '<span class="pwa-install-icon" aria-hidden="true">…</span>' +
            '<span class="pwa-install-copy"><span>Открываем установку…</span><small>Подтвердите в системном окне</small></span>';
          await prompt.prompt();
          const choice = await prompt.userChoice;
          if (choice?.outcome === 'accepted') {
            // Persist the confirmed install so ordinary browser tabs do not
            // show the install button again when installed-app detection is unavailable.
            markInstalled({ persist: true });
          } else {
            refreshInstallButton();
          }
        } catch {
          refreshInstallButton();
          showInstallGuide();
        }
        return;
      }
      showInstallGuide();
    });

    const closeGuide = () => guide.classList.add('hidden');
    document.getElementById('pwaGuideClose')?.addEventListener('click', closeGuide);
    document.getElementById('pwaGuideDone')?.addEventListener('click', closeGuide);
    document.getElementById('pwaInstallConfirmed')?.addEventListener('click', () => markInstalled({ persist: true }));
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
    clearInstalledHint();
    deferredInstallPrompt = event;
    refreshInstallButton();
  });
  window.addEventListener('appinstalled', () => markInstalled({ persist: true }));
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
    // Remove obsolete v1 persistence. The v2 hint is cleared automatically
    // when the browser exposes a fresh beforeinstallprompt after uninstall.
    clearLegacyInstalledFlag();
    installedThisSession = readInstalledHint();
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