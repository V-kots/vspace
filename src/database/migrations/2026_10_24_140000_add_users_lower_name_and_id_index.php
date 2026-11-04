<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const INDEX_NAME = 'idx_users_lower_name_and_id_desc';

    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_users_lower_name_and_id_desc
ON users (
    (LOWER(first_name)) text_pattern_ops,
    (LOWER(second_name)) text_pattern_ops,
    id DESC
)
SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX_NAME);
    }
};
