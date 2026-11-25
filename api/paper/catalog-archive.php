<?php
declare(strict_types=1);
function paper_catalog_archive_schema(PDO $pdo): void {
    $pdo->exec(db_is_mysql($pdo)
        ? 'CREATE TABLE IF NOT EXISTS paper_catalog_archive (assignment_id BIGINT UNSIGNED PRIMARY KEY, archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        : 'CREATE TABLE IF NOT EXISTS paper_catalog_archive (assignment_id INTEGER PRIMARY KEY REFERENCES assignments(id) ON DELETE CASCADE, archived_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
}
function paper_catalog_is_archived(PDO $pdo,int $id): bool {
    $q=$pdo->prepare('SELECT 1 FROM paper_catalog_archive WHERE assignment_id=?');$q->execute([$id]);return (bool)$q->fetchColumn();
}
function paper_catalog_enrich(PDO $pdo,array $rows): array {
    $ids=array_map(static fn($r)=>(int)$r['id'],$rows);if(!$ids)return $rows;
    $q=$pdo->prepare('SELECT assignment_id FROM paper_catalog_archive WHERE assignment_id IN ('.implode(',',array_fill(0,count($ids),'?')).')');$q->execute($ids);$archived=array_fill_keys(array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN)),true);
    foreach($rows as &$r)$r['paper_archived']=isset($archived[(int)$r['id']]);unset($r);return $rows;
}
function paper_catalog_has_history(PDO $pdo,int $id): bool {
    foreach(['attempts','paper_sheets','paper_sheet_archive'] as $table){
        $sql=db_is_mysql($pdo)?'SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?':"SELECT 1 FROM sqlite_master WHERE type='table' AND name=?";
        $q=$pdo->prepare($sql);$q->execute([$table]);if(!$q->fetchColumn())continue;
        $q=$pdo->prepare('SELECT 1 FROM '.$table.' WHERE assignment_id=? LIMIT 1');$q->execute([$id]);if($q->fetchColumn())return true;
    }return false;
}
function paper_catalog_set_archive(PDO $pdo,array $ids,bool $archive): void {
    if(!$pdo->inTransaction())throw new LogicException('Archive requires transaction');
    foreach($ids as $id){
        $q=$pdo->prepare('DELETE FROM paper_catalog_archive WHERE assignment_id=?');$q->execute([$id]);
        if($archive){$q=$pdo->prepare('INSERT INTO paper_catalog_archive(assignment_id) VALUES(?)');$q->execute([$id]);}
    }
}
