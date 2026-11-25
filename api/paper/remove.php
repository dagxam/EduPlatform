<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
require __DIR__.'/versions.php';
require __DIR__.'/catalog-archive.php';
$user=require_user(['admin','teacher']);
if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['ok'=>false,'error'=>'Метод не поддерживается.'],405);
$data=read_json_body();$id=(int)($data['assignment_id']??0);$action=(string)($data['action']??'remove');
if($id<1||!in_array($action,['remove','restore'],true))json_response(['ok'=>false,'error'=>'Некорректное действие.'],422);
$pdo=app_db();$school=require_active_school($user,false);$manager=can_manage_school($user,$school);
try{
    paper_versions_schema($pdo);paper_catalog_archive_schema($pdo);
    $pdo->beginTransaction();
    if(!db_is_mysql($pdo)){$q=$pdo->prepare("UPDATE assignments SET id=id WHERE id=? AND school_id=? AND type='paper'");$q->execute([$id,$school]);}
    $q=$pdo->prepare("SELECT id,teacher_id,status FROM assignments WHERE id=? AND school_id=? AND type='paper'".(db_is_mysql($pdo)?' FOR UPDATE':''));$q->execute([$id,$school]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new DomainException('Бумажная работа не найдена.',404);
    if(!$manager&&(int)$row['teacher_id']!==(int)$user['id'])throw new DomainException('Удалить работу может автор или администратор школы.',403);
    $root=paper_version_info($pdo,$id)['root_id'];
    $q=$pdo->prepare("SELECT a.id,a.teacher_id,a.status FROM assignments a LEFT JOIN paper_versions v ON v.assignment_id=a.id WHERE a.school_id=? AND a.type='paper' AND (a.id=? OR v.root_id=?) ORDER BY a.id".(db_is_mysql($pdo)?' FOR UPDATE':''));$q->execute([$school,$root,$root]);$family=$q->fetchAll(PDO::FETCH_ASSOC);
    $ids=array_map(static fn($r)=>(int)$r['id'],$family);
    if(!in_array($id,$ids,true))throw new DomainException('Работа недоступна.',404);
    foreach($family as $member)if(!$manager&&(int)$member['teacher_id']!==(int)$user['id'])throw new DomainException('Нет доступа ко всей истории работы.',403);
    $mode='restored';
    if($action==='restore'){paper_catalog_set_archive($pdo,$ids,false);}
    else{
        $protected=count($ids)>1||$row['status']==='paper_locked'||paper_catalog_has_history($pdo,$id);
        if($protected){paper_catalog_set_archive($pdo,$ids,true);$mode='archived';}
        else{
            $q=$pdo->prepare("DELETE FROM assignments WHERE id=? AND school_id=? AND type='paper'");$q->execute([$id,$school]);if($q->rowCount()!==1)throw new RuntimeException('Deletion failed');
            $mode='deleted';
        }
    }
    $pdo->commit();
    // Retain private files; cleanup is separate to avoid deleting shared assets.
    audit_event('paper_catalog_'.$mode,'assignment',$id,['family_ids'=>$ids],$school,(int)$user['id']);
    json_response(['ok'=>true,'mode'=>$mode,'assignment_id'=>$id]);
}catch(DomainException $e){if($pdo->inTransaction())$pdo->rollBack();json_response(['ok'=>false,'error'=>$e->getMessage()],in_array($e->getCode(),[403,404,409],true)?$e->getCode():409);}
catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('PAPER remove: '.$e->getMessage());json_response(['ok'=>false,'error'=>'Не удалось изменить список работ. Обновите страницу.'],500);}
