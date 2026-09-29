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

function student_import_students_from_lines(array $lines): array
{
    $students = [];
    $seen = [];

    foreach ($lines as $line) {
        $parsed = parse_student_line((string)$line);
        if (!$parsed) {
            continue;
        }
        $key = student_name_key($parsed['last_name'], $parsed['first_name']);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $students[] = $parsed;

        if (count($students) >= 1000) {
            break;
        }
    }

    return $students;
}

function student_import_students_from_text(string $text): array
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]+/u', "\n", $text) ?? $text;
    $lines = preg_split('/\n+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return student_import_students_from_lines($lines);
}

function student_import_xml_text(string $xml): string
{
    $parts = [];
    if (preg_match_all('/<t\b[^>]*>(.*?)<\/t>/si', $xml, $matches)) {
        foreach ($matches[1] as $value) {
            $decoded = html_entity_decode(strip_tags((string)$value), ENT_QUOTES | ENT_XML1, 'UTF-8');
            $decoded = trim(preg_replace('/\s+/u', ' ', $decoded) ?? '');
            if ($decoded !== '') {
                $parts[] = $decoded;
            }
        }
    }
    return trim(implode(' ', $parts));
}

function parse_xlsx_students(string $tmpName): array
{
    if (!class_exists('ZipArchive')) {
        json_response([
            'ok' => false,
            'error' => 'На сервере не включено расширение PHP ZipArchive, необходимое для чтения XLSX.',
            'code' => 'ZIP_UNAVAILABLE',
        ], 500);
    }

    $zip = new ZipArchive();
    if ($zip->open($tmpName) !== true) {
        json_response(['ok' => false, 'error' => 'Не удалось открыть XLSX-файл.'], 422);
    }

    $sharedStrings = [];
    $sharedStat = $zip->statName('xl/sharedStrings.xml');
    if ($sharedStat && (int)($sharedStat['size'] ?? 0) <= 6 * 1024 * 1024) {
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if (is_string($sharedXml) && preg_match_all('/<si\b[^>]*>(.*?)<\/si>/si', $sharedXml, $matches)) {
            foreach ($matches[1] as $siXml) {
                $sharedStrings[] = student_import_xml_text((string)$siXml);
                if (count($sharedStrings) >= 20000) break;
            }
        }
    }

    $sheets = [];
    $totalSheetBytes = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string)$zip->getNameIndex($i);
        if (!preg_match('#^xl/worksheets/sheet(\d+)\.xml$#', $name, $m)) {
            continue;
        }
        $stat = $zip->statIndex($i);
        $size = (int)($stat['size'] ?? 0);
        $totalSheetBytes += $size;
        if ($size <= 0 || $totalSheetBytes > 10 * 1024 * 1024) {
            continue;
        }
        $sheets[(int)$m[1]] = $name;
    }
    ksort($sheets);

    $lines = [];
    foreach ($sheets as $sheetName) {
        $xml = $zip->getFromName($sheetName);
        if (!is_string($xml) || $xml === '') continue;

        if (!preg_match_all('/<row\b[^>]*>(.*?)<\/row>/si', $xml, $rowMatches)) {
            continue;
        }

        foreach ($rowMatches[1] as $rowXml) {
            $cells = [];
            if (preg_match_all('/<c\b([^>]*)>(.*?)<\/c>/si', (string)$rowXml, $cellMatches, PREG_SET_ORDER)) {
                foreach ($cellMatches as $cellMatch) {
                    $attrs = (string)$cellMatch[1];
                    $cellXml = (string)$cellMatch[2];
                    $type = '';
                    if (preg_match('/\bt="([^"]+)"/i', $attrs, $typeMatch)) {
                        $type = strtolower((string)$typeMatch[1]);
                    }

                    $value = '';
                    if ($type === 'inlinestr') {
                        $value = student_import_xml_text($cellXml);
                    } elseif (preg_match('/<v\b[^>]*>(.*?)<\/v>/si', $cellXml, $valueMatch)) {
                        $raw = trim(html_entity_decode(strip_tags((string)$valueMatch[1]), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                        if ($type === 's' && ctype_digit($raw)) {
                            $value = (string)($sharedStrings[(int)$raw] ?? '');
                        } elseif (!in_array($type, ['b', 'e'], true)) {
                            $value = $raw;
                        }
                    }

                    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
                    if ($value !== '') {
                        $cells[] = $value;
                    }
                }
            }

            if ($cells) {
                $lines[] = implode(' ', array_slice($cells, 0, 4));
            }
            if (count($lines) >= 3000) break 2;
        }
    }

    $zip->close();
    return student_import_students_from_lines($lines);
}

function student_import_convert_to_utf8(string $value, string $encoding): string
{
    if (function_exists('mb_convert_encoding')) {
        $converted = @mb_convert_encoding($value, 'UTF-8', $encoding);
        return is_string($converted) ? $converted : '';
    }
    if (function_exists('iconv')) {
        $converted = @iconv($encoding, 'UTF-8//IGNORE', $value);
        return is_string($converted) ? $converted : '';
    }
    return $encoding === 'UTF-16LE' ? str_replace("\x00", '', $value) : $value;
}

function student_import_extract_rtf_text(string $data): string
{
    $data = preg_replace_callback("/\\\\'([0-9a-fA-F]{2})/", static function (array $m): string {
        return chr(hexdec($m[1]));
    }, $data) ?? $data;
    $data = preg_replace('/\\\\(?:par|line)\b ?/i', "\n", $data) ?? $data;
    $data = preg_replace('/\\\\[a-zA-Z]+-?\d* ?/', '', $data) ?? $data;
    $data = str_replace(['{', '}'], '', $data);
    return student_import_convert_to_utf8($data, 'Windows-1251');
}

function student_import_extract_legacy_office_text(string $tmpName): string
{
    $data = @file_get_contents($tmpName);
    if (!is_string($data) || $data === '') {
        return '';
    }
    if (strlen($data) > 10 * 1024 * 1024) {
        return '';
    }

    if (str_starts_with(ltrim($data), '{\\rtf')) {
        return student_import_extract_rtf_text($data);
    }

    $parts = [];

    // Old DOC/XLS files commonly store visible cell/text values as UTF-16LE.
    if (preg_match_all('/(?:(?:[\x20-\x7E]\x00)|(?:[\x00-\xFF]\x04)){2,}/s', $data, $matches)) {
        foreach ($matches[0] as $raw) {
            $value = trim(student_import_convert_to_utf8((string)$raw, 'UTF-16LE'));
            if ($value !== '' && preg_match('/[\p{L}]/u', $value)) {
                $parts[] = $value;
            }
            if (count($parts) >= 5000) break;
        }
    }

    // BIFF/legacy Word may also contain compressed Windows-1251 strings.
    if (preg_match_all('/[\x20-\x7E\xC0-\xFF\xA8\xB8]{4,}/s', $data, $matches)) {
        foreach ($matches[0] as $raw) {
            $value = trim(student_import_convert_to_utf8((string)$raw, 'Windows-1251'));
            if ($value !== '' && preg_match('/[\p{L}]/u', $value)) {
                $parts[] = $value;
            }
            if (count($parts) >= 8000) break;
        }
    }

    return implode("\n", array_values(array_unique($parts)));
}

function parse_legacy_office_students(string $tmpName): array
{
    $text = student_import_extract_legacy_office_text($tmpName);
    if ($text === '') {
        return [];
    }

    $lines = preg_split('/\n+/u', str_replace(["\r\n", "\r"], "\n", $text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $students = student_import_students_from_lines($lines);
    if ($students) {
        return $students;
    }

    // Some old XLS files store surname and first name in adjacent string cells.
    $fragments = array_values(array_filter(array_map(
        static fn(string $value): string => trim(preg_replace('/\s+/u', ' ', $value) ?? ''),
        $lines
    )));
    $candidates = [];
    for ($i = 0; $i < count($fragments) - 1 && count($candidates) < 4000; $i += 2) {
        $candidates[] = $fragments[$i] . ' ' . $fragments[$i + 1];
    }

    return student_import_students_from_lines($candidates);
}

function parse_pdf_students(string $tmpName): array
{
    if (!function_exists('import_extract_pdf_text')) {
        return [];
    }
    $text = import_extract_pdf_text($tmpName);
    return is_string($text) ? student_import_students_from_text($text) : [];
}

function parse_student_import_file(string $tmpName, string $extension): array
{
    return match ($extension) {
        'docx' => parse_docx_students($tmpName),
        'xlsx' => parse_xlsx_students($tmpName),
        'pdf' => parse_pdf_students($tmpName),
        'doc', 'xls' => parse_legacy_office_students($tmpName),
        default => [],
    };
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

    return student_import_students_from_lines($lines);
}

function require_school_class_for_roster_manager(PDO $pdo, array $user, int $classId): array
{
    $schoolId = require_active_school($user, false);

    if (can_manage_school($user, $schoolId)) {
        $stmt = $pdo->prepare(
            'SELECT c.id, COALESCE(c.display_name, c.name) AS name, c.school_id
             FROM classes c
             WHERE c.id = :class_id AND c.school_id = :school_id
             LIMIT 1'
        );
        $stmt->execute(['class_id' => $classId, 'school_id' => $schoolId]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT c.id, COALESCE(c.display_name, c.name) AS name, c.school_id
             FROM teacher_classes tc
             JOIN classes c ON c.id = tc.class_id
             WHERE tc.school_id = :school_id
               AND tc.teacher_id = :teacher_id
               AND tc.class_id = :class_id
             LIMIT 1'
        );
        $stmt->execute([
            'school_id' => $schoolId,
            'teacher_id' => (int)$user['id'],
            'class_id' => $classId,
        ]);
    }

    $class = $stmt->fetch();
    if (!$class) {
        json_response(['ok' => false, 'error' => 'Класс не найден или не назначен этому учителю.'], 404);
    }
    return $class;
}

function require_school_class_for_admin(PDO $pdo, array $user, int $classId): array
{
    return require_school_class_for_roster_manager($pdo, $user, $classId);
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
