<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$assignmentId = (int)($data['assignment_id'] ?? 0);
if ($assignmentId < 1) {
    json_response(['ok' => false, 'error' => 'Не выбрано задание.'], 422);
}

$pdo = app_db();
$schoolId = require_active_school($user, false);
$manager = can_manage_school($user, $schoolId);

$stmt = $pdo->prepare(
    'SELECT a.*, s.name AS subject_name
     FROM assignments a
     LEFT JOIN subjects s ON s.id = a.subject_id
     WHERE a.id = :id AND a.school_id = :school_id
     LIMIT 1'
);
$stmt->execute(['id' => $assignmentId, 'school_id' => $schoolId]);
$assignment = $stmt->fetch();
if (!$assignment) {
    json_response(['ok' => false, 'error' => 'Задание не найдено.'], 404);
}

if (!$manager) {
    if (!can_teach_school($user, $schoolId)) {
        json_response(['ok' => false, 'error' => 'Недостаточно прав.'], 403);
    }
    $stmt = $pdo->prepare(
        'SELECT 1 FROM teacher_subjects
         WHERE school_id = :school_id AND teacher_id = :teacher_id AND subject_id = :subject_id
         LIMIT 1'
    );
    $stmt->execute([
        'school_id' => $schoolId,
        'teacher_id' => (int)$user['id'],
        'subject_id' => (int)$assignment['subject_id'],
    ]);
    if ((int)$assignment['teacher_id'] !== (int)$user['id'] && !$stmt->fetchColumn()) {
        json_response(['ok' => false, 'error' => 'Нет доступа к этому заданию.'], 403);
    }
}

$stmt = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE assignment_id = :id');
$stmt->execute(['id' => $assignmentId]);
$questionCount = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT COUNT(*) FROM assignment_imports WHERE assignment_id = :id');
$stmt->execute(['id' => $assignmentId]);
$hasImport = (int)$stmt->fetchColumn() > 0;

if ($questionCount === 0 && !$hasImport) {
    json_response(['ok' => false, 'error' => 'В библиотеку можно отправить задание с вопросами или импортированным материалом.'], 422);
}

$status = $manager ? 'published' : 'pending';
$approvedBy = $manager ? (int)$user['id'] : null;

$stmt = $pdo->prepare(
    'INSERT INTO library_items
     (source_school_id, source_assignment_id, subject_id, submitted_by, approved_by,
      status, title_snapshot, description_snapshot, questions_count_snapshot, published_at)
     VALUES
     (:school_id, :assignment_id, :subject_id, :submitted_by, :approved_by,
      :status, :title, :description, :questions_count,
      CASE WHEN :status2 = "published" THEN CURRENT_TIMESTAMP ELSE NULL END)'
    . db_upsert_clause(
        $pdo,
        ['source_school_id', 'source_assignment_id'],
        ['subject_id', 'submitted_by', 'approved_by', 'status', 'title_snapshot',
         'description_snapshot', 'questions_count_snapshot', 'published_at', 'updated_at']
    )
);
$stmt->execute([
    'school_id' => $schoolId,
    'assignment_id' => $assignmentId,
    'subject_id' => $assignment['subject_id'],
    'submitted_by' => (int)$user['id'],
    'approved_by' => $approvedBy,
    'status' => $status,
    'status2' => $status,
    'title' => (string)$assignment['title'],
    'description' => $assignment['description'],
    'questions_count' => $questionCount,
]);

$itemId = (int)$pdo->lastInsertId();
if ($itemId === 0) {
    $stmt = $pdo->prepare(
        'SELECT id FROM library_items
         WHERE source_school_id = :school_id AND source_assignment_id = :assignment_id'
    );
    $stmt->execute(['school_id' => $schoolId, 'assignment_id' => $assignmentId]);
    $itemId = (int)$stmt->fetchColumn();
}

audit_event(
    $status === 'published' ? 'library_item_published' : 'library_item_submitted',
    'library_item',
    $itemId,
    ['assignment_id' => $assignmentId, 'questions_count' => $questionCount],
    $schoolId,
    (int)$user['id']
);

json_response([
    'ok' => true,
    'item_id' => $itemId,
    'status' => $status,
    'message' => $status === 'published'
        ? 'Задание опубликовано в библиотеке UVORIA.'
        : 'Задание отправлено администратору школы на публикацию.',
]);
