<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin']);
$schoolId = require_active_school($user, true);
$pdo = app_db();

$stmt = $pdo->prepare(
    'SELECT assignment_review_required, grade_5_min, grade_4_min, grade_3_min
     FROM schools
     WHERE id = :id
     LIMIT 1'
);
$stmt->execute(['id' => $schoolId]);
$row = $stmt->fetch();
if (!$row) {
    json_response(['ok' => false, 'error' => 'Школа не найдена.'], 404);
}

$currentScale = normalize_grade_scale($row);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response([
        'ok' => true,
        'settings' => [
            'assignment_review_required' => (int)$row['assignment_review_required'] === 1,
            'grade_5_min' => (int)$currentScale['grade_5_min'],
            'grade_4_min' => (int)$currentScale['grade_4_min'],
            'grade_3_min' => (int)$currentScale['grade_3_min'],
        ],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();

$required = array_key_exists('assignment_review_required', $data)
    ? (!empty($data['assignment_review_required']) ? 1 : 0)
    : ((int)$row['assignment_review_required'] === 1 ? 1 : 0);

$grade5 = array_key_exists('grade_5_min', $data)
    ? (int)$data['grade_5_min']
    : (int)$currentScale['grade_5_min'];
$grade4 = array_key_exists('grade_4_min', $data)
    ? (int)$data['grade_4_min']
    : (int)$currentScale['grade_4_min'];
$grade3 = array_key_exists('grade_3_min', $data)
    ? (int)$data['grade_3_min']
    : (int)$currentScale['grade_3_min'];

if (
    $grade3 < 1 || $grade3 > 98
    || $grade4 <= $grade3 || $grade4 > 99
    || $grade5 <= $grade4 || $grade5 > 100
) {
    json_response([
        'ok' => false,
        'error' => 'Проверьте шкалу: порог для 3 должен быть ниже 4, а порог для 4 — ниже 5. Допустимый диапазон 1–100%.',
        'code' => 'INVALID_GRADE_SCALE',
    ], 422);
}

$stmt = $pdo->prepare(
    'UPDATE schools
     SET assignment_review_required = :required,
         grade_5_min = :grade_5_min,
         grade_4_min = :grade_4_min,
         grade_3_min = :grade_3_min,
         updated_at = CURRENT_TIMESTAMP
     WHERE id = :id'
);
$stmt->execute([
    'required' => $required,
    'grade_5_min' => $grade5,
    'grade_4_min' => $grade4,
    'grade_3_min' => $grade3,
    'id' => $schoolId,
]);

audit_event('school_settings_changed', 'school', $schoolId, [
    'assignment_review_required' => (bool)$required,
    'grade_scale' => [
        'grade_5_min' => $grade5,
        'grade_4_min' => $grade4,
        'grade_3_min' => $grade3,
    ],
], $schoolId, (int)$user['id']);

json_response([
    'ok' => true,
    'settings' => [
        'assignment_review_required' => (bool)$required,
        'grade_5_min' => $grade5,
        'grade_4_min' => $grade4,
        'grade_3_min' => $grade3,
    ],
]);
