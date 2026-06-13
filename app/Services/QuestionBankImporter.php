<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;
use Throwable;
use ZipArchive;

final class QuestionBankImporter
{
    private array $warnings = [];
    private array $mediaByReference = [];
    private array $mediaIds = [];
    private array $mediaIdsByStoredPath = [];
    private array $usedMediaFiles = [];
    private ?string $temporaryDirectory = null;
    private ?string $finalDirectory = null;
    private bool $finalDirectoryCreated = false;

    public function __construct(
        private readonly PDO $pdo,
        private readonly array $config,
        private readonly string $root
    ) {
    }

    public function import(string $csvPath, ?string $zipPath = null, bool $dryRun = false): array
    {
        $batchCode = date('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $parsed = $this->parseCsv($csvPath);
        $rows = $parsed['rows'];
        $summary = [
            'batch_code' => $batchCode,
            'dry_run' => $dryRun,
            'rows_total' => $parsed['rows_total'],
            'rows_imported' => 0,
            'rows_skipped' => $parsed['rows_skipped'],
            'categories_created' => 0,
            'topics_created' => 0,
            'questions_created' => 0,
            'questions_versioned' => 0,
            'questions_unchanged' => 0,
            'options_created' => 0,
            'media_imported' => 0,
            'warnings' => [],
        ];

        $imageReferences = $this->collectImageReferences($rows);
        if ($imageReferences && !$zipPath) {
            throw new RuntimeException('The CSV contains image references, so a matching image ZIP file is required.');
        }
        if ($zipPath) {
            $this->prepareImages($zipPath, $imageReferences, $batchCode);
        }

        foreach ($rows as &$row) {
            $row['content_hash'] = $this->contentHash($row);
        }
        unset($row);

        $batchId = null;

        try {
            $this->pdo->beginTransaction();
            $batchId = $this->createBatch($batchCode, $csvPath, $zipPath, $summary);
            $this->validateExistingGroups($rows);

            foreach ($rows as $row) {
                $this->importRow($row, $batchId, $summary);
            }

            $this->removeUnusedConvertedMedia();
            if (!$dryRun) {
                $this->publishMediaDirectory();
            }

            $summary['warnings'] = $this->warnings;
            $this->completeBatch($batchId, $summary);

            if ($dryRun) {
                $this->pdo->rollBack();
            } else {
                $this->pdo->commit();
            }

            return $summary;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->removeFinalDirectory();
            $this->recordFailedBatch($batchCode, $csvPath, $zipPath, $summary, $exception);
            throw $exception;
        } finally {
            $this->removeDirectory($this->temporaryDirectory);
        }
    }

    private function parseCsv(string $csvPath): array
    {
        if (!is_file($csvPath) || !is_readable($csvPath)) {
            throw new RuntimeException("CSV file is not readable: {$csvPath}");
        }

        $handle = fopen($csvPath, 'rb');
        if (!$handle) {
            throw new RuntimeException("Could not open CSV file: {$csvPath}");
        }

        try {
            $headers = fgetcsv($handle, 0, ',', '"', '\\');
            if (!$headers) {
                throw new RuntimeException('CSV file is empty.');
            }

            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
            $headers = array_map([$this, 'normalizeHeader'], $headers);
            $required = [
                'question_code',
                'subject',
                'topic',
                'question_text',
                'question_type',
                'correct_option',
                'mode',
            ];
            $missing = array_diff($required, $headers);
            if ($missing) {
                throw new RuntimeException('Missing required column(s): ' . implode(', ', $missing));
            }

            $rows = [];
            $rowsTotal = 0;
            $rowsSkipped = 0;
            $questionCodes = [];
            $rowNumber = 1;

            while (($values = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                $rowNumber++;
                if ($this->isBlankRow($values)) {
                    continue;
                }

                $rowsTotal++;
                $data = array_combine($headers, array_pad($values, count($headers), ''));
                if (!$data) {
                    throw new RuntimeException("Row {$rowNumber}: could not read the row.");
                }

                if (!$this->isReadyForImport((string) ($data['ready_for_import'] ?? 'yes'))) {
                    $rowsSkipped++;
                    continue;
                }

                $row = $this->normalizeRow($data, $rowNumber);
                $codeKey = strtolower($row['question_code']);
                if (isset($questionCodes[$codeKey])) {
                    throw new RuntimeException(
                        "Row {$rowNumber}: Question Code {$row['question_code']} is duplicated in the CSV."
                    );
                }
                $questionCodes[$codeKey] = true;
                $rows[] = $row;
            }
        } finally {
            fclose($handle);
        }

        if (!$rows) {
            throw new RuntimeException('No rows are marked ready for import.');
        }

        $this->assignAndValidateGroupSequence($rows);

        return [
            'rows' => $rows,
            'rows_total' => $rowsTotal,
            'rows_skipped' => $rowsSkipped,
        ];
    }

    private function normalizeRow(array $data, int $rowNumber): array
    {
        $questionCode = trim((string) ($data['question_code'] ?? ''));
        $subject = trim((string) ($data['subject'] ?? ''));
        $topic = trim((string) ($data['topic'] ?? ''));
        $groupCode = trim((string) ($data['group_code'] ?? ''));
        $questionText = trim((string) ($data['question_text'] ?? ''));
        $questionType = strtolower(trim((string) ($data['question_type'] ?? '')));
        $correctOption = strtoupper(str_replace(' ', '', trim((string) ($data['correct_option'] ?? ''))));
        $mode = strtolower(trim((string) ($data['mode'] ?? '')));
        $difficulty = strtolower(trim((string) ($data['difficulty'] ?? 'medium')));
        $scoringRule = strtolower(trim((string) ($data['scoring_rule'] ?? 'exact_match')));
        $marks = $this->nonNegativeDecimal($data['marks'] ?? '1', $rowNumber, 'Marks');
        $negativeMarks = $this->nonNegativeDecimal(
            $data['negative_marks'] ?? '0',
            $rowNumber,
            'Negative Marks'
        );

        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{2,99}$/', $questionCode)) {
            throw new RuntimeException(
                "Row {$rowNumber}: Question Code must be 3-100 characters using letters, numbers, dots, hyphens, or underscores."
            );
        }
        if ($subject === '' || $topic === '' || $questionText === '') {
            throw new RuntimeException("Row {$rowNumber}: Subject, Topic, and Question Text are required.");
        }
        if ($groupCode !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{2,99}$/', $groupCode)) {
            throw new RuntimeException(
                "Row {$rowNumber}: Group Code must be 3-100 characters using letters, numbers, dots, hyphens, or underscores."
            );
        }
        if (!in_array($questionType, ['single', 'multi'], true)) {
            throw new RuntimeException("Row {$rowNumber}: Question Type must be single or multi.");
        }
        if (!in_array($mode, ['demo', 'assessment'], true)) {
            throw new RuntimeException("Row {$rowNumber}: Mode must be demo or assessment.");
        }
        if (!in_array($difficulty, ['easy', 'medium', 'hard'], true)) {
            throw new RuntimeException("Row {$rowNumber}: Difficulty must be easy, medium, or hard.");
        }
        if (!in_array($scoringRule, ['exact_match', 'partial_credit'], true)) {
            throw new RuntimeException("Row {$rowNumber}: Scoring Rule must be exact_match or partial_credit.");
        }
        if ($questionType === 'single' && $scoringRule !== 'exact_match') {
            throw new RuntimeException("Row {$rowNumber}: single questions must use exact_match scoring.");
        }
        if ($marks <= 0) {
            throw new RuntimeException("Row {$rowNumber}: Marks must be greater than zero.");
        }

        $options = $this->collectOptions($data, $rowNumber);
        $correctKeys = array_values(array_filter(explode(',', $correctOption)));
        if (!$correctKeys) {
            throw new RuntimeException("Row {$rowNumber}: Correct Option is required.");
        }
        if ($questionType === 'single' && count($correctKeys) !== 1) {
            throw new RuntimeException("Row {$rowNumber}: single questions require exactly one correct option.");
        }
        if (count(array_unique($correctKeys)) !== count($correctKeys)) {
            throw new RuntimeException("Row {$rowNumber}: Correct Option contains a duplicate option letter.");
        }
        foreach ($correctKeys as $key) {
            if (!isset($options[$key])) {
                throw new RuntimeException("Row {$rowNumber}: Correct Option {$key} has no matching option.");
            }
        }

        return [
            'source_row_number' => $rowNumber,
            'question_code' => $questionCode,
            'subject' => $subject,
            'topic' => $topic,
            'group_code' => $groupCode !== '' ? $groupCode : null,
            'group_sequence' => null,
            'passage_text' => trim((string) ($data['passage_text'] ?? '')) ?: null,
            'passage_image' => $this->normalizeImageReference($data['passage_image'] ?? '', $rowNumber),
            'question_text' => $questionText,
            'question_image' => $this->normalizeImageReference($data['question_image'] ?? '', $rowNumber),
            'question_type' => $questionType,
            'options' => $options,
            'correct_keys' => $correctKeys,
            'explanation' => trim((string) ($data['explanation'] ?? '')) ?: null,
            'mode' => $mode,
            'is_active' => $this->booleanValue($data['active'] ?? '1', true),
            'difficulty' => $difficulty,
            'marks' => $marks,
            'negative_marks' => $negativeMarks,
            'scoring_rule' => $scoringRule,
            'shuffle_options' => $this->booleanValue($data['shuffle_options'] ?? 'no', false),
        ];
    }

    private function collectOptions(array $data, int $rowNumber): array
    {
        $options = [];
        foreach (range('A', 'H') as $letter) {
            $key = 'option_' . strtolower($letter);
            $text = trim((string) ($data[$key . '_text'] ?? $data[$key] ?? ''));
            $image = $this->normalizeImageReference($data[$key . '_image'] ?? '', $rowNumber);
            if ($text === '' && $image === null) {
                continue;
            }
            $options[$letter] = [
                'key' => $letter,
                'text' => $text !== '' ? $text : null,
                'image' => $image,
            ];
        }

        if (count($options) < 2) {
            throw new RuntimeException("Row {$rowNumber}: at least two text or image options are required.");
        }

        return $options;
    }

    private function assignAndValidateGroupSequence(array &$rows): void
    {
        $currentGroup = null;
        $closedGroups = [];
        $groupCounts = [];
        $groupMetadata = [];
        $sequence = 0;

        foreach ($rows as &$row) {
            $group = $row['group_code'];
            if ($group === null) {
                if ($currentGroup !== null) {
                    $closedGroups[strtolower($currentGroup)] = true;
                }
                $currentGroup = null;
                $sequence = 0;
                continue;
            }

            $groupKey = strtolower($group);
            if ($currentGroup === null || strcasecmp($currentGroup, $group) !== 0) {
                if ($currentGroup !== null) {
                    $closedGroups[strtolower($currentGroup)] = true;
                }
                if (isset($closedGroups[$groupKey])) {
                    throw new RuntimeException(
                        "Row {$row['source_row_number']}: Group Code {$group} reappears after unrelated rows. Keep every group consecutive."
                    );
                }
                $currentGroup = $group;
                $sequence = 0;
            }

            $sequence++;
            $row['group_sequence'] = $sequence;
            $groupCounts[$groupKey] = ($groupCounts[$groupKey] ?? 0) + 1;
            $metadata = [
                strtolower($row['subject']),
                strtolower($row['topic']),
                $row['mode'],
                $row['passage_text'] ?? '',
                strtolower($row['passage_image'] ?? ''),
            ];
            if (isset($groupMetadata[$groupKey]) && $groupMetadata[$groupKey] !== $metadata) {
                throw new RuntimeException(
                    "Row {$row['source_row_number']}: all questions in Group Code {$group} must share Subject, Topic, Mode, Passage Text, and Passage Image."
                );
            }
            $groupMetadata[$groupKey] = $metadata;
        }
        unset($row);

        foreach ($groupCounts as $groupKey => $count) {
            if ($count < 2) {
                throw new RuntimeException("Group Code {$groupKey} contains only one question; remove the code or add the remaining group questions.");
            }
        }
    }

    private function collectImageReferences(array $rows): array
    {
        $references = [];
        foreach ($rows as $row) {
            foreach ([$row['passage_image'], $row['question_image']] as $reference) {
                if ($reference) {
                    $references[strtolower($reference)] = $reference;
                }
            }
            foreach ($row['options'] as $option) {
                if ($option['image']) {
                    $references[strtolower($option['image'])] = $option['image'];
                }
            }
        }

        return array_values($references);
    }

    private function prepareImages(string $zipPath, array $references, string $batchCode): void
    {
        if (!is_file($zipPath) || !is_readable($zipPath)) {
            throw new RuntimeException("Image ZIP file is not readable: {$zipPath}");
        }
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP ZipArchive is required for image imports.');
        }
        if (!function_exists('imagewebp')) {
            throw new RuntimeException('PHP GD with WebP support is required for image imports.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Could not open the image ZIP file.');
        }

        $maxFiles = (int) ($this->config['question_media_max_files'] ?? 500);
        $maxFileBytes = (int) ($this->config['question_media_max_file_bytes'] ?? 12582912);
        $maxTotalBytes = (int) ($this->config['question_media_max_total_bytes'] ?? 104857600);
        $maxPixels = (int) ($this->config['question_media_max_pixels'] ?? 40000000);
        $entries = [];
        $byBasename = [];
        $totalBytes = 0;

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $rawName = (string) ($stat['name'] ?? '');
                if ($rawName === '' || str_ends_with($rawName, '/')) {
                    continue;
                }

                $name = $this->safeZipPath($rawName);
                $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (!in_array($extension, ['png', 'jpg', 'jpeg', 'webp'], true)) {
                    throw new RuntimeException("ZIP entry {$name} is not an accepted PNG, JPG, JPEG, or WebP image.");
                }
                if ($this->isZipSymlink($zip, $index)) {
                    throw new RuntimeException("ZIP entry {$name} is a symbolic link and was rejected.");
                }

                $size = (int) ($stat['size'] ?? 0);
                $compressedSize = (int) ($stat['comp_size'] ?? 0);
                if ($size <= 0 || $size > $maxFileBytes) {
                    throw new RuntimeException("ZIP entry {$name} exceeds the permitted image size.");
                }
                if ($compressedSize > 0 && $size / $compressedSize > 200) {
                    throw new RuntimeException("ZIP entry {$name} has an unsafe compression ratio.");
                }
                $totalBytes += $size;
                if ($totalBytes > $maxTotalBytes) {
                    throw new RuntimeException('The uncompressed ZIP contents exceed the permitted total size.');
                }

                $key = strtolower($name);
                $basenameKey = strtolower(basename($name));
                if (isset($entries[$key])) {
                    throw new RuntimeException("ZIP contains duplicate case-insensitive path {$name}.");
                }
                if (isset($byBasename[$basenameKey])) {
                    throw new RuntimeException(
                        "ZIP contains duplicate image filename " . basename($name) . '. Image basenames must be unique inside one ZIP.'
                    );
                }

                $entries[$key] = ['index' => $index, 'name' => $name];
                $byBasename[$basenameKey] = $key;
            }

