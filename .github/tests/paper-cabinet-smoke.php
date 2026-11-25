<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
function check(string $path,string $expected):void {
 $s=file_get_contents($GLOBALS['root'].'/'.$path);
 if(!str_contains($s,$expected))throw new RuntimeException('Missing paper isolation in '.$path.': '.$expected);
}
check('api/paper/create.php',"\$type = 'paper'");
check('api/paper/copy.php',"'type' => 'paper'");
check('api/paper/copy.php','INSERT INTO questions');
check('api/paper/copy.php','duplicate_assignment_private_file');
check('api/assignments/list.php',"a.type <> 'paper'");
check('api/assignments/assign.php',"\$assignment['type'] === 'paper'");
check('api/assignments/assign-student.php',"\$assignment['type'] === 'paper'");
check('api/attempts/start.php','ass.type <> "paper"');
check('api/student/assignments.php','a.type <> "paper"');
check('api/paper/work.php',"\$assignment['type'] !== 'paper'");
check('api/paper/work.php',"SET status='paper_locked'");
check('api/questions/_helpers.php',"=== 'paper_locked'");
check('paper-workspace.js','assignments/list.php?mode=paper');
check('paper-cabinet.js','paper/copy.php');
check('paper-cabinet.js','paper/create.php');
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE assignments(id INTEGER PRIMARY KEY,type TEXT NOT NULL,status TEXT NOT NULL,title TEXT NOT NULL); INSERT INTO assignments VALUES(1,'quiz','published','Online'),(2,'paper','paper_locked','Paper');");
if((int)$pdo->query("SELECT COUNT(*) FROM assignments WHERE type <> 'paper'")->fetchColumn()!==1)throw new RuntimeException('Online list contains paper');
if((int)$pdo->query("SELECT COUNT(*) FROM assignments WHERE type='paper' AND status='published'")->fetchColumn()!==0)throw new RuntimeException('Paper was published online');
echo "PAPER cabinet separation checks OK\n";
