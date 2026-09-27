<?php
declare(strict_types=1);

function material_copy_private_file(string $folder, string $storedName, string $prefix, array &$copiedFiles): ?string
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
        throw new RuntimeException('Не удалось скопировать файл материала.');
    }

    $copiedFiles[] = $destination;
    return $newName;
}

function material_target_owner_id(PDO $pdo, int $schoolId, int $fallbackUserId): int
{
    $stmt = $pdo->prepare(
        'SELECT u.id
         FROM school_users su
         JOIN users u ON u.id = su.user_id
         WHERE su.school_id = :school_id
           AND su.is_active = 1
           AND u.is_active = 1
           AND su.role IN ("owner", "school_admin")
         ORDER BY CASE su.role WHEN "owner" THEN 0 ELSE 1 END, su.created_at, u.id
         LIMIT 1'
    );
    $stmt->execute(['school_id' => $schoolId]);
    $id = (int)($stmt->fetchColumn() ?: 0);
    return $id > 0 ? $id : $fallbackUserId;
}

function material_copy_assignment(
    PDO $pdo,
    array $assignment,
    int $sourceSchoolId,
    int $targetSchoolId,
    int $targetOwnerId,
    int $senderUserId,
    array &$copiedFiles
): array {
    $originSchoolId = (int)($assignment['source_school_id'] ?: $sourceSchoolId);
    $originAssignmentId = (int)($assignment['source_assignment_id'] ?: $assignment['id']);

    $stmt = $pdo->prepare(
        'SELECT id, title
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
    $existing = $stmt->fetch();
    if ($existing) {
        return [
            'copied' => false,
            'source_id' => (int)$assignment['id'],
            'target_id' => (int)$existing['id'],
            'title' => (string)$existing['title'],
        ];
    }

    $stmt = $pdo->prepare(
        'INSERT INTO assignments
         (teacher_id, school_id, subject_id, title, description, type, status, workflow_status,
          max_attempts, time_limit_minutes, starts_at, due_at, show_answers,
          focus_policy, variant_count, shuffle_questions, shuffle_options, shuffle_structured,
          source_school_id, source_assignment_id, shared_by_user_id)
         VALUES
         (:teacher_id, :school_id, :subject_id, :title, :description, :type, "draft", "draft",
          :max_attempts, :time_limit_minutes, NULL, NULL, :show_answers,
          :focus_policy, :variant_count, :shuffle_questions, :shuffle_options, :shuffle_structured,
          :source_school_id, :source_assignment_id, :shared_by_user_id)'
    );
    $stmt->execute([
        'teacher_id' => $targetOwnerId,
        'school_id' => $targetSchoolId,
        'subject_id' => (int)$assignment['subject_id'],
        'title' => (string)$assignment['title'],
        'description' => $assignment['description'],
        'type' => (string)$assignment['type'],
        'max_attempts' => (int)$assignment['max_attempts'],
        'time_limit_minutes' => $assignment['time_limit_minutes'],
        'show_answers' => (int)$assignment['show_answers'],
        'focus_policy' => (string)($assignment['focus_policy'] ?? 'allow'),
        'variant_count' => max(1, min(4, (int)($assignment['variant_count'] ?? 1))),
        'shuffle_questions' => (int)($assignment['shuffle_questions'] ?? 0),
        'shuffle_options' => (int)($assignment['shuffle_options'] ?? 0),
        'shuffle_structured' => (int)($assignment['shuffle_structured'] ?? 0),
        'source_school_id' => $originSchoolId,
        'source_assignment_id' => $originAssignmentId,
        'shared_by_user_id' => $senderUserId,
    ]);
    $newAssignmentId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare(
        'SELECT * FROM assignment_imports WHERE assignment_id = :assignment_id LIMIT 1'
    );
    $stmt->execute(['assignment_id' => (int)$assignment['id']]);
    $import = $stmt->fetch();
    if ($import) {
        $newStoredName = material_copy_private_file(
            'assignment-imports',
            (string)$import['stored_name'],
            'received-assignment',
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
        $insertQuestion = $pdo->prepare(
            'INSERT INTO questions
             (assignment_id, type, text, points, position, correct_text, interaction_type, settings_json)
             VALUES
             (:assignment_id, :type, :text, :points, :position, :correct_text, :interaction_type, :settings_json)'
        );
        $insertQuestion->execute([
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
        $insertOption = $pdo->prepare(
            'INSERT INTO question_options (question_id, text, is_correct, position)
             VALUES (:question_id, :text, :is_correct, :position)'
        );
        foreach ($stmt->fetchAll() as $option) {
            $insertOption->execute([
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
            $newAssetName = material_copy_private_file(
                'question-assets',
                (string)$asset['stored_name'],
                'received-question',
                $copiedFiles
            );
            if ($newAssetName === null) continue;

            $insertAsset = $pdo->prepare(
                'INSERT INTO question_assets
                 (question_id, stored_name, original_name, mime_type, position)
                 VALUES (:question_id, :stored_name, :original_name, :mime_type, :position)'
            );
            $insertAsset->execute([
                'question_id' => $newQuestionId,
                'stored_name' => $newAssetName,
                'original_name' => $asset['original_name'],
                'mime_type' => $asset['mime_type'],
                'position' => (int)$asset['position'],
            ]);
        }
    }

    return [
        'copied' => true,
        'source_id' => (int)$assignment['id'],
        'target_id' => $newAssignmentId,
        'title' => (string)$assignment['title'],
    ];
}
