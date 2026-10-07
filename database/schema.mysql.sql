-- UROVIA MySQL schema.
-- Keep this schema data-free: production migration copies the existing SQLite rows verbatim.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS urovia_meta (
  meta_key VARCHAR(100) NOT NULL,
  meta_value VARCHAR(255) NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (meta_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  first_name VARCHAR(191) NOT NULL,
  last_name VARCHAR(191) NOT NULL,
  email VARCHAR(255) NOT NULL,
  login_name VARCHAR(191) NULL,
  password_hash VARCHAR(255) NOT NULL,
  must_change_password TINYINT NOT NULL DEFAULT 0,
  credentials_sent_at DATETIME NULL,
  role VARCHAR(32) NOT NULL,
  is_platform_admin TINYINT NOT NULL DEFAULT 0,
  session_version INT NOT NULL DEFAULT 0,
  middle_name VARCHAR(191) NULL,
  phone VARCHAR(64) NULL,
  avatar_name VARCHAR(255) NULL,
  class_name VARCHAR(191) NULL,
  is_active TINYINT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_login_name (login_name),
  KEY idx_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subjects (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(191) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_subjects_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schools (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  slug VARCHAR(191) NULL,
  city VARCHAR(191) NULL,
  theme_color VARCHAR(16) NOT NULL DEFAULT '#1d68f0',
  favicon_data LONGTEXT NULL,
  assignment_review_required TINYINT NOT NULL DEFAULT 0,
  grade_5_min INT NOT NULL DEFAULT 85,
  grade_4_min INT NOT NULL DEFAULT 71,
  grade_3_min INT NOT NULL DEFAULT 50,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_schools_slug (slug),
  KEY idx_schools_created_by (created_by),
  CONSTRAINT fk_schools_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS classes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(191) NOT NULL,
  display_name VARCHAR(191) NULL,
  teacher_id BIGINT UNSIGNED NULL,
  academic_year VARCHAR(64) NULL,
  school_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_classes_name (name),
  KEY idx_classes_teacher (teacher_id),
  KEY idx_classes_school (school_id),
  CONSTRAINT fk_classes_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_classes_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assignments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  teacher_id BIGINT UNSIGNED NOT NULL,
  school_id BIGINT UNSIGNED NULL,
  subject_id BIGINT UNSIGNED NULL,
  title VARCHAR(500) NOT NULL,
  description LONGTEXT NULL,
  type VARCHAR(32) NOT NULL DEFAULT 'quiz',
  status VARCHAR(32) NOT NULL DEFAULT 'draft',
  workflow_status VARCHAR(32) NOT NULL DEFAULT 'draft',
  review_submitted_at DATETIME NULL,
  reviewed_at DATETIME NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  review_comment LONGTEXT NULL,
  completed_at DATETIME NULL,
  max_attempts INT NOT NULL DEFAULT 1,
  time_limit_minutes INT NULL,
  starts_at DATETIME NULL,
  due_at DATETIME NULL,
  show_answers TINYINT NOT NULL DEFAULT 0,
  focus_policy VARCHAR(32) NOT NULL DEFAULT 'allow',
  variant_count INT NOT NULL DEFAULT 1,
  shuffle_questions TINYINT NOT NULL DEFAULT 0,
  shuffle_options TINYINT NOT NULL DEFAULT 0,
  shuffle_structured TINYINT NOT NULL DEFAULT 0,
  source_school_id BIGINT UNSIGNED NULL,
  source_assignment_id BIGINT UNSIGNED NULL,
  shared_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_assignments_teacher (teacher_id),
  KEY idx_assignments_school (school_id),
  KEY idx_assignments_subject (subject_id),
  KEY idx_assignments_workflow_status (school_id, workflow_status),
  KEY idx_assignments_school_status_updated (school_id, status, updated_at),
  UNIQUE KEY uq_assignments_shared_origin (school_id, source_school_id, source_assignment_id),
  CONSTRAINT fk_assignments_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_assignments_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
  CONSTRAINT fk_assignments_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL,
  CONSTRAINT fk_assignments_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_assignments_source_school FOREIGN KEY (source_school_id) REFERENCES schools(id) ON DELETE SET NULL,
  CONSTRAINT fk_assignments_source_assignment FOREIGN KEY (source_assignment_id) REFERENCES assignments(id) ON DELETE SET NULL,
  CONSTRAINT fk_assignments_shared_by FOREIGN KEY (shared_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS school_users (
  school_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  role VARCHAR(32) NOT NULL,
  can_teach TINYINT NOT NULL DEFAULT 0,
  is_active TINYINT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (school_id, user_id),
  KEY idx_school_users_user (user_id),
  CONSTRAINT fk_school_users_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
  CONSTRAINT fk_school_users_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS school_subjects (
  school_id BIGINT UNSIGNED NOT NULL,
  subject_id BIGINT UNSIGNED NOT NULL,
  is_active TINYINT NOT NULL DEFAULT 1,
  PRIMARY KEY (school_id, subject_id),
  KEY idx_school_subjects_subject (subject_id),
  CONSTRAINT fk_school_subjects_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
  CONSTRAINT fk_school_subjects_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS teacher_subjects (
  school_id BIGINT UNSIGNED NOT NULL,
  teacher_id BIGINT UNSIGNED NOT NULL,
  subject_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (school_id, teacher_id, subject_id),
  KEY idx_teacher_subjects_teacher (teacher_id),
  KEY idx_teacher_subjects_subject (subject_id),
  CONSTRAINT fk_teacher_subjects_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
  CONSTRAINT fk_teacher_subjects_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_teacher_subjects_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS teacher_classes (
  school_id BIGINT UNSIGNED NOT NULL,
  teacher_id BIGINT UNSIGNED NOT NULL,
  class_id BIGINT UNSIGNED NOT NULL,
  subject_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (school_id, teacher_id, class_id, subject_id),
  KEY idx_teacher_classes_teacher (teacher_id),
  KEY idx_teacher_classes_class (class_id),
  KEY idx_teacher_classes_subject (subject_id),
  CONSTRAINT fk_teacher_classes_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
  CONSTRAINT fk_teacher_classes_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_teacher_classes_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
  CONSTRAINT fk_teacher_classes_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assignment_classes (
  assignment_id BIGINT UNSIGNED NOT NULL,
  class_id BIGINT UNSIGNED NOT NULL,
  time_limit_minutes INT NULL,
  PRIMARY KEY (assignment_id, class_id),
  KEY idx_assignment_classes_class (class_id, assignment_id),
  CONSTRAINT fk_assignment_classes_assignment FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
  CONSTRAINT fk_assignment_classes_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assignment_students (
  assignment_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  time_limit_minutes INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (assignment_id, student_id),
  KEY idx_assignment_students_student (student_id, assignment_id),
  CONSTRAINT fk_assignment_students_assignment FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
  CONSTRAINT fk_assignment_students_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assignment_imports (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  assignment_id BIGINT UNSIGNED NOT NULL,
  original_name VARCHAR(500) NOT NULL,
  stored_name VARCHAR(500) NOT NULL,
  source_format VARCHAR(32) NOT NULL,
  mime_type VARCHAR(191) NULL,
  size_bytes BIGINT NOT NULL DEFAULT 0,
  parse_status VARCHAR(64) NOT NULL DEFAULT 'uploaded',
  extracted_text LONGTEXT NULL,
  parsed_question_count INT NOT NULL DEFAULT 0,
  parser_message LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_assignment_imports_assignment (assignment_id),
  CONSTRAINT fk_assignment_imports_assignment FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS questions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  assignment_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(32) NOT NULL,
  text LONGTEXT NOT NULL,
  points DOUBLE NOT NULL DEFAULT 1,
  position INT NOT NULL DEFAULT 0,
  correct_text LONGTEXT NULL,
  interaction_type VARCHAR(64) NULL,
  settings_json LONGTEXT NULL,
  is_active TINYINT NOT NULL DEFAULT 1,
  revision_of_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_questions_assignment_position (assignment_id, position, id),
  KEY idx_questions_assignment_active_position (assignment_id, is_active, position, id),
  CONSTRAINT fk_questions_assignment FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS question_options (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  question_id BIGINT UNSIGNED NOT NULL,
  text LONGTEXT NOT NULL,
  is_correct TINYINT NOT NULL DEFAULT 0,
  position INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_question_options_question (question_id, position, id),
  CONSTRAINT fk_question_options_question FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS question_assets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  question_id BIGINT UNSIGNED NOT NULL,
  stored_name VARCHAR(500) NOT NULL,
  original_name VARCHAR(500) NULL,
  mime_type VARCHAR(191) NULL,
  position INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_question_assets_question (question_id, position, id),
  CONSTRAINT fk_question_assets_question FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  assignment_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  submitted_at DATETIME NULL,
  score DOUBLE NULL,
  max_score DOUBLE NULL,
  percent DOUBLE NULL,
  grade VARCHAR(16) NULL,
  manual_score DOUBLE NULL,
  manual_percent DOUBLE NULL,
  manual_grade VARCHAR(16) NULL,
  manual_comment LONGTEXT NULL,
  manual_updated_at DATETIME NULL,
  manual_updated_by BIGINT UNSIGNED NULL,
  published_score DOUBLE NULL,
  published_percent DOUBLE NULL,
  published_grade VARCHAR(16) NULL,
  published_comment LONGTEXT NULL,
  result_published_at DATETIME NULL,
  result_published_by BIGINT UNSIGNED NULL,
  result_revision INT NOT NULL DEFAULT 0,
  time_limit_snapshot INT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'in_progress',
  last_seen_at DATETIME NULL,
  termination_reason VARCHAR(64) NULL,
  focus_violations INT NOT NULL DEFAULT 0,
  attempt_session_hash CHAR(64) NULL,
  variant_index INT NOT NULL DEFAULT 0,
  variant_label VARCHAR(16) NULL,
  question_order_json LONGTEXT NULL,
  option_order_json LONGTEXT NULL,
  structured_order_json LONGTEXT NULL,
  PRIMARY KEY (id),
  KEY idx_attempts_assignment (assignment_id),
  KEY idx_attempts_student (student_id),
  KEY idx_attempts_assignment_student_status_id (assignment_id, student_id, status, id),
  KEY idx_attempts_student_status_submitted (student_id, status, submitted_at),
  KEY idx_attempts_assignment_status_submitted (assignment_id, status, submitted_at),
  CONSTRAINT fk_attempts_assignment FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
  CONSTRAINT fk_attempts_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_attempts_manual_updated_by FOREIGN KEY (manual_updated_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_attempts_result_published_by FOREIGN KEY (result_published_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS answers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  attempt_id BIGINT UNSIGNED NOT NULL,
  question_id BIGINT UNSIGNED NOT NULL,
  answer_text LONGTEXT NULL,
  score DOUBLE NULL,
  is_correct TINYINT NULL,
  needs_review TINYINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_answers_attempt_question (attempt_id, question_id),
  KEY idx_answers_attempt_updated (attempt_id, updated_at),
  KEY idx_answers_question (question_id),
  CONSTRAINT fk_answers_attempt FOREIGN KEY (attempt_id) REFERENCES attempts(id) ON DELETE CASCADE,
  CONSTRAINT fk_answers_question FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attempt_result_revisions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  attempt_id BIGINT UNSIGNED NOT NULL,
  revision INT NOT NULL,
  score DOUBLE NOT NULL,
  max_score DOUBLE NOT NULL,
  percent DOUBLE NOT NULL,
  grade VARCHAR(16) NOT NULL,
  comment LONGTEXT NULL,
  published_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_attempt_result_revision (attempt_id, revision),
  KEY idx_attempt_result_revisions_attempt (attempt_id, revision),
  CONSTRAINT fk_attempt_result_revisions_attempt FOREIGN KEY (attempt_id) REFERENCES attempts(id) ON DELETE CASCADE,
  CONSTRAINT fk_attempt_result_revisions_publisher FOREIGN KEY (published_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS class_access (
  class_id BIGINT UNSIGNED NOT NULL,
  join_code VARCHAR(64) NOT NULL,
  registration_open TINYINT NOT NULL DEFAULT 0,
  registration_expires_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (class_id),
  UNIQUE KEY uq_class_access_code (join_code),
  CONSTRAINT fk_class_access_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS class_students (
  student_id BIGINT UNSIGNED NOT NULL,
  class_id BIGINT UNSIGNED NOT NULL,
  pin_hash VARCHAR(255) NULL,
  activated_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (student_id),
  KEY idx_class_students_class (class_id),
  CONSTRAINT fk_class_students_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_class_students_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS school_material_transfers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_school_id BIGINT UNSIGNED NOT NULL,
  target_school_id BIGINT UNSIGNED NOT NULL,
  subject_id BIGINT UNSIGNED NOT NULL,
  sender_user_id BIGINT UNSIGNED NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  resolved_by BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_material_transfers_target (target_school_id, status, created_at),
  KEY idx_material_transfers_source (source_school_id, created_at),
  CONSTRAINT fk_material_transfer_source FOREIGN KEY (source_school_id) REFERENCES schools(id) ON DELETE CASCADE,
  CONSTRAINT fk_material_transfer_target FOREIGN KEY (target_school_id) REFERENCES schools(id) ON DELETE CASCADE,
  CONSTRAINT fk_material_transfer_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
  CONSTRAINT fk_material_transfer_sender FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_material_transfer_resolver FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS school_material_transfer_assignments (
  transfer_id BIGINT UNSIGNED NOT NULL,
  assignment_id BIGINT UNSIGNED NOT NULL,
  position INT NOT NULL DEFAULT 0,
  title_snapshot VARCHAR(500) NOT NULL,
  type_snapshot VARCHAR(64) NULL,
  questions_count_snapshot INT NOT NULL DEFAULT 0,
  source_format_snapshot VARCHAR(64) NULL,
  PRIMARY KEY (transfer_id, assignment_id),
  KEY idx_transfer_assignments_assignment (assignment_id),
  CONSTRAINT fk_transfer_assignments_transfer FOREIGN KEY (transfer_id) REFERENCES school_material_transfers(id) ON DELETE CASCADE,
  CONSTRAINT fk_transfer_assignments_assignment FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  school_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(191) NOT NULL,
  entity_type VARCHAR(191) NULL,
  entity_id BIGINT UNSIGNED NULL,
  metadata_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_log_school (school_id, created_at),
  KEY idx_audit_log_user (user_id, created_at),
  KEY idx_audit_log_entity_event (entity_type, entity_id, event_type, id),
  CONSTRAINT fk_audit_log_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE SET NULL,
  CONSTRAINT fk_audit_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attempt_security_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  attempt_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(191) NOT NULL,
  details LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_attempt_security_attempt (attempt_id, created_at),
  CONSTRAINT fk_attempt_security_attempt FOREIGN KEY (attempt_id) REFERENCES attempts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_throttle (
  key_hash CHAR(64) NOT NULL,
  failures INT NOT NULL DEFAULT 0,
  window_started BIGINT NOT NULL,
  locked_until BIGINT NULL,
  updated_at BIGINT NOT NULL,
  PRIMARY KEY (key_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at BIGINT NOT NULL,
  used_at BIGINT NULL,
  created_at BIGINT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_password_reset_token_hash (token_hash),
  KEY idx_password_reset_tokens_user (user_id, expires_at),
  CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS library_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_school_id BIGINT UNSIGNED NOT NULL,
  source_assignment_id BIGINT UNSIGNED NOT NULL,
  subject_id BIGINT UNSIGNED NULL,
  submitted_by BIGINT UNSIGNED NULL,
  approved_by BIGINT UNSIGNED NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  title_snapshot VARCHAR(500) NOT NULL,
  description_snapshot LONGTEXT NULL,
  questions_count_snapshot INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  published_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_library_source (source_school_id, source_assignment_id),
  KEY idx_library_items_status (status, published_at),
  KEY idx_library_items_school (source_school_id, status),
  CONSTRAINT fk_library_source_school FOREIGN KEY (source_school_id) REFERENCES schools(id) ON DELETE CASCADE,
  CONSTRAINT fk_library_source_assignment FOREIGN KEY (source_assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
  CONSTRAINT fk_library_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL,
  CONSTRAINT fk_library_submitted_by FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_library_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS library_imports (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  library_item_id BIGINT UNSIGNED NOT NULL,
  target_school_id BIGINT UNSIGNED NOT NULL,
  target_assignment_id BIGINT UNSIGNED NOT NULL,
  imported_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_library_import_target (library_item_id, target_school_id),
  KEY idx_library_imports_target (target_school_id, created_at),
  CONSTRAINT fk_library_import_item FOREIGN KEY (library_item_id) REFERENCES library_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_library_import_school FOREIGN KEY (target_school_id) REFERENCES schools(id) ON DELETE CASCADE,
  CONSTRAINT fk_library_import_assignment FOREIGN KEY (target_assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
  CONSTRAINT fk_library_import_user FOREIGN KEY (imported_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
