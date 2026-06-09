<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
date_default_timezone_set('Asia/Kolkata');
require_once $root . '/config/database.php';

$csvPath = $argv[1] ?? '';
$dryRun = in_array('--dry-run', $argv, true);

if ($csvPath === '' || in_array($csvPath, ['-h', '--help'], true)) {
    fwrite(STDERR, "Usage: php scripts/import_question_bank.php path/to/question_bank.csv [--dry-run]\n");
    exit($csvPath === '' ? 1 : 0);
}

if (!str_starts_with($csvPath, '/')) {
    $csvPath = $root . '/' . $csvPath;
}

if (!is_file($csvPath)) {
    fwrite(STDERR, "CSV file not found: {$csvPath}\n");
    exit(1);
}

$handle = fopen($csvPath, 'rb');
if (!$handle) {
    fwrite(STDERR, "Could not open CSV file: {$csvPath}\n");
    exit(1);
}

$headers = fgetcsv($handle, 0, ',', '"', '\\');
if (!$headers) {
    fwrite(STDERR, "CSV file is empty.\n");
    exit(1);
}

$headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
$headers = array_map('normalize_header', $headers);

$required = ['subject', 'topic', 'question_text', 'question_type', 'correct_option', 'mode'];
$missing = array_diff($required, $headers);
if ($missing) {
    fwrite(STDERR, "Missing required column(s): " . implode(', ', $missing) . "\n");
    exit(1);
}

$pdo = null;
$summary = [
    'rows' => 0,
    'categories_created' => 0,
    'topics_created' => 0,
    'questions_created' => 0,
    'options_created' => 0,
];

try {
    $pdo = db();
    $pdo->beginTransaction();

    $rowNumber = 1;
    while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        $rowNumber++;
        if (is_blank_row($row)) {
            continue;
        }

        $data = array_combine($headers, array_pad($row, count($headers), ''));
        if (!$data) {
            throw new RuntimeException("Row {$rowNumber}: could not read row.");
        }

        $summary['rows']++;
        import_row($pdo, $data, $rowNumber, $summary);
    }

    if ($dryRun) {
        $pdo->rollBack();
        echo "Dry run passed. No database changes were saved.\n";
    } else {
        $pdo->commit();
        echo "Import completed.\n";
    }

    foreach ($summary as $label => $value) {
        echo str_replace('_', ' ', ucfirst($label)) . ': ' . $value . PHP_EOL;
    }
} catch (Throwable $exception) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Import failed: " . $exception->getMessage() . "\n");
    exit(1);
} finally {
    fclose($handle);
}

function import_row(PDO $pdo, array $data, int $rowNumber, array &$summary): void
{
    $subject = trim((string) ($data['subject'] ?? ''));
    $topic = trim((string) ($data['topic'] ?? ''));
    $questionText = trim((string) ($data['question_text'] ?? ''));
    $questionType = strtolower(trim((string) ($data['question_type'] ?? '')));
    $correctOption = strtoupper(str_replace(' ', '', trim((string) ($data['correct_option'] ?? ''))));
    $explanation = trim((string) ($data['explanation'] ?? ''));
    $mode = strtolower(trim((string) ($data['mode'] ?? '')));
    $activeRaw = strtolower(trim((string) ($data['active'] ?? '1')));

    if ($subject === '' || $topic === '' || $questionText === '') {
        throw new RuntimeException("Row {$rowNumber}: subject, topic, and question text are required.");
    }
    if (!in_array($questionType, ['single', 'multi'], true)) {
        throw new RuntimeException("Row {$rowNumber}: Question Type must be single or multi.");
    }
    if (!in_array($mode, ['demo', 'assessment'], true)) {
        throw new RuntimeException("Row {$rowNumber}: Mode must be demo or assessment.");
    }

    $options = collect_options($data);
    if (count($options) < 2) {
        throw new RuntimeException("Row {$rowNumber}: at least two options are required.");
    }

    $correctKeys = array_filter(explode(',', $correctOption));
    if (!$correctKeys) {
        throw new RuntimeException("Row {$rowNumber}: Correct Option is required.");
    }
    if ($questionType === 'single' && count($correctKeys) !== 1) {
        throw new RuntimeException("Row {$rowNumber}: single questions must have exactly one correct option.");
    }

    foreach ($correctKeys as $key) {
        if (!array_key_exists($key, $options)) {
            throw new RuntimeException("Row {$rowNumber}: Correct Option {$key} does not match an option column.");
        }
    }

    $categoryId = find_or_create_category($pdo, $subject, $summary);
    $topicId = find_or_create_topic($pdo, $categoryId, $topic, $summary);
    $isDemo = $mode === 'demo' ? 1 : 0;
    $isActive = in_array($activeRaw, ['1', 'yes', 'y', 'true', 'active', ''], true) ? 1 : 0;

    $stmt = $pdo->prepare(
        'INSERT INTO questions (category_id, topic_id, question_text, question_type, explanation, is_demo, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$categoryId, $topicId, $questionText, $questionType, $explanation ?: null, $isDemo, $isActive]);
    $questionId = (int) $pdo->lastInsertId();
    $summary['questions_created']++;

    $sortOrder = 1;
    $stmt = $pdo->prepare(
        'INSERT INTO question_options (question_id, option_text, is_correct, sort_order)
         VALUES (?, ?, ?, ?)'
    );
    foreach ($options as $key => $text) {
        $stmt->execute([$questionId, $text, in_array($key, $correctKeys, true) ? 1 : 0, $sortOrder]);
        $summary['options_created']++;
        $sortOrder++;
    }
}

function collect_options(array $data): array
{
    $options = [];
    foreach (range('A', 'H') as $letter) {
        $key = 'option_' . strtolower($letter);
        $value = trim((string) ($data[$key] ?? ''));
        if ($value !== '') {
            $options[$letter] = $value;
        }
    }

    return $options;
}

function find_or_create_category(PDO $pdo, string $name, array &$summary): int
{
    $stmt = $pdo->prepare('SELECT id FROM categories WHERE name = ? LIMIT 1');
    $stmt->execute([$name]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int) $id;
    }

    $stmt = $pdo->prepare('INSERT INTO categories (name) VALUES (?)');
    $stmt->execute([$name]);
    $summary['categories_created']++;

    return (int) $pdo->lastInsertId();
}

function find_or_create_topic(PDO $pdo, int $categoryId, string $name, array &$summary): int
{
    $stmt = $pdo->prepare('SELECT id FROM topics WHERE category_id = ? AND name = ? LIMIT 1');
    $stmt->execute([$categoryId, $name]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int) $id;
    }

    $stmt = $pdo->prepare('INSERT INTO topics (category_id, name) VALUES (?, ?)');
    $stmt->execute([$categoryId, $name]);
    $summary['topics_created']++;

    return (int) $pdo->lastInsertId();
}

function normalize_header(string $header): string
{
    $header = strtolower(trim($header));
    $header = preg_replace('/[^a-z0-9]+/', '_', $header) ?? $header;

    return trim($header, '_');
}

function is_blank_row(array $row): bool
{
    foreach ($row as $value) {
        if (trim((string) $value) !== '') {
            return false;
        }
    }

    return true;
}
