<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin']);
$schoolId = require_active_school($user, true);
$transferId = (int)($_GET['transfer_id'] ?? 0);
$assetId = (int)($_GET['asset_id'] ?? 0);

if ($transferId < 1 || $assetId < 1) {
    json_response(['ok' => false, 'error' => 'Не указано изображение.'], 422);
}

$pdo = app_db();
$stmt = $pdo->prepare(
    'SELECT qa.stored_name, qa.mime_type
     FROM question_assets qa
     JOIN questions q ON q.id = qa.question_id
     JOIN school_material_transfer_assignments m ON m.assignment_id = q.assignment_id
     JOIN school_material_transfers t ON t.id = m.transfer_id
     WHERE qa.id = :asset_id
       AND t.id = :transfer_id
       AND t.target_school_id = :school_id
     LIMIT 1'
);
$stmt->execute([
    'asset_id' => $assetId,
    'transfer_id' => $transferId,
    'school_id' => $schoolId,
]);
$row = $stmt->fetch();
if (!$row) {
    json_response(['ok' => false, 'error' => 'Изображение недоступно.'], 404);
}

$storedName = basename((string)$row['stored_name']);
$path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'question-assets' . DIRECTORY_SEPARATOR . $storedName;
if (!is_file($path)) {
    json_response(['ok' => false, 'error' => 'Файл изображения отсутствует.'], 404);
}

header('Content-Type: ' . (string)($row['mime_type'] ?: 'application/octet-stream'));
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: inline');
readfile($path);
exit;
