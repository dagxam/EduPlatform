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
  assert(html.includes('manifest.webmanifest?v=46'), 'manifest version must be consistent');
  assert(html.includes('pwa.js?v=46'), 'PWA handler must be updated on all entry points');
  assert(html.includes('pwa.css?v=46'), 'PWA styles must be updated on all entry points');
}
assert(login.includes('login.css?v=46'));
assert(index.includes('styles.css?v=76'));
assert(sw.includes("const CACHE = 'uvoria-v80'"));
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
console.log('Tablet layout and cross-browser PWA regression checks OK');
