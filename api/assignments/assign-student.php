<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$assignmentId = (int)($data['assignment_id'] ?? 0);
$studentId = (int)($data['student_id'] ?? 0);
$timeLimitRaw = $data['time_limit_minutes'] ?? null;
$timeLimit = ($timeLimitRaw === null || $timeLimitRaw === '' || (int)$timeLimitRaw === 0)
    ? null
    : (int)$timeLimitRaw;
$allowed = [10,15,20,25,30,35,40,45,50,55,60];

if ($assignmentId < 1 || $studentId < 1) {
    json_response(['ok' => false, 'error' => 'Выберите задание и ученика.'], 422);
}
if ($timeLimit !== null && !in_array($timeLimit, $allowed, true)) {
    json_response(['ok' => false, 'error' => 'Выберите доступное время выполнения.'], 422);
}

$pdo = app_db();
$schoolId = require_active_school($user, false);

$stmt = $pdo->prepare(
    'SELECT id, subject_id, workflow_status
     FROM assignments
     WHERE id = :assignment_id AND school_id = :school_id
     LIMIT 1'
);
$stmt->execute(['assignment_id'=>$assignmentId,'school_id'=>$schoolId]);
$assignment = $stmt->fetch();
if (!$assignment) {
    json_response(['ok'=>false,'error'=>'Задание не найдено.'],404);
}
if (!in_array((string)$assignment['workflow_status'], ['ready','assigned'], true)) {
    json_response(['ok'=>false,'error'=>'Сначала переведите задание в статус «Готово».'],409);
}

$stmt = $pdo->prepare(
    'SELECT u.id, cs.class_id
     FROM users u
     JOIN class_students cs ON cs.student_id = u.id
     JOIN classes c ON c.id = cs.class_id
     WHERE u.id = :student_id
       AND u.role = "student"
       AND c.school_id = :school_id
     LIMIT 1'
);
$stmt->execute(['student_id'=>$studentId,'school_id'=>$schoolId]);
$student = $stmt->fetch();
if (!$student) {
    json_response(['ok'=>false,'error'=>'Ученик не найден в выбранной школе.'],404);
}

if (!can_manage_school($user, $schoolId)) {
    $stmt = $pdo->prepare(
        'SELECT 1 FROM teacher_classes
         WHERE school_id = :school_id
           AND teacher_id = :teacher_id
           AND class_id = :class_id
           AND subject_id = :subject_id
         LIMIT 1'
    );
    $stmt->execute([
        'school_id'=>$schoolId,
        'teacher_id'=>(int)$user['id'],
        'class_id'=>(int)$student['class_id'],
        'subject_id'=>(int)$assignment['subject_id'],
    ]);
    if (!$stmt->fetchColumn()) {
        json_response(['ok'=>false,'error'=>'Этот ученик недоступен для данного предмета.'],403);
    }
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'INSERT INTO assignment_students (assignment_id, student_id, time_limit_minutes)
         VALUES (:assignment_id, :student_id, :time_limit_minutes)'
        . db_upsert_clause($pdo, ['assignment_id','student_id'], ['time_limit_minutes'])
    );
    $stmt->execute([
        'assignment_id'=>$assignmentId,
        'student_id'=>$studentId,
        'time_limit_minutes'=>$timeLimit,
    ]);

    $stmt = $pdo->prepare(
        'UPDATE assignments
         SET status = "published",
             workflow_status = "assigned",
             completed_at = NULL,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :assignment_id'
    );
    $stmt->execute(['assignment_id'=>$assignmentId]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

audit_event('assignment_assigned_to_student','assignment',$assignmentId,[
    'student_id'=>$studentId,
    'time_limit_minutes'=>$timeLimit,
],$schoolId,(int)$user['id']);

json_response([
    'ok'=>true,
    'assignment_id'=>$assignmentId,
    'student_id'=>$studentId,
    'time_limit_minutes'=>$timeLimit,
    'workflow_status'=>'assigned',
]);
