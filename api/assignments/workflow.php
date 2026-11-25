<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/attempts/_helpers.php';

$user = require_user(['admin', 'teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$data = read_json_body();
$assignmentId = (int)($data['assignment_id'] ?? 0);
$action = trim((string)($data['action'] ?? ''));
$comment = trim((string)($data['comment'] ?? ''));

if ($assignmentId < 1 || $action === '') {
    json_response(['ok' => false, 'error' => 'Не указано действие или задание.'], 422);
}
if (strlen($comment) > 1500) {
    json_response(['ok' => false, 'error' => 'Комментарий слишком длинный.'], 422);
}

$pdo = app_db();
$schoolId = require_active_school($user, false);
$manager = can_manage_school($user, $schoolId);

$stmt = $pdo->prepare(
    'SELECT a.id, a.teacher_id, a.school_id, a.subject_id, a.title, a.type,
            a.status, a.workflow_status,
            s.assignment_review_required
     FROM assignments a
     JOIN schools s ON s.id = a.school_id
     WHERE a.id = :assignment_id AND a.school_id = :school_id
     LIMIT 1'
);
$stmt->execute([
    'assignment_id' => $assignmentId,
    'school_id' => $schoolId,
]);
$assignment = $stmt->fetch();
if (!$assignment) {
    json_response(['ok' => false, 'error' => 'Задание не найдено.'], 404);
}

if (!$manager && (int)$assignment['teacher_id'] !== (int)$user['id']) {
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM teacher_subjects
         WHERE school_id = :school_id
           AND teacher_id = :teacher_id
           AND subject_id = :subject_id
         LIMIT 1'
    );
    $stmt->execute([
        'school_id' => $schoolId,
        'teacher_id' => (int)$user['id'],
        'subject_id' => (int)$assignment['subject_id'],
    ]);
    if (!$stmt->fetchColumn()) {
        json_response(['ok' => false, 'error' => 'Нет доступа к этому заданию.'], 403);
    }
}

if ($assignment['type'] === 'paper' && $action === 'paper_edit') {
    require dirname(__DIR__).'/paper/catalog-archive.php';paper_catalog_archive_schema($pdo);
    if(paper_catalog_is_archived($pdo,$assignmentId))json_response(['ok'=>false,'error'=>'Сначала восстановите работу из архива.'],409);
    if(!$manager&&(int)$assignment['teacher_id']!==(int)$user['id'])json_response(['ok'=>false,'error'=>'Редактирование доступно автору или администратору.'],403);
    $q=$pdo->prepare("UPDATE assignments SET status='draft',workflow_status='draft',review_submitted_at=NULL,reviewed_at=NULL,reviewed_by=NULL,review_comment=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=? AND school_id=? AND type='paper' AND status='paper_locked'");$q->execute([$assignmentId,$schoolId]);
    if(!$q->rowCount())json_response(['ok'=>false,'error'=>'Работа уже открыта для редактирования. Обновите список.'],409);
    audit_event('paper_edit_opened','assignment',$assignmentId,[],$schoolId,(int)$user['id']);
    json_response(['ok'=>true,'assignment_id'=>$assignmentId,'workflow_status'=>'draft']);
}
if ($assignment['type'] === 'paper' && $assignment['status'] === 'paper_locked') json_response(['ok'=>false,'error'=>'Откройте редактирование бумажной работы.'],409);
$current = (string)($assignment['workflow_status'] ?: 'draft');
$reviewRequired = (int)$assignment['assignment_review_required'] === 1;
$next = null;
$event = null;
$updates = [];

if($assignment['type']==='paper'&&in_array($action,['prepare','approve'],true)){
    require_once dirname(__DIR__).'/paper/choice-policy.php';
    $q=$pdo->prepare('SELECT type,interaction_type FROM questions WHERE assignment_id=? AND is_active=1 ORDER BY position,id');$q->execute([$assignmentId]);$questions=$q->fetchAll();
    if(!$questions)json_response(['ok'=>false,'error'=>'Добавьте хотя бы один тестовый вопрос.'],422);
    try{paper_require_choice_questions($questions);}catch(DomainException $e){json_response(['ok'=>false,'error'=>$e->getMessage()],422);}
}

if ($action === 'prepare') {
    if ($current !== 'draft') {
        json_response(['ok' => false, 'error' => 'Подготовить можно только черновик.'], 409);
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE assignment_id = :assignment_id AND is_active = 1');
    $stmt->execute(['assignment_id' => $assignmentId]);
    $questionCount = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM assignment_imports WHERE assignment_id = :assignment_id');
    $stmt->execute(['assignment_id' => $assignmentId]);
    $hasImport = (int)$stmt->fetchColumn() > 0;

    if ($questionCount === 0 && !$hasImport) {
        json_response(['ok' => false, 'error' => 'Добавьте хотя бы один вопрос или импортируйте материал перед подготовкой задания.'], 422);
    }

    if ($reviewRequired && !$manager) {
        $next = 'review';
        $event = 'assignment_submitted_for_review';
        $updates = [
            'review_submitted_at = CURRENT_TIMESTAMP',
            'reviewed_at = NULL',
            'reviewed_by = NULL',
            'review_comment = NULL',
        ];
    } else {
        $next = 'ready';
        $event = 'assignment_marked_ready';
        $updates = [
            'reviewed_at = CURRENT_TIMESTAMP',
            'reviewed_by = ' . (int)$user['id'],
            'review_comment = NULL',
        ];
    }
} elseif ($action === 'approve') {
    if (!$manager) {
        json_response(['ok' => false, 'error' => 'Одобрить задание может только администратор школы.'], 403);
    }
    if ($current !== 'review') {
        json_response(['ok' => false, 'error' => 'На проверке сейчас нет этого задания.'], 409);
    }
    $next = 'ready';
    $event = 'assignment_review_approved';
    $updates = [
        'reviewed_at = CURRENT_TIMESTAMP',
        'reviewed_by = ' . (int)$user['id'],
        'review_comment = ' . ($comment !== '' ? $pdo->quote($comment) : 'NULL'),
    ];
} elseif ($action === 'return') {
    if (!$manager) {
        json_response(['ok' => false, 'error' => 'Вернуть на доработку может только администратор школы.'], 403);
    }
    if ($current !== 'review') {
        json_response(['ok' => false, 'error' => 'Вернуть можно только задание на проверке.'], 409);
    }
    $next = 'draft';
    $event = 'assignment_review_returned';
    $updates = [
        'reviewed_at = CURRENT_TIMESTAMP',
        'reviewed_by = ' . (int)$user['id'],
        'review_comment = ' . $pdo->quote($comment),
    ];
} elseif ($action === 'withdraw') {
    if ($current !== 'review') {
        json_response(['ok' => false, 'error' => 'Отозвать можно только задание на проверке.'], 409);
    }
    if ($manager) {
        json_response(['ok' => false, 'error' => 'Администратору используйте «Вернуть на доработку».'], 422);
    }
    $next = 'draft';
    $event = 'assignment_review_withdrawn';
    $updates = [
        'review_submitted_at = NULL',
        'reviewed_at = NULL',
        'reviewed_by = NULL',
        'review_comment = NULL',
    ];
} elseif ($action === 'reopen') {
    if ($current !== 'ready') {
        json_response(['ok' => false, 'error' => 'Вернуть в черновик можно только готовое, ещё не назначенное задание.'], 409);
    }

    $stmt = $pdo->prepare(
        'SELECT
           (SELECT COUNT(*) FROM assignment_classes WHERE assignment_id = :class_assignment_id)
           +
           (SELECT COUNT(*) FROM assignment_students WHERE assignment_id = :student_assignment_id)'
    );
    $stmt->execute([
        'class_assignment_id' => $assignmentId,
        'student_assignment_id' => $assignmentId,
    ]);
    if ((int)$stmt->fetchColumn() > 0) {
        json_response([
            'ok' => false,
            'error' => 'Задание уже назначено. Сначала отмените все назначения классам и отдельным ученикам.'
        ], 409);
    }

    $next = 'draft';
    $event = 'assignment_reopened_as_draft';
    $updates = [
        'review_submitted_at = NULL',
        'reviewed_at = NULL',
        'reviewed_by = NULL',
        'review_comment = NULL',
    ];
} elseif ($action === 'complete') {
    if ($current !== 'assigned') {
        json_response(['ok' => false, 'error' => 'Завершить можно только назначенное задание.'], 409);
    }

    // Browser/PWA shutdown may leave an orphaned in_progress row. Clean stale
    // heartbeat sessions before deciding whether the assignment can be closed.
    finalize_stale_attempts($pdo, $assignmentId, null, $schoolId, 120);

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM attempts
         WHERE assignment_id = :assignment_id AND status = "in_progress"'
    );
    $stmt->execute(['assignment_id' => $assignmentId]);
    if ((int)$stmt->fetchColumn() > 0) {
        json_response(['ok' => false, 'error' => 'Есть ученики, которые действительно выполняют задание сейчас. Дождитесь их завершения перед закрытием задания.'], 409);
    }

    $next = 'completed';
    $event = 'assignment_completed';
    $updates = [
        'status = "closed"',
        'completed_at = CURRENT_TIMESTAMP',
    ];
} else {
    json_response(['ok' => false, 'error' => 'Неизвестное действие.'], 422);
}

$set = array_merge(
    ['workflow_status = ' . $pdo->quote($next), 'updated_at = CURRENT_TIMESTAMP'],
    $updates
);
$sql = 'UPDATE assignments SET ' . implode(', ', $set) . ' WHERE id = :id';
$stmt = $pdo->prepare($sql);
$stmt->execute(['id' => $assignmentId]);

audit_event($event, 'assignment', $assignmentId, [
    'from' => $current,
    'to' => $next,
    'comment' => $comment !== '' ? $comment : null,
    'review_required' => $reviewRequired,
], $schoolId, (int)$user['id']);

json_response([
    'ok' => true,
    'assignment_id' => $assignmentId,
    'workflow_status' => $next,
]);