            if (count($entries) > $maxFiles) {
                throw new RuntimeException("The ZIP contains more than {$maxFiles} image files.");
            }

            $this->temporaryDirectory = sys_get_temp_dir() . '/assessment-media-' . $batchCode;
            if (!mkdir($this->temporaryDirectory, 0700, true) && !is_dir($this->temporaryDirectory)) {
                throw new RuntimeException('Could not create the temporary image-processing directory.');
            }

            $usedZipEntries = [];
            foreach ($references as $reference) {
                $referenceKey = strtolower($reference);
                $entryKey = $entries[$referenceKey]['name'] ?? null
                    ? $referenceKey
                    : ($byBasename[strtolower(basename($reference))] ?? null);
                if ($entryKey === null || !isset($entries[$entryKey])) {
                    throw new RuntimeException("Image {$reference} is referenced by the CSV but is missing from the ZIP.");
                }

                $entry = $entries[$entryKey];
                $data = $zip->getFromIndex($entry['index']);
                if (!is_string($data)) {
                    throw new RuntimeException("Could not read image {$entry['name']} from the ZIP.");
                }

                $imageInfo = @getimagesizefromstring($data);
                if (!$imageInfo) {
                    throw new RuntimeException("ZIP entry {$entry['name']} is not a valid image.");
                }
                [$width, $height] = $imageInfo;
                $mime = (string) ($imageInfo['mime'] ?? '');
                if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
                    throw new RuntimeException("ZIP entry {$entry['name']} has unsupported MIME type {$mime}.");
                }
                if ($width <= 0 || $height <= 0 || ($width * $height) > $maxPixels) {
                    throw new RuntimeException("ZIP entry {$entry['name']} has unsafe image dimensions.");
                }

