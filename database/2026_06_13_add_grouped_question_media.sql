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

ALTER TABLE questions
    ADD COLUMN question_code VARCHAR(100) NULL AFTER id,
    ADD COLUMN version_number INT UNSIGNED NOT NULL DEFAULT 1 AFTER question_code,
    ADD COLUMN is_current TINYINT(1) NOT NULL DEFAULT 1 AFTER version_number,
    ADD COLUMN group_code VARCHAR(100) NULL AFTER topic_id,
    ADD COLUMN group_sequence INT UNSIGNED NULL AFTER group_code,
    ADD COLUMN passage_text LONGTEXT NULL AFTER group_sequence,
    ADD COLUMN passage_media_id BIGINT UNSIGNED NULL AFTER passage_text,
    ADD COLUMN question_media_id BIGINT UNSIGNED NULL AFTER question_text,
    ADD COLUMN difficulty ENUM('easy', 'medium', 'hard') NOT NULL DEFAULT 'medium' AFTER scoring_rule,
    ADD COLUMN shuffle_options TINYINT(1) NOT NULL DEFAULT 0 AFTER difficulty,
    ADD COLUMN content_hash CHAR(64) NULL AFTER shuffle_options,
    ADD COLUMN import_batch_id BIGINT UNSIGNED NULL AFTER content_hash,
    ADD COLUMN source_row_number INT UNSIGNED NULL AFTER import_batch_id;

UPDATE questions
SET question_code = CONCAT('LEGACY-', LPAD(id, 10, '0')),
    content_hash = SHA2(CONCAT('legacy:', id), 256)
WHERE question_code IS NULL;

ALTER TABLE questions
    MODIFY COLUMN question_code VARCHAR(100) NOT NULL,
    ADD CONSTRAINT fk_questions_passage_media FOREIGN KEY (passage_media_id)
        REFERENCES question_media(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_questions_question_media FOREIGN KEY (question_media_id)
        REFERENCES question_media(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_questions_import_batch FOREIGN KEY (import_batch_id)
        REFERENCES question_import_batches(id) ON DELETE SET NULL,
    ADD UNIQUE KEY uq_questions_code_version (question_code, version_number),
    ADD INDEX idx_questions_current_selection
        (category_id, topic_id, is_demo, is_active, is_current),
    ADD INDEX idx_questions_group_current (group_code, is_current, group_sequence);

ALTER TABLE question_options
    MODIFY COLUMN option_text TEXT NULL,
    ADD COLUMN option_key CHAR(1) NULL AFTER question_id,
    ADD COLUMN option_media_id BIGINT UNSIGNED NULL AFTER option_text,
    ADD CONSTRAINT fk_question_options_media FOREIGN KEY (option_media_id)
        REFERENCES question_media(id) ON DELETE SET NULL;

UPDATE question_options
SET option_key = CHAR(64 + sort_order)
WHERE option_key IS NULL;

ALTER TABLE question_options
    MODIFY COLUMN option_key CHAR(1) NOT NULL,
    ADD UNIQUE KEY uq_question_options_key (question_id, option_key);

ALTER TABLE assessment_attempts
    ADD COLUMN option_orders JSON NULL AFTER question_order;
