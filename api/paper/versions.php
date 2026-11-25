<?php
declare(strict_types=1);
// A version is an independent assignment; old question IDs, sheets and attempts are never rewritten.
function paper_versions_schema(PDO $pdo): void {
    if(db_is_mysql($pdo)) {
        $pdo->exec('CREATE TABLE IF NOT EXISTS paper_versions (assignment_id BIGINT UNSIGNED PRIMARY KEY, root_id BIGINT UNSIGNED NOT NULL, revision_no INT UNSIGNED NOT NULL, parent_id BIGINT UNSIGNED NULL, UNIQUE KEY uq_paper_revision(root_id,revision_no), FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    } else {
        $pdo->exec('CREATE TABLE IF NOT EXISTS paper_versions (assignment_id INTEGER PRIMARY KEY REFERENCES assignments(id) ON DELETE CASCADE, root_id INTEGER NOT NULL, revision_no INTEGER NOT NULL, parent_id INTEGER NULL, UNIQUE(root_id,revision_no))');
    }
}
function paper_version_info(PDO $pdo,int $id): array {
    $q=$pdo->prepare('SELECT root_id,revision_no,parent_id FROM paper_versions WHERE assignment_id=?');$q->execute([$id]);$r=$q->fetch(PDO::FETCH_ASSOC);
    return $r?['root_id'=>(int)$r['root_id'],'revision_no'=>(int)$r['revision_no'],'parent_id'=>$r['parent_id']===null?null:(int)$r['parent_id']]:['root_id'=>$id,'revision_no'=>1,'parent_id'=>null];
}
function paper_version_next(PDO $pdo,int $sourceId,int $schoolId): array {
    if(!$pdo->inTransaction())throw new LogicException('Paper version requires a transaction');
    $info=paper_version_info($pdo,$sourceId);$root=$info['root_id'];
    // Serialize versions in the family; SQLite obtains a write lock before reading max.
    if(db_is_mysql($pdo)){$q=$pdo->prepare("SELECT id FROM assignments WHERE id=? AND school_id=? AND type='paper' FOR UPDATE");$q->execute([$root,$schoolId]);if(!$q->fetchColumn())throw new DomainException('Исходная версия недоступна.',409);}
    else{$q=$pdo->prepare("UPDATE assignments SET id=id WHERE id=? AND school_id=? AND type='paper'");$q->execute([$root,$schoolId]);if(!$q->rowCount())throw new DomainException('Исходная версия недоступна.',409);}
    $q=$pdo->prepare('SELECT revision_no FROM paper_versions WHERE root_id=? ORDER BY revision_no DESC LIMIT 1'.(db_is_mysql($pdo)?' FOR UPDATE':''));$q->execute([$root]);
    return ['root_id'=>$root,'revision_no'=>max(1,(int)$q->fetchColumn())+1,'parent_id'=>$sourceId];
}
function paper_version_record(PDO $pdo,int $id,array $version): void {
    $q=$pdo->prepare('INSERT INTO paper_versions(assignment_id,root_id,revision_no,parent_id) VALUES(?,?,?,?)');
    $q->execute([$id,$version['root_id'],$version['revision_no'],$version['parent_id']]);
}
function paper_version_enrich(PDO $pdo,array $assignments): array {
    $ids=array_map(static fn($a)=>(int)$a['id'],$assignments);if(!$ids)return $assignments;
    $q=$pdo->prepare('SELECT assignment_id,root_id,revision_no,parent_id FROM paper_versions WHERE assignment_id IN ('.implode(',',array_fill(0,count($ids),'?')).')');$q->execute($ids);$map=[];$latest=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $v){$map[(int)$v['assignment_id']]=$v;$root=(int)$v['root_id'];$latest[$root]=max($latest[$root]??1,(int)$v['revision_no']);}
    foreach($assignments as &$a){$id=(int)$a['id'];$v=$map[$id]??['root_id'=>$id,'revision_no'=>1,'parent_id'=>null];$a['paper_root_id']=(int)$v['root_id'];$a['paper_revision']=(int)$v['revision_no'];$a['paper_previous_id']=$v['parent_id']===null?null:(int)$v['parent_id'];$a['paper_latest']=($latest[(int)$v['root_id']]??1)===(int)$v['revision_no'];}unset($a);
    return $assignments;
}
