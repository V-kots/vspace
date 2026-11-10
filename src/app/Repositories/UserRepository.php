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

        // Проверка уникальности стоит непосредственно перед INSERT: читаем с
        // мастера, иначе отставание реплики пропустит дубликат email.
        $row = DB::selectFromWriteConnection($sql, ['email' => $email]);

        return $row ? (array) $row : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchByNames(string $firstName, string $lastName): array
    {
        $sql = <<<'SQL'
SELECT
    id,
    first_name,
    second_name,
    birthdate,
    biography,
    city
FROM users
WHERE LOWER(first_name) LIKE LOWER(:first_name) ESCAPE '!'
  AND LOWER(second_name) LIKE LOWER(:second_name) ESCAPE '!'
ORDER BY id ASC
SQL;

        $rows = DB::select($sql, [
            'first_name' => self::prefixPattern($firstName),
            'second_name' => self::prefixPattern($lastName),
        ]);

        return array_map(static fn (object $row): array => (array) $row, $rows);
    }

    private static function prefixPattern(string $value): string
    {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);

        return $escaped.'%';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function insert(array $data): string
    {
        $id = (string) Str::uuid();
        $email = ($data['email'] ?? '') !== '' ? $data['email'] : Str::uuid().'@vspace.local';
        $gender = $data['gender'] ?? 'other';

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

        DB::insert($sql, [
            'id' => $id,
            'first_name' => $data['first_name'],
            'second_name' => $data['second_name'],
            'birthdate' => $data['birthdate'],
            'email' => $email,
            'gender' => $gender,
            'biography' => $data['biography'] ?? null,
            'city' => $data['city'],
            'password' => $data['password'],
            'is_active' => true,
            'created_at' => now(),
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
