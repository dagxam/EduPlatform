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

function import_docx_relationship_map(ZipArchive $zip): array
{
    $relsXml = $zip->getFromName('word/_rels/document.xml.rels');
    if ($relsXml === false) return [];

    $map = [];
    if (preg_match_all('/<Relationship\b[^>]*\bId="([^"]+)"[^>]*\bTarget="([^"]+)"[^>]*\/>/i', $relsXml, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $id = (string)$match[1];
            $target = ltrim(str_replace('\\', '/', (string)$match[2]), '/');
            if (str_starts_with($target, '../')) continue;
            if (!str_starts_with($target, 'media/')) continue;
            $map[$id] = 'word/' . $target;
        }
    }
    return $map;
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
    if ($xml === false) {
        $zip->close();
        return null;
    }

    $relationshipMap = import_docx_relationship_map($zip);
    $paragraphs = [];

    if (preg_match_all('/<w:p\b[^>]*>(.*?)<\/w:p>/si', $xml, $paragraphMatches)) {
        foreach ($paragraphMatches[1] as $paragraphXml) {
            $parts = [];
            if (preg_match_all('/<w:t\b[^>]*>(.*?)<\/w:t>/si', (string)$paragraphXml, $textMatches)) {
                foreach ($textMatches[1] as $part) {
                    $decoded = html_entity_decode(strip_tags((string)$part), ENT_QUOTES | ENT_XML1, 'UTF-8');
                    if ($decoded !== '') $parts[] = $decoded;
                }
            }

            $line = trim(preg_replace('/\s+/u', ' ', implode(' ', $parts)) ?? '');

            $imageIds = [];
            if (preg_match_all('/<a:blip\b[^>]*\br:embed="([^"]+)"/i', (string)$paragraphXml, $imageMatches)) {
                foreach ($imageMatches[1] as $relationshipId) {
                    if (isset($relationshipMap[$relationshipId])) {
                        $imageIds[] = (string)$relationshipId;
                    }
                }
            }

            if ($line !== '') {
                $paragraphs[] = $line;
            }
            foreach ($imageIds as $relationshipId) {
                $paragraphs[] = '[[UVORIA_IMAGE:' . $relationshipId . ']]';
            }
        }
    }

    $zip->close();
    $text = trim(implode("\n", $paragraphs));
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
    $inflatedBytes = 0;
    $decodedStreams = 0;
    if (preg_match_all('/stream\\r?\\n(.*?)\\r?\\nendstream/s', $data, $matches)) {
        foreach ($matches[1] as $stream) {
            if ($decodedStreams >= 100 || $inflatedBytes >= 16 * 1024 * 1024) {
                break;
            }

            // Limit decompressed output from hostile/oversized PDF streams.
            $decoded = @gzuncompress((string)$stream, 8 * 1024 * 1024);
            if ($decoded === false) {
                $decoded = @gzinflate((string)$stream, 8 * 1024 * 1024);
            }
            if ($decoded === false || !is_string($decoded)) {
                continue;
            }

            $decodedStreams++;
            $inflatedBytes += strlen($decoded);
            if ($inflatedBytes > 16 * 1024 * 1024) {
                break;
            }
            $chunks[] = $decoded;
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
    $relationshipByPath = [];
    if ($extension === 'docx') {
        foreach (import_docx_relationship_map($zip) as $relationshipId => $mediaPath) {
            $relationshipByPath[$mediaPath] = $relationshipId;
        }
    }

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
            'relationship_id' => $relationshipByPath[$name] ?? null,
        ];
    }

    $zip->close();
    return $media;
}

function import_normalize_option_label(string $value): string
{
    $value = strtoupper(trim($value));
    $value = trim($value, " .):;-");

    $latin = ['A','B','C','D','E','F','G','H'];
    $cyrillic = ['А','Б','В','Г','Д','Е','Ж','З'];

    $latinIndex = array_search($value, $latin, true);
    if ($latinIndex !== false) return (string)($latinIndex + 1);

    $cyrIndex = array_search($value, $cyrillic, true);
    if ($cyrIndex !== false) return (string)($cyrIndex + 1);

    if (preg_match('/^\d{1,2}$/', $value)) return (string)(int)$value;
    return $value;
}

