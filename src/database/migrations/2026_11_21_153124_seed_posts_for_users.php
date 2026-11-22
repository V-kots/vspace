<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Наполняет ленту тестовыми постами: каждый из 500 пользователей получает
 * 1000 случайно перемешанных текстов из database/seeders/csv/posts.txt.gz.
 *
 * Итого 500 000 постов. Id детерминированные (md5 от user_id и индекса),
 * а список получателей хранится в seeded_post_users — поэтому down() удаляет
 * ровно вставленные строки и не задевает реальные посты.
 */
return new class extends Migration
{
    /**
     * Сколько пользователей получают посты.
     */
    private const USERS_COUNT = 500;

    /**
     * Сколько постов получает каждый пользователь.
     */
    private const POSTS_PER_USER = 1000;

    private const INSERT_CHUNK = 500;

    /**
     * Пользователи, которые обязательно должны попасть в выборку.
     *
     * @var list<string>
     */
    private const REQUIRED_USER_IDS = [
        '0ee50b1e-451d-4734-9508-f97f8745858a',
        '1a740597-87f7-49cf-8fcb-d8114a4a64d8',
    ];

    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $path = database_path('seeders/csv/posts.txt.gz');

        if (! is_file($path)) {
            return;
        }

        $texts = $this->readTexts($path);

        if ($texts === []) {
            return;
        }

        $userIds = $this->targetUserIds();

        if ($userIds === []) {
            return;
        }

        $this->createStagingTables($texts, $userIds);

        DB::statement($this->insertPostsSql());

        DB::statement('DROP TABLE seed_texts');
        DB::statement('DROP TABLE seed_users');
    }

    public function down(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        if (! Schema::hasTable('seeded_post_users')) {
            return;
        }

        DB::statement($this->deletePostsSql());

        DB::statement('DROP TABLE seeded_post_users');
    }

    /**
     * Временные таблицы с текстами и получателями. Обе живут до конца
     * транзакции миграции.
     *
     * @param  list<string>  $texts
     * @param  list<string>  $userIds
     */
    private function createStagingTables(array $texts, array $userIds): void
    {
        DB::statement('CREATE TABLE IF NOT EXISTS seeded_post_users (user_id uuid PRIMARY KEY)');

        $recipients = array_map(
            static fn (string $userId): array => ['user_id' => $userId],
            $userIds,
        );

        foreach (array_chunk($recipients, self::INSERT_CHUNK) as $chunk) {
            DB::table('seeded_post_users')->insertOrIgnore($chunk);
        }

        DB::statement('CREATE TEMP TABLE seed_texts (rn integer PRIMARY KEY, body text NOT NULL)');
        DB::statement('CREATE TEMP TABLE seed_users (rn integer PRIMARY KEY, user_id uuid NOT NULL)');

        $textRows = [];

        foreach ($texts as $index => $text) {
            $textRows[] = ['rn' => $index, 'body' => $text];
        }

        foreach (array_chunk($textRows, self::INSERT_CHUNK) as $chunk) {
            DB::table('seed_texts')->insert($chunk);
        }

        $userRows = [];

        foreach ($userIds as $index => $userId) {
            $userRows[] = ['rn' => $index, 'user_id' => $userId];
        }

        foreach (array_chunk($userRows, self::INSERT_CHUNK) as $chunk) {
            DB::table('seed_users')->insert($chunk);
        }
    }

    private function insertPostsSql(): string
    {
        $postsPerUser = self::POSTS_PER_USER;

        return <<<SQL
INSERT INTO posts (id, author_user_id, text, created_at, updated_at)
SELECT
    (
        substr(ids.h, 1, 8) || '-' || substr(ids.h, 9, 4) || '-' || substr(ids.h, 13, 4) || '-' ||
        substr(ids.h, 17, 4) || '-' || substr(ids.h, 21, 12)
    )::uuid,
    u.user_id,
    sample.body,
    now() - (random() * interval '365 days'),
    NULL
FROM seed_users u
CROSS JOIN LATERAL (
    SELECT row_number() OVER () - 1 AS k, picked.body
    FROM (
        SELECT body
        FROM seed_texts
        ORDER BY random()
        LIMIT {$postsPerUser}
    ) AS picked
) AS sample
CROSS JOIN LATERAL (
    SELECT md5('vspace-seeded-post-' || u.user_id || '-' || sample.k) AS h
) AS ids
ON CONFLICT (id) DO NOTHING
SQL;
    }

    private function deletePostsSql(): string
    {
        $lastIndex = self::POSTS_PER_USER - 1;

        return <<<SQL
DELETE FROM posts p
USING (
    SELECT (
        substr(hashed.h, 1, 8) || '-' || substr(hashed.h, 9, 4) || '-' || substr(hashed.h, 13, 4) || '-' ||
        substr(hashed.h, 17, 4) || '-' || substr(hashed.h, 21, 12)
    )::uuid AS id
    FROM (
        SELECT md5('vspace-seeded-post-' || u.user_id || '-' || k) AS h
        FROM seeded_post_users u
        CROSS JOIN generate_series(0, {$lastIndex}) AS k
    ) AS hashed
) AS seeded
WHERE p.id = seeded.id
SQL;
    }

    /**
     * Непустые строки файла — по одной на пост.
     *
     * @return list<string>
     */
    private function readTexts(string $path): array
    {
        $lines = gzfile($path);

        if ($lines === false) {
            return [];
        }

        /** @var list<string> $texts */
        $texts = array_values(array_filter(
            array_map(static fn (string $line): string => trim($line), $lines),
            static fn (string $line): bool => $line !== '',
        ));

        return $texts;
    }

    /**
     * 500 получателей: 2 обязательных пользователя и 498 случайных.
     *
     * @return list<string>
     */
    private function targetUserIds(): array
    {
        $required = DB::table('users')
            ->whereIn('id', self::REQUIRED_USER_IDS)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $random = DB::table('users')
            ->whereNotIn('id', $required)
            ->inRandomOrder()
            ->limit(max(0, self::USERS_COUNT - count($required)))
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        return array_values(array_merge($required, $random));
    }
};
