<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin', 'teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$classId = (int)($_POST['class_id'] ?? 0);
if ($classId < 1 || empty($_FILES['file'])) {
    json_response(['ok' => false, 'error' => 'Выберите класс и Word-файл.'], 422);
}

$file = $_FILES['file'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_response(['ok' => false, 'error' => 'Не удалось загрузить файл.'], 422);
}
if ((int)($file['size'] ?? 0) > 5 * 1024 * 1024) {
    json_response(['ok' => false, 'error' => 'DOCX слишком большой. Максимальный размер — 5 МБ.'], 413);
}

$extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
if ($extension !== 'docx') {
    json_response(['ok' => false, 'error' => 'Сейчас поддерживается формат .docx. Старый .doc сохраните как DOCX.'], 422);
}

if (!class_exists('ZipArchive')) {
    json_response([
        'ok' => false,
        'error' => 'На сервере не включено расширение PHP ZipArchive, необходимое для чтения DOCX.',
        'code' => 'ZIP_UNAVAILABLE',
    ], 500);
}

$pdo = app_db();
$schoolId = current_school_id();
$sql = 'SELECT c.id, COALESCE(c.display_name, c.name) AS name, c.school_id FROM classes c WHERE c.id = :id';
$params = ['id' => $classId];
if ($schoolId !== null) {
    if (!can_access_school($user, $schoolId)) {
        json_response(['ok' => false, 'error' => 'Нет доступа к выбранной школе.'], 403);
    }
    $sql .= ' AND c.school_id = :school_id';
    $params['school_id'] = $schoolId;
} else {
    $sql .= ' AND c.school_id IS NULL';
}
if ($user['role'] === 'teacher') {
    $sql .= ' AND c.teacher_id = :teacher_id';
    $params['teacher_id'] = (int)$user['id'];
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$class = $stmt->fetch();
if (!$class) {
    json_response(['ok' => false, 'error' => 'Класс не найден.'], 404);
}

$zip = new ZipArchive();
if ($zip->open($file['tmp_name']) !== true) {
    json_response(['ok' => false, 'error' => 'Не удалось открыть DOCX-файл.'], 422);
}
$entry = $zip->statName('word/document.xml');
if (!$entry || (int)($entry['size'] ?? 0) > 2 * 1024 * 1024) {
    $zip->close();
    json_response(['ok' => false, 'error' => 'DOCX имеет слишком большой или некорректный текстовый блок.'], 422);
}
$xml = $zip->getFromName('word/document.xml');
$zip->close();

if ($xml === false || trim($xml) === '') {
    json_response(['ok' => false, 'error' => 'В DOCX не найден текст.'], 422);
}

$lines = [];

// Word-таблицы: поддерживаем строки вида "№ | Фамилия | Имя" и "Фамилия | Имя".
if (preg_match_all('/<w:tr\\b[^>]*>(.*?)<\\/w:tr>/si', $xml, $rows)) {
    foreach ($rows[1] as $rowXml) {
        $cells = [];
        if (preg_match_all('/<w:tc\\b[^>]*>(.*?)<\\/w:tc>/si', $rowXml, $cellMatches)) {
            foreach ($cellMatches[1] as $cellXml) {
                $parts = [];
                if (preg_match_all('/<w:t\\b[^>]*>(.*?)<\\/w:t>/si', $cellXml, $texts)) {
                    foreach ($texts[1] as $text) {
                        $parts[] = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_XML1, 'UTF-8');
                    }
                }
                $cell = trim(preg_replace('/\\s+/u', ' ', implode('', $parts)) ?? '');
                if ($cell !== '') {
                    $cells[] = $cell;
                }
            }
        }

        if ($cells && preg_match('/^\\d+[.)-]?$/u', $cells[0])) {
            array_shift($cells);
        }
        if (count($cells) >= 2) {
            $lines[] = implode(' ', array_slice($cells, 0, 3));
        }
    }
}