                $sha256 = hash('sha256', $data);
                $base = $this->slug(pathinfo(basename($entry['name']), PATHINFO_FILENAME));
                $storedName = $base . '-' . substr($sha256, 0, 12) . '.webp';
                $temporaryPath = $this->temporaryDirectory . '/' . $storedName;
                $this->convertToWebp($data, $temporaryPath, $entry['name']);

                $publicPrefix = trim((string) ($this->config['question_media_public_prefix'] ?? 'assets/question-media'), '/');
                $metadata = [
                    'original_name' => $entry['name'],
                    'stored_name' => $storedName,
                    'stored_path' => $publicPrefix . '/' . $batchCode . '/' . $storedName,
                    'temporary_path' => $temporaryPath,
                    'mime_type' => 'image/webp',
                    'file_size' => filesize($temporaryPath) ?: 0,
                    'width' => $width,
                    'height' => $height,
                    'sha256' => $sha256,
                ];
                $this->mediaByReference[$referenceKey] = $metadata;
                $usedZipEntries[$entryKey] = true;
            }

            foreach ($entries as $entryKey => $entry) {
                if (!isset($usedZipEntries[$entryKey])) {
                    $this->warnings[] = "Unused ZIP image: {$entry['name']}";
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function contentHash(array $row): string
    {
        $payload = $row;
        unset($payload['source_row_number'], $payload['content_hash']);
        $payload['passage_image_sha256'] = $this->mediaSha($row['passage_image']);
        $payload['question_image_sha256'] = $this->mediaSha($row['question_image']);
        foreach ($payload['options'] as &$option) {
            $option['image_sha256'] = $this->mediaSha($option['image']);
        }
        unset($option);

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function validateExistingGroups(array $rows): void
    {
        $incomingCodes = [];
        $incomingGroups = [];
        foreach ($rows as $row) {
            $incomingCodes[strtolower($row['question_code'])] = true;
            if ($row['group_code']) {
                $incomingGroups[strtolower($row['group_code'])][strtolower($row['question_code'])] = true;
            }
        }

        foreach ($incomingGroups as $groupKey => $codes) {
            $stmt = $this->pdo->prepare(
                'SELECT question_code FROM questions WHERE LOWER(group_code) = ? AND is_current = 1'
            );
            $stmt->execute([$groupKey]);
            $existing = array_map('strtolower', array_column($stmt->fetchAll(), 'question_code'));
            $missing = array_diff($existing, array_keys($codes));
            if ($missing) {
                throw new RuntimeException(
                    "Group Code {$groupKey} already contains questions not present in this import: " . implode(', ', $missing)
                );
            }
        }

        if (!$incomingCodes) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($incomingCodes), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT question_code, group_code
             FROM questions
             WHERE LOWER(question_code) IN ({$placeholders}) AND is_current = 1 AND group_code IS NOT NULL"
        );
        $stmt->execute(array_keys($incomingCodes));
        foreach ($stmt->fetchAll() as $existingRow) {
            $oldGroup = strtolower((string) $existingRow['group_code']);
            $stmtGroup = $this->pdo->prepare(
                'SELECT LOWER(question_code) FROM questions WHERE LOWER(group_code) = ? AND is_current = 1'
            );
            $stmtGroup->execute([$oldGroup]);
            $oldCodes = $stmtGroup->fetchAll(PDO::FETCH_COLUMN);
            $missing = array_diff($oldCodes, array_keys($incomingCodes));
            if ($missing) {
                throw new RuntimeException(
                    "Question {$existingRow['question_code']} belongs to existing group {$existingRow['group_code']}. "
                    . 'Import the complete existing group when changing any member.'
                );
            }
        }
    }

    private function importRow(array $row, int $batchId, array &$summary): void
    {
        $categoryId = $this->findOrCreateCategory($row['subject'], $summary);
        $topicId = $this->findOrCreateTopic($categoryId, $row['topic'], $summary);

        $stmt = $this->pdo->prepare(
            'SELECT id, version_number, content_hash
             FROM questions
             WHERE question_code = ? AND is_current = 1
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute([$row['question_code']]);
        $existing = $stmt->fetch();

        if ($existing && hash_equals((string) ($existing['content_hash'] ?? ''), $row['content_hash'])) {
            $summary['questions_unchanged']++;
            $summary['rows_imported']++;
            return;
        }

        $version = 1;
        if ($existing) {
            $version = (int) $existing['version_number'] + 1;
            $this->pdo->prepare('UPDATE questions SET is_current = 0, is_active = 0 WHERE id = ?')
                ->execute([(int) $existing['id']]);
            $summary['questions_versioned']++;
        } else {
            $summary['questions_created']++;
        }

        $passageMediaId = $this->mediaId($row['passage_image'], $batchId, $summary);
        $questionMediaId = $this->mediaId($row['question_image'], $batchId, $summary);
        $stmt = $this->pdo->prepare(
            'INSERT INTO questions (
                question_code, version_number, is_current, category_id, topic_id,
                group_code, group_sequence, passage_text, passage_media_id,
                question_text, question_media_id, question_type, marks, negative_marks,
                scoring_rule, difficulty, shuffle_options, content_hash, import_batch_id,
                source_row_number, explanation, is_demo, is_active
             ) VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $row['question_code'],
            $version,
            $categoryId,
            $topicId,
            $row['group_code'],
            $row['group_sequence'],
            $row['passage_text'],
            $passageMediaId,
            $row['question_text'],
            $questionMediaId,
            $row['question_type'],
            $row['marks'],
            $row['negative_marks'],
            $row['scoring_rule'],
            $row['difficulty'],
            $row['shuffle_options'] ? 1 : 0,
            $row['content_hash'],
            $batchId,
            $row['source_row_number'],
            $row['explanation'],
            $row['mode'] === 'demo' ? 1 : 0,
            $row['is_active'] ? 1 : 0,
        ]);
        $questionId = (int) $this->pdo->lastInsertId();

        $optionStmt = $this->pdo->prepare(
            'INSERT INTO question_options
             (question_id, option_key, option_text, option_media_id, is_correct, sort_order)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $sortOrder = 1;
        foreach ($row['options'] as $key => $option) {
            $optionStmt->execute([
                $questionId,
                $key,
                $option['text'],
                $this->mediaId($option['image'], $batchId, $summary),
                in_array($key, $row['correct_keys'], true) ? 1 : 0,
                $sortOrder,
            ]);
            $sortOrder++;
            $summary['options_created']++;
        }

        $summary['rows_imported']++;
    }

    private function mediaId(?string $reference, int $batchId, array &$summary): ?int
    {
        if (!$reference) {
            return null;
        }
        $key = strtolower($reference);
        if (isset($this->mediaIds[$key])) {
            return $this->mediaIds[$key];
        }
        $media = $this->mediaByReference[$key] ?? null;
        if (!$media) {
            throw new RuntimeException("Prepared media metadata is missing for {$reference}.");
        }
        if (isset($this->mediaIdsByStoredPath[$media['stored_path']])) {
            $id = $this->mediaIdsByStoredPath[$media['stored_path']];
            $this->mediaIds[$key] = $id;
            $this->usedMediaFiles[$media['stored_name']] = true;
            return $id;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO question_media
             (import_batch_id, original_name, stored_path, mime_type, file_size, width, height, sha256)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $batchId,
            $media['original_name'],
            $media['stored_path'],
            $media['mime_type'],
            $media['file_size'],
            $media['width'],
            $media['height'],
            $media['sha256'],
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->mediaIds[$key] = $id;
        $this->mediaIdsByStoredPath[$media['stored_path']] = $id;
        $this->usedMediaFiles[$media['stored_name']] = true;
        $summary['media_imported']++;

        return $id;
    }

    private function createBatch(
        string $batchCode,
        string $csvPath,
        ?string $zipPath,
        array $summary
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO question_import_batches
             (batch_code, source_filename, image_zip_filename, status, rows_total, rows_skipped)
             VALUES (?, ?, ?, "processing", ?, ?)'
        );
        $stmt->execute([
            $batchCode,
            basename($csvPath),
            $zipPath ? basename($zipPath) : null,
            $summary['rows_total'],
            $summary['rows_skipped'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function completeBatch(int $batchId, array $summary): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE question_import_batches
             SET status = "completed", rows_imported = ?, questions_created = ?,
                 questions_versioned = ?, questions_unchanged = ?, media_imported = ?,
                 warnings = ?, completed_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([
            $summary['rows_imported'],
            $summary['questions_created'],
            $summary['questions_versioned'],
            $summary['questions_unchanged'],
            $summary['media_imported'],
            json_encode($this->warnings, JSON_UNESCAPED_SLASHES),
            $batchId,
        ]);
    }

    private function recordFailedBatch(
        string $batchCode,
        string $csvPath,
        ?string $zipPath,
        array $summary,
        Throwable $exception
    ): void {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO question_import_batches
                 (batch_code, source_filename, image_zip_filename, status, rows_total, rows_skipped, warnings, errors, completed_at)
                 VALUES (?, ?, ?, "failed", ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE status = "failed", warnings = VALUES(warnings),
                    errors = VALUES(errors), completed_at = NOW()'
            );
            $stmt->execute([
                $batchCode,
                basename($csvPath),
                $zipPath ? basename($zipPath) : null,
                $summary['rows_total'],
                $summary['rows_skipped'],
                json_encode($this->warnings, JSON_UNESCAPED_SLASHES),
                json_encode([$exception->getMessage()], JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable) {
            // The original import exception remains the useful failure.
        }
    }

    private function findOrCreateCategory(string $name, array &$summary): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM categories WHERE name = ? LIMIT 1');
        $stmt->execute([$name]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int) $id;
        }
        $this->pdo->prepare('INSERT INTO categories (name) VALUES (?)')->execute([$name]);
        $summary['categories_created']++;
        return (int) $this->pdo->lastInsertId();
    }

    private function findOrCreateTopic(int $categoryId, string $name, array &$summary): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM topics WHERE category_id = ? AND name = ? LIMIT 1');
        $stmt->execute([$categoryId, $name]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int) $id;
        }
        $this->pdo->prepare('INSERT INTO topics (category_id, name) VALUES (?, ?)')->execute([$categoryId, $name]);
        $summary['topics_created']++;
        return (int) $this->pdo->lastInsertId();
    }

    private function publishMediaDirectory(): void
    {
        if (!$this->temporaryDirectory || !$this->usedMediaFiles) {
            return;
        }
        $base = rtrim(
            (string) ($this->config['question_media_public_dir'] ?? $this->root . '/assets/question-media'),
            '/'
        );
        if (!is_dir($base) && !mkdir($base, 0755, true) && !is_dir($base)) {
            throw new RuntimeException('Could not create the question media base directory.');
        }
        $pathParts = explode('/', $this->mediaByReference[array_key_first($this->mediaByReference)]['stored_path']);
        $batchCode = $pathParts[count($pathParts) - 2] ?? '';
        if ($batchCode === '') {
            throw new RuntimeException('Could not determine the generated media batch directory.');
        }
        $this->finalDirectory = $base . '/' . $batchCode;
        if (file_exists($this->finalDirectory)) {
            throw new RuntimeException('The generated media batch directory already exists.');
        }
        if (!rename($this->temporaryDirectory, $this->finalDirectory)) {
            throw new RuntimeException('Could not publish the processed question images.');
        }
        $this->finalDirectoryCreated = true;
        $this->temporaryDirectory = null;
    }

    private function removeUnusedConvertedMedia(): void
    {
        if (!$this->temporaryDirectory || !is_dir($this->temporaryDirectory)) {
            return;
        }
        foreach (glob($this->temporaryDirectory . '/*.webp') ?: [] as $path) {
            if (!isset($this->usedMediaFiles[basename($path)])) {
                @unlink($path);
            }
        }
    }

    private function removeFinalDirectory(): void
    {
        if ($this->finalDirectoryCreated) {
            $this->removeDirectory($this->finalDirectory);
            $this->finalDirectoryCreated = false;
        }
    }

    private function removeDirectory(?string $directory): void
    {
        if (!$directory || !is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }

    private function convertToWebp(string $data, string $destination, string $name): void
    {
        $image = @imagecreatefromstring($data);
        if (!$image) {
            throw new RuntimeException("Could not decode image {$name}.");
        }
        try {
            imagepalettetotruecolor($image);
            imagealphablending($image, false);
            imagesavealpha($image, true);
            if (!imagewebp($image, $destination, 84)) {
                throw new RuntimeException("Could not convert image {$name} to WebP.");
            }
            chmod($destination, 0644);
        } finally {
            if (PHP_VERSION_ID < 80500) {
                imagedestroy($image);
            }
        }
    }

    private function mediaSha(?string $reference): ?string
    {
        if (!$reference) {
            return null;
        }
        return $this->mediaByReference[strtolower($reference)]['sha256'] ?? null;
    }

    private function safeZipPath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if (
            $path === ''
            || str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:\//', $path)
            || preg_match('/[\x00-\x1F\x7F]/', $path)
        ) {
            throw new RuntimeException("ZIP contains unsafe path {$path}.");
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException("ZIP contains unsafe path {$path}.");
            }
        }
        return $path;
    }

    private function isZipSymlink(ZipArchive $zip, int $index): bool
    {
        if (!$zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
            return false;
        }
        return $opsys === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000;
    }

    private function normalizeImageReference(mixed $value, int $rowNumber): ?string
    {
        $reference = str_replace('\\', '/', trim((string) $value));
        if ($reference === '') {
            return null;
        }
        if (
            str_starts_with($reference, '/')
            || preg_match('/^[A-Za-z]:\//', $reference)
            || preg_match('/[\x00-\x1F\x7F]/', $reference)
        ) {
            throw new RuntimeException("Row {$rowNumber}: image reference {$reference} is unsafe.");
        }
        foreach (explode('/', $reference) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException("Row {$rowNumber}: image reference {$reference} is unsafe.");
            }
        }
        $extension = strtolower(pathinfo($reference, PATHINFO_EXTENSION));
        if (!in_array($extension, ['png', 'jpg', 'jpeg', 'webp'], true)) {
            throw new RuntimeException(
                "Row {$rowNumber}: image reference {$reference} must end in PNG, JPG, JPEG, or WebP."
            );
        }
        return $reference;
    }

    private function normalizeHeader(string $header): string
    {
        $header = strtolower(trim($header));
        $header = preg_replace('/[^a-z0-9]+/', '_', $header) ?? $header;
        return trim($header, '_');
    }

    private function isBlankRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }
        return true;
    }

    private function isReadyForImport(string $value): bool
    {
        return !in_array(strtolower(trim($value)), ['0', 'no', 'n', 'false', 'draft', 'not ready'], true);
    }

    private function booleanValue(mixed $value, bool $default): bool
    {
        $raw = strtolower(trim((string) $value));
        if ($raw === '') {
            return $default;
        }
        return in_array($raw, ['1', 'yes', 'y', 'true', 'on', 'active'], true);
    }

    private function nonNegativeDecimal(mixed $value, int $rowNumber, string $label): float
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            $raw = $label === 'Marks' ? '1' : '0';
        }
        if (!is_numeric($raw)) {
            throw new RuntimeException("Row {$rowNumber}: {$label} must be a number.");
        }
        $number = round((float) $raw, 2);
        if ($number < 0) {
            throw new RuntimeException("Row {$rowNumber}: {$label} cannot be negative.");
        }
        return $number;
    }

    private function slug(string $value): string
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $value));
        $slug = trim($slug, '-');
        return substr($slug !== '' ? $slug : 'image', 0, 60);
    }
}
