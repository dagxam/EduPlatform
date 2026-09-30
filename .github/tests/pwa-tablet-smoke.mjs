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
  assert(html.includes('manifest.webmanifest?v=45'), 'manifest version must be consistent');
  assert(html.includes('pwa.js?v=45'), 'PWA handler must be updated on all entry points');
  assert(html.includes('pwa.css?v=45'), 'PWA styles must be updated on all entry points');
}
assert(login.includes('login.css?v=46'));
assert(index.includes('styles.css?v=76'));
assert(sw.includes("const CACHE = 'uvoria-v79'"));
assert(loginCss.includes('Tablet auth layout and touch ergonomics v46'));
assert(appCss.includes('Tablet touch and scroll access v76'));
assert(pwa.includes('showInstallGuide'));
assert(pwa.includes('isYandex()'));
assert(pwa.includes('beforeinstallprompt'));
assert(apache.includes('application/manifest+json .webmanifest'));
console.log('Tablet layout and cross-browser PWA regression checks OK');
