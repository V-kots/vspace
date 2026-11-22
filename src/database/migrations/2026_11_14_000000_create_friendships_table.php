<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('friendships', function (Blueprint $table) {
            $table->uuid('user_id');
            $table->uuid('friend_user_id');
            $table->timestamp('created_at')->useCurrent();

            // Направленная связь: составной ключ гарантирует отсутствие дублей.
            $table->primary(['user_id', 'friend_user_id']);

            // Индекс под обратный запрос «кто добавил меня» и FK.
            $table->index('friend_user_id');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();

            $table->foreign('friend_user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('friendships');
    }
};
