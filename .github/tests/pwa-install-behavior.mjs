import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const script = readFileSync('pwa.js', 'utf8');
const KEY = 'urovia-pwa-installed-v1';

function harness({ standalone = false, ios = false, installed = false } = {}) {
  const store = new Map(installed ? [[KEY, '1']] : []);
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
      userAgent: ios ? 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1' : 'Mozilla/5.0 Chrome/130.0 Safari/537.36',
      platform: ios ? 'iPhone' : 'Win32',
      maxTouchPoints: ios ? 5 : 0,
      standalone: standalone && ios
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
    addEventListener(type, callback) { windowListeners.set(type, callback); }
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
  return { node, emit, hidden, store };
}

// Fresh ordinary browser: installation action remains available.
{
  const h = harness();
  assert.equal(h.hidden(), false);
  h.emit('appinstalled');
  assert.equal(h.hidden(), true, 'appinstalled hides the installation button');
  assert.equal(h.store.get(KEY), '1', 'install acknowledgment persists');
}

// Reopening browser after installation preserves hidden state.
assert.equal(harness({ installed: true }).hidden(), true);

// Installed PWA launched from its icon: no duplicate install prompt.
assert.equal(harness({ standalone: true }).hidden(), true);

// If uninstalled, a new native prompt can make installation available again.
{
  const h = harness({ installed: true });
  let prevented = false;
  h.emit('beforeinstallprompt', {
    preventDefault() { prevented = true; },
    prompt: async () => {},
    userChoice: Promise.resolve({ outcome: 'dismissed' })
  });
  assert.equal(prevented, true);
  assert.equal(h.hidden(), false);
  assert.equal(h.store.has(KEY), false);
}

// Accepted native install promptly hides the button even if no appinstalled event fires.
{
  const h = harness();
  h.emit('beforeinstallprompt', {
    preventDefault() {},
    prompt: async () => {},
    userChoice: Promise.resolve({ outcome: 'accepted' })
  });
  await h.node('pwaInstallButton').click();
  assert.equal(h.hidden(), true);
  assert.equal(h.store.get(KEY), '1');
}

// On iOS the website gives the share-sheet instructions; user confirms manually.
{
  const h = harness({ ios: true });
  await h.node('pwaInstallButton').click();
  assert.equal(h.node('pwaInstallGuide').classList.contains('hidden'), false);
  assert.match(h.node('pwaGuideDescription').textContent, /Safari/);
  await h.node('pwaInstallConfirmed').click();
  assert.equal(h.hidden(), true);
}

console.log('PWA installation behavior OK: prompt, iOS, standalone, persistence and reinstall');
