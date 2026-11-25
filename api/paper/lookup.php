<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
$user=require_user(['admin','teacher']);
$schoolId=require_active_school($user,false);
if($_SERVER['REQUEST_METHOD']!=='POST') json_response(['ok'=>false,'error'=>'Метод не поддерживается.'],405);
$data=read_json_body(4096);$code=strtolower(trim((string)($data['code']??'')));
if(!preg_match('/^[a-f0-9]{32}$/D',$code)) json_response(['ok'=>false,'error'=>'Некорректный код UROVIA PAPER.'],422);
$pdo=app_db();
try {
    $q=$pdo->prepare('SELECT p.assignment_id,p.class_id,p.student_id,a.teacher_id,a.subject_id FROM paper_sheets p JOIN assignments a ON a.id=p.assignment_id AND a.school_id=p.school_id JOIN classes c ON c.id=p.class_id AND c.school_id=p.school_id JOIN class_students cs ON cs.class_id=c.id AND cs.student_id=p.student_id JOIN users u ON u.id=p.student_id AND u.is_active=1 WHERE p.code=? AND p.school_id=?');
    $q->execute([$code,$schoolId]);$row=$q->fetch();
    if(!$row) json_response(['ok'=>false,'error'=>'Бланк не найден в выбранной школе.'],404);
    if(!can_manage_school($user,$schoolId)) {
        if(!can_teach_school($user,$schoolId)||(int)$row['teacher_id']!==(int)$user['id']) json_response(['ok'=>false,'error'=>'Бланк недоступен.'],403);
        $q=$pdo->prepare('SELECT 1 FROM teacher_classes WHERE school_id=? AND teacher_id=? AND class_id=? AND subject_id=?');
        $q->execute([$schoolId,(int)$user['id'],(int)$row['class_id'],(int)$row['subject_id']]);
        if(!$q->fetchColumn()) json_response(['ok'=>false,'error'=>'Бланк недоступен.'],403);
    }
    json_response(['ok'=>true,'assignment_id'=>(int)$row['assignment_id'],'class_id'=>(int)$row['class_id'],'student_id'=>(int)$row['student_id']]);
} catch(PDOException $e) {
    error_log('PAPER lookup: '.$e->getMessage());
    json_response(['ok'=>false,'error'=>'Бланк недоступен. Сначала подготовьте персональные бланки.'],404);
}
