<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$sourceSchoolId = require_active_school($user, true);
$data = read_json_body();
$subjectId = (int)($data['subject_id'] ?? 0);
$targetSchoolId = (int)($data['target_school_id'] ?? 0);
$assignmentIds = is_array($data['assignment_ids'] ?? null) ? $data['assignment_ids'] : [];

$assignmentIds = array_values(array_unique(array_filter(
    array_map('intval', $assignmentIds),
    static fn(int $id): bool => $id > 0
)));

if ($subjectId < 1 || $targetSchoolId < 1) {
    json_response(['ok' => false, 'error' => 'Выберите предмет и школу-получателя.'], 422);
}
if ($targetSchoolId === $sourceSchoolId) {
    json_response(['ok' => false, 'error' => 'Нельзя отправить предмет в ту же школу.'], 422);
}
if (count($assignmentIds) > 200) {
    json_response(['ok' => false, 'error' => 'За одну передачу можно отправить не более 200 заданий.'], 422);
}

$pdo = app_db();

$stmt = $pdo->prepare(
    'SELECT s.id, s.name
     FROM school_subjects ss
     JOIN subjects s ON s.id = ss.subject_id
     WHERE ss.school_id = :school_id
       AND ss.subject_id = :subject_id
       AND ss.is_active = 1
     LIMIT 1'
);
$stmt->execute([
    'school_id' => $sourceSchoolId,
    'subject_id' => $subjectId,
]);
$subject = $stmt->fetch();
if (!$subject) {
    json_response(['ok' => false, 'error' => 'Предмет не найден в выбранной школе.'], 404);
}

$stmt = $pdo->prepare(
    'SELECT id, name
     FROM schools
     WHERE id = :id AND status = "active"
     LIMIT 1'
);
$stmt->execute(['id' => $targetSchoolId]);
$targetSchool = $stmt->fetch();
if (!$targetSchool) {
    json_response(['ok' => false, 'error' => 'Школа-получатель не найдена.'], 404);
}

$stmt = $pdo->prepare(
    'SELECT u.id
     FROM school_users su
     JOIN users u ON u.id = su.user_id
     WHERE su.school_id = :school_id
       AND su.is_active = 1
       AND u.is_active = 1
       AND su.role IN ("school_admin", "owner")
     ORDER BY CASE su.role WHEN "owner" THEN 0 ELSE 1 END, su.created_at, u.id
     LIMIT 1'
);
$stmt->execute(['school_id' => $targetSchoolId]);
$targetOwnerId = (int)($stmt->fetchColumn() ?: 0);
if ($targetOwnerId < 1) {
    json_response([
        'ok' => false,
        'error' => 'У школы-получателя нет активного администратора. Сначала назначьте администратора.',
    ], 409);
}

