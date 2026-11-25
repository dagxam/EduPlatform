<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/api/bootstrap.php';require dirname(__DIR__,2).'/api/paper/sheets.php';
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->exec('PRAGMA foreign_keys=ON');
foreach(['assignments','users','classes','schools','attempts'] as $table)$pdo->exec("CREATE TABLE $table(id INTEGER PRIMARY KEY)");
foreach(['assignments','users','classes','schools'] as $table)$pdo->exec("INSERT INTO $table VALUES(1)");
$pdo->exec('CREATE TABLE paper_sheets(code TEXT PRIMARY KEY,assignment_id INTEGER,student_id INTEGER,class_id INTEGER,school_id INTEGER,snapshot TEXT,attempt_id INTEGER,created_at TEXT,UNIQUE(assignment_id,student_id))');
paper_archive_schema($pdo);
$pdo->exec("INSERT INTO paper_sheets VALUES('old',1,1,1,1,'old-key',NULL,'2026-01-01')");
$pdo->beginTransaction();paper_archive_current($pdo,'old');$pdo->exec("INSERT INTO paper_sheets VALUES('new',1,1,1,1,'new-key',NULL,'2026-01-02')");$pdo->commit();
$rows=$pdo->query('SELECT code,snapshot FROM '.paper_all_sheets_sql().' p ORDER BY code')->fetchAll(PDO::FETCH_KEY_PAIR);
if($rows!==['new'=>'new-key','old'=>'old-key'])throw new RuntimeException('Old sheet lost');
if((int)$pdo->query('SELECT COUNT(*) FROM assignments')->fetchColumn()!==1)throw new RuntimeException('Extra assignment');
$js=file_get_contents(dirname(__DIR__,2).'/paper-cabinet.js');if(str_contains($js,'new_revision:true')||!str_contains($js,"action:'paper_edit'"))throw new RuntimeException('Editor still creates copies');
echo "PAPER in-place edit and archived QR OK\n";
