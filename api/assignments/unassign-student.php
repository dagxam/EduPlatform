<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin','teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok'=>false,'error'=>'Метод не поддерживается.'],405);
}
$data = read_json_body();
$assignmentId = (int)($data['assignment_id'] ?? 0);
$studentId = (int)($data['student_id'] ?? 0);
if ($assignmentId < 1 || $studentId < 1) {
    json_response(['ok'=>false,'error'=>'Не указано назначение.'],422);
}

$pdo = app_db();
$schoolId = require_active_school($user,false);

$stmt = $pdo->prepare(
    'SELECT a.id, a.subject_id, cs.class_id
     FROM assignments a
     JOIN class_students cs ON cs.student_id = :student_id
     JOIN classes c ON c.id = cs.class_id
     WHERE a.id = :assignment_id
       AND a.school_id = :school_id
       AND c.school_id = :school_id2
     LIMIT 1'
);
$stmt->execute([
    'student_id'=>$studentId,
    'assignment_id'=>$assignmentId,
    'school_id'=>$schoolId,
    'school_id2'=>$schoolId,
]);
$row = $stmt->fetch();
if (!$row) json_response(['ok'=>false,'error'=>'Назначение не найдено.'],404);

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
        'class_id'=>(int)$row['class_id'],
        'subject_id'=>(int)$row['subject_id'],
    ]);
    if (!$stmt->fetchColumn()) json_response(['ok'=>false,'error'=>'Нет доступа к этому ученику.'],403);
}

$stmt = $pdo->prepare(
    'DELETE FROM assignment_students
     WHERE assignment_id = :assignment_id AND student_id = :student_id'
);
$stmt->execute(['assignment_id'=>$assignmentId,'student_id'=>$studentId]);

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

audit_event('assignment_unassigned_from_student','assignment',$assignmentId,[
    'student_id'=>$studentId,
],$schoolId,(int)$user['id']);

json_response(['ok'=>true,'assignment_id'=>$assignmentId,'student_id'=>$studentId,'targets_left'=>$targetsLeft]);
