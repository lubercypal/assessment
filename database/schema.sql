CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(160) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    mobile_number VARCHAR(20) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    terms_accepted_at DATETIME NOT NULL,
    email_verified_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_sessions (
    id CHAR(64) PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    csrf_token_hash CHAR(64) NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_sessions_user (user_id),
    INDEX idx_user_sessions_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rate_limits (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    action_key CHAR(64) NOT NULL,
    attempts INT NOT NULL DEFAULT 1,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rate_limits_action_key (action_key),
    INDEX idx_rate_limits_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE security_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event VARCHAR(80) NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    context JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_security_events_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_security_events_event (event),
    INDEX idx_security_events_user (user_id),
    INDEX idx_security_events_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_otps (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    otp_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_email_otps_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_email_otps_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_resets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_password_resets_user (user_id),
    INDEX idx_password_resets_token (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE topics (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_topics_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    UNIQUE KEY uq_topics_category_name (category_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE question_import_batches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    batch_code VARCHAR(40) NOT NULL UNIQUE,
    source_filename VARCHAR(255) NOT NULL,
    image_zip_filename VARCHAR(255) NULL,
    status ENUM('processing', 'completed', 'failed') NOT NULL DEFAULT 'processing',
    rows_total INT NOT NULL DEFAULT 0,
    rows_imported INT NOT NULL DEFAULT 0,
    rows_skipped INT NOT NULL DEFAULT 0,
    questions_created INT NOT NULL DEFAULT 0,
    questions_versioned INT NOT NULL DEFAULT 0,
    questions_unchanged INT NOT NULL DEFAULT 0,
    media_imported INT NOT NULL DEFAULT 0,
    warnings JSON NULL,
    errors JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    INDEX idx_question_import_batches_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE question_media (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    import_batch_id BIGINT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_path VARCHAR(500) NOT NULL,
    mime_type VARCHAR(80) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    width INT UNSIGNED NOT NULL,
    height INT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_question_media_batch FOREIGN KEY (import_batch_id)
        REFERENCES question_import_batches(id) ON DELETE RESTRICT,
    UNIQUE KEY uq_question_media_stored_path (stored_path),
    INDEX idx_question_media_batch (import_batch_id),
    INDEX idx_question_media_hash (sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE questions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    question_code VARCHAR(100) NOT NULL,
    version_number INT UNSIGNED NOT NULL DEFAULT 1,
    is_current TINYINT(1) NOT NULL DEFAULT 1,
    category_id BIGINT UNSIGNED NOT NULL,
    topic_id BIGINT UNSIGNED NULL,
    group_code VARCHAR(100) NULL,
    group_sequence INT UNSIGNED NULL,
    passage_text LONGTEXT NULL,
    passage_media_id BIGINT UNSIGNED NULL,
    question_text TEXT NOT NULL,
    question_media_id BIGINT UNSIGNED NULL,
    question_type ENUM('single', 'multi') NOT NULL DEFAULT 'single',
    marks DECIMAL(8,2) NOT NULL DEFAULT 1.00,
    negative_marks DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    scoring_rule ENUM('exact_match', 'partial_credit') NOT NULL DEFAULT 'exact_match',
    difficulty ENUM('easy', 'medium', 'hard') NOT NULL DEFAULT 'medium',
    shuffle_options TINYINT(1) NOT NULL DEFAULT 0,
    content_hash CHAR(64) NULL,
    import_batch_id BIGINT UNSIGNED NULL,
    source_row_number INT UNSIGNED NULL,
    explanation TEXT NULL,
    is_demo TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_questions_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    CONSTRAINT fk_questions_topic FOREIGN KEY (topic_id) REFERENCES topics(id) ON DELETE SET NULL,
    CONSTRAINT fk_questions_passage_media FOREIGN KEY (passage_media_id) REFERENCES question_media(id) ON DELETE SET NULL,
    CONSTRAINT fk_questions_question_media FOREIGN KEY (question_media_id) REFERENCES question_media(id) ON DELETE SET NULL,
    CONSTRAINT fk_questions_import_batch FOREIGN KEY (import_batch_id) REFERENCES question_import_batches(id) ON DELETE SET NULL,
    UNIQUE KEY uq_questions_code_version (question_code, version_number),
    INDEX idx_questions_current_selection (category_id, topic_id, is_demo, is_active, is_current),
    INDEX idx_questions_group_current (group_code, is_current, group_sequence)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE question_options (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    question_id BIGINT UNSIGNED NOT NULL,
    option_key CHAR(1) NOT NULL,
    option_text TEXT NULL,
    option_media_id BIGINT UNSIGNED NULL,
    is_correct TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    CONSTRAINT fk_question_options_question FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE,
    CONSTRAINT fk_question_options_media FOREIGN KEY (option_media_id) REFERENCES question_media(id) ON DELETE SET NULL,
    UNIQUE KEY uq_question_options_key (question_id, option_key),
    INDEX idx_question_options_question (question_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE assessment_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    topic_id BIGINT UNSIGNED NULL,
    mode ENUM('demo', 'assessment') NOT NULL DEFAULT 'assessment',
    question_order JSON NOT NULL,
    option_orders JSON NULL,
    total_questions INT NOT NULL,
    duration_seconds INT NOT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    submitted_at DATETIME NULL,
    expires_at DATETIME NOT NULL,
    score DECIMAL(10,2) NULL,
    CONSTRAINT fk_attempts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_attempts_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    CONSTRAINT fk_attempts_topic FOREIGN KEY (topic_id) REFERENCES topics(id) ON DELETE SET NULL,
    INDEX idx_attempts_user (user_id, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE assessment_responses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attempt_id BIGINT UNSIGNED NOT NULL,
    question_id BIGINT UNSIGNED NOT NULL,
    selected_option_ids JSON NULL,
    status ENUM('answered', 'skipped', 'review') NOT NULL DEFAULT 'skipped',
    answered_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_responses_attempt FOREIGN KEY (attempt_id) REFERENCES assessment_attempts(id) ON DELETE CASCADE,
    CONSTRAINT fk_responses_question FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE,
    UNIQUE KEY uq_response_attempt_question (attempt_id, question_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