function import_normalize_type(string $raw): string
{
    $value = function_exists('mb_strtolower') ? mb_strtolower(trim($raw)) : strtolower(trim($raw));
    $value = str_replace(['ё', '_'], ['е', ' '], $value);

    if (preg_match('/^(single|один|один правильный)/u', $value)) return 'single';
    if (preg_match('/^(multiple|multi|несколько|множеств)/u', $value)) return 'multiple';
    if (preg_match('/^(true false|truefalse|верно|неверно)/u', $value)) return 'true_false';
    if (preg_match('/^(order|ordering|порядок|хронолог)/u', $value)) return 'order';
    if (preg_match('/^(matching|match|соответ)/u', $value)) return 'matching';
    if (preg_match('/^(number|числ)/u', $value)) return 'number';
    if (preg_match('/^(correction|исправ|ошиб)/u', $value)) return 'correction';
    if (preg_match('/^(text|short|корот|слово|дата)/u', $value)) return 'text';

    // Старый формат оставляем читаемым, но новые шаблоны его не используют.
    if (preg_match('/^(essay|эссе|развернут)/u', $value)) return 'essay';

    return '';
}

function import_split_question_blocks(string $text): array
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[ \t]+$/m', '', $text) ?? $text;
    $lines = preg_split('/\n/u', $text) ?: [];

    $blocks = [];
    $current = [];
    $startedByHeading = false;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        if (preg_match('/^справочник\s+служебных\s+полей\s+uvoria/iu', $line)) {
            if ($current) $blocks[] = implode("\n", $current);
            $current = [];
            break;
        }

        $isQuestionMarker = preg_match('/^(?:вопрос|question|задание)\s*\d+\b/iu', $line) === 1;
        if ($isQuestionMarker) {
            if ($current) $blocks[] = implode("\n", $current);
            $current = [$line];
            $startedByHeading = true;
            continue;
        }

        $current[] = $line;

        // Практический формат учителей: вопрос + варианты + TYPE + ANSWER + POINTS,
        // без обязательных заголовков «ЗАДАНИЕ N». POINTS завершает текущий вопрос.
        if (!$startedByHeading && preg_match('/^(?:баллы|points?)\s*:\s*[0-9]+(?:[.,][0-9]+)?$/iu', $line)) {
            $blocks[] = implode("\n", $current);
            $current = [];
        }
    }

    if ($current) $blocks[] = implode("\n", $current);

    if (!$blocks) {
        foreach (preg_split('/\n\s*---+\s*\n|\n{2,}/u', $text) ?: [] as $raw) {
            $raw = trim($raw);
            if ($raw !== '') $blocks[] = $raw;
        }
    }

    return array_values(array_filter($blocks, static fn(string $block): bool => trim($block) !== ''));
}

function import_prompt_candidate(array $lines): string
{
    foreach ($lines as $line) {
        $value = trim((string)$line);
        if ($value === '') continue;
        if (
            str_contains($value, '?')
            || preg_match('/^(?:расположите|сопоставьте|исправьте|рассмотрите|выберите|укажите|найдите|определите|в\s+каком|какие|какой|какая|какое|как|когда|где|кто|что|сколько)\b/iu', $value)
        ) {
            return $value;
        }
    }

    $filtered = array_values(array_filter($lines, static function ($line): bool {
        $value = trim((string)$line);
        if ($value === '') return false;
        return preg_match('/^(?:тест\s*:|ученик\b|картинка\b|изображение\s*\+|событие\s*↔|один\s+правильный|несколько\s+правильных|восстановить\s+хронологию|соответствие\s*:|короткий\s+ответ\s*:|найти\s+и\s+исправить|вопрос\s+по\s+изображению)/iu', $value) !== 1;
    }));

    return trim((string)($filtered[0] ?? $lines[0] ?? ''));
}

