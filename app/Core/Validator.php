<?php

namespace App\Core;

final class Validator
{
    public static function required(array $data, array $fields): array
    {
        $errors = [];
        foreach ($fields as $field) {
            if (!isset($data[$field]) || trim((string) $data[$field]) === '') {
                $errors[$field] = 'This field is required.';
            }
        }

        return $errors;
    }

    public static function password(string $password): ?string
    {
        if (strlen($password) < 8) {
            return 'Password must be at least 8 characters.';
        }
        if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password)) {
            return 'Password must include uppercase and lowercase letters.';
        }
        if (!preg_match('/\d/', $password)) {
            return 'Password must include a number.';
        }
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            return 'Password must include a special character.';
        }

        return null;
    }
}
