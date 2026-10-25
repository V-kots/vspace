<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    private const CSV_PATH = '/import/people.v2.csv';

    private const EMAIL_DOMAIN = 'import.vspace.local';

    private const DEFAULT_PASSWORD = 'your-password';

    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $passwordHash = Hash::make(self::DEFAULT_PASSWORD);

        DB::transaction(function () use ($passwordHash): void {
            DB::statement(
                'CREATE TEMP TABLE users_import_staging (full_name text NOT NULL, birthdate date NOT NULL, city text NOT NULL) ON COMMIT DROP'
            );

            DB::statement(
                "COPY users_import_staging (full_name, birthdate, city) FROM PROGRAM 'head -n -1 ".self::CSV_PATH."' WITH (FORMAT csv)"
            );

            DB::statement(<<<'SQL'
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
)
SELECT
    gen_random_uuid(),
    COALESCE(NULLIF(btrim(substr(full_name, strpos(full_name, ' ') + 1)), ''), full_name),
    split_part(full_name, ' ', 1),
    birthdate,
    'import+' || row_number() OVER () || '@import.vspace.local',
    'other',
    'Импортированный',
    city,
    ?,
    true,
    now()
FROM users_import_staging
ON CONFLICT (email) DO NOTHING
SQL, [$passwordHash]);
        });
    }

    public function down(): void
    {
        DB::table('users')
            ->where('email', 'like', '%@'.self::EMAIL_DOMAIN)
            ->delete();
    }
};
