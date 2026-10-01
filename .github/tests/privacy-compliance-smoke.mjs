import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = file => readFileSync(file, 'utf8');
const policy = read('privacy-policy.html');
const consent = read('personal-data-consent.html');
const login = read('login.html');
const index = read('index.html');
const app = read('app.js');
const bootstrap = read('api/bootstrap.php');

assert(policy.includes('Политика обработки персональных данных'));
assert(policy.includes('265-ФЗ'));
assert(/трансгранич/i.test(policy));
assert(policy.includes('info@urovia.ru'));
assert(consent.includes('Согласие на обработку персональных данных'));
assert(consent.includes('отдельную отметку'));
assert(consent.includes('отзыва'));

assert(login.includes('name="privacy_consent"'));
assert(login.includes('personal-data-consent.html'));
assert(login.includes('privacy-policy.html'));

for (const marker of [
  'profileBasicForm',
  'profileAvatarForm',
  'schoolForm',
  'importStudentsForm',
  'studentEditForm',
  'teacherForm',
  'schoolAdminForm'
]) {
  const pos = index.indexOf('id="' + marker + '"');
  assert(pos >= 0, 'missing form ' + marker);
  const end = index.indexOf('</form>', pos);
  const form = index.slice(pos, end);
  assert(form.includes('privacy_basis_confirmed'), marker + ' must contain legal-basis confirmation');
}

assert(app.includes("data.append('privacy_basis_confirmed', '1')"));
assert(app.includes('privacy_basis_confirmed: true'));
assert(bootstrap.includes('function require_privacy_confirmation'));
assert(bootstrap.includes("return '2026-10-01'"));

const protectedEndpoints = [
  'api/setup/create-admin.php',
  'api/school/teachers/create.php',
  'api/school/admins/create.php',
  'api/school/admins/update.php',
  'api/schools/create.php',
  'api/classes/import-students.php',
  'api/classes/import-students-commit.php',
  'api/classes/update-student.php',
  'api/profile/update.php',
  'api/profile/upload-avatar.php'
];

for (const file of protectedEndpoints) {
  const source = read(file);
  assert(source.includes('require_privacy_confirmation'), file + ' must enforce confirmation server-side');
}

for (const html of [login, index]) {
  assert(!/<script\s+[^>]*src=["']https?:\/\//i.test(html), 'external scripts are not allowed on data collection pages');
}

console.log('Personal data forms, policy, consent pages and server enforcement OK');
