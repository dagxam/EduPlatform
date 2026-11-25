<?php
declare(strict_types=1);
function paper_archive_schema(PDO $pdo): void {
    if(db_is_mysql($pdo)) {
        $pdo->exec('CREATE TABLE IF NOT EXISTS paper_sheet_archive (code CHAR(32) PRIMARY KEY, assignment_id BIGINT UNSIGNED NOT NULL, student_id BIGINT UNSIGNED NOT NULL, class_id BIGINT UNSIGNED NOT NULL, school_id BIGINT UNSIGNED NOT NULL, snapshot LONGTEXT NOT NULL, attempt_id BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_paper_archive_assignment_student(assignment_id,student_id), KEY idx_paper_archive_attempt(attempt_id), FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE, FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE, FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE, FOREIGN KEY (attempt_id) REFERENCES attempts(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    } else {
        $pdo->exec('CREATE TABLE IF NOT EXISTS paper_sheet_archive (code TEXT PRIMARY KEY, assignment_id INTEGER NOT NULL REFERENCES assignments(id) ON DELETE CASCADE, student_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, class_id INTEGER NOT NULL REFERENCES classes(id) ON DELETE CASCADE, school_id INTEGER NOT NULL REFERENCES schools(id) ON DELETE CASCADE, snapshot TEXT NOT NULL, attempt_id INTEGER REFERENCES attempts(id) ON DELETE SET NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_paper_archive_attempt ON paper_sheet_archive(attempt_id)');
    }
}
function paper_all_sheets_sql(): string {
    return '(SELECT code,assignment_id,student_id,class_id,school_id,snapshot,attempt_id,created_at FROM paper_sheets UNION ALL SELECT code,assignment_id,student_id,class_id,school_id,snapshot,attempt_id,created_at FROM paper_sheet_archive)';
}
function paper_archive_current(PDO $pdo,string $code): void {
    if(!$pdo->inTransaction())throw new LogicException('Archive requires transaction');
    $q=$pdo->prepare('INSERT INTO paper_sheet_archive(code,assignment_id,student_id,class_id,school_id,snapshot,attempt_id,created_at) SELECT code,assignment_id,student_id,class_id,school_id,snapshot,attempt_id,created_at FROM paper_sheets WHERE code=? AND attempt_id IS NULL');$q->execute([$code]);
    if($q->rowCount()!==1)throw new RuntimeException('Cannot archive checked sheet');
    $q=$pdo->prepare('DELETE FROM paper_sheets WHERE code=? AND attempt_id IS NULL');$q->execute([$code]);if($q->rowCount()!==1)throw new RuntimeException('Sheet changed');
}
