<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
$schoolId = require_active_school($user, false);
$pdo = app_db();

$q = trim((string)($_GET['q'] ?? ''));
$subjectId = max(0, (int)($_GET['subject_id'] ?? 0));
$params = ['school_id' => $schoolId];
$where = ['li.status = "published"'];

if ($q !== '') {
    $where[] = '(li.title_snapshot LIKE :q OR COALESCE(li.description_snapshot, "") LIKE :q OR s.name LIKE :q OR sch.name LIKE :q OR COALESCE(u.first_name, "") LIKE :q OR COALESCE(u.last_name, "") LIKE :q)';
    $params['q'] = '%' . $q . '%';
}
if ($subjectId > 0) {
    $where[] = 'li.subject_id = :subject_id';
    $params['subject_id'] = $subjectId;
}

$stmt = $pdo->prepare(
    'SELECT li.id, li.source_school_id, li.source_assignment_id, li.subject_id,
            li.title_snapshot, li.description_snapshot, li.questions_count_snapshot,
            li.published_at, li.created_at,
            sch.name AS school_name, sch.city AS school_city,
            s.name AS subject_name,
            u.id AS author_id,
            u.first_name AS author_first_name, u.last_name AS author_last_name,
            a.time_limit_minutes,
            COALESCE((
                SELECT GROUP_CONCAT(DISTINCT COALESCE(c.display_name, c.name))
                FROM assignment_classes ac
                JOIN classes c ON c.id = ac.class_id
                WHERE ac.assignment_id = li.source_assignment_id
            ), "") AS class_names,
            (
                SELECT COUNT(*)
                FROM library_imports usage_rows
                WHERE usage_rows.library_item_id = li.id
            ) AS usage_count,
            (
                SELECT COUNT(*)
                FROM library_items author_rows
                WHERE author_rows.submitted_by = li.submitted_by
                  AND author_rows.status = "published"
            ) AS author_materials_count,
            (
                SELECT COUNT(*)
                FROM library_reactions lr
                WHERE lr.library_item_id = li.id AND lr.liked = 1
            ) AS likes_count,
            (
                SELECT ROUND(AVG(lr.rating), 1)
                FROM library_reactions lr
                WHERE lr.library_item_id = li.id AND lr.rating IS NOT NULL
            ) AS rating_average,
            (
                SELECT COUNT(*)
                FROM library_reactions lr
                WHERE lr.library_item_id = li.id AND lr.rating IS NOT NULL
            ) AS ratings_count,
            (
                SELECT lr.liked
                FROM library_reactions lr
                WHERE lr.library_item_id = li.id AND lr.user_id = (int)$user['id']
                LIMIT 1
            ) AS user_liked,
            (
                SELECT lr.rating
                FROM library_reactions lr
                WHERE lr.library_item_id = li.id AND lr.user_id = (int)$user['id']
                LIMIT 1
            ) AS user_rating,
            CASE WHEN imp.id IS NULL THEN 0 ELSE 1 END AS imported,
            imp.target_assignment_id
     FROM library_items li
     JOIN schools sch ON sch.id = li.source_school_id
     JOIN assignments a ON a.id = li.source_assignment_id
     LEFT JOIN subjects s ON s.id = li.subject_id
     LEFT JOIN users u ON u.id = li.submitted_by
     LEFT JOIN library_imports imp
       ON imp.library_item_id = li.id AND imp.target_school_id = :school_id
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY li.published_at DESC, li.id DESC
     LIMIT 200'
);
$stmt->execute($params);
$items = $stmt->fetchAll();

$ownStmt = $pdo->prepare(
    'SELECT li.id, li.source_assignment_id, li.subject_id, li.status,
            li.title_snapshot, li.questions_count_snapshot, li.created_at, li.published_at,
            s.name AS subject_name,
            u.first_name AS submitted_first_name, u.last_name AS submitted_last_name
     FROM library_items li
     LEFT JOIN subjects s ON s.id = li.subject_id
     LEFT JOIN users u ON u.id = li.submitted_by
     WHERE li.source_school_id = :school_id
     ORDER BY li.updated_at DESC, li.id DESC'
);
$ownStmt->execute(['school_id' => $schoolId]);

json_response([
    'ok' => true,
    'items' => $items,
    'own_items' => $ownStmt->fetchAll(),
    'can_manage' => can_manage_school($user, $schoolId),
    'can_import' => can_manage_school($user, $schoolId) || can_teach_school($user, $schoolId),
    'active_school_id' => $schoolId,
]);