// Обычные абзацы и нумерованные списки.
if (preg_match_all('/<w:p\\b[^>]*>(.*?)<\\/w:p>/si', $xml, $paragraphs)) {
    foreach ($paragraphs[1] as $paragraph) {
        $parts = [];
        if (preg_match_all('/<w:t\b[^>]*>(.*?)<\/w:t>/si', $paragraph, $texts)) {
            foreach ($texts[1] as $text) {
                $parts[] = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }
        $line = trim(preg_replace('/\s+/u', ' ', implode('', $parts)) ?? '');
        if ($line !== '') {
            $lines[] = $line;
        }
    }
}

function parse_student_name(string $line): ?array
{
    $line = trim($line);
    $line = preg_replace('/^[\s\x{2022}\x{25CF}\x{25AA}\-–—]*\d+[\.)\-:]?\s*/u', '', $line) ?? $line;
    $line = preg_replace('/^[\s\x{2022}\x{25CF}\x{25AA}\-–—]+/u', '', $line) ?? $line;
    $line = trim($line);

    if ($line === '' || preg_match('/\d/u', $line)) {
        return null;
    }

    $lower = function_exists('mb_strtolower') ? mb_strtolower($line) : strtolower($line);
    foreach (['список', 'класс', 'ученик', 'учащ', 'фио', 'фамилия', 'имя', '№'] as $stop) {
        if (str_contains($lower, $stop)) {
            return null;
        }
    }

    $parts = preg_split('/\s+/u', $line) ?: [];
    if (count($parts) < 2 || count($parts) > 4) {
        return null;
    }

    foreach ($parts as $part) {
        if (!preg_match('/^[\p{L}\-\'’]+$/u', $part)) {
            return null;
        }
    }

    return [
        'last_name' => array_shift($parts),
        'first_name' => implode(' ', $parts),
    ];
}

$candidates = [];
foreach ($lines as $line) {
    $parsed = parse_student_name($line);
    if ($parsed) {
        $keyText = $parsed['last_name'] . '|' . $parsed['first_name'];
        $key = function_exists('mb_strtolower') ? mb_strtolower($keyText) : strtolower($keyText);
        $candidates[$key] = $parsed;
    }
}

if (!$candidates) {
    json_response([
        'ok' => false,
        'error' => 'Не удалось найти фамилии и имена. Используйте строки вида: «Магомедов Али».',
    ], 422);
}

$existingStmt = $pdo->prepare(
    'SELECT u.first_name, u.last_name
     FROM class_students cs
     JOIN users u ON u.id = cs.student_id
     WHERE cs.class_id = :class_id'
);
$existingStmt->execute(['class_id' => $classId]);
$existing = [];
foreach ($existingStmt->fetchAll() as $row) {
    $keyText = $row['last_name'] . '|' . $row['first_name'];
    $key = function_exists('mb_strtolower') ? mb_strtolower($keyText) : strtolower($keyText);
    $existing[$key] = true;
}

$inserted = [];
$skipped = [];
$pdo->beginTransaction();
try {
    $userStmt = $pdo->prepare(
        'INSERT INTO users (first_name, last_name, email, password_hash, role, class_name)
         VALUES (:first_name, :last_name, :email, :password_hash, "student", :class_name)'
    );
    $linkStmt = $pdo->prepare(
        'INSERT INTO class_students (student_id, class_id)
         VALUES (:student_id, :class_id)'
    );

    foreach ($candidates as $key => $student) {
        if (isset($existing[$key])) {
            $skipped[] = $student;
            continue;
        }

        $internalEmail = 'student-' . bin2hex(random_bytes(8)) . '@edu.local';
        $userStmt->execute([
            'first_name' => $student['first_name'],
            'last_name' => $student['last_name'],
            'email' => $internalEmail,
            'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
            'class_name' => $class['name'],
        ]);
        $studentId = (int)$pdo->lastInsertId();
        $linkStmt->execute(['student_id' => $studentId, 'class_id' => $classId]);

        $inserted[] = [
            'id' => $studentId,
            'first_name' => $student['first_name'],
            'last_name' => $student['last_name'],
        ];
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

json_response([
    'ok' => true,
    'imported_count' => count($inserted),
    'skipped_count' => count($skipped),
    'students' => $inserted,
]);
