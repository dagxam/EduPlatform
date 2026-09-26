<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$user = require_user(['admin']);
$sourceSchoolId = require_active_school($user, true);

$stmt = app_db()->prepare(
    'SELECT s.id, s.name, s.city, s.theme_color
     FROM schools s
     WHERE s.status = "active"
       AND s.id <> :source_school_id
     ORDER BY s.name COLLATE NOCASE'
);
$stmt->execute(['source_school_id' => $sourceSchoolId]);

json_response([
    'ok' => true,
    'schools' => $stmt->fetchAll(),
]);