$assignments = [];
if ($assignmentIds) {
    $placeholders = implode(',', array_fill(0, count($assignmentIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT *
         FROM assignments
         WHERE school_id = ?
           AND subject_id = ?
           AND id IN ($placeholders)
         ORDER BY id"
    );
    $stmt->execute(array_merge([$sourceSchoolId, $subjectId], $assignmentIds));
    $assignments = $stmt->fetchAll();

    if (count($assignments) !== count($assignmentIds)) {
        json_response([
            'ok' => false,
            'error' => 'Одно или несколько заданий не относятся к выбранному предмету этой школы.',
        ], 422);
    }
}

$copiedFiles = [];
$copiedAssignments = [];
$skippedAssignments = [];

function copy_private_file(string $folder, string $storedName, string $prefix, array &$copiedFiles): ?string
{
    $safeName = basename($storedName);
    $source = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR . $safeName;
    if (!is_file($source)) {
        return null;
    }

    $extension = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
    $newName = $prefix . '-' . bin2hex(random_bytes(12)) . ($extension !== '' ? '.' . $extension : '');
    $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . $folder;
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Не удалось подготовить хранилище файлов.');
    }

    $destination = $dir . DIRECTORY_SEPARATOR . $newName;
    if (!copy($source, $destination)) {
        throw new RuntimeException('Не удалось скопировать файл задания.');
    }

    $copiedFiles[] = $destination;
    return $newName;
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'INSERT INTO school_subjects (school_id, subject_id, is_active)
         VALUES (:school_id, :subject_id, 1)
         ON CONFLICT(school_id, subject_id) DO UPDATE SET is_active = 1'
    );
    $stmt->execute([
        'school_id' => $targetSchoolId,
        'subject_id' => $subjectId,
    ]);

    foreach ($assignments as $assignment) {
        $originSchoolId = (int)($assignment['source_school_id'] ?: $sourceSchoolId);
        $originAssignmentId = (int)($assignment['source_assignment_id'] ?: $assignment['id']);

        $stmt = $pdo->prepare(
            'SELECT id
             FROM assignments
             WHERE school_id = :target_school_id
               AND source_school_id = :source_school_id
               AND source_assignment_id = :source_assignment_id
             LIMIT 1'
        );
        $stmt->execute([
            'target_school_id' => $targetSchoolId,
            'source_school_id' => $originSchoolId,
            'source_assignment_id' => $originAssignmentId,
        ]);
        $existingId = (int)($stmt->fetchColumn() ?: 0);
        if ($existingId > 0) {
            $skippedAssignments[] = [
                'source_id' => (int)$assignment['id'],
                'target_id' => $existingId,
                'title' => (string)$assignment['title'],
            ];
            continue;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO assignments
             (teacher_id, school_id, subject_id, title, description, type, status,
              max_attempts, time_limit_minutes, starts_at, due_at, show_answers,
              focus_policy, source_school_id, source_assignment_id, shared_by_user_id)
             VALUES
             (:teacher_id, :school_id, :subject_id, :title, :description, :type, "draft",
              :max_attempts, :time_limit_minutes, NULL, NULL, :show_answers,
              :focus_policy, :source_school_id, :source_assignment_id, :shared_by_user_id)'
        );
        $stmt->execute([
            'teacher_id' => $targetOwnerId,
            'school_id' => $targetSchoolId,
            'subject_id' => $subjectId,
            'title' => (string)$assignment['title'],
            'description' => $assignment['description'],
            'type' => (string)$assignment['type'],
            'max_attempts' => (int)$assignment['max_attempts'],
            'time_limit_minutes' => $assignment['time_limit_minutes'],
            'show_answers' => (int)$assignment['show_answers'],
            'focus_policy' => (string)($assignment['focus_policy'] ?? 'allow'),
            'source_school_id' => $originSchoolId,
            'source_assignment_id' => $originAssignmentId,
            'shared_by_user_id' => (int)$user['id'],
        ]);
        $newAssignmentId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'SELECT * FROM assignment_imports WHERE assignment_id = :assignment_id LIMIT 1'
        );
        $stmt->execute(['assignment_id' => (int)$assignment['id']]);
        $import = $stmt->fetch();
        if ($import) {
            $newStoredName = copy_private_file(
                'assignment-imports',
                (string)$import['stored_name'],
                'shared-assignment',
                $copiedFiles
            );

            if ($newStoredName !== null) {
                $stmt = $pdo->prepare(
                    'INSERT INTO assignment_imports
                     (assignment_id, original_name, stored_name, source_format, mime_type, size_bytes,
                      parse_status, extracted_text, parsed_question_count, parser_message)
                     VALUES
                     (:assignment_id, :original_name, :stored_name, :source_format, :mime_type, :size_bytes,
                      :parse_status, :extracted_text, :parsed_question_count, :parser_message)'
                );
                $stmt->execute([
                    'assignment_id' => $newAssignmentId,
                    'original_name' => (string)$import['original_name'],
                    'stored_name' => $newStoredName,
                    'source_format' => (string)$import['source_format'],
                    'mime_type' => $import['mime_type'],
                    'size_bytes' => (int)$import['size_bytes'],
                    'parse_status' => (string)$import['parse_status'],
                    'extracted_text' => $import['extracted_text'],
                    'parsed_question_count' => (int)($import['parsed_question_count'] ?? 0),
                    'parser_message' => $import['parser_message'],
                ]);
            }
        }

        $stmt = $pdo->prepare(
            'SELECT * FROM questions
             WHERE assignment_id = :assignment_id
             ORDER BY position, id'
        );
        $stmt->execute(['assignment_id' => (int)$assignment['id']]);
        $questions = $stmt->fetchAll();

        foreach ($questions as $question) {
            $stmt = $pdo->prepare(
                'INSERT INTO questions
                 (assignment_id, type, text, points, position, correct_text, interaction_type, settings_json)
                 VALUES
                 (:assignment_id, :type, :text, :points, :position, :correct_text, :interaction_type, :settings_json)'
            );
            $stmt->execute([
                'assignment_id' => $newAssignmentId,
                'type' => (string)$question['type'],
                'text' => (string)$question['text'],
                'points' => (float)$question['points'],
                'position' => (int)$question['position'],
                'correct_text' => $question['correct_text'],
                'interaction_type' => $question['interaction_type'],
                'settings_json' => $question['settings_json'],
            ]);
            $newQuestionId = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare(
                'SELECT * FROM question_options
                 WHERE question_id = :question_id
                 ORDER BY position, id'
            );
            $stmt->execute(['question_id' => (int)$question['id']]);
            foreach ($stmt->fetchAll() as $option) {
                $insert = $pdo->prepare(
                    'INSERT INTO question_options (question_id, text, is_correct, position)
                     VALUES (:question_id, :text, :is_correct, :position)'
                );
                $insert->execute([
                    'question_id' => $newQuestionId,
                    'text' => (string)$option['text'],
                    'is_correct' => (int)$option['is_correct'],
                    'position' => (int)$option['position'],
                ]);
            }

            $stmt = $pdo->prepare(
                'SELECT * FROM question_assets
                 WHERE question_id = :question_id
                 ORDER BY position, id'
            );
            $stmt->execute(['question_id' => (int)$question['id']]);
            foreach ($stmt->fetchAll() as $asset) {
                $newAssetName = copy_private_file(
                    'question-assets',
                    (string)$asset['stored_name'],
                    'shared-question',
                    $copiedFiles
                );
                if ($newAssetName === null) continue;

                $insert = $pdo->prepare(
                    'INSERT INTO question_assets
                     (question_id, stored_name, original_name, mime_type, position)
                     VALUES (:question_id, :stored_name, :original_name, :mime_type, :position)'
                );
                $insert->execute([
                    'question_id' => $newQuestionId,
                    'stored_name' => $newAssetName,
                    'original_name' => $asset['original_name'],
                    'mime_type' => $asset['mime_type'],
                    'position' => (int)$asset['position'],
                ]);
            }
        }

        $copiedAssignments[] = [
            'source_id' => (int)$assignment['id'],
            'target_id' => $newAssignmentId,
            'title' => (string)$assignment['title'],
        ];
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($copiedFiles as $path) {
        @unlink($path);
    }
    throw $e;
}

audit_event('subject_shared_to_school', 'subject', $subjectId, [
    'target_school_id' => $targetSchoolId,
    'assignment_ids' => $assignmentIds,
    'copied_count' => count($copiedAssignments),
    'skipped_count' => count($skippedAssignments),
], $sourceSchoolId, (int)$user['id']);

json_response([
    'ok' => true,
    'subject' => [
        'id' => $subjectId,
        'name' => (string)$subject['name'],
    ],
    'target_school' => [
        'id' => $targetSchoolId,
        'name' => (string)$targetSchool['name'],
    ],
    'copied_assignments' => $copiedAssignments,
    'skipped_assignments' => $skippedAssignments,
]);
