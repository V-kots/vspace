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

    public function test_feed_returns_own_and_friends_posts_with_pagination(): void
    {
        $viewer = User::factory()->create();
        $friend = User::factory()->create();
        $stranger = User::factory()->create();

        $ownPost = Post::factory()->create([
            'author_user_id' => $viewer->id,
            'text' => 'Мой пост',
        ]);
        DB::table('posts')->where('id', $ownPost->id)->update(['created_at' => now()->subMinute()]);

        $friendPost = Post::factory()->create([
            'author_user_id' => $friend->id,
            'text' => 'Пост друга',
        ]);

        $strangerPost = Post::factory()->create([
            'author_user_id' => $stranger->id,
            'text' => 'Пост незнакомца',
        ]);
        DB::table('posts')->where('id', $strangerPost->id)->update(['created_at' => now()->subMinutes(2)]);

        DB::table('friendships')->insert([
            'user_id' => $viewer->id,
            'friend_user_id' => $friend->id,
        ]);

        Sanctum::actingAs($viewer);

        $this->getJson('/post/feed?offset=0&limit=1')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $friendPost->id);

        $this->getJson('/post/feed?offset=1&limit=1')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $ownPost->id);

        // Пост незнакомца в ленту не попадает.
        $this->getJson('/post/feed?offset=2&limit=1')
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_feed_includes_friend_posts_when_link_stored_in_reverse_direction(): void
    {
        $viewer = User::factory()->create();
        $friend = User::factory()->create();

        $friendPost = Post::factory()->create([
            'author_user_id' => $friend->id,
        ]);

        // Старое направленное ребро «друг → я»: дружба всё равно считается.
        DB::table('friendships')->insert([
            'user_id' => $friend->id,
            'friend_user_id' => $viewer->id,
        ]);

        Sanctum::actingAs($viewer);

        $this->getJson('/post/feed?offset=0&limit=10')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $friendPost->id);
    }

    public function test_feed_can_be_filtered_by_own_author(): void
    {
        $viewer = User::factory()->create();
        $friend = User::factory()->create();

        $ownPost = Post::factory()->create([
            'author_user_id' => $viewer->id,
        ]);
        Post::factory()->create([
            'author_user_id' => $friend->id,
        ]);

        DB::table('friendships')->insert([
            'user_id' => $viewer->id,
            'friend_user_id' => $friend->id,
        ]);

        Sanctum::actingAs($viewer);

        $this->getJson("/post/feed?author_user_id={$viewer->id}")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $ownPost->id);
    }

    public function test_feed_can_be_filtered_by_friend_author(): void
    {
        $viewer = User::factory()->create();
        $friend = User::factory()->create();

        Post::factory()->create([
            'author_user_id' => $viewer->id,
        ]);
        $friendPost = Post::factory()->create([
            'author_user_id' => $friend->id,
        ]);

        DB::table('friendships')->insert([
            'user_id' => $viewer->id,
            'friend_user_id' => $friend->id,
        ]);

        Sanctum::actingAs($viewer);

        $this->getJson("/post/feed?author_user_id={$friend->id}")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $friendPost->id);
    }

    public function test_feed_filter_by_author_returns_posts_of_non_friend(): void
    {
        $viewer = User::factory()->create();
        $stranger = User::factory()->create();

        Post::factory()->create([
            'author_user_id' => $viewer->id,
        ]);
        $strangerPost = Post::factory()->create([
            'author_user_id' => $stranger->id,
        ]);

        Sanctum::actingAs($viewer);

        $this->getJson("/post/feed?author_user_id={$stranger->id}")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $strangerPost->id);
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

    public function test_author_can_update_own_post(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->create([
            'author_user_id' => $user->id,
            'text' => 'Старый текст',
        ]);

        Sanctum::actingAs($user);

        $this->putJson('/post/update', [
            'id' => $post->id,
            'text' => 'Новый текст',
        ])->assertOk();

        $this->assertDatabaseHas('posts', [
            'id' => $post->id,
            'text' => 'Новый текст',
        ]);
    }

    public function test_update_post_returns_404_for_unknown_post(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->assertApiError(
            $this->putJson('/post/update', [
                'id' => '00000000-0000-0000-0000-000000000000',
                'text' => 'Новый текст',
            ]),
            404,
            ApiError::CODE_NOT_FOUND,
            'Пост не найден',
        );
    }

    public function test_user_cannot_update_foreign_post(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $post = Post::factory()->create([
            'author_user_id' => $owner->id,
            'text' => 'Чужой текст',
        ]);

        Sanctum::actingAs($other);

        $this->assertApiError(
            $this->putJson('/post/update', [
                'id' => $post->id,
                'text' => 'Взломанный текст',
            ]),
            403,
            ApiError::CODE_FORBIDDEN,
        );

        $this->assertDatabaseHas('posts', [
            'id' => $post->id,
            'text' => 'Чужой текст',
        ]);
    }

    public function test_update_post_validates_payload(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->create(['author_user_id' => $user->id]);

        Sanctum::actingAs($user);

        $this->assertApiError(
            $this->putJson('/post/update', ['id' => $post->id, 'text' => '']),
            400,
            ApiError::CODE_VALIDATION,
        );

        $this->assertApiError(
            $this->putJson('/post/update', ['id' => 'not-a-uuid', 'text' => 'Текст']),
            400,
            ApiError::CODE_VALIDATION,
        );

        $this->assertApiError(
            $this->putJson('/post/update', ['id' => $post->id]),
            400,
            ApiError::CODE_VALIDATION,
        );
    }

    public function test_update_post_requires_authentication(): void
    {
        $post = Post::factory()->create();

        $this->assertApiError(
            $this->putJson('/post/update', ['id' => $post->id, 'text' => 'Текст']),
            401,
            ApiError::CODE_UNAUTHORIZED,
        );
    }

    public function test_author_can_delete_own_post(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->create(['author_user_id' => $user->id]);

        Sanctum::actingAs($user);

        $this->putJson("/post/delete/{$post->id}")->assertOk();

        $this->assertDatabaseMissing('posts', ['id' => $post->id]);

        $this->getJson("/post/get/{$post->id}")
            ->assertNotFound();
    }

    public function test_user_cannot_delete_foreign_post(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $post = Post::factory()->create(['author_user_id' => $owner->id]);

        Sanctum::actingAs($other);

        $this->assertApiError(
            $this->putJson("/post/delete/{$post->id}"),
            403,
            ApiError::CODE_FORBIDDEN,
        );

        $this->assertDatabaseHas('posts', ['id' => $post->id]);
    }

    public function test_delete_post_returns_404_for_unknown_post(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->assertApiError(
            $this->putJson('/post/delete/00000000-0000-0000-0000-000000000000'),
            404,
            ApiError::CODE_NOT_FOUND,
            'Пост не найден',
        );
    }

    public function test_delete_post_validates_uuid(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->assertApiError(
            $this->putJson('/post/delete/not-a-uuid'),
            400,
            ApiError::CODE_VALIDATION,
        );
    }

    public function test_delete_post_requires_authentication(): void
    {
        $post = Post::factory()->create();

        $this->assertApiError(
            $this->putJson("/post/delete/{$post->id}"),
            401,
            ApiError::CODE_UNAUTHORIZED,
        );
    }
}
