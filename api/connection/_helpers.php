<?php
declare(strict_types=1);

function connection_requests_schema(PDO $pdo): void
{
    if(db_is_mysql($pdo)){
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS connection_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    kind VARCHAR(20) NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    school_name VARCHAR(200) NOT NULL,
    locality VARCHAR(160) NOT NULL,
    email VARCHAR(254) NOT NULL,
    phone VARCHAR(40) NOT NULL DEFAULT '',
    message TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'new',
    admin_note TEXT NOT NULL,
    consent_version VARCHAR(20) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_connection_requests_status (status,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
    } else {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS connection_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    kind TEXT NOT NULL,
    full_name TEXT NOT NULL,
    school_name TEXT NOT NULL,
    locality TEXT NOT NULL,
    email TEXT NOT NULL,
    phone TEXT NOT NULL DEFAULT '',
    message TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'new',
    admin_note TEXT NOT NULL DEFAULT '',
    consent_version TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_connection_requests_status ON connection_requests(status,id);
SQL);
    }
}
function connection_request_statuses(): array
{
    return ['new','in_progress','connected','declined'];
}
function connection_request_text(array $data,string $key,int $max,bool $required=false): string
{
    if(!is_string($data[$key]??''))throw new InvalidArgumentException('Некорректное поле формы.');
    $value=trim($data[$key]??'');
    if(($required&&$value==='')||mb_strlen($value)>$max)throw new InvalidArgumentException('Проверьте заполнение полей и допустимую длину.');
    return $value;
}
function connection_request_normalize(array $data): array
{
    if(($data['consent']??null)!==true)throw new InvalidArgumentException('Необходимо отдельное согласие на обработку персональных данных.');
    $kind=connection_request_text($data,'kind',20,true);
    if(!in_array($kind,['teacher','school'],true))throw new InvalidArgumentException('Выберите тип подключения.');
    $email=connection_request_text($data,'email',254,true);
    if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Укажите корректный email.');
    $phone=connection_request_text($data,'phone',40);
    if($phone!==''&&!preg_match('/^[+0-9 ()-]{5,40}$/D',$phone))throw new InvalidArgumentException('Укажите корректный телефон или оставьте поле пустым.');
    return [
        'kind'=>$kind,'full_name'=>connection_request_text($data,'full_name',150,true),
        'school_name'=>connection_request_text($data,'school_name',200,$kind==='school'),
        'locality'=>connection_request_text($data,'locality',160,true),
        'email'=>$email,'phone'=>$phone,'message'=>connection_request_text($data,'message',1000),
        'consent_version'=>'2026-11-27',
    ];
}
function connection_request_create(PDO $pdo,array $data): int
{
    $row=connection_request_normalize($data);
    $q=$pdo->prepare("INSERT INTO connection_requests (kind,full_name,school_name,locality,email,phone,message,admin_note,consent_version) VALUES (:kind,:full_name,:school_name,:locality,:email,:phone,:message,'',:consent_version)");
    $q->execute($row);
    return (int)$pdo->lastInsertId();
}
function connection_require_platform_admin(): array
{
    $user=require_user(['admin']);
    if(!is_platform_admin($user))json_response(['ok'=>false,'error'=>'Доступ только главному администратору UROVIA.'],403);
    return $user;
}
function connection_request_update(PDO $pdo,int $id,string $status,string $note): bool
{
    if(!in_array($status,connection_request_statuses(),true)||mb_strlen($note)>1000||$id<1)throw new InvalidArgumentException('Некорректные параметры обращения.');
    $q=$pdo->prepare('SELECT id FROM connection_requests WHERE id=?');$q->execute([$id]);
    if(!$q->fetchColumn())return false;
    $q=$pdo->prepare('UPDATE connection_requests SET status=?,admin_note=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
    $q->execute([$status,$note,$id]);
    return true;
}
