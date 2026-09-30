(() => {
  'use strict';

  sessionStorage.removeItem('uvoria-sw-reloading');

  let deferredInstallPrompt = null;
  let registration = null;

  const isStandalone = () =>
    window.matchMedia('(display-mode: standalone)').matches ||
    window.matchMedia('(display-mode: fullscreen)').matches ||
    window.matchMedia('(display-mode: minimal-ui)').matches ||
    window.navigator.standalone === true;

  const isIOS = () =>
    /iphone|ipad|ipod/i.test(navigator.userAgent) ||
    (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

  const isSafari = () =>
    /^((?!chrome|android|crios|fxios|edgios|yabrowser).)*safari/i.test(navigator.userAgent);

  const isYandex = () => /yabrowser/i.test(navigator.userAgent);
  const isAndroid = () => /android/i.test(navigator.userAgent);
  const isChromium = () =>
    /chrome|crios|chromium/i.test(navigator.userAgent) &&
    !/edg|edgios|opr|opera|firefox|fxios/i.test(navigator.userAgent);

  function showInstallGuide(kind) {
    const guide = document.getElementById('pwaIosModal');
    if (!guide) return;
    const title = document.getElementById('pwaIosTitle');
    const details = guide.querySelector('.pwa-ios-card > p');
    const steps = guide.querySelector('.pwa-ios-card > ol');
    if (kind === 'ios') {
      title.textContent = 'Установить UROVIA на iPhone или iPad';
      details.textContent = 'Откройте UROVIA в Safari, затем добавьте сайт на экран «Домой».';
      steps.innerHTML = '<li><span>1</span>Нажмите «Поделиться» в Safari.</li><li><span>2</span>Выберите «На экран Домой».</li><li><span>3</span>Подтвердите «Добавить».</li>';
    } else {
      title.textContent = 'Установить UROVIA';
      details.textContent = 'Браузер не предоставил системное окно установки. Приложение можно добавить через его меню.';
      steps.innerHTML = '<li><span>1</span>Откройте меню браузера (⋮ или ≡).</li><li><span>2</span>Выберите «Установить приложение» либо «Добавить на главный экран».</li><li><span>3</span>Подтвердите установку. Если пункта нет, откройте UROVIA в актуальном Chrome.</li>';
    }
    guide.classList.remove('hidden');
    document.getElementById('pwaIosClose')?.focus();
  }

  function ensureUi() {
    if (document.getElementById('pwaInstallButton')) return;

    const install = document.createElement('button');
    install.id = 'pwaInstallButton';
    install.type = 'button';
    install.className = 'pwa-install-button hidden';
    install.innerHTML = '<span class="pwa-install-icon">↓</span><span>Установить UROVIA</span>';
    install.setAttribute('aria-label', 'Установить UROVIA как приложение');

    const update = document.createElement('div');
    update.id = 'pwaUpdateToast';
    update.className = 'pwa-update-toast hidden';
    update.innerHTML = `
      <div>
        <b>Доступно обновление UROVIA</b>
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
        <h2 id="pwaIosTitle">Установить UROVIA на iPhone</h2>
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
      if (isStandalone()) return;
      if (deferredInstallPrompt) {
        const prompt = deferredInstallPrompt;
        deferredInstallPrompt = null;
        try {
          await prompt.prompt();
          await prompt.userChoice;
        } catch {
          showInstallGuide(isIOS() ? 'ios' : 'browser');
        } finally {
          refreshInstallButton();
        }
        return;
      }
      showInstallGuide(isIOS() ? 'ios' : 'browser');
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

    // YaBrowser and some Chromium versions never dispatch beforeinstallprompt:
    // show a truthful manual installation guide instead of a dead button.
    const canGuide = isIOS() || isYandex() || isAndroid() || isChromium();
    button.classList.toggle('hidden', !deferredInstallPrompt && !canGuide);
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
