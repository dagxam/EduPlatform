<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin','teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok'=>false,'error'=>'Метод не поддерживается.'],405);
}
$data = read_json_body();
$assignmentId = (int)($data['assignment_id'] ?? 0);
$classId = (int)($data['class_id'] ?? 0);
if ($assignmentId < 1 || $classId < 1) {
    json_response(['ok'=>false,'error'=>'Не указано назначение.'],422);
}

$pdo = app_db();
$schoolId = require_active_school($user,false);

$stmt = $pdo->prepare(
    'SELECT id, subject_id FROM assignments
     WHERE id = :assignment_id AND school_id = :school_id LIMIT 1'
);
$stmt->execute(['assignment_id'=>$assignmentId,'school_id'=>$schoolId]);
$assignment = $stmt->fetch();
if (!$assignment) json_response(['ok'=>false,'error'=>'Задание не найдено.'],404);

if (!can_manage_school($user,$schoolId)) {
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
        'class_id'=>$classId,
        'subject_id'=>(int)$assignment['subject_id'],
    ]);
    if (!$stmt->fetchColumn()) json_response(['ok'=>false,'error'=>'Нет доступа к этому классу.'],403);
}

$stmt = $pdo->prepare(
    'DELETE FROM assignment_classes
     WHERE assignment_id = :assignment_id AND class_id = :class_id'
);
$stmt->execute(['assignment_id'=>$assignmentId,'class_id'=>$classId]);

$stmt = $pdo->prepare(
    'SELECT
       (SELECT COUNT(*) FROM assignment_classes WHERE assignment_id = :a1)
       +
       (SELECT COUNT(*) FROM assignment_students WHERE assignment_id = :a2)'
);
$stmt->execute(['a1'=>$assignmentId,'a2'=>$assignmentId]);
$targetsLeft = (int)$stmt->fetchColumn();

if ($targetsLeft === 0) {
    $pdo->prepare(
        'UPDATE assignments
         SET status = "draft", workflow_status = "ready", updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    )->execute(['id'=>$assignmentId]);
}

audit_event('assignment_unassigned_from_class','assignment',$assignmentId,[
    'class_id'=>$classId,
],$schoolId,(int)$user['id']);

json_response(['ok'=>true,'assignment_id'=>$assignmentId,'class_id'=>$classId,'targets_left'=>$targetsLeft]);
