<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/attempts/_helpers.php';
require __DIR__ . '/grading.php';

$user = require_user(['admin', 'teacher']);
$schoolId = require_active_school($user, false);
$pdo = app_db();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok'=>false,'error'=>'Метод не поддерживается.'],405);
$data = read_json_body();
$action = (string)($data['action'] ?? '');
$assignmentId = (int)($data['assignment_id'] ?? 0);
$classId = (int)($data['class_id'] ?? 0);
$studentId = (int)($data['student_id'] ?? 0);
if (!in_array($action,['prepare','submit'],true) || min($assignmentId,$classId,$studentId)<1) {
    json_response(['ok'=>false,'error'=>'Выберите задание, класс и ученика.'],422);
}
function paper_fail(string $message, int $status=409): never {
    throw new DomainException($message,$status);
}
function paper_json(mixed $data): string {
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
function paper_access(PDO $pdo,array $user,int $schoolId,int $assignmentId,int $classId,int $studentId): array {
    $q=$pdo->prepare('SELECT a.*,s.name AS subject_name FROM assignments a LEFT JOIN subjects s ON s.id=a.subject_id WHERE a.id=? AND a.school_id=?');
    $q->execute([$assignmentId,$schoolId]); $a=$q->fetch();
    if (!$a) paper_fail('Задание не найдено.',404);
    if (!can_manage_school($user,$schoolId)) {
        if (!can_teach_school($user,$schoolId) || (int)$a['teacher_id'] !== (int)$user['id']) paper_fail('Нет доступа к заданию.',403);
        $q=$pdo->prepare('SELECT 1 FROM teacher_classes WHERE school_id=? AND teacher_id=? AND class_id=? AND subject_id=?');
        $q->execute([$schoolId,(int)$user['id'],$classId,(int)$a['subject_id']]);
        if (!$q->fetchColumn()) paper_fail('Класс недоступен по этому предмету.',403);
    }
    $q=$pdo->prepare('SELECT u.id,u.first_name,u.last_name,u.middle_name,COALESCE(c.display_name,c.name) AS class_name FROM class_students cs JOIN classes c ON c.id=cs.class_id JOIN users u ON u.id=cs.student_id WHERE c.id=? AND c.school_id=? AND u.id=? AND u.is_active=1 AND u.role=?');
    $q->execute([$classId,$schoolId,$studentId,'student']); $student=$q->fetch();
    if (!$student) paper_fail('Ученик не найден в выбранном классе.',403);
    if (!in_array((string)$a['workflow_status'],['ready','assigned','completed'],true)) paper_fail('Для сохранения результата задание должно быть готово. Черновик можно просто распечатать.');
    return [$a,$student];
}
function paper_snapshot(PDO $pdo,int $assignmentId,int $studentId): array {
    $variant=build_attempt_variant($pdo,$assignmentId,$studentId);
    $q=$pdo->prepare('SELECT id,type,interaction_type,text,points,correct_text,settings_json FROM questions WHERE assignment_id=? AND is_active=1 ORDER BY position,id');
    $q->execute([$assignmentId]); $byId=[];
    foreach($q->fetchAll() as $row) $byId[(int)$row['id']]=$row;
    if (!$byId || count($byId)>36) paper_fail('Персональный бланк поддерживает от 1 до 36 вопросов. Для длинных заданий используйте обычную печать.',422);
    $options=$pdo->prepare('SELECT id,text,is_correct FROM question_options WHERE question_id=? ORDER BY position,id');
    $assets=$pdo->prepare('SELECT id,original_name,mime_type FROM question_assets WHERE question_id=? ORDER BY position,id');
    $optionOrder=decoded_json_array($variant['option_order_json']);
    $structuredOrder=decoded_json_array($variant['structured_order_json']);
    $questions=[];
    foreach(decoded_json_array($variant['question_order_json']) as $id) {
        $row=$byId[(int)$id];
        $type=(string)($row['interaction_type'] ?: $row['type']);
        if($type==='ordering') $type='order';
        if(in_array($type,['short_answer','image_answer'],true)) $type='text';
        $choice=in_array($type,['single','multiple','true_false'],true);
        $settings=decoded_json_array($row['settings_json']);$structured=[];
        $order=$structuredOrder[(string)$id]??[];
        if($type==='order') {
            foreach($order['items']??[] as $key) if(isset($settings['items'][$key])) $structured['items'][]=['key'=>(string)$key,'text'=>(string)$settings['items'][$key]];
            if(empty($structured['items'])) paper_fail('Некорректное задание на порядок.',422);
        } elseif($type==='matching') {
            foreach(['left','right'] as $side) foreach($order[$side]??[] as $key) if(isset($settings[$side][$key])) $structured[$side][]=['key'=>(string)$key,'text'=>(string)$settings[$side][$key]];
            if(empty($structured['left'])||empty($structured['right'])) paper_fail('Некорректное задание на соответствие.',422);
        } elseif($type==='correction') $structured['original_text']=(string)($settings['original_text']??'');
        $options->execute([(int)$id]); $map=[]; $correct=[];
        foreach($options->fetchAll() as $opt) {
            $map[(int)$opt['id']]=['id'=>(int)$opt['id'],'text'=>(string)$opt['text']];
            if ((int)$opt['is_correct']===1) $correct[]=(int)$opt['id'];
        }
        if($choice && (count($map)<2 || count($map)>6 || !valid_choice_answer_key($type,$correct))) paper_fail('Вопрос № '.(count($questions)+1).' имеет неподдерживаемые варианты или неверно заданный ключ. Проверьте конструктор.',422);
        sort($correct,SORT_NUMERIC); $ordered=[];
        foreach($optionOrder[(string)$id] ?? [] as $optId) if(isset($map[(int)$optId])) $ordered[]=$map[(int)$optId];
        if($choice && count($ordered)!==count($map)) paper_fail('Не удалось закрепить порядок вариантов.',422);
        $assets->execute([(int)$id]); $images=[];
        foreach($assets->fetchAll() as $asset) $images[]=['id'=>(int)$asset['id'],'original_name'=>(string)$asset['original_name'],'url'=>'./api/questions/asset.php?id='.(int)$asset['id']];
        $questions[]=['id'=>(int)$id,'type'=>$type,'text'=>(string)$row['text'],'points'=>(float)$row['points'],'options'=>$choice?$ordered:[],'assets'=>$images,'structured'=>$structured,'correct'=>$correct,'correct_text'=>(string)($row['correct_text']??'')];
    }
    return ['variant'=>$variant,'questions'=>$questions];
}
function paper_public(array $snapshot): array {
    return array_map(static function(array $q):array {$q['manual_required']=!in_array($q['type'],['single','multiple','true_false','order','matching','number','text','correction'],true)||(!in_array($q['type'],['single','multiple','true_false'],true)&&trim((string)$q['correct_text'])==='');unset($q['correct'],$q['correct_text']);return $q;},$snapshot['questions']);
}
try {
    [$assignment,$student]=paper_access($pdo,$user,$schoolId,$assignmentId,$classId,$studentId);
// Isolated storage: no migration of existing tables and no change to online endpoints.
if(db_is_mysql($pdo)) {
    $pdo->exec('CREATE TABLE IF NOT EXISTS paper_sheets (code CHAR(32) NOT NULL PRIMARY KEY, assignment_id BIGINT UNSIGNED NOT NULL, student_id BIGINT UNSIGNED NOT NULL, class_id BIGINT UNSIGNED NOT NULL, school_id BIGINT UNSIGNED NOT NULL, snapshot LONGTEXT NOT NULL, attempt_id BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_paper_assignment_student (assignment_id,student_id), FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE, FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE, FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE, FOREIGN KEY (attempt_id) REFERENCES attempts(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
} else {
    $pdo->exec('CREATE TABLE IF NOT EXISTS paper_sheets (code TEXT PRIMARY KEY, assignment_id INTEGER NOT NULL REFERENCES assignments(id) ON DELETE CASCADE, student_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, class_id INTEGER NOT NULL REFERENCES classes(id) ON DELETE CASCADE, school_id INTEGER NOT NULL REFERENCES schools(id) ON DELETE CASCADE, snapshot TEXT NOT NULL, attempt_id INTEGER REFERENCES attempts(id) ON DELETE SET NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(assignment_id,student_id))');
}
    $pdo->beginTransaction();
    if(db_is_mysql($pdo)) {
        $lock=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$lock->execute([$studentId]);$lock->fetchColumn();
    } else {
        // Obtain a SQLite write lock before conflict checks.
        $lock=$pdo->prepare('UPDATE paper_sheets SET code=code WHERE assignment_id=? AND student_id=?');$lock->execute([$assignmentId,$studentId]);
    }
    [$assignment,$student]=paper_access($pdo,$user,$schoolId,$assignmentId,$classId,$studentId);
    $q=$pdo->prepare('SELECT * FROM paper_sheets WHERE assignment_id=? AND student_id=?');
    $q->execute([$assignmentId,$studentId]);$sheet=$q->fetch();
    if($sheet && ((int)$sheet['school_id']!==$schoolId || (int)$sheet['class_id']!==$classId)) paper_fail('Бланк был создан для другого класса.');
    if($sheet && $sheet['attempt_id']!==null) {
        $q=$pdo->prepare('SELECT id,score,max_score,percent,grade FROM attempts WHERE id=? AND student_id=? AND assignment_id=?');
        $q->execute([(int)$sheet['attempt_id'],$studentId,$assignmentId]);$saved=$q->fetch();
        if($saved) {
            if($action==='submit' && !hash_equals((string)$sheet['code'],(string)($data['code']??''))) paper_fail('Код бланка не совпадает.');
            $pdo->commit();json_response(['ok'=>true,'saved'=>true,'result'=>$saved]);
        }
        paper_fail('Состояние бланка изменилось. Обновите страницу.');
    }
    $q=$pdo->prepare('SELECT 1 FROM attempts WHERE assignment_id=? AND student_id=? LIMIT 1');
    $q->execute([$assignmentId,$studentId]);
    if($q->fetchColumn()) paper_fail('У ученика уже есть попытка по этой работе. Она не будет изменена.');
    if((string)$assignment['status']==='published') {
        $q=$pdo->prepare('SELECT 1 FROM assignment_students WHERE assignment_id=? AND student_id=? UNION ALL SELECT 1 FROM assignment_classes ac JOIN class_students cs ON cs.class_id=ac.class_id WHERE ac.assignment_id=? AND cs.student_id=? LIMIT 1');
        $q->execute([$assignmentId,$studentId,$assignmentId,$studentId]);
        if($q->fetchColumn()) paper_fail('Работа уже назначена этому ученику онлайн. Для бумажного режима выберите ученика без онлайн-назначения.');
    }
    $snapshot=paper_snapshot($pdo,$assignmentId,$studentId);
    $encoded=paper_json($snapshot);
    if($action==='prepare') {
        if(!$sheet) {
            $code=bin2hex(random_bytes(16));
            $q=$pdo->prepare('INSERT INTO paper_sheets(code,assignment_id,student_id,class_id,school_id,snapshot) VALUES(?,?,?,?,?,?)');
            $q->execute([$code,$assignmentId,$studentId,$classId,$schoolId,$encoded]);
        } elseif(!hash_equals(hash('sha256',(string)$sheet['snapshot']),hash('sha256',$encoded))) {
            paper_fail('Задание изменилось после создания бланка. Старый бланк нельзя проверять автоматически. Обратитесь к администратору.');
        } else $code=(string)$sheet['code'];
        $pdo->commit();
        json_response(['ok'=>true,'code'=>$code,'assignment'=>['id'=>$assignmentId,'title'=>(string)$assignment['title'],'subject_name'=>(string)$assignment['subject_name']],'student'=>$student,'variant_label'=>$snapshot['variant']['variant_label'],'questions'=>paper_public($snapshot)]);
    }
    if(!$sheet || !hash_equals((string)$sheet['code'],(string)($data['code']??''))) paper_fail('Код бланка не совпадает.');
    if(!hash_equals(hash('sha256',(string)$sheet['snapshot']),hash('sha256',$encoded))) paper_fail('Задание изменилось после печати. Проверка остановлена, чтобы не выставить неверные оценки.');
    $answers=$data['answers']??null;
    if(!is_array($answers) || count($answers)!==count($snapshot['questions']) || ($data['confirmed']??false)!==true) paper_fail('Подтвердите все распознанные ответы.',422);
    $score=0.0;$max=0.0;$graded=[];
    foreach($snapshot['questions'] as $i=>$question) {
        if(!array_key_exists($i,$answers) || !is_array($answers[$i])) paper_fail('Некорректный формат ответа.',422);
        $g=paper_grade_answer($question,$answers[$i]);
        $score+=$g['score'];$max+=$question['points'];
        $graded[]=[$question['id'],$g['answer_text'],$g['score'],$g['is_correct']];
    }
    if($max<=0) paper_fail('Максимальный балл работы должен быть больше нуля.',422);
    $percent=round($score/$max*100,2);$grade=grade_from_percent_for_assignment($pdo,$assignmentId,$percent);
    $v=$snapshot['variant'];
    $q=$pdo->prepare('INSERT INTO attempts (assignment_id,student_id,started_at,submitted_at,last_seen_at,score,max_score,percent,grade,status,termination_reason,variant_index,variant_label,question_order_json,option_order_json,structured_order_json) VALUES (?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,?,?,?,?,?,?,?,?,?,?,?)');
    $q->execute([$assignmentId,$studentId,$score,$max,$percent,$grade,'submitted','paper',(int)$v['variant_index'],(string)$v['variant_label'],$v['question_order_json'],$v['option_order_json'],$v['structured_order_json']]);
    $attemptId=(int)$pdo->lastInsertId();
    $q=$pdo->prepare('INSERT INTO answers (attempt_id,question_id,answer_text,score,is_correct,needs_review,updated_at) VALUES (?,?,?,?,?,0,CURRENT_TIMESTAMP)');
    foreach($graded as $g) $q->execute(array_merge([$attemptId],$g));
    $q=$pdo->prepare('UPDATE paper_sheets SET attempt_id=? WHERE code=? AND attempt_id IS NULL');$q->execute([$attemptId,$sheet['code']]);
    if($q->rowCount()!==1) paper_fail('Бланк уже обработан.');
    $pdo->commit();
    audit_event('paper_result_submitted','attempt',$attemptId,['assignment_id'=>$assignmentId,'student_id'=>$studentId,'class_id'=>$classId],$schoolId,(int)$user['id']);
    json_response(['ok'=>true,'saved'=>true,'result'=>['id'=>$attemptId,'score'=>$score,'max_score'=>$max,'percent'=>$percent,'grade'=>$grade]]);
} catch(DomainException $e) {
    if($pdo->inTransaction()) $pdo->rollBack();
    json_response(['ok'=>false,'error'=>$e->getMessage()],in_array($e->getCode(),[403,404,409,422],true)?$e->getCode():409);
} catch(Throwable $e) {
    if($pdo->inTransaction()) $pdo->rollBack();
    error_log('UROVIA PAPER: '.$e->getMessage());
    json_response(['ok'=>false,'error'=>'Не удалось обработать бумажную работу. Повторите попытку.'],500);
}
