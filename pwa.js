(() => {
  'use strict';

  let deferredInstallPrompt = null;
  let registration = null;

  const isStandalone = () =>
    window.matchMedia('(display-mode: standalone)').matches ||
    window.navigator.standalone === true;

  const isIOS = () =>
    /iphone|ipad|ipod/i.test(navigator.userAgent) ||
    (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

  const isSafari = () =>
    /^((?!chrome|android|crios|fxios|edgios).)*safari/i.test(navigator.userAgent);

  function ensureUi() {
    if (document.getElementById('pwaInstallButton')) return;

    const install = document.createElement('button');
    install.id = 'pwaInstallButton';
    install.type = 'button';
    install.className = 'pwa-install-button hidden';
    install.innerHTML = '<span class="pwa-install-icon">↓</span><span>Установить UVORIA</span>';
    install.setAttribute('aria-label', 'Установить UVORIA как приложение');

    const update = document.createElement('div');
    update.id = 'pwaUpdateToast';
    update.className = 'pwa-update-toast hidden';
    update.innerHTML = `
      <div>
        <b>Доступно обновление UVORIA</b>
        <span>Новая версия готова к установке.</span>
      </div>
      <button type="button" id="pwaUpdateButton">Обновить</button>`;

    const ios = document.createElement('div');
    ios.id = 'pwaIosModal';
    ios.className = 'pwa-ios-modal hidden';
    ios.innerHTML = `
      <div class="pwa-ios-card" role="dialog" aria-modal="true" aria-labelledby="pwaIosTitle">
        <button type="button" class="pwa-ios-close" id="pwaIosClose" aria-label="Закрыть">×</button>
        <div class="pwa-ios-app-icon">U</div>
        <h2 id="pwaIosTitle">Установить UVORIA на iPhone</h2>
        <p>В Safari нажмите кнопку <b>«Поделиться»</b>, затем выберите <b>«На экран Домой»</b> и подтвердите добавление.</p>
        <ol>
          <li><span>1</span> Откройте меню «Поделиться».</li>
          <li><span>2</span> Выберите «На экран Домой».</li>
          <li><span>3</span> Нажмите «Добавить».</li>
        </ol>
        <button type="button" class="pwa-ios-ok" id="pwaIosOk">Понятно</button>
      </div>`;

    document.body.append(install, update, ios);

    install.addEventListener('click', async () => {
      if (isIOS()) {
        ios.classList.remove('hidden');
        return;
      }

      if (!deferredInstallPrompt) return;
      deferredInstallPrompt.prompt();
      try {
        await deferredInstallPrompt.userChoice;
      } finally {
        deferredInstallPrompt = null;
        refreshInstallButton();
      }
    });

    const closeIos = () => ios.classList.add('hidden');
    document.getElementById('pwaIosClose')?.addEventListener('click', closeIos);
    document.getElementById('pwaIosOk')?.addEventListener('click', closeIos);
    ios.addEventListener('click', event => {
      if (event.target === ios) closeIos();
    });

    document.getElementById('pwaUpdateButton')?.addEventListener('click', () => {
      if (!registration?.waiting) return;
      registration.waiting.postMessage({ type: 'SKIP_WAITING' });
    });
  }

  function refreshInstallButton() {
    const button = document.getElementById('pwaInstallButton');
    if (!button) return;

    if (isStandalone()) {
      button.classList.add('hidden');
      document.documentElement.classList.add('pwa-standalone');
      return;
    }

    document.documentElement.classList.remove('pwa-standalone');

    if (deferredInstallPrompt || (isIOS() && isSafari())) {
      button.classList.remove('hidden');
    } else {
      button.classList.add('hidden');
    }
  }

  function showUpdateReady() {
    document.getElementById('pwaUpdateToast')?.classList.remove('hidden');
  }

  function watchRegistration(reg) {
    registration = reg;

    if (reg.waiting && navigator.serviceWorker.controller) {
      showUpdateReady();
    }

    reg.addEventListener('updatefound', () => {
      const installing = reg.installing;
      if (!installing) return;

      installing.addEventListener('statechange', () => {
        if (installing.state === 'installed' && navigator.serviceWorker.controller) {
          showUpdateReady();
        }
      });
    });
  }

  window.addEventListener('beforeinstallprompt', event => {
    event.preventDefault();
    deferredInstallPrompt = event;
    refreshInstallButton();
  });

  window.addEventListener('appinstalled', () => {
    deferredInstallPrompt = null;
    refreshInstallButton();
  });

  window.matchMedia('(display-mode: standalone)').addEventListener?.('change', refreshInstallButton);

  async function initializePwa() {
    ensureUi();
    refreshInstallButton();

    if (!('serviceWorker' in navigator)) return;

    try {
      const reg = await navigator.serviceWorker.register('./sw.js');
      watchRegistration(reg);

      navigator.serviceWorker.addEventListener('controllerchange', () => {
        if (sessionStorage.getItem('uvoria-sw-reloading') === '1') return;
        sessionStorage.setItem('uvoria-sw-reloading', '1');
        window.location.reload();
      });

      window.addEventListener('load', () => {
        reg.update().catch(() => {});
      });
    } catch {
      // PWA remains optional; web access must continue to work.
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializePwa, { once: true });
  } else {
    initializePwa();
  }
})();
