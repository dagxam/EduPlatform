<?php
declare(strict_types=1);

function import_xml_text_by_paragraph(string $xml, string $paragraphTag, string $textTag): string
{
    $paragraphs = [];
    $paragraphPattern = '/<' . preg_quote($paragraphTag, '/') . '\\b[^>]*>(.*?)<\\/' . preg_quote($paragraphTag, '/') . '>/si';
    if (!preg_match_all($paragraphPattern, $xml, $paragraphMatches)) {
        return '';
    }

    $textPattern = '/<' . preg_quote($textTag, '/') . '\\b[^>]*>(.*?)<\\/' . preg_quote($textTag, '/') . '>/si';
    foreach ($paragraphMatches[1] as $paragraphXml) {
        $parts = [];
        if (preg_match_all($textPattern, (string)$paragraphXml, $textMatches)) {
            foreach ($textMatches[1] as $part) {
                $decoded = html_entity_decode(strip_tags((string)$part), ENT_QUOTES | ENT_XML1, 'UTF-8');
                if ($decoded !== '') $parts[] = $decoded;
            }
        }
        $line = trim(preg_replace('/\\s+/u', ' ', implode(' ', $parts)) ?? '');
        if ($line !== '') $paragraphs[] = $line;
    }

    return trim(implode("\n", $paragraphs));
}

function import_extract_docx_text(string $path): ?string
{
    if (!class_exists('ZipArchive')) return null;
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return null;

    $stat = $zip->statName('word/document.xml');
    if (!$stat || (int)($stat['size'] ?? 0) > 6 * 1024 * 1024) {
        $zip->close();
        return null;
    }

    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false) return null;

    $text = import_xml_text_by_paragraph($xml, 'w:p', 'w:t');
    return $text !== '' ? $text : null;
}

function import_extract_pptx_text(string $path): ?string
{
    if (!class_exists('ZipArchive')) return null;
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return null;

    $slides = [];
    $totalXmlBytes = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string)$zip->getNameIndex($i);
        if (!preg_match('#^ppt/slides/slide(\\d+)\\.xml$#', $name, $m)) continue;

        $stat = $zip->statIndex($i);
        $totalXmlBytes += (int)($stat['size'] ?? 0);
        if ($totalXmlBytes > 10 * 1024 * 1024) {
            $zip->close();
            return null;
        }
        $slides[(int)$m[1]] = $name;
    }

    ksort($slides);
    $texts = [];
    foreach ($slides as $number => $name) {
        $xml = $zip->getFromName($name);
        if ($xml === false) continue;
        $slideText = import_xml_text_by_paragraph($xml, 'a:p', 'a:t');
        if ($slideText !== '') {
            $texts[] = "Слайд {$number}:\n" . $slideText;
        }
    }
    $zip->close();

    $text = trim(implode("\n\n---\n\n", $texts));
    return $text !== '' ? $text : null;
}

function import_decode_pdf_literal(string $value): string
{
    $value = preg_replace_callback('/\\\\([0-7]{1,3})/', static function (array $m): string {
        return chr(octdec($m[1]));
    }, $value) ?? $value;

    $value = str_replace(
        ['\\n', '\\r', '\\t', '\\b', '\\f', '\\(', '\\)', '\\\\'],
        ["\n", "\r", "\t", "\b", "\f", '(', ')', '\\'],
        $value
    );

    if (str_starts_with($value, "\xFE\xFF") && function_exists('mb_convert_encoding')) {
        $converted = @mb_convert_encoding(substr($value, 2), 'UTF-8', 'UTF-16BE');
        if (is_string($converted)) return $converted;
    }
    if (str_starts_with($value, "\xFF\xFE") && function_exists('mb_convert_encoding')) {
        $converted = @mb_convert_encoding(substr($value, 2), 'UTF-8', 'UTF-16LE');
        if (is_string($converted)) return $converted;
    }

    return $value;
}

