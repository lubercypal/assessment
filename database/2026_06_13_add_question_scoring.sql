ALTER TABLE questions
    ADD COLUMN marks DECIMAL(8,2) NOT NULL DEFAULT 1.00 AFTER question_type,
    ADD COLUMN negative_marks DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER marks,
    ADD COLUMN scoring_rule ENUM('exact_match', 'partial_credit') NOT NULL DEFAULT 'exact_match' AFTER negative_marks;

ALTER TABLE assessment_attempts
    MODIFY COLUMN score DECIMAL(10,2) NULL;
