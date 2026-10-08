import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = file => readFileSync(file, 'utf8');
const manifest = JSON.parse(read('manifest.webmanifest'));
const login = read('login.html');
const index = read('index.html');
const pwa = read('pwa.js');
const loginCss = read('login.css');
const appCss = read('styles.css');
const sw = read('sw.js');
const apache = read('.htaccess');

assert.equal(manifest.display, 'standalone');
assert.equal(manifest.display_override[0], 'standalone');
assert.equal(manifest.prefer_related_applications, false);
assert(manifest.icons.some(icon => icon.sizes === '192x192' && icon.type === 'image/png'));
assert(manifest.icons.some(icon => icon.sizes === '512x512' && icon.type === 'image/png'));
assert(manifest.icons.some(icon => icon.purpose === 'maskable'));
for (const html of [login, index]) {
  assert(/manifest\.webmanifest\?v=\d+/.test(html), 'manifest must be versioned on all entry points');
  assert(/pwa\.js\?v=\d+/.test(html), 'PWA handler must be versioned on all entry points');
  assert(/pwa\.css\?v=\d+/.test(html), 'PWA styles must be versioned on all entry points');
}
assert(/login\.css\?v=\d+/.test(login), 'login styles must be versioned');
assert(/styles\.css\?v=\d+/.test(index), 'app styles must be versioned');
assert(/const CACHE = 'uvoria-v\d+'/.test(sw), 'service worker cache must be versioned');
assert(loginCss.includes('Tablet auth layout and touch ergonomics v46'));
assert(appCss.includes('Tablet touch and scroll access v76'));
assert(pwa.includes('showInstallGuide'));
assert(pwa.includes('isYandex()'));
assert(pwa.includes('beforeinstallprompt'));
assert(apache.includes('application/manifest+json .webmanifest'));
assert(pwa.includes("window.addEventListener('appinstalled', markInstalled)"));
assert(pwa.includes('pwaInstallConfirmed'));
assert(pwa.includes('urovia-pwa-installed-v1'));
assert(pwa.includes('clearInstalledFlag()'));
assert(pwa.includes('Обновить UROVIA'), 'PWA update action must be clearly distinguished from assignment completion');
assert(pwa.includes('urovia:assessment-state'), 'PWA updates must listen to assessment state');
assert(pwa.includes('assessmentUiVisible'), 'PWA update toast must be deferred while a student assessment is visible');
console.log('Tablet layout and cross-browser PWA regression checks OK');