function import_parse_question_block(string $block): ?array
{
    $lines = preg_split('/\n/u', trim($block)) ?: [];
    if (!$lines) return null;

    $rawType = '';
    $answer = '';
    $orderRaw = '';
    $pairsRaw = '';
    $alternativesRaw = '';
    $points = 1.0;
    $textBody = '';
    $contentLines = [];
    $heading = '';
    $mediaRelationshipIds = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        if (preg_match('/^(?:вопрос|question|задание)\s*\d+\b\s*[:.)-]?\s*(.*)$/iu', $line, $m)) {
            $heading = trim((string)($m[1] ?? ''));
            continue;
        }
        if (preg_match('/^(?:тип|type)\s*:\s*(.+)$/iu', $line, $m)) {
            $rawType = trim($m[1]);
            continue;
        }
        if (preg_match('/^(?:order|порядок)\s*:\s*(.+)$/iu', $line, $m)) {
            $orderRaw = trim($m[1]);
            continue;
        }
        if (preg_match('/^(?:pairs|пары)\s*:\s*(.+)$/iu', $line, $m)) {
            $pairsRaw = trim($m[1]);
            continue;
        }
        if (preg_match('/^(?:alternatives|альтернативы|варианты ответа)\s*:\s*(.+)$/iu', $line, $m)) {
            $alternativesRaw = trim($m[1]);
            continue;
        }
        if (preg_match('/^(?:ответ|answer|правильный ответ)\s*:\s*(.+)$/iu', $line, $m)) {
            $answer = trim($m[1]);
            continue;
        }
        if (preg_match('/^(?:баллы|points?)\s*:\s*([0-9]+(?:[.,][0-9]+)?)$/iu', $line, $m)) {
            $points = max(0.1, (float)str_replace(',', '.', $m[1]));
            continue;
        }
        if (preg_match('/^(?:текст|text)\s*:\s*(.+)$/iu', $line, $m)) {
            $textBody = trim($m[1]);
            continue;
        }
        if (preg_match('/^\[\[UVORIA_IMAGE:([^\]]+)\]\]$/i', $line, $m)) {
            $mediaRelationshipIds[] = trim((string)$m[1]);
            continue;
        }
        if (preg_match('/^примечание\s*:/iu', $line)) {
            continue;
        }
        if (preg_match('/^слайд\s+\d+\s*:\s*(.*)$/iu', $line, $m)) {
            if (trim($m[1]) !== '') $contentLines[] = trim($m[1]);
            continue;
        }

        $contentLines[] = $line;
    }

    $interaction = import_normalize_type($rawType);

    $alphaOptions = [];
    $numberOptions = [];
    $bulletItems = [];
    $freeLines = [];

    foreach ($contentLines as $line) {
        if (preg_match('/^([A-HА-З])\s*[).:-]\s*(.+)$/u', $line, $m)) {
            $alphaOptions[strtoupper($m[1])] = trim($m[2]);
        } elseif (preg_match('/^(\d{1,2})\s*[).:-]\s*(.+)$/u', $line, $m)) {
            $numberOptions[(string)(int)$m[1]] = trim($m[2]);
        } elseif (preg_match('/^[•·\-–—]\s*(.+)$/u', $line, $m)) {
            $bulletItems[] = trim($m[1]);
        } else {
            $freeLines[] = $line;
        }
    }

    if ($interaction === '') {
        if (count($alphaOptions) >= 2 && $answer !== '') {
            $tokens = preg_split('/[|,;\s]+/u', strtoupper($answer), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $tokens = array_values(array_unique(array_map(
                static fn(string $v): string => import_normalize_option_label($v),
                $tokens
            )));
            $interaction = count($tokens) > 1 ? 'multiple' : 'single';
        } elseif ($orderRaw !== '') {
            $interaction = 'order';
        } elseif ($pairsRaw !== '') {
            $interaction = 'matching';
        } elseif ($answer !== '') {
            $interaction = 'text';
        } else {
            $interaction = 'essay';
        }
    }

    $prompt = $textBody !== '' ? $textBody : import_prompt_candidate($freeLines);
    if ($prompt === '') return null;

    $haystack = $heading . "\n" . implode("\n", $contentLines);
    $needsImage = !empty($mediaRelationshipIds)
        || preg_match('/(?:изображ|картин|фото|портрет|карта|схема)/iu', $haystack) === 1;

    $question = [
        'interaction_type' => $interaction,
        'db_type' => 'text',
        'text' => $prompt,
        'points' => $points,
        'correct_text' => null,
        'settings' => [],
        'options' => [],
        'needs_image' => $needsImage,
        'media_relationship_ids' => $mediaRelationshipIds,
    ];

    if ($interaction === 'single' || $interaction === 'multiple') {
        $options = $alphaOptions ?: $numberOptions;
        if (count($options) < 2) return null;

        $question['db_type'] = $interaction;
        $tokens = preg_split('/[|,;\s]+/u', strtoupper($answer), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_values(array_unique(array_map(
            static fn(string $v): string => import_normalize_option_label($v),
            $tokens
        )));

        foreach ($options as $label => $optionText) {
            $question['options'][] = [
                'label' => (string)$label,
                'text' => $optionText,
                'is_correct' => in_array(import_normalize_option_label((string)$label), $tokens, true),
            ];
        }
    } elseif ($interaction === 'true_false') {
        $question['db_type'] = 'true_false';
        $normalized = function_exists('mb_strtolower') ? mb_strtolower($answer) : strtolower($answer);
        $truthy = preg_match('/^(верно|да|true|1)$/u', trim($normalized)) === 1;
        $question['options'] = [
            ['label' => 'TRUE', 'text' => 'Верно', 'is_correct' => $truthy],
            ['label' => 'FALSE', 'text' => 'Неверно', 'is_correct' => !$truthy],
        ];
    } elseif ($interaction === 'order') {
        $orderedValues = array_values(array_filter(array_map(
            static fn(string $value): string => trim($value),
            preg_split('/\s*\|\s*/u', $orderRaw !== '' ? $orderRaw : $answer) ?: []
        ), static fn(string $value): bool => $value !== ''));

        $displayValues = count($bulletItems) >= 2 ? $bulletItems : $orderedValues;
        if (count($displayValues) < 2) {
            $displayValues = array_values($numberOptions);
        }
        if (count($displayValues) < 2) return null;
        if (!$orderedValues) $orderedValues = $displayValues;

        $items = [];
        $keyByText = [];
        foreach ($displayValues as $i => $value) {
            $key = (string)($i + 1);
            $items[$key] = $value;
            $normalizedKey = function_exists('mb_strtolower') ? mb_strtolower(trim($value)) : strtolower(trim($value));
            $keyByText[$normalizedKey] = $key;
        }

        $correctOrder = [];
        foreach ($orderedValues as $value) {
            $normalizedKey = function_exists('mb_strtolower') ? mb_strtolower(trim($value)) : strtolower(trim($value));
            if (!isset($keyByText[$normalizedKey])) {
                $key = (string)(count($items) + 1);
                $items[$key] = $value;
                $keyByText[$normalizedKey] = $key;
            }
            $correctOrder[] = $keyByText[$normalizedKey];
        }

        $question['correct_text'] = json_encode($correctOrder, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $question['settings'] = ['items' => $items, 'correct_order' => $correctOrder];
    } elseif ($interaction === 'matching') {
        $left = [];
        $right = [];
        $pairs = [];

        if ($pairsRaw !== '') {
            $pairParts = preg_split('/\s*\|\s*/u', $pairsRaw) ?: [];
            $index = 0;
            foreach ($pairParts as $pairText) {
                if (!preg_match('/^\s*(.+?)\s*=\s*(.+?)\s*$/u', trim($pairText), $m)) continue;
                $index++;
                $lk = 'L' . $index;
                $rk = 'R' . $index;
                $left[$lk] = trim($m[1]);
                $right[$rk] = trim($m[2]);
                $pairs[$lk] = $rk;
            }
        } else {
            foreach ($numberOptions as $key => $value) $left[(string)$key] = $value;
            foreach ($alphaOptions as $key => $value) $right[(string)$key] = $value;
            foreach (preg_split('/[,;]+/u', $answer, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $pair) {
                if (preg_match('/(\d+)\s*[-:=]\s*([A-HА-З])/u', trim($pair), $m)) {
                    $pairs[(string)(int)$m[1]] = strtoupper($m[2]);
                }
            }
        }

        if (count($pairs) < 2) return null;
        $question['correct_text'] = json_encode($pairs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $question['settings'] = ['left' => $left, 'right' => $right, 'pairs' => $pairs];
    } elseif ($interaction === 'correction') {
        $original = '';
        foreach ($freeLines as $line) {
            if ($line === $prompt) continue;
            if (
                preg_match('/^(?:ученик\b|найти\s+и\s+исправить)/iu', $line)
                || preg_match('/^исправьте\b/iu', $line)
            ) continue;
            $original = trim($line);
            if ($original !== '') break;
        }
        if ($textBody !== '' && $textBody !== $prompt) $original = $textBody;
        $question['settings']['original_text'] = $original;
        $question['correct_text'] = $answer;
    } elseif ($interaction === 'number') {
        $question['db_type'] = 'number';
        $question['correct_text'] = str_replace(',', '.', trim($answer));
    } elseif ($interaction === 'essay') {
        $question['db_type'] = 'essay';
        $question['correct_text'] = null;
    } else {
        $answers = [];
        foreach (array_merge(
            preg_split('/\s*\|\s*/u', $answer) ?: [],
            preg_split('/\s*\|\s*/u', $alternativesRaw) ?: []
        ) as $value) {
            $value = trim((string)$value);
            if ($value === '') continue;
            $key = function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
            $answers[$key] = $value;
        }
        $question['correct_text'] = implode(' | ', array_values($answers));
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

function import_validate_questions(array $questions): array
{
    $issues = [];
    $supported = ['single', 'multiple', 'order', 'matching', 'text', 'number', 'correction', 'true_false'];

    foreach (array_values($questions) as $index => $question) {
        $number = $index + 1;
        $interaction = (string)($question['interaction_type'] ?? '');

        if (!in_array($interaction, $supported, true)) {
            $issues[] = "Вопрос {$number}: неподдерживаемый TYPE «{$interaction}».";
            continue;
        }

        if (in_array($interaction, ['single', 'multiple', 'true_false'], true)) {
            $options = is_array($question['options'] ?? null) ? $question['options'] : [];
            $correctCount = count(array_filter(
                $options,
                static fn(array $option): bool => !empty($option['is_correct'])
            ));
            if (count($options) < 2) {
                $issues[] = "Вопрос {$number}: нужно минимум два варианта ответа.";
            } elseif ($interaction === 'single' && $correctCount !== 1) {
                $issues[] = "Вопрос {$number}: поле ANSWER должно указывать ровно один правильный вариант.";
            } elseif ($interaction === 'multiple' && $correctCount < 1) {
                $issues[] = "Вопрос {$number}: в ANSWER не распознан ни один правильный вариант.";
            } elseif ($interaction === 'true_false' && $correctCount !== 1) {
                $issues[] = "Вопрос {$number}: для true_false нужен один правильный ANSWER.";
            }
            continue;
        }

        $correctText = trim((string)($question['correct_text'] ?? ''));
        if ($interaction === 'order') {
            $order = json_decode($correctText, true);
            if (!is_array($order) || count($order) < 2) {
                $issues[] = "Вопрос {$number}: поле ORDER не распознано.";
            }
        } elseif ($interaction === 'matching') {
            $pairs = json_decode($correctText, true);
            if (!is_array($pairs) || count($pairs) < 2) {
                $issues[] = "Вопрос {$number}: поле PAIRS не распознано.";
            }
        } elseif ($interaction === 'number') {
            if ($correctText === '' || !is_numeric($correctText)) {
                $issues[] = "Вопрос {$number}: числовой ANSWER не распознан.";
            }
        } elseif ($correctText === '') {
            $issues[] = "Вопрос {$number}: правильный ANSWER не распознан.";
        }
    }

    return $issues;
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
        $requestedRelationships = array_values(array_filter(array_map(
            'strval',
            (array)($question['media_relationship_ids'] ?? [])
        )));

        if ($requestedRelationships && $media) {
            foreach ($media as $candidate) {
                if (in_array((string)($candidate['relationship_id'] ?? ''), $requestedRelationships, true)) {
                    $assetToSave = $candidate;
                    break;
                }
            }
        }

        if ($assetToSave === null && !empty($question['needs_image']) && $media) {
            $safeIndex = min($mediaIndex, count($media) - 1);
            $assetToSave = $media[$safeIndex];
            if ($mediaIndex < count($media) - 1) $mediaIndex++;
        }

        if ($assetToSave !== null) {
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