function import_extract_pdf_text(string $path): ?string
{
    $data = @file_get_contents($path);
    if ($data === false || $data === '') return null;

    $chunks = [$data];
    if (preg_match_all('/stream\\r?\\n(.*?)\\r?\\nendstream/s', $data, $matches)) {
        foreach ($matches[1] as $stream) {
            $decoded = @gzuncompress((string)$stream);
            if ($decoded === false) $decoded = @gzinflate((string)$stream);
            if ($decoded !== false && is_string($decoded)) $chunks[] = $decoded;
        }
    }

    $parts = [];
    foreach ($chunks as $chunk) {
        if (preg_match_all('/\\((?:\\\\.|[^\\)]){2,}\\)/s', (string)$chunk, $literalMatches)) {
            foreach ($literalMatches[0] as $literal) {
                $value = substr((string)$literal, 1, -1);
                $value = trim(import_decode_pdf_literal($value));
                if ($value !== '' && preg_match('/[\\p{L}\\p{N}]/u', $value)) {
                    $parts[] = $value;
                }
            }
        }

        if (preg_match_all('/<([0-9A-Fa-f]{8,})>/', (string)$chunk, $hexMatches)) {
            foreach ($hexMatches[1] as $hex) {
                if (strlen($hex) % 2 !== 0) continue;
                $bytes = @hex2bin((string)$hex);
                if ($bytes === false) continue;
                if (str_starts_with($bytes, "\xFE\xFF") && function_exists('mb_convert_encoding')) {
                    $converted = @mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE');
                    if (is_string($converted) && trim($converted) !== '') $parts[] = trim($converted);
                }
            }
        }
    }

    $text = trim(preg_replace('/[ \\t]+/u', ' ', implode("\n", $parts)) ?? '');
    $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    return $length >= 20 ? $text : null;
}

function import_extract_ppt_binary_text(string $path): ?string
{
    $data = @file_get_contents($path);
    if ($data === false || $data === '') return null;

    $parts = [];

    if (preg_match_all('/(?:[\\x20-\\x7E]\\x00){4,}/', $data, $unicodeMatches)) {
        foreach ($unicodeMatches[0] as $raw) {
            $converted = function_exists('mb_convert_encoding')
                ? @mb_convert_encoding((string)$raw, 'UTF-8', 'UTF-16LE')
                : str_replace("\x00", '', (string)$raw);
            if (is_string($converted) && trim($converted) !== '') $parts[] = trim($converted);
        }
    }

    if (preg_match_all('/[\\x20-\\x7E]{6,}/', $data, $asciiMatches)) {
        foreach ($asciiMatches[0] as $raw) {
            $value = trim((string)$raw);
            if (preg_match('/[A-Za-z0-9]/', $value)) $parts[] = $value;
        }
    }

    $parts = array_values(array_unique($parts));
    $text = trim(implode("\n", $parts));
    return strlen($text) >= 20 ? $text : null;
}

function import_extract_text(string $path, string $extension): ?string
{
    return match ($extension) {
        'docx' => import_extract_docx_text($path),
        'pptx' => import_extract_pptx_text($path),
        'pdf' => import_extract_pdf_text($path),
        'ppt' => import_extract_ppt_binary_text($path),
        default => null,
    };
}

function import_extract_media(string $path, string $extension): array
{
    if (!in_array($extension, ['docx', 'pptx'], true) || !class_exists('ZipArchive')) {
        return [];
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return [];

    $prefix = $extension === 'docx' ? 'word/media/' : 'ppt/media/';
    $media = [];
    $totalBytes = 0;

    for ($i = 0; $i < $zip->numFiles && count($media) < 20; $i++) {
        $name = (string)$zip->getNameIndex($i);
        if (!str_starts_with($name, $prefix)) continue;

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => null,
        };
        if ($mime === null) continue;

        $stat = $zip->statIndex($i);
        $size = (int)($stat['size'] ?? 0);
        if ($size <= 0 || $size > 5 * 1024 * 1024) continue;
        $totalBytes += $size;
        if ($totalBytes > 12 * 1024 * 1024) break;

        $bytes = $zip->getFromIndex($i);
        if ($bytes === false) continue;

        $media[] = [
            'original_name' => basename($name),
            'mime_type' => $mime,
            'extension' => $ext === 'jpeg' ? 'jpg' : $ext,
            'bytes' => $bytes,
        ];
    }

    $zip->close();
    return $media;
}

