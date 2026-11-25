<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/api/bootstrap.php';
require dirname(__DIR__,2).'/api/paper/versions.php';
$mysql=in_array('--mysql',$argv??[],true);
if($mysql){
    // Isolated disposable database in the CI MySQL service only.
    $pdo=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root','urovia_ci_root',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $db='urovia_paper_test_'.bin2hex(random_bytes(5));$pdo->exec('CREATE DATABASE `'.$db.'`');$pdo->exec('USE `'.$db.'`');
    register_shutdown_function(static function()use($pdo,$db){if($pdo->inTransaction())$pdo->rollBack();$pdo->exec('DROP DATABASE `'.$db.'`');});
    $pdo->exec('CREATE TABLE assignments(id BIGINT UNSIGNED PRIMARY KEY,school_id BIGINT UNSIGNED,type VARCHAR(32),title VARCHAR(500)) ENGINE=InnoDB');
}else{$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->exec('PRAGMA foreign_keys=ON');$pdo->exec('CREATE TABLE assignments(id INTEGER PRIMARY KEY,school_id INTEGER,type TEXT,title TEXT)');}
$pdo->exec("INSERT INTO assignments VALUES(1,1,'paper','Original'),(2,1,'paper','Revision 2'),(3,1,'paper','Revision 3')");
paper_versions_schema($pdo);
$pdo->beginTransaction();$v=paper_version_next($pdo,1,1);if($v['revision_no']!==2)throw new RuntimeException('Expected second version');paper_version_record($pdo,2,$v);$pdo->commit();
$pdo->beginTransaction();$v=paper_version_next($pdo,2,1);if($v['revision_no']!==3||$v['root_id']!==1)throw new RuntimeException('Expected third version in same family');paper_version_record($pdo,3,$v);$pdo->commit();
$a=paper_version_enrich($pdo,[['id'=>1],['id'=>2],['id'=>3]]);if($a[0]['paper_latest']||$a[1]['paper_latest']||!$a[2]['paper_latest'])throw new RuntimeException('Version history incorrect');if($pdo->query('SELECT title FROM assignments WHERE id=1')->fetchColumn()!=='Original')throw new RuntimeException('Original changed');
echo "PAPER revision history OK\n";
