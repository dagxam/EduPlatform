<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/api/bootstrap.php';require dirname(__DIR__,2).'/api/paper/catalog-archive.php';
$p=new PDO('sqlite::memory:');$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$p->exec('PRAGMA foreign_keys=ON');
$p->exec('CREATE TABLE assignments(id INTEGER PRIMARY KEY); INSERT INTO assignments VALUES(1),(2); CREATE TABLE attempts(id INTEGER PRIMARY KEY,assignment_id INTEGER); INSERT INTO attempts VALUES(1,1)');
paper_catalog_archive_schema($p);if(!paper_catalog_has_history($p,1)||paper_catalog_has_history($p,2))throw new RuntimeException('History protection failed');
$p->beginTransaction();paper_catalog_set_archive($p,[1],true);$p->commit();
if(!paper_catalog_is_archived($p,1)||paper_catalog_is_archived($p,2)||!paper_catalog_enrich($p,[['id'=>1]])[0]['paper_archived'])throw new RuntimeException('Archive failed');
$p->beginTransaction();paper_catalog_set_archive($p,[1],false);$p->commit();
if(paper_catalog_is_archived($p,1)||(int)$p->query('SELECT COUNT(*) FROM attempts')->fetchColumn()!==1)throw new RuntimeException('Restore changed results');
echo "PAPER archive and restore OK\n";
