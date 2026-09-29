<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/api/bootstrap.php';
require dirname(__DIR__, 2) . '/api/assignments/_import_parser.php';
require dirname(__DIR__, 2) . '/api/classes/_student-import.php';

function roster_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Student roster import smoke failed: {$message}\n");
        exit(1);
    }
}

function roster_names(array $students): array
{
    return array_map(
        static fn(array $student): string => $student['last_name'] . ' ' . $student['first_name'],
        $students
    );
}

function roster_make_zip(string $path, array $entries): void
{
    $zip = new ZipArchive();
    roster_assert($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'cannot create ZIP fixture');
    foreach ($entries as $name => $bytes) {
        $zip->addFromString($name, $bytes);
    }
    $zip->close();
}

$tmpDir = sys_get_temp_dir() . '/urovia-roster-' . bin2hex(random_bytes(5));
roster_assert(mkdir($tmpDir, 0777, true), 'cannot create temp dir');

try {
    $docx = $tmpDir . '/students.docx';
    roster_make_zip($docx, [
        '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>',
        'word/document.xml' => '<?xml version="1.0" encoding="UTF-8"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            . '<w:p><w:r><w:t>Магомедов Али</w:t></w:r></w:p>'
            . '<w:p><w:r><w:t>Абдуллаева Амина</w:t></w:r></w:p>'
            . '</w:body></w:document>',
    ]);
    $docxStudents = parse_student_import_file($docx, 'docx');
    roster_assert(roster_names($docxStudents) === ['Магомедов Али', 'Абдуллаева Амина'], 'DOCX parsing');

    $xlsx = $tmpDir . '/students.xlsx';
    roster_make_zip($xlsx, [
        '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>',
        'xl/sharedStrings.xml' => '<?xml version="1.0" encoding="UTF-8"?>'
            . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="5" uniqueCount="5">'
            . '<si><t>Фамилия</t></si><si><t>Имя</t></si>'
            . '<si><t>Хаджиев</t></si><si><t>Омар</t></si><si><t>Саидова Марьям</t></si>'
            . '</sst>',
        'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            . '<row r="1"><c t="s"><v>0</v></c><c t="s"><v>1</v></c></row>'
            . '<row r="2"><c><v>1</v></c><c t="s"><v>2</v></c><c t="s"><v>3</v></c></row>'
            . '<row r="3"><c><v>2</v></c><c t="s"><v>4</v></c></row>'
            . '</sheetData></worksheet>',
    ]);
    $xlsxStudents = parse_student_import_file($xlsx, 'xlsx');
    roster_assert(roster_names($xlsxStudents) === ['Хаджиев Омар', 'Саидова Марьям'], 'XLSX parsing');

    $pdf = $tmpDir . '/students.pdf';
    file_put_contents(
        $pdf,
        "%PDF-1.4\n1 0 obj\n<< /Length 80 >>\nstream\nBT (Курбанов Ахмад) Tj ET\nBT (Ахмедова Патимат) Tj ET\nendstream\nendobj\n%%EOF"
    );
    $pdfStudents = parse_student_import_file($pdf, 'pdf');
    roster_assert(roster_names($pdfStudents) === ['Курбанов Ахмад', 'Ахмедова Патимат'], 'PDF parsing');

    $legacyText = "Ибрагимов Магомед\nГаджиева Алия\n";
    $legacyBytes = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"
        . str_repeat("\x00", 32)
        . mb_convert_encoding($legacyText, 'UTF-16LE', 'UTF-8');

    $doc = $tmpDir . '/students.doc';
    file_put_contents($doc, $legacyBytes);
    $docStudents = parse_student_import_file($doc, 'doc');
    roster_assert(roster_names($docStudents) === ['Ибрагимов Магомед', 'Гаджиева Алия'], 'legacy DOC parsing');

    $xls = $tmpDir . '/students.xls';
    file_put_contents($xls, $legacyBytes);
    $xlsStudents = parse_student_import_file($xls, 'xls');
    roster_assert(roster_names($xlsStudents) === ['Ибрагимов Магомед', 'Гаджиева Алия'], 'legacy XLS parsing');

    echo "Student roster import formats OK: DOC, DOCX, PDF, XLS, XLSX\n";
} finally {
    foreach (glob($tmpDir . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($tmpDir);
}
