<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/api/bootstrap.php';require dirname(__DIR__,2).'/api/paper/sheets.php';require dirname(__DIR__,2).'/api/questions/_helpers.php';
$mysql=in_array('--mysql',$argv??[],true);if($mysql){$pdo=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root','urovia_ci_root',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$db='paper_edit_test_'.bin2hex(random_bytes(5));$pdo->exec('CREATE DATABASE `'.$db.'`');$pdo->exec('USE `'.$db.'`');register_shutdown_function(static function()use($pdo,$db){if($pdo->inTransaction())$pdo->rollBack();$pdo->exec('DROP DATABASE `'.$db.'`');});}else{$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->exec('PRAGMA foreign_keys=ON');}
$idType=$mysql?'BIGINT UNSIGNED':'INTEGER';
foreach(['assignments','users','classes','schools','attempts'] as $table)$pdo->exec("CREATE TABLE $table(id $idType PRIMARY KEY)".($mysql?' ENGINE=InnoDB':''));
foreach(['assignments','users','classes','schools'] as $table)$pdo->exec("INSERT INTO $table VALUES(1)");
$pdo->exec('CREATE TABLE paper_sheets(code VARCHAR(32) PRIMARY KEY,assignment_id '.$idType.',student_id '.$idType.',class_id '.$idType.',school_id '.$idType.',snapshot TEXT,attempt_id '.$idType.',created_at VARCHAR(30),UNIQUE(assignment_id,student_id))');
paper_archive_schema($pdo);
$pdo->exec("INSERT INTO paper_sheets VALUES('old',1,1,1,1,'old-key',NULL,'2026-01-01')");
$pdo->beginTransaction();paper_archive_current($pdo,'old');$pdo->exec("INSERT INTO paper_sheets VALUES('new',1,1,1,1,'new-key',NULL,'2026-01-02')");$pdo->commit();
$rows=$pdo->query('SELECT code,snapshot FROM '.paper_all_sheets_sql().' p ORDER BY code')->fetchAll(PDO::FETCH_KEY_PAIR);
if($rows!==['new'=>'new-key','old'=>'old-key'])throw new RuntimeException('Old sheet lost');
if((int)$pdo->query('SELECT COUNT(*) FROM assignments')->fetchColumn()!==1)throw new RuntimeException('Extra assignment');
$js=file_get_contents(dirname(__DIR__,2).'/paper-cabinet.js');if(str_contains($js,'new_revision:true')||!str_contains($js,"action:'paper_edit'"))throw new RuntimeException('Editor still creates copies');
echo "PAPER in-place edit and archived QR OK\n";
