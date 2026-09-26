<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/bootstrap.php';

$user = require_user(['admin']);
if (!is_platform_admin($user)) {
    json_response(['ok' => false, 'error' => 'Цвет школы может изменять только главный администратор UVORIA.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$schoolId = require_active_school($user, false);
$data = read_json_body();
$color = strtolower(trim((string)($data['theme_color'] ?? '#1d68f0')));

if (!preg_match('/^#[0-9a-f]{6}$/', $color)) {
    json_response(['ok' => false, 'error' => 'Цвет должен быть в формате #RRGGBB.'], 422);
}

$stmt = app_db()->prepare(
    'UPDATE schools
     SET theme_color = :theme_color,
         updated_at = CURRENT_TIMESTAMP
     WHERE id = :school_id'
);
$stmt->execute([
    'theme_color' => $color,
    'school_id' => $schoolId,
]);

audit_event('school_theme_updated', 'school', $schoolId, [
    'theme_color' => $color,
], $schoolId, (int)$user['id']);

json_response([
    'ok' => true,
    'school_id' => $schoolId,
    'branding' => school_branding($schoolId),
]);
