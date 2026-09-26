<?php
declare(strict_types=1);

function student_name_key(string $lastName, string $firstName): string
{
    $value = trim($lastName) . '|' . trim($firstName);
    return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
}

function validate_student_name_part(string $value): bool
{
    $value = trim($value);
    if ($value === '') {
        return false;
    }
    return (bool)preg_match('/^[\p{L}\-\'’ ]+$/u', $value);
}

function parse_student_line(string $line): ?array
{
    $line = trim($line);
    $line = preg_replace('/^[\s\x{2022}\x{25CF}\x{25AA}\-–—]*\d+[\.)\-:]?\s*/u', '', $line) ?? $line;
    $line = preg_replace('/^[\s\x{2022}\x{25CF}\x{25AA}\-–—]+/u', '', $line) ?? $line;
    $line = trim(preg_replace('/\s+/u', ' ', $line) ?? $line);

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
        'last_name' => (string)array_shift($parts),
        'first_name' => implode(' ', $parts),
    ];
}

function parse_docx_students(string $tmpName): array
{
    if (!class_exists('ZipArchive')) {
        json_response([
            'ok' => false,
            'error' => 'На сервере не включено расширение PHP ZipArchive, необходимое для чтения DOCX.',
            'code' => 'ZIP_UNAVAILABLE',
        ], 500);
    }

    $zip = new ZipArchive();
    if ($zip->open($tmpName) !== true) {
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

    if (preg_match_all('/<w:tr\b[^>]*>(.*?)<\/w:tr>/si', $xml, $rows)) {
        foreach ($rows[1] as $rowXml) {
            $cells = [];
            if (preg_match_all('/<w:tc\b[^>]*>(.*?)<\/w:tc>/si', $rowXml, $cellMatches)) {
                foreach ($cellMatches[1] as $cellXml) {
                    $parts = [];
                    if (preg_match_all('/<w:t\b[^>]*>(.*?)<\/w:t>/si', $cellXml, $texts)) {
                        foreach ($texts[1] as $text) {
                            $parts[] = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_XML1, 'UTF-8');
                        }
                    }
                    $cell = trim(preg_replace('/\s+/u', ' ', implode('', $parts)) ?? '');
                    if ($cell !== '') {
                        $cells[] = $cell;
                    }
                }
            }

            if ($cells && preg_match('/^\d+[.)-]?$/u', $cells[0])) {
                array_shift($cells);
            }
            if (count($cells) >= 2) {
                $lines[] = implode(' ', array_slice($cells, 0, 3));
            }
        }
    }

    if (preg_match_all('/<w:p\b[^>]*>(.*?)<\/w:p>/si', $xml, $paragraphs)) {
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

    $students = [];
    $seen = [];
    foreach ($lines as $line) {
        $parsed = parse_student_line($line);
        if (!$parsed) {
            continue;
        }
        $key = student_name_key($parsed['last_name'], $parsed['first_name']);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $students[] = $parsed;
    }

    return $students;
}

function require_school_class_for_admin(PDO $pdo, array $user, int $classId): array
{
    $schoolId = require_active_school($user, true);
    $stmt = $pdo->prepare(
        'SELECT c.id, COALESCE(c.display_name, c.name) AS name, c.school_id
         FROM classes c
         WHERE c.id = :class_id AND c.school_id = :school_id
         LIMIT 1'
    );
    $stmt->execute(['class_id' => $classId, 'school_id' => $schoolId]);
    $class = $stmt->fetch();
    if (!$class) {
        json_response(['ok' => false, 'error' => 'Класс не найден.'], 404);
    }
    return $class;
}

function class_existing_student_keys(PDO $pdo, int $classId): array
{
    $stmt = $pdo->prepare(
        'SELECT u.first_name, u.last_name
         FROM class_students cs
         JOIN users u ON u.id = cs.student_id
         WHERE cs.class_id = :class_id'
    );
    $stmt->execute(['class_id' => $classId]);

    $keys = [];
    foreach ($stmt->fetchAll() as $row) {
        $keys[student_name_key((string)$row['last_name'], (string)$row['first_name'])] = true;
    }
    return $keys;
}
