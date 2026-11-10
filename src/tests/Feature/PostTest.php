<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use App\Support\ApiError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PostTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_post_requires_authentication(): void
    {
        $this->assertApiError(
            $this->postJson('/post/create', ['text' => 'Текст поста']),
            401,
            ApiError::CODE_UNAUTHORIZED,
        );
    }

    public function test_authenticated_user_can_create_and_read_post(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Иван',
            'second_name' => 'Петров',
        ]);

        Sanctum::actingAs($user);

        $createResponse = $this->postJson('/post/create', [
            'text' => 'Мой первый пост',
        ]);

        $createResponse
            ->assertOk()
            ->assertJsonStructure(['id']);

        $postId = $createResponse->json('id');

        $this->getJson("/post/get/{$postId}")
            ->assertOk()
            ->assertJson([
                'id' => $postId,
                'text' => 'Мой первый пост',
                'author_user_id' => $user->id,
                'author' => [
                    'id' => $user->id,
                    'first_name' => 'Иван',
                    'second_name' => 'Петров',
                ],
            ]);
    }

    public function test_feed_returns_posts_of_all_authors_with_pagination(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        $older = Post::factory()->create([
            'author_user_id' => $first->id,
            'text' => 'Старый пост',
        ]);
        DB::table('posts')->where('id', $older->id)->update(['created_at' => now()->subMinute()]);

        $newer = Post::factory()->create([
            'author_user_id' => $second->id,
            'text' => 'Новый пост',
        ]);

        Sanctum::actingAs($first);

        $response = $this->getJson('/post/feed?offset=0&limit=1');

        $response
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $newer->id);

        $this->getJson('/post/feed?offset=1&limit=1')
            ->assertOk()
            ->assertJsonPath('0.id', $older->id);
    }

    public function test_feed_can_be_filtered_by_author(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        $firstPost = Post::factory()->create([
            'author_user_id' => $first->id,
        ]);
        Post::factory()->create([
            'author_user_id' => $second->id,
        ]);

        Sanctum::actingAs($first);

        $this->getJson("/post/feed?author_user_id={$first->id}")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $firstPost->id);
    }

    public function test_get_post_returns_404_for_unknown_post(): void
    {
        $this->assertApiError(
            $this->getJson('/post/get/00000000-0000-0000-0000-000000000000'),
            404,
            ApiError::CODE_NOT_FOUND,
        );
    }

    public function test_create_post_validates_text(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->assertApiError(
            $this->postJson('/post/create', ['text' => '']),
            400,
            ApiError::CODE_VALIDATION,
        );

        $this->assertApiError(
            $this->postJson('/post/create', ['text' => '   ']),
            400,
            ApiError::CODE_VALIDATION,
        );
    }

    public function test_get_post_validates_uuid(): void
    {
        $this->assertApiError(
            $this->getJson('/post/get/not-a-uuid'),
            400,
            ApiError::CODE_VALIDATION,
        );
    }
}
