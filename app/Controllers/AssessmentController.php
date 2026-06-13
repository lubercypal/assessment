<?php

namespace App\Controllers;

use App\Core\Response;
use App\Core\Validator;
use App\Services\AuthService;

final class AssessmentController
{
    public function categories(): void
    {
        $rows = db()->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
        Response::ok(['categories' => $rows]);
    }

    public function topics(array $query): void
    {
        $categoryId = (int) ($query['category_id'] ?? 0);
        $stmt = db()->prepare('SELECT id, name FROM topics WHERE category_id = ? ORDER BY name');
        $stmt->execute([$categoryId]);
        Response::ok(['topics' => $stmt->fetchAll()]);
    }

    public function start(array $data): void
    {
        $user = AuthService::user();
        $errors = Validator::required($data, ['category_id', 'mode']);
        if ($errors) {
            Response::error('Validation failed.', 422, $errors);
        }

        $mode = ($data['mode'] ?? '') === 'demo' ? 'demo' : 'assessment';
        $limit = $mode === 'demo'
            ? self::configInt('demo_question_count', 5, 1, 100)
            : self::configInt('assessment_question_count', 30, 1, 300);
        $duration = $mode === 'demo' ? 900 : 3600;
        $categoryId = (int) $data['category_id'];
        $topicId = !empty($data['topic_id']) ? (int) $data['topic_id'] : null;

        $where = 'category_id = ? AND is_current = 1 AND is_demo = ?';
        $params = [$categoryId];
        $params[] = $mode === 'demo' ? 1 : 0;
        if ($topicId) {
            $where .= ' AND topic_id = ?';
            $params[] = $topicId;
        }

        $stmt = db()->prepare(
            "SELECT id, group_code, group_sequence, is_active
             FROM questions
             WHERE {$where}
             ORDER BY id"
        );
        $stmt->execute($params);
        $units = $this->questionUnits($stmt->fetchAll());
        if ((bool) app_config('question_shuffle_enabled', true)) {
            shuffle($units);
        }
        $questionIds = $this->selectQuestionIds($units, $limit);

        if (!$questionIds) {
            Response::error('No questions are available for this selection.', 404);
        }

        $optionOrders = $this->buildOptionOrders($questionIds);
        $stmt = db()->prepare(
            'INSERT INTO assessment_attempts
             (user_id, category_id, topic_id, mode, question_order, option_orders, total_questions, duration_seconds, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))'
        );
        $stmt->execute([
            $user['id'],
            $categoryId,
            $topicId,
            $mode,
            json_encode($questionIds),
            json_encode($optionOrders),
            count($questionIds),
            $duration,
            $duration,
        ]);

