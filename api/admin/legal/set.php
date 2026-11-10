<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

$user = require_user(['admin']);
if (!is_platform_admin($user)) {
    json_response(['ok' => false, 'error' => 'Доступ только главному администратору UROVIA.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$d = read_json_body();
$type = trim((string)($d['entity_type'] ?? 'self_employed'));
if (!in_array($type, ['self_employed', 'ip', 'company'], true)) {
    json_response(['ok' => false, 'error' => 'Некорректный тип владельца.'], 422);
}

$legalName = trim((string)($d['legal_name'] ?? ''));
$inn = preg_replace('/\D+/', '', (string)($d['inn'] ?? '')) ?? '';
$ogrn = preg_replace('/\D+/', '', (string)($d['ogrn'] ?? '')) ?? '';
$legalAddress = trim((string)($d['legal_address'] ?? ''));
$postalAddress = trim((string)($d['postal_address'] ?? ''));
$phone = trim((string)($d['phone'] ?? ''));
$email = trim((string)($d['email'] ?? ''));
$support = trim((string)($d['support_email'] ?? ''));
$taxNote = trim((string)($d['tax_note'] ?? ''));

if ($legalName === '') {
    json_response(['ok' => false, 'error' => 'Укажите Ф.И.О. самозанятого или полное наименование владельца.'], 422);
}

$expectedInnLength = $type === 'company' ? 10 : 12;
if (strlen($inn) !== $expectedInnLength) {
    json_response([
        'ok' => false,
        'error' => $type === 'company'
            ? 'Для юридического лица ИНН должен содержать 10 цифр.'
            : 'Для самозанятого или ИП ИНН должен содержать 12 цифр.',
    ], 422);
}

if ($type === 'ip' && strlen($ogrn) !== 15) {
    json_response(['ok' => false, 'error' => 'Для ИП укажите ОГРНИП из 15 цифр.'], 422);
}
if ($type === 'company' && strlen($ogrn) !== 13) {
    json_response(['ok' => false, 'error' => 'Для юридического лица укажите ОГРН из 13 цифр.'], 422);
}

if ($phone === '' || mb_strlen(preg_replace('/\D+/', '', $phone) ?? '') < 10) {
    json_response(['ok' => false, 'error' => 'Укажите действующий контактный телефон.'], 422);
}
if ($postalAddress === '') {
    json_response(['ok' => false, 'error' => 'Укажите почтовый адрес для публичной страницы реквизитов.'], 422);
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['ok' => false, 'error' => 'Укажите корректный контактный email.'], 422);
}
if ($support !== '' && !filter_var($support, FILTER_VALIDATE_EMAIL)) {
    json_response(['ok' => false, 'error' => 'Проверьте email поддержки.'], 422);
}

$pdo = app_db();
$stmt = $pdo->prepare(
    'INSERT INTO site_legal_profile
     (id, entity_type, legal_name, inn, ogrn, legal_address, postal_address, phone, email, support_email, tax_note, updated_by, updated_at)
     VALUES
     (1, :entity_type, :legal_name, :inn, :ogrn, :legal_address, :postal_address, :phone, :email, :support_email, :tax_note, :updated_by, CURRENT_TIMESTAMP)'
    . db_upsert_clause(
        $pdo,
        ['id'],
        ['entity_type', 'legal_name', 'inn', 'ogrn', 'legal_address', 'postal_address', 'phone', 'email', 'support_email', 'tax_note', 'updated_by', 'updated_at']
    )
);
$stmt->execute([
    'entity_type' => $type,
    'legal_name' => $legalName,
    'inn' => $inn,
    'ogrn' => $ogrn !== '' ? $ogrn : null,
    'legal_address' => $legalAddress !== '' ? $legalAddress : null,
    'postal_address' => $postalAddress,
    'phone' => $phone,
    'email' => $email,
    'support_email' => $support !== '' ? $support : $email,
    'tax_note' => $taxNote !== '' ? $taxNote : null,
    'updated_by' => (int)$user['id'],
]);

audit_event('site_legal_profile_updated', 'site', 1, [
    'entity_type' => $type,
    'yookassa_public_profile_complete' => true,
], null, (int)$user['id']);

json_response([
    'ok' => true,
    'profile' => site_legal_profile($pdo),
    'message' => 'Реквизиты сохранены. Публичная страница готова для проверки ЮKassa.',
]);