function import_normalize_type(string $raw): string
{
    $value = function_exists('mb_strtolower') ? mb_strtolower(trim($raw)) : strtolower(trim($raw));
    $value = str_replace(['ё', '_'], ['е', ' '], $value);

    if (preg_match('/(несколько|множеств|multiple|multi)/u', $value)) return 'multiple';
    if (preg_match('/(верно|неверно|true|false)/u', $value)) return 'true_false';
    if (preg_match('/(хронолог|порядок|ordering|order)/u', $value)) return 'ordering';
    if (preg_match('/(соответ|matching|match)/u', $value)) return 'matching';
    if (preg_match('/(ошиб|исправ|correction|correct error)/u', $value)) return 'correction';
    if (preg_match('/(изображ|картин|фото|image|picture)/u', $value)) return 'image_answer';
    if (preg_match('/(эссе|развернут|essay)/u', $value)) return 'essay';
    if (preg_match('/(корот|слово|дата|определен|short|text|number)/u', $value)) return 'short_answer';
    if (preg_match('/(тест|один ответ|single|choice)/u', $value)) return 'single';

    return '';
}

function import_split_question_blocks(string $text): array
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[ \\t]+$/m', '', $text) ?? $text;

    $rawBlocks = preg_split('/\\n\\s*---+\\s*\\n|\\n{2,}/u', $text) ?: [];
    $blocks = [];

    foreach ($rawBlocks as $raw) {
        $raw = trim($raw);
        if ($raw === '') continue;

        $lines = preg_split('/\\n/u', $raw) ?: [];
        $current = [];
        $hasQuestionMarker = false;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            $isQuestionMarker = preg_match('/^(?:вопрос|question|задание)\\s*\\d*\\s*[:.)-]/iu', $line) === 1;
            if ($current && $isQuestionMarker && $hasQuestionMarker) {
                $blocks[] = implode("\n", $current);
                $current = [];
                $hasQuestionMarker = false;
            }

            $current[] = $line;
            if ($isQuestionMarker) $hasQuestionMarker = true;
        }
        if ($current) $blocks[] = implode("\n", $current);
    }

    return $blocks;
}

