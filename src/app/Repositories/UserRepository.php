<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserRepository
{
    /**
     * @return array<string, mixed>|null
     */
    public function findById(string $id): ?array
    {
        $sql = <<<'SQL'
SELECT
    id,
    first_name,
    second_name,
    birthdate,
    email,
    gender,
    biography,
    city,
    password,
    last_login_at,
    is_active,
    created_at
FROM users
WHERE id = :id
LIMIT 1
SQL;

        $row = DB::selectOne($sql, ['id' => $id]);

        return $row ? (array) $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        $sql = <<<'SQL'
SELECT
    id,
    first_name,
    second_name,
    birthdate,
    email,
    gender,
    biography,
    city,
    password,
    last_login_at,
    is_active,
    created_at
FROM users
WHERE email = :email
LIMIT 1
SQL;

        $row = DB::selectOne($sql, ['email' => $email]);

        return $row ? (array) $row : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function insert(array $data): string
    {
        $id = (string) Str::uuid();

        $sql = <<<'SQL'
INSERT INTO users (
    id,
    first_name,
    second_name,
    birthdate,
    email,
    gender,
    biography,
    city,
    password,
    is_active,
    created_at
) VALUES (
    :id,
    :first_name,
    :second_name,
    :birthdate,
    :email,
    :gender,
    :biography,
    :city,
    :password,
    :is_active,
    :created_at
)
SQL;

        $now = now();

        DB::insert($sql, [
            'id' => $id,
            'first_name' => $data['first_name'],
            'second_name' => $data['second_name'],
            'birthdate' => $data['birthdate'],
            'email' => $data['email'],
            'gender' => $data['gender'],
            'biography' => $data['biography'] ?? null,
            'city' => $data['city'],
            'password' => $data['password'],
            'is_active' => true,
            'created_at' => $now,
        ]);

        return $id;
    }

    public function touchLastLogin(string $id): void
    {
        $sql = <<<'SQL'
UPDATE users
SET last_login_at = :last_login_at
WHERE id = :id
SQL;

        DB::update($sql, [
            'id' => $id,
            'last_login_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicProfile(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'first_name' => $row['first_name'],
            'second_name' => $row['second_name'],
            'birthdate' => is_string($row['birthdate'])
                ? substr($row['birthdate'], 0, 10)
                : $row['birthdate']->format('Y-m-d'),
            'biography' => $row['biography'] ?? '',
            'city' => $row['city'],
        ];
    }
}
