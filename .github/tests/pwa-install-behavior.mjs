import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const script = readFileSync('pwa.js', 'utf8');
const LEGACY_KEY = 'urovia-pwa-installed-v1';

function harness({ standalone = false, ios = false, yandex = false, legacyInstalled = false, relatedInstalled = false } = {}) {
  const store = new Map(legacyInstalled ? [[LEGACY_KEY, '1']] : []);
  const nodes = new Map();
  const windowListeners = new Map();

  class FakeElement {
    constructor() {
      const classes = new Set();
      this.classList = {
        add: name => classes.add(name),
        remove: name => classes.delete(name),
        contains: name => classes.has(name),
        toggle(name, on) {
          if (on === undefined) on = !classes.has(name);
          if (on) classes.add(name);
          else classes.delete(name);
          return on;
        }
      };
      this.listeners = new Map();
      this.innerHTML = '';
      this.textContent = '';
      this.disabled = false;
    }
    setAttribute() {}
    addEventListener(type, callback) { this.listeners.set(type, callback); }
    append() {}
    replaceChildren() {}
    focus() {}
    async click() {
      const fn = this.listeners.get('click');
      if (fn) await fn({ target: this });
    }
  }

  const register = element => {
    if (element.id) nodes.set(element.id, element);
    for (const match of element.innerHTML.matchAll(/\bid="([^"]+)"/g)) {
      if (!nodes.has(match[1])) {
        const nested = new FakeElement();
        nested.id = match[1];
        nodes.set(nested.id, nested);
      }
    }
  };

  const body = { append(...elements) { elements.forEach(register); } };
  const document = {
    readyState: 'complete',
    body,
    hidden: false,
    documentElement: new FakeElement(),
    createElement() { return new FakeElement(); },
    createTextNode(value) { return String(value); },
    getElementById(id) { return nodes.get(id) || null; },
    addEventListener() {}
  };

  const window = {
    navigator: {
      userAgent: ios
        ? 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1'
        : (yandex
          ? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/140.0 Safari/537.36 YaBrowser/26.10.0.0'
          : 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/140.0 Safari/537.36'),
      platform: ios ? 'iPhone' : 'Win32',
      maxTouchPoints: ios ? 5 : 0,
      standalone: standalone && ios,
      async getInstalledRelatedApps() {
        return relatedInstalled
          ? [{ platform: 'webapp', url: 'https://urovia.ru/manifest.webmanifest', id: 'https://urovia.ru/' }]
          : [];
      }
    },
    matchMedia: query => ({
      matches: standalone && query === '(display-mode: standalone)',
      addEventListener() {}
    }),
    localStorage: {
      getItem: key => store.get(key) ?? null,
      setItem: (key, value) => store.set(key, String(value)),
      removeItem: key => store.delete(key)
    },
    addEventListener(type, callback) { windowListeners.set(type, callback); },
    setTimeout(callback) { callback(); return 1; }
  };

  const context = {
    window,
    navigator: window.navigator,
    document,
    sessionStorage: { getItem: () => null, setItem() {} },
    console
  };

  runInNewContext(script, context, { filename: 'pwa.js' });

  const node = id => {
    const value = nodes.get(id);
    assert(value, 'expected install UI element ' + id);
    return value;
  };
  const emit = (type, event = {}) => {
    const fn = windowListeners.get(type);
    assert(fn, 'expected ' + type + ' listener');
    return fn(event);
  };
  const hidden = () => node('pwaInstallButton').classList.contains('hidden');
  const settle = async () => {
    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();
  };

  return { node, emit, hidden, store, settle };
}

// Fresh ordinary browser: install action is immediately visible.
{
  const h = harness();
  assert.equal(h.hidden(), false);
  await h.settle();
  assert.equal(h.hidden(), false);
  h.emit('appinstalled');
  assert.equal(h.hidden(), true, 'appinstalled hides the button in the current browser session');
}

// Obsolete persisted "installed" flag must never hide the action after uninstall/reopen.
{
  const h = harness({ legacyInstalled: true });
  assert.equal(h.hidden(), false, 'legacy localStorage flag must not hide install button');
  assert.equal(h.store.has(LEGACY_KEY), false, 'legacy flag is cleared during initialization');
}

// Installed PWA launched from its icon: no duplicate install action.
assert.equal(harness({ standalone: true }).hidden(), true);

// Supported browsers can detect an installed related PWA.
{
  const h = harness({ relatedInstalled: true });
  assert.equal(h.hidden(), false, 'button starts visible before async installed-app detection');
  await h.settle();
  assert.equal(h.hidden(), true, 'installed related PWA hides duplicate install action');
}

// A fresh native prompt is authoritative evidence that installation is available now.
{
  const h = harness({ legacyInstalled: true });
  let prevented = false;
  h.emit('beforeinstallprompt', {
    preventDefault() { prevented = true; },
    prompt: async () => {},
    userChoice: Promise.resolve({ outcome: 'dismissed' })
  });
  assert.equal(prevented, true);
  assert.equal(h.hidden(), false);
}

// Accepted native install hides the button for the current browser session.
{
  const h = harness();
  h.emit('beforeinstallprompt', {
    preventDefault() {},
    prompt: async () => {},
    userChoice: Promise.resolve({ outcome: 'accepted' })
  });
  await h.node('pwaInstallButton').click();
  assert.equal(h.hidden(), true);
}

// Yandex Browser follows the same installed behavior: once installation is
// confirmed, the install action disappears and no desktop-shortcut helper is shown.
{
  const h = harness({ yandex: true });
  h.emit('beforeinstallprompt', {
    preventDefault() {},
    prompt: async () => {},
    userChoice: Promise.resolve({ outcome: 'accepted' })
  });
  await h.node('pwaInstallButton').click();
  assert.equal(h.hidden(), true, 'Yandex must hide install action after confirmed install');
  assert.doesNotMatch(h.node('pwaInstallButton').innerHTML, /Создать ярлык UROVIA/);
}

{
  const h = harness({ yandex: true, relatedInstalled: true });
  await h.settle();
  assert.equal(h.hidden(), true, 'already-installed Yandex web app must not show an extra shortcut button');
}

// On iOS the website gives share-sheet instructions; manual confirmation is session-only.
{
  const h = harness({ ios: true });
  await h.node('pwaInstallButton').click();
  assert.equal(h.node('pwaInstallGuide').classList.contains('hidden'), false);
  assert.match(h.node('pwaGuideDescription').textContent, /Safari/);
  await h.node('pwaInstallConfirmed').click();
  assert.equal(h.hidden(), true);

  const reopened = harness({ ios: true, legacyInstalled: true });
  assert.equal(reopened.hidden(), false, 'reopened normal browser must offer install again unless real install state is detectable');
}

console.log('PWA installation behavior OK: always-visible fallback, prompt, standalone, related-app detection and reinstall');
