<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin']);
if (!is_platform_admin($user)) {
    json_response(['ok' => false, 'error' => 'Тест почты доступен только главному администратору UROVIA.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$to = normalize_email((string)($data['email'] ?? $user['email'] ?? ''));

if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    json_response(['ok' => false, 'error' => 'Укажите корректный email для теста.'], 422);
}

$from = uvoria_mail_from();
$subject = 'Проверка почты UROVIA';
$body = "Это тестовое письмо UROVIA.\n\n"
    . "Отправитель: " . $from . "\n"
    . "Сайт: " . uvoria_app_url() . "\n"
    . "Время: " . date(DATE_ATOM) . "\n\n"
    . "Если вы получили это письмо, механизм отправки UROVIA работает.\n";

$sent = send_uvoria_email($to, $subject, $body);

audit_event('mail_test_sent', 'mail', null, [
    'recipient' => $to,
    'from' => $from,
    'accepted_by_php_mail' => $sent,
], current_school_id(), (int)$user['id']);

if (!$sent) {
    json_response([
        'ok' => false,
        'error' => 'PHP mail() не принял письмо. Для info@urovia.ru потребуется настроить SMTP или почтовую службу хостинга.',
        'from' => $from,
    ], 502);
}

json_response([
    'ok' => true,
    'message' => 'Почтовый сервер принял тестовое письмо на отправку.',
    'from' => $from,
    'to' => $to,
]);
