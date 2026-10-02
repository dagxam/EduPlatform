PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    first_name TEXT NOT NULL,
    last_name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE COLLATE NOCASE,
    login_name TEXT COLLATE NOCASE,
    password_hash TEXT NOT NULL,
    must_change_password INTEGER NOT NULL DEFAULT 0,
    credentials_sent_at TEXT,
    role TEXT NOT NULL CHECK (role IN ('admin', 'teacher', 'student')),
    is_platform_admin INTEGER NOT NULL DEFAULT 0,
    session_version INTEGER NOT NULL DEFAULT 0,
    middle_name TEXT,
    phone TEXT,
    avatar_name TEXT,
    class_name TEXT,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS classes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    display_name TEXT,
    teacher_id INTEGER,
    academic_year TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS subjects (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS assignments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    teacher_id INTEGER NOT NULL,
    subject_id INTEGER,
    title TEXT NOT NULL,
    description TEXT,
    type TEXT NOT NULL DEFAULT 'quiz',
    status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'published', 'closed')),
    workflow_status TEXT NOT NULL DEFAULT 'draft',
    review_submitted_at TEXT,
    reviewed_at TEXT,
    reviewed_by INTEGER,
    review_comment TEXT,
    completed_at TEXT,
    max_attempts INTEGER NOT NULL DEFAULT 1,
    time_limit_minutes INTEGER,
    starts_at TEXT,
    due_at TEXT,
    show_answers INTEGER NOT NULL DEFAULT 0,
    variant_count INTEGER NOT NULL DEFAULT 1,
    shuffle_questions INTEGER NOT NULL DEFAULT 0,
    shuffle_options INTEGER NOT NULL DEFAULT 0,
    shuffle_structured INTEGER NOT NULL DEFAULT 0,
    source_school_id INTEGER,
    source_assignment_id INTEGER,
    shared_by_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL,
    FOREIGN KEY (source_school_id) REFERENCES schools(id) ON DELETE SET NULL,
    FOREIGN KEY (source_assignment_id) REFERENCES assignments(id) ON DELETE SET NULL,
    FOREIGN KEY (shared_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS assignment_classes (
    assignment_id INTEGER NOT NULL,
    class_id INTEGER NOT NULL,
    time_limit_minutes INTEGER,
    PRIMARY KEY (assignment_id, class_id),
    FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS assignment_students (
    assignment_id INTEGER NOT NULL,
    student_id INTEGER NOT NULL,
    time_limit_minutes INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (assignment_id, student_id),
    FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_assignment_students_student
    ON assignment_students(student_id, assignment_id);

CREATE TABLE IF NOT EXISTS assignment_imports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    assignment_id INTEGER NOT NULL UNIQUE,
    original_name TEXT NOT NULL,
    stored_name TEXT NOT NULL,
    source_format TEXT NOT NULL,
    mime_type TEXT,
    size_bytes INTEGER NOT NULL DEFAULT 0,
    parse_status TEXT NOT NULL DEFAULT 'uploaded',
    extracted_text TEXT,
    parsed_question_count INTEGER NOT NULL DEFAULT 0,
    parser_message TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_assignment_imports_assignment ON assignment_imports(assignment_id);

CREATE TABLE IF NOT EXISTS questions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    assignment_id INTEGER NOT NULL,
    type TEXT NOT NULL CHECK (type IN ('single', 'multiple', 'true_false', 'text', 'number', 'essay', 'file')),
    text TEXT NOT NULL,
    points REAL NOT NULL DEFAULT 1,
    position INTEGER NOT NULL DEFAULT 0,
    correct_text TEXT,
    interaction_type TEXT,
    settings_json TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS question_options (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    question_id INTEGER NOT NULL,
    text TEXT NOT NULL,
    is_correct INTEGER NOT NULL DEFAULT 0,
    position INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS question_assets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    question_id INTEGER NOT NULL,
    stored_name TEXT NOT NULL,
    original_name TEXT,
    mime_type TEXT,
    position INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_question_assets_question ON question_assets(question_id);

CREATE TABLE IF NOT EXISTS attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    assignment_id INTEGER NOT NULL,
    student_id INTEGER NOT NULL,
    started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    submitted_at TEXT,
    score REAL,
    max_score REAL,
    percent REAL,
    grade TEXT,
    manual_score REAL,
    manual_percent REAL,
    manual_grade TEXT,
    manual_comment TEXT,
    manual_updated_at TEXT,
    manual_updated_by INTEGER,
    published_score REAL,
    published_percent REAL,
    published_grade TEXT,
    published_comment TEXT,
    result_published_at TEXT,
    result_published_by INTEGER,
    result_revision INTEGER NOT NULL DEFAULT 0,
    time_limit_snapshot INTEGER,
    status TEXT NOT NULL DEFAULT 'in_progress' CHECK (status IN ('in_progress', 'submitted', 'needs_review')),
    FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS answers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    attempt_id INTEGER NOT NULL,
    question_id INTEGER NOT NULL,
    answer_text TEXT,
    score REAL,
    is_correct INTEGER,
    needs_review INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (attempt_id, question_id),
    FOREIGN KEY (attempt_id) REFERENCES attempts(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_users_role ON users(role);
CREATE INDEX IF NOT EXISTS idx_assignments_teacher ON assignments(teacher_id);
CREATE INDEX IF NOT EXISTS idx_attempts_assignment ON attempts(assignment_id);
CREATE INDEX IF NOT EXISTS idx_attempts_assignment_student_status_id
    ON attempts(assignment_id, student_id, status, id);

CREATE TABLE IF NOT EXISTS attempt_result_revisions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    attempt_id INTEGER NOT NULL,
    revision INTEGER NOT NULL,
    score REAL NOT NULL,
    max_score REAL NOT NULL,
    percent REAL NOT NULL,
    grade TEXT NOT NULL,
    comment TEXT,
    published_by INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (attempt_id) REFERENCES attempts(id) ON DELETE CASCADE,
    FOREIGN KEY (published_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE (attempt_id, revision)
);

CREATE INDEX IF NOT EXISTS idx_attempt_result_revisions_attempt
    ON attempt_result_revisions(attempt_id, revision);


CREATE TABLE IF NOT EXISTS class_access (
    class_id INTEGER PRIMARY KEY,
    join_code TEXT NOT NULL UNIQUE COLLATE NOCASE,
    registration_open INTEGER NOT NULL DEFAULT 0,
    registration_expires_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS class_students (
    student_id INTEGER PRIMARY KEY,
    class_id INTEGER NOT NULL,
    pin_hash TEXT,
    activated_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_class_students_class ON class_students(class_id);
CREATE INDEX IF NOT EXISTS idx_class_access_code ON class_access(join_code);


-- Multi-school foundation for UVORIA.
CREATE TABLE IF NOT EXISTS schools (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    slug TEXT UNIQUE COLLATE NOCASE,
    city TEXT,
    theme_color TEXT NOT NULL DEFAULT '#1d68f0',
    assignment_review_required INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'suspended', 'archived')),
    created_by INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS school_users (
    school_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    role TEXT NOT NULL CHECK (role IN ('owner', 'school_admin', 'teacher')),
    can_teach INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (school_id, user_id),
    FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS school_subjects (
    school_id INTEGER NOT NULL,
    subject_id INTEGER NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    PRIMARY KEY (school_id, subject_id),
    FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS teacher_subjects (
    school_id INTEGER NOT NULL,
    teacher_id INTEGER NOT NULL,
    subject_id INTEGER NOT NULL,
    PRIMARY KEY (school_id, teacher_id, subject_id),
    FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS teacher_classes (
    school_id INTEGER NOT NULL,
    teacher_id INTEGER NOT NULL,
    class_id INTEGER NOT NULL,
    subject_id INTEGER NOT NULL,
    PRIMARY KEY (school_id, teacher_id, class_id, subject_id),
    FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS school_material_transfers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_school_id INTEGER NOT NULL,
    target_school_id INTEGER NOT NULL,
    subject_id INTEGER NOT NULL,
    sender_user_id INTEGER,
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'accepted', 'rejected')),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at TEXT,
    resolved_by INTEGER,
    FOREIGN KEY (source_school_id) REFERENCES schools(id) ON DELETE CASCADE,
    FOREIGN KEY (target_school_id) REFERENCES schools(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS school_material_transfer_assignments (
    transfer_id INTEGER NOT NULL,
    assignment_id INTEGER NOT NULL,
    position INTEGER NOT NULL DEFAULT 0,
    title_snapshot TEXT NOT NULL,
    type_snapshot TEXT,
    questions_count_snapshot INTEGER NOT NULL DEFAULT 0,
    source_format_snapshot TEXT,
    PRIMARY KEY (transfer_id, assignment_id),
    FOREIGN KEY (transfer_id) REFERENCES school_material_transfers(id) ON DELETE CASCADE,
    FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_material_transfers_target
    ON school_material_transfers(target_school_id, status, created_at);
CREATE INDEX IF NOT EXISTS idx_material_transfers_source
    ON school_material_transfers(source_school_id, created_at);

CREATE TABLE IF NOT EXISTS audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    school_id INTEGER,
    user_id INTEGER,
    event_type TEXT NOT NULL,
    entity_type TEXT,
    entity_id INTEGER,
    metadata_json TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS attempt_security_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    attempt_id INTEGER NOT NULL,
    event_type TEXT NOT NULL,
    details TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (attempt_id) REFERENCES attempts(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_school_users_user ON school_users(user_id);
CREATE INDEX IF NOT EXISTS idx_teacher_subjects_teacher ON teacher_subjects(teacher_id);
CREATE INDEX IF NOT EXISTS idx_teacher_classes_teacher ON teacher_classes(teacher_id);
CREATE INDEX IF NOT EXISTS idx_audit_log_school ON audit_log(school_id);
CREATE INDEX IF NOT EXISTS idx_audit_log_entity_event
    ON audit_log(entity_type, entity_id, event_type, id);
CREATE INDEX IF NOT EXISTS idx_attempt_security_attempt ON attempt_security_events(attempt_id);

INSERT OR IGNORE INTO subjects (name) VALUES
('Математика'),
('Русский язык'),
('Литература'),
('История'),
('Обществознание'),
('Биология'),
('География'),
('Физика'),
('Химия'),
('Информатика'),
('Английский язык'),
('Физическая культура');


CREATE TABLE IF NOT EXISTS auth_throttle (
    key_hash TEXT PRIMARY KEY,
    failures INTEGER NOT NULL DEFAULT 0,
    window_started INTEGER NOT NULL,
    locked_until INTEGER,
    updated_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    expires_at INTEGER NOT NULL,
    used_at INTEGER,
    created_at INTEGER NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_password_reset_tokens_user
    ON password_reset_tokens(user_id, expires_at);

CREATE TABLE IF NOT EXISTS library_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_school_id INTEGER NOT NULL,
    source_assignment_id INTEGER NOT NULL,
    subject_id INTEGER,
    submitted_by INTEGER,
    approved_by INTEGER,
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'published', 'rejected', 'withdrawn')),
    title_snapshot TEXT NOT NULL,
    description_snapshot TEXT,
    questions_count_snapshot INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    published_at TEXT,
    FOREIGN KEY (source_school_id) REFERENCES schools(id) ON DELETE CASCADE,
    FOREIGN KEY (source_assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL,
    FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE (source_school_id, source_assignment_id)
);

CREATE TABLE IF NOT EXISTS library_imports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    library_item_id INTEGER NOT NULL,
    target_school_id INTEGER NOT NULL,
    target_assignment_id INTEGER NOT NULL,
    imported_by INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (library_item_id) REFERENCES library_items(id) ON DELETE CASCADE,
    FOREIGN KEY (target_school_id) REFERENCES schools(id) ON DELETE CASCADE,
    FOREIGN KEY (target_assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
    FOREIGN KEY (imported_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE (library_item_id, target_school_id)
);

CREATE INDEX IF NOT EXISTS idx_library_items_status
    ON library_items(status, published_at);
CREATE INDEX IF NOT EXISTS idx_library_items_school
    ON library_items(source_school_id, status);
CREATE INDEX IF NOT EXISTS idx_library_imports_target
    ON library_imports(target_school_id, created_at);