        Response::ok([
            'attempt_id' => (int) db()->lastInsertId(),
            'total_questions' => count($questionIds),
            'duration_seconds' => $duration,
        ], 201);
    }

    private static function configInt(string $key, int $default, int $min, int $max): int
    {
        $value = (int) app_config($key, $default);
        return max($min, min($max, $value));
    }

    private function questionUnits(array $rows): array
    {
        $units = [];
        $grouped = [];

        foreach ($rows as $row) {
            $question = [
                'id' => (int) $row['id'],
                'group_sequence' => (int) ($row['group_sequence'] ?? 0),
                'is_active' => (int) $row['is_active'] === 1,
            ];
            $groupCode = trim((string) ($row['group_code'] ?? ''));
            if ($groupCode === '') {
                if ($question['is_active']) {
                    $units[] = [
                        'first_id' => $question['id'],
                        'question_ids' => [$question['id']],
                    ];
                }
                continue;
            }
            $grouped[strtolower($groupCode)][] = $question;
        }

        foreach ($grouped as $questions) {
            if (array_filter($questions, fn (array $question): bool => !$question['is_active'])) {
                continue;
            }
            usort(
                $questions,
                fn (array $left, array $right): int =>
                    [$left['group_sequence'], $left['id']] <=> [$right['group_sequence'], $right['id']]
            );
            $units[] = [
                'first_id' => min(array_column($questions, 'id')),
                'question_ids' => array_column($questions, 'id'),
            ];
        }

        if (!(bool) app_config('question_shuffle_enabled', true)) {
            usort($units, fn (array $left, array $right): int => $left['first_id'] <=> $right['first_id']);
        }

        return $units;
    }

    private function selectQuestionIds(array $units, int $limit): array
    {
        $selected = [];
        foreach ($units as $unit) {
            $ids = array_map('intval', $unit['question_ids'] ?? []);
            if (!$ids || count($selected) + count($ids) > $limit) {
                continue;
            }
            array_push($selected, ...$ids);
            if (count($selected) >= $limit) {
                break;
            }
        }

        return $selected;
    }

    private function buildOptionOrders(array $questionIds): array
    {
        if (!$questionIds) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
        $stmt = db()->prepare(
            "SELECT q.id AS question_id, q.shuffle_options, qo.id AS option_id
             FROM questions q
             JOIN question_options qo ON qo.question_id = q.id
             WHERE q.id IN ({$placeholders})
             ORDER BY q.id, qo.sort_order, qo.id"
        );
        $stmt->execute($questionIds);
        $orders = [];
        $shuffleByQuestion = [];
        foreach ($stmt->fetchAll() as $row) {
            $questionId = (int) $row['question_id'];
            $orders[(string) $questionId][] = (int) $row['option_id'];
            $shuffleByQuestion[$questionId] = (int) $row['shuffle_options'] === 1;
        }
        foreach ($shuffleByQuestion as $questionId => $shouldShuffle) {
            if ($shouldShuffle) {
                shuffle($orders[(string) $questionId]);
            }
        }

        return $orders;
    }

    public function question(int $attemptId, array $query): void
    {
        $attempt = $this->attemptForUser($attemptId);
        $index = max(0, (int) ($query['index'] ?? 0));
        $questionIds = json_decode($attempt['question_order'], true) ?: [];

        if (!isset($questionIds[$index])) {
            Response::error('Question index is out of range.', 404);
        }

        $optionOrders = json_decode($attempt['option_orders'] ?? '{}', true) ?: [];
        $questionId = (int) $questionIds[$index];
        $question = $this->loadQuestion(
            $questionId,
            $attempt['mode'] === 'demo',
            (array) ($optionOrders[(string) $questionId] ?? [])
        );
        $response = $this->loadResponse($attemptId, (int) $question['id']);

        Response::ok([
            'attempt' => $this->attemptSummary($attempt),
            'index' => $index,
            'question' => $question,
            'group' => $this->groupMetadata($questionIds, $question),
            'response' => $response,
        ]);
    }

    public function saveAnswer(int $attemptId, array $data): void
    {
        $attempt = $this->attemptForUser($attemptId);
        if ($attempt['submitted_at'] || (int) ($attempt['remaining_seconds'] ?? 0) <= 0) {
            Response::error('This attempt is closed.', 409);
        }

        $errors = Validator::required($data, ['question_id', 'status']);
        if ($errors) {
            Response::error('Validation failed.', 422, $errors);
        }

        $status = in_array($data['status'], ['answered', 'skipped', 'review'], true) ? $data['status'] : 'skipped';
        $questionId = (int) $data['question_id'];
        $questionIds = array_map('intval', json_decode($attempt['question_order'], true) ?: []);
        if (!in_array($questionId, $questionIds, true)) {
            Response::error('This question does not belong to the current attempt.', 422);
        }

        $selected = array_values(array_unique(array_map('intval', (array) ($data['selected_option_ids'] ?? []))));
        $stmt = db()->prepare(
            'SELECT q.question_type, qo.id AS option_id
             FROM questions q
             JOIN question_options qo ON qo.question_id = q.id
             WHERE q.id = ?
             ORDER BY qo.id'
        );
        $stmt->execute([$questionId]);
        $rows = $stmt->fetchAll();
        if (!$rows) {
            Response::error('The selected question is unavailable.', 422);
        }
        $validOptionIds = array_map('intval', array_column($rows, 'option_id'));
        if (array_diff($selected, $validOptionIds)) {
            Response::error('One or more selected options do not belong to this question.', 422);
        }
        if ((string) $rows[0]['question_type'] === 'single' && count($selected) > 1) {
            Response::error('Only one option may be selected for this question.', 422);
        }
        if ($status === 'answered' && !$selected) {
            $status = 'skipped';
        }
        if ($status === 'skipped') {
            $selected = [];
        }

        $stmt = db()->prepare(
            'INSERT INTO assessment_responses (attempt_id, question_id, selected_option_ids, status, answered_at)
             VALUES (?, ?, ?, ?, IF(? = 1, NOW(), NULL))
             ON DUPLICATE KEY UPDATE selected_option_ids = VALUES(selected_option_ids), status = VALUES(status), answered_at = VALUES(answered_at)'
        );
        $stmt->execute([
            $attemptId,
            $questionId,
            json_encode($selected),
            $status,
            $status === 'answered' ? 1 : 0,
        ]);

        $payload = ['saved' => true];
        if ($attempt['mode'] === 'demo') {
            $payload['feedback'] = $this->feedback($questionId, $selected);
        }

        Response::ok($payload);
    }

    public function submit(int $attemptId): void
    {
        $attempt = $this->attemptForUser($attemptId);
        if (!$attempt['submitted_at']) {
            $score = $this->score($attemptId);
            db()->prepare('UPDATE assessment_attempts SET submitted_at = NOW(), score = ? WHERE id = ?')->execute([$score, $attemptId]);
        }

        Response::ok($this->resultPayload($attemptId));
    }

    public function result(int $attemptId): void
    {
        $attempt = $this->attemptForUser($attemptId);
        if (!$attempt['submitted_at'] && (int) ($attempt['remaining_seconds'] ?? 0) > 0) {
            Response::error('Result is available only after submission.', 409);
        }
        if (!$attempt['submitted_at']) {
            $this->submit($attemptId);
            return;
        }

        Response::ok($this->resultPayload($attemptId));
    }

    private function attemptForUser(int $attemptId): array
    {
        $user = AuthService::user();
        $stmt = db()->prepare(
            'SELECT assessment_attempts.*,
                    GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), expires_at)) AS remaining_seconds
             FROM assessment_attempts
             WHERE id = ? AND user_id = ?
             LIMIT 1'
        );
        $stmt->execute([$attemptId, $user['id']]);
        $attempt = $stmt->fetch();

        if (!$attempt) {
            Response::error('Attempt not found.', 404);
        }

        return $attempt;
    }

    private function loadQuestion(int $questionId, bool $includeAnswers = false, array $optionOrder = []): array
    {
        $stmt = db()->prepare(
            'SELECT q.id, q.question_code, q.group_code, q.group_sequence,
                    q.passage_text, passage_media.stored_path AS passage_image,
                    q.question_text, question_media.stored_path AS question_image,
                    q.question_type, q.marks, q.negative_marks, q.scoring_rule,
                    q.difficulty, q.explanation
             FROM questions q
             LEFT JOIN question_media passage_media ON passage_media.id = q.passage_media_id
             LEFT JOIN question_media question_media ON question_media.id = q.question_media_id
             WHERE q.id = ?
             LIMIT 1'
        );
        $stmt->execute([$questionId]);
        $question = $stmt->fetch();

        $stmt = db()->prepare(
            'SELECT qo.id, qo.option_key, qo.option_text, media.stored_path AS option_image'
            . ($includeAnswers ? ', qo.is_correct' : '') .
            ' FROM question_options qo
              LEFT JOIN question_media media ON media.id = qo.option_media_id
              WHERE qo.question_id = ?
              ORDER BY qo.sort_order, qo.id'
        );
        $stmt->execute([$questionId]);
        $options = $stmt->fetchAll();
        if ($optionOrder) {
            $byId = [];
            foreach ($options as $option) {
                $byId[(int) $option['id']] = $option;
            }
            $ordered = [];
            foreach ($optionOrder as $optionId) {
                if (isset($byId[(int) $optionId])) {
                    $ordered[] = $byId[(int) $optionId];
                    unset($byId[(int) $optionId]);
                }
            }
            array_push($ordered, ...array_values($byId));
            $options = $ordered;
        }
        $question['options'] = $options;

        return $question;
    }

    private function groupMetadata(array $questionIds, array $question): ?array
    {
        $groupCode = trim((string) ($question['group_code'] ?? ''));
        if ($groupCode === '') {
            return null;
        }
        $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
        $stmt = db()->prepare(
            "SELECT id, group_code
             FROM questions
             WHERE id IN ({$placeholders})"
        );
        $stmt->execute($questionIds);
        $groupById = [];
        foreach ($stmt->fetchAll() as $row) {
            $groupById[(int) $row['id']] = (string) ($row['group_code'] ?? '');
        }
        $groupIds = [];
        foreach ($questionIds as $questionId) {
            if (strcasecmp($groupById[(int) $questionId] ?? '', $groupCode) === 0) {
                $groupIds[] = (int) $questionId;
            }
        }
        $position = array_search((int) $question['id'], $groupIds, true);

        return [
            'code' => $groupCode,
            'position' => $position === false ? 1 : $position + 1,
            'total' => count($groupIds),
        ];
    }

    private function loadResponse(int $attemptId, int $questionId): ?array
    {
        $stmt = db()->prepare('SELECT selected_option_ids, status FROM assessment_responses WHERE attempt_id = ? AND question_id = ?');
        $stmt->execute([$attemptId, $questionId]);
        $response = $stmt->fetch();
        if (!$response) {
            return null;
        }

        $response['selected_option_ids'] = json_decode($response['selected_option_ids'] ?? '[]', true) ?: [];
        return $response;
    }

    private function feedback(int $questionId, array $selected): array
    {
        $optionStmt = db()->prepare(
            'SELECT qo.id, qo.option_key, qo.option_text,
                    media.stored_path AS option_image, qo.is_correct
             FROM question_options qo
             LEFT JOIN question_media media ON media.id = qo.option_media_id
             WHERE qo.question_id = ?
             ORDER BY qo.sort_order, qo.id'
        );
        $optionStmt->execute([$questionId]);
        $options = $optionStmt->fetchAll();
        $correct = array_map(
            'intval',
            array_column(array_filter($options, fn ($option) => (int) $option['is_correct'] === 1), 'id')
        );
        $selected = array_values(array_unique(array_map('intval', $selected)));
        sort($selected);
        sort($correct);

        $stmt = db()->prepare(
            'SELECT explanation, marks, negative_marks, scoring_rule
             FROM questions
             WHERE id = ?
             LIMIT 1'
        );
        $stmt->execute([$questionId]);
        $question = $stmt->fetch() ?: [];
        $awardedMarks = $this->awardedMarks(
            $selected,
            $correct,
            (float) ($question['marks'] ?? 1),
            (float) ($question['negative_marks'] ?? 0),
            (string) ($question['scoring_rule'] ?? 'exact_match')
        );

        return [
            'is_correct' => $selected === $correct,
            'selected_option_ids' => $selected,
            'correct_option_ids' => $correct,
            'selected_answers' => array_values(array_filter($options, fn ($option) => in_array((int) $option['id'], $selected, true))),
            'correct_answers' => array_values(array_filter($options, fn ($option) => in_array((int) $option['id'], $correct, true))),
            'explanation' => (string) ($question['explanation'] ?? ''),
            'marks' => (float) ($question['marks'] ?? 1),
            'negative_marks' => (float) ($question['negative_marks'] ?? 0),
            'scoring_rule' => (string) ($question['scoring_rule'] ?? 'exact_match'),
            'awarded_marks' => $awardedMarks,
        ];
    }

    private function awardedMarks(
        array $selected,
        array $correct,
        float $marks,
        float $negativeMarks,
        string $scoringRule
    ): float {
        if (!$selected) {
            return 0.0;
        }
        if ($selected === $correct) {
            return round($marks, 2);
        }

        $wrongSelections = array_diff($selected, $correct);
        if ($scoringRule === 'partial_credit' && !$wrongSelections && $correct) {
            $correctSelections = array_intersect($selected, $correct);
            return round($marks * count($correctSelections) / count($correct), 2);
        }

        return round(-$negativeMarks, 2);
    }

    private function score(int $attemptId): float
    {
        $stmt = db()->prepare('SELECT question_id, selected_option_ids FROM assessment_responses WHERE attempt_id = ? AND status = ?');
        $stmt->execute([$attemptId, 'answered']);
        $score = 0.0;

        foreach ($stmt->fetchAll() as $row) {
            $selected = json_decode($row['selected_option_ids'] ?? '[]', true) ?: [];
            $feedback = $this->feedback((int) $row['question_id'], array_map('intval', $selected));
            $score += (float) ($feedback['awarded_marks'] ?? 0);
        }

        return round($score, 2);
    }

    private function resultPayload(int $attemptId): array
    {
        $stmt = db()->prepare('SELECT * FROM assessment_attempts WHERE id = ?');
        $stmt->execute([$attemptId]);
        $attempt = $stmt->fetch();
        $questionIds = json_decode($attempt['question_order'], true) ?: [];
        $optionOrders = json_decode($attempt['option_orders'] ?? '{}', true) ?: [];

        $responses = [];
        foreach ($questionIds as $questionId) {
            $question = $this->loadQuestion(
                (int) $questionId,
                true,
                (array) ($optionOrders[(string) $questionId] ?? [])
            );
            $response = $this->loadResponse($attemptId, (int) $questionId);
            $selected = $response['selected_option_ids'] ?? [];
            $responses[] = [
                'question' => $question,
                'response' => $response,
                'feedback' => $this->feedback((int) $questionId, array_map('intval', $selected)),
            ];
        }

        $attempted = count(array_filter($responses, fn ($item) => ($item['response']['status'] ?? '') === 'answered'));
        $review = count(array_filter($responses, fn ($item) => ($item['response']['status'] ?? '') === 'review'));
        $maximumScore = array_reduce(
            $responses,
            fn (float $total, array $item): float => $total + (float) ($item['question']['marks'] ?? 1),
            0.0
        );

        return [
            'summary' => [
                'total_questions' => (int) $attempt['total_questions'],
                'attempted' => $attempted,
                'not_attempted' => (int) $attempt['total_questions'] - $attempted,
                'marked_for_review' => $review,
                'time_used_seconds' => max(0, strtotime($attempt['submitted_at'] ?? date('Y-m-d H:i:s')) - strtotime($attempt['started_at'])),
                'score' => round((float) ($attempt['score'] ?? $this->score($attemptId)), 2),
                'max_score' => round($maximumScore, 2),
            ],
            'responses' => $responses,
        ];
    }

    private function attemptSummary(array $attempt): array
    {
        return [
            'id' => (int) $attempt['id'],
            'mode' => $attempt['mode'],
            'total_questions' => (int) $attempt['total_questions'],
            'expires_at' => $attempt['expires_at'],
            'submitted_at' => $attempt['submitted_at'],
            'remaining_seconds' => max(0, (int) ($attempt['remaining_seconds'] ?? 0)),
        ];
    }
}
