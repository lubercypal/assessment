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
        $limit = $mode === 'demo' ? 5 : 30;
        $duration = $mode === 'demo' ? 900 : 3600;
        $categoryId = (int) $data['category_id'];
        $topicId = !empty($data['topic_id']) ? (int) $data['topic_id'] : null;

        $where = 'category_id = ? AND is_active = 1';
        $params = [$categoryId];
        if ($topicId) {
            $where .= ' AND topic_id = ?';
            $params[] = $topicId;
        }
        if ($mode === 'demo') {
            $where .= ' AND is_demo = 1';
        }

        $stmt = db()->prepare("SELECT id FROM questions WHERE {$where} ORDER BY RAND() LIMIT {$limit}");
        $stmt->execute($params);
        $questionIds = array_map('intval', array_column($stmt->fetchAll(), 'id'));

        if (!$questionIds) {
            Response::error('No questions are available for this selection.', 404);
        }

        $stmt = db()->prepare(
            'INSERT INTO assessment_attempts
             (user_id, category_id, topic_id, mode, question_order, total_questions, duration_seconds, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))'
        );
        $stmt->execute([
            $user['id'],
            $categoryId,
            $topicId,
            $mode,
            json_encode($questionIds),
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

    public function question(int $attemptId, array $query): void
    {
        $attempt = $this->attemptForUser($attemptId);
        $index = max(0, (int) ($query['index'] ?? 0));
        $questionIds = json_decode($attempt['question_order'], true) ?: [];

        if (!isset($questionIds[$index])) {
            Response::error('Question index is out of range.', 404);
        }

        $question = $this->loadQuestion((int) $questionIds[$index], $attempt['mode'] === 'demo');
        $response = $this->loadResponse($attemptId, (int) $question['id']);

        Response::ok([
            'attempt' => $this->attemptSummary($attempt),
            'index' => $index,
            'question' => $question,
            'response' => $response,
        ]);
    }

    public function saveAnswer(int $attemptId, array $data): void
    {
        $attempt = $this->attemptForUser($attemptId);
        if ($attempt['submitted_at'] || strtotime($attempt['expires_at']) < time()) {
            Response::error('This attempt is closed.', 409);
        }

        $errors = Validator::required($data, ['question_id', 'status']);
        if ($errors) {
            Response::error('Validation failed.', 422, $errors);
        }

        $status = in_array($data['status'], ['answered', 'skipped', 'review'], true) ? $data['status'] : 'skipped';
        $selected = array_values(array_map('intval', (array) ($data['selected_option_ids'] ?? [])));
        if ($status === 'answered' && !$selected) {
            $status = 'skipped';
        }

        $stmt = db()->prepare(
            'INSERT INTO assessment_responses (attempt_id, question_id, selected_option_ids, status, answered_at)
             VALUES (?, ?, ?, ?, IF(? = "answered", NOW(), NULL))
             ON DUPLICATE KEY UPDATE selected_option_ids = VALUES(selected_option_ids), status = VALUES(status), answered_at = VALUES(answered_at)'
        );
        $stmt->execute([$attemptId, (int) $data['question_id'], json_encode($selected), $status, $status]);

        $payload = ['saved' => true];
        if ($attempt['mode'] === 'demo') {
            $payload['feedback'] = $this->feedback((int) $data['question_id'], $selected);
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
        if (!$attempt['submitted_at'] && strtotime($attempt['expires_at']) >= time()) {
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
        $stmt = db()->prepare('SELECT * FROM assessment_attempts WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$attemptId, $user['id']]);
        $attempt = $stmt->fetch();

        if (!$attempt) {
            Response::error('Attempt not found.', 404);
        }

        return $attempt;
    }

    private function loadQuestion(int $questionId, bool $includeAnswers = false): array
    {
        $stmt = db()->prepare('SELECT id, question_text, question_type, explanation FROM questions WHERE id = ? LIMIT 1');
        $stmt->execute([$questionId]);
        $question = $stmt->fetch();

        $stmt = db()->prepare('SELECT id, option_text' . ($includeAnswers ? ', is_correct' : '') . ' FROM question_options WHERE question_id = ? ORDER BY sort_order, id');
        $stmt->execute([$questionId]);
        $question['options'] = $stmt->fetchAll();

        return $question;
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
        $stmt = db()->prepare('SELECT id FROM question_options WHERE question_id = ? AND is_correct = 1 ORDER BY id');
        $stmt->execute([$questionId]);
        $correct = array_map('intval', array_column($stmt->fetchAll(), 'id'));
        sort($selected);

        $optionStmt = db()->prepare('SELECT id, option_text FROM question_options WHERE question_id = ? ORDER BY sort_order, id');
        $optionStmt->execute([$questionId]);
        $options = $optionStmt->fetchAll();

        $stmt = db()->prepare('SELECT explanation FROM questions WHERE id = ?');
        $stmt->execute([$questionId]);

        return [
            'is_correct' => $selected === $correct,
            'selected_option_ids' => $selected,
            'correct_option_ids' => $correct,
            'selected_answers' => array_values(array_filter($options, fn ($option) => in_array((int) $option['id'], $selected, true))),
            'correct_answers' => array_values(array_filter($options, fn ($option) => in_array((int) $option['id'], $correct, true))),
            'explanation' => (string) ($stmt->fetchColumn() ?: ''),
        ];
    }

    private function score(int $attemptId): int
    {
        $stmt = db()->prepare('SELECT question_id, selected_option_ids FROM assessment_responses WHERE attempt_id = ? AND status = "answered"');
        $stmt->execute([$attemptId]);
        $score = 0;

        foreach ($stmt->fetchAll() as $row) {
            $selected = json_decode($row['selected_option_ids'] ?? '[]', true) ?: [];
            sort($selected);
            if ($this->feedback((int) $row['question_id'], array_map('intval', $selected))['is_correct']) {
                $score++;
            }
        }

        return $score;
    }

    private function resultPayload(int $attemptId): array
    {
        $stmt = db()->prepare('SELECT * FROM assessment_attempts WHERE id = ?');
        $stmt->execute([$attemptId]);
        $attempt = $stmt->fetch();
        $questionIds = json_decode($attempt['question_order'], true) ?: [];

        $responses = [];
        foreach ($questionIds as $questionId) {
            $question = $this->loadQuestion((int) $questionId, true);
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

        return [
            'summary' => [
                'total_questions' => (int) $attempt['total_questions'],
                'attempted' => $attempted,
                'not_attempted' => (int) $attempt['total_questions'] - $attempted,
                'marked_for_review' => $review,
                'time_used_seconds' => max(0, strtotime($attempt['submitted_at'] ?? date('Y-m-d H:i:s')) - strtotime($attempt['started_at'])),
                'score' => (int) ($attempt['score'] ?? $this->score($attemptId)),
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
            'remaining_seconds' => max(0, strtotime($attempt['expires_at']) - time()),
        ];
    }
}
