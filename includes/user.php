<?php
/**
 * Shared Community user input, validation, and identity-conflict helpers.
 */

function empty_user_profile_data(): array
{
    return [
        'fname'    => '',
        'mname'    => '',
        'lname'    => '',
        'email'    => '',
        'contact'  => '',
        'address'  => '',
        'username' => '',
    ];
}

function user_profile_data_from_input(array $input): array
{
    $data = empty_user_profile_data();

    foreach (array_keys($data) as $field) {
        $data[$field] = trim((string) ($input[$field] ?? ''));
    }

    return $data;
}

function user_text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function validate_user_profile_data(array $data): array
{
    $errors = [];

    if ($data['fname'] === '') {
        $errors[] = 'First name is required.';
    } elseif (user_text_length($data['fname']) > 100) {
        $errors[] = 'First name must not exceed 100 characters.';
    }

    if ($data['mname'] !== '' && user_text_length($data['mname']) > 100) {
        $errors[] = 'Middle name must not exceed 100 characters.';
    }

    if ($data['lname'] === '') {
        $errors[] = 'Last name is required.';
    } elseif (user_text_length($data['lname']) > 100) {
        $errors[] = 'Last name must not exceed 100 characters.';
    }

    if ($data['email'] === '') {
        $errors[] = 'Email address is required.';
    } elseif (user_text_length($data['email']) > 150) {
        $errors[] = 'Email address must not exceed 150 characters.';
    } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if ($data['contact'] === '') {
        $errors[] = 'Contact number is required.';
    } elseif (user_text_length($data['contact']) > 20) {
        $errors[] = 'Contact number must not exceed 20 characters.';
    } elseif (!preg_match('/^[0-9+\-\s().]{7,20}$/', $data['contact'])) {
        $errors[] = 'Contact number may contain only numbers, spaces, +, -, parentheses, and periods.';
    }

    if ($data['address'] === '') {
        $errors[] = 'Address is required.';
    } elseif (user_text_length($data['address']) > 255) {
        $errors[] = 'Address must not exceed 255 characters.';
    }

    if ($data['username'] === '') {
        $errors[] = 'Username is required.';
    } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $data['username'])) {
        $errors[] = 'Username must be 3-50 characters and may contain letters, numbers, underscores, periods, or hyphens.';
    }

    return $errors;
}

function find_user_identity_conflicts(
    PDO $pdo,
    string $username,
    string $email,
    ?int $excludeUserId = null
): array {
    $sql = 'SELECT id, username, email
            FROM tbl_users
            WHERE (username = :username OR email = :email)';

    $params = [
        ':username' => $username,
        ':email'    => $email,
    ];

    if ($excludeUserId !== null) {
        $sql .= ' AND id <> :exclude_user_id';
        $params[':exclude_user_id'] = $excludeUserId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $conflicts = [
        'username' => false,
        'email'    => false,
    ];

    foreach ($stmt->fetchAll() as $user) {
        if (isset($user['username']) && strcasecmp((string) $user['username'], $username) === 0) {
            $conflicts['username'] = true;
        }

        if (isset($user['email']) && strcasecmp((string) $user['email'], $email) === 0) {
            $conflicts['email'] = true;
        }
    }

    return $conflicts;
}