function import_parse_question_block(string $block): ?array
{
    $lines = preg_split('/\\n/u', trim($block)) ?: [];
    if (!$lines) return null;

    $rawType = '';
    $prompt = '';
    $answer = '';
    $points = 1.0;
    $textBody = '';
    $contentLines = [];
    $answerLineIndex = null;

    foreach ($lines as $index => $line) {
        $line = trim($line);
        if ($line === '') continue;

        if (preg_match('/^(?:тип|type)\\s*:\\s*(.+)$/iu', $line, $m)) {
            $rawType = trim($m[1]);
            continue;
        }
        if (preg_match('/^(?:вопрос|question|задание)\\s*\\d*\\s*[:.)-]\\s*(.+)$/iu', $line, $m)) {
            $prompt = trim($m[1]);
            continue;
        }
        if (preg_match('/^(?:ответ|answer|правильный ответ|порядок)\\s*:\\s*(.+)$/iu', $line, $m)) {
            $answer = trim($m[1]);
            $answerLineIndex = $index;
            continue;
        }
        if (preg_match('/^(?:баллы|points?)\\s*:\\s*([0-9]+(?:[.,][0-9]+)?)$/iu', $line, $m)) {
            $points = max(0.1, (float)str_replace(',', '.', $m[1]));
            continue;
        }
        if (preg_match('/^(?:текст|text)\\s*:\\s*(.+)$/iu', $line, $m)) {
            $textBody = trim($m[1]);
            continue;
        }
        if (preg_match('/^слайд\\s+\\d+\\s*:\\s*(.*)$/iu', $line, $m)) {
            if ($prompt === '' && trim($m[1]) !== '') $contentLines[] = trim($m[1]);
            continue;
        }

        $contentLines[] = $line;
    }

    $interaction = import_normalize_type($rawType);

    $alphaOptions = [];
    $numberOptions = [];
    $freeLines = [];
    foreach ($contentLines as $line) {
        if (preg_match('/^([A-HА-З])\\s*[).:-]\\s*(.+)$/u', $line, $m)) {
            $alphaOptions[strtoupper($m[1])] = trim($m[2]);
        } elseif (preg_match('/^(\\d{1,2})\\s*[).:-]\\s*(.+)$/u', $line, $m)) {
            $numberOptions[(string)(int)$m[1]] = trim($m[2]);
        } else {
            $freeLines[] = $line;
        }
    }

    if ($prompt === '') {
        if ($textBody !== '') {
            $prompt = $textBody;
        } elseif ($freeLines) {
            $prompt = array_shift($freeLines);
        }
    }

    if ($interaction === '') {
        if (count($alphaOptions) >= 2 && $answer !== '') {
            $answerTokens = preg_split('/[,;\\s]+/u', strtoupper($answer), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $interaction = count($answerTokens) > 1 ? 'multiple' : 'single';
        } elseif ($answer !== '') {
            $interaction = 'short_answer';
        } else {
            $interaction = 'essay';
        }
    }

    if ($prompt === '') return null;

    $question = [
        'interaction_type' => $interaction,
        'db_type' => 'text',
        'text' => $prompt,
        'points' => $points,
        'correct_text' => $answer !== '' ? $answer : null,
        'settings' => [],
        'options' => [],
        'needs_image' => false,
    ];

    if ($interaction === 'single' || $interaction === 'multiple') {
        $options = $alphaOptions ?: $numberOptions;
        if (count($options) < 2) return null;

        $question['db_type'] = $interaction;
        $tokens = preg_split('/[,;\\s]+/u', strtoupper($answer), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_map(static fn(string $v): string => trim($v, " .)"), $tokens);
        foreach ($options as $label => $optionText) {
            $question['options'][] = [
                'label' => (string)$label,
                'text' => $optionText,
                'is_correct' => in_array(strtoupper((string)$label), $tokens, true),
            ];
        }
        $question['correct_text'] = null;
    } elseif ($interaction === 'true_false') {
        $question['db_type'] = 'true_false';
        $normalized = function_exists('mb_strtolower') ? mb_strtolower($answer) : strtolower($answer);
        $truthy = preg_match('/^(верно|да|true|1)$/u', trim($normalized)) === 1;
        $question['options'] = [
            ['label' => 'TRUE', 'text' => 'Верно', 'is_correct' => $truthy],
            ['label' => 'FALSE', 'text' => 'Неверно', 'is_correct' => !$truthy],
        ];
        $question['correct_text'] = null;
    } elseif ($interaction === 'ordering') {
        $items = $numberOptions;
        if (!$items && $freeLines) {
            foreach (array_values($freeLines) as $i => $line) $items[(string)($i + 1)] = $line;
        }
        if (count($items) < 2) return null;

        $order = preg_split('/[,;>\\-\\s]+/u', $answer, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $order = array_values(array_filter(array_map(static fn(string $v): string => trim($v, " .)"), $order), static fn(string $v): bool => isset($items[$v])));
        if (!$order) $order = array_keys($items);

        $question['db_type'] = 'text';
        $question['correct_text'] = json_encode($order, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $question['settings'] = [
            'items' => $items,
            'correct_order' => $order,
        ];
    } elseif ($interaction === 'matching') {
        if (count($numberOptions) < 1 || count($alphaOptions) < 1) return null;

        $pairs = [];
        foreach (preg_split('/[,;]+/u', $answer, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $pair) {
            if (preg_match('/(\\d+)\\s*[-:=]\\s*([A-HА-З])/u', trim($pair), $m)) {
                $pairs[(string)(int)$m[1]] = strtoupper($m[2]);
            }
        }

        $question['db_type'] = 'text';
        $question['correct_text'] = json_encode($pairs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $question['settings'] = [
            'left' => $numberOptions,
            'right' => $alphaOptions,
            'pairs' => $pairs,
        ];
    } elseif ($interaction === 'correction') {
        $question['db_type'] = 'text';
        if ($textBody !== '') {
            $question['settings']['original_text'] = $textBody;
            if ($question['text'] === $textBody) $question['text'] = 'Найдите ошибку и напишите правильный вариант.';
        } elseif ($freeLines) {
            $question['settings']['original_text'] = implode(' ', $freeLines);
        }
    } elseif ($interaction === 'image_answer') {
        $question['db_type'] = 'text';
        $question['needs_image'] = true;
        $question['settings']['answer_hint'] = 'Событие / год / место / объект';
    } elseif ($interaction === 'essay') {
        $question['db_type'] = 'essay';
        $question['correct_text'] = null;
    } else {
        $question['db_type'] = 'text';
    }

    return $question;
}

function import_parse_questions(string $text): array
{
    $questions = [];
    foreach (import_split_question_blocks($text) as $block) {
        $question = import_parse_question_block($block);
        if ($question !== null) $questions[] = $question;
    }
    return $questions;
}

function import_store_questions(PDO $pdo, int $assignmentId, array $questions, array $media = []): array
{
    $insertQuestion = $pdo->prepare(
        'INSERT INTO questions
         (assignment_id, type, text, points, position, correct_text, interaction_type, settings_json)
         VALUES
         (:assignment_id, :type, :text, :points, :position, :correct_text, :interaction_type, :settings_json)'
    );
    $insertOption = $pdo->prepare(
        'INSERT INTO question_options (question_id, text, is_correct, position)
         VALUES (:question_id, :text, :is_correct, :position)'
    );
    $insertAsset = $pdo->prepare(
        'INSERT INTO question_assets (question_id, stored_name, original_name, mime_type, position)
         VALUES (:question_id, :stored_name, :original_name, :mime_type, :position)'
    );

    $assetDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'question-assets';
    if (!is_dir($assetDir) && !mkdir($assetDir, 0775, true) && !is_dir($assetDir)) {
        throw new RuntimeException('Не удалось подготовить хранилище изображений заданий.');
    }

    $mediaIndex = 0;
    $saved = 0;
    $typeCounts = [];

    foreach ($questions as $position => $question) {
        $settings = $question['settings'] ?? [];
        $assetToSave = null;
        if (!empty($question['needs_image']) && isset($media[$mediaIndex])) {
            $assetToSave = $media[$mediaIndex++];
            $settings['has_image'] = true;
        } elseif (!empty($question['needs_image'])) {
            $settings['has_image'] = false;
        }

        $insertQuestion->execute([
            'assignment_id' => $assignmentId,
            'type' => $question['db_type'],
            'text' => $question['text'],
            'points' => (float)$question['points'],
            'position' => $position + 1,
            'correct_text' => $question['correct_text'],
            'interaction_type' => $question['interaction_type'],
            'settings_json' => $settings ? json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ]);
        $questionId = (int)$pdo->lastInsertId();

        foreach (($question['options'] ?? []) as $optionPosition => $option) {
            $insertOption->execute([
                'question_id' => $questionId,
                'text' => (string)$option['text'],
                'is_correct' => !empty($option['is_correct']) ? 1 : 0,
                'position' => $optionPosition + 1,
            ]);
        }

        if ($assetToSave !== null) {
            $storedName = 'question-' . $questionId . '-' . bin2hex(random_bytes(8)) . '.' . $assetToSave['extension'];
            $destination = $assetDir . DIRECTORY_SEPARATOR . $storedName;
            if (file_put_contents($destination, $assetToSave['bytes']) !== false) {
                $insertAsset->execute([
                    'question_id' => $questionId,
                    'stored_name' => $storedName,
                    'original_name' => $assetToSave['original_name'],
                    'mime_type' => $assetToSave['mime_type'],
                    'position' => 1,
                ]);
            }
        }

        $saved++;
        $kind = (string)$question['interaction_type'];
        $typeCounts[$kind] = ($typeCounts[$kind] ?? 0) + 1;
    }

    return [
        'count' => $saved,
        'types' => $typeCounts,
    ];
}
