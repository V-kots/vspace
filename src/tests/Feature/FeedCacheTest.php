<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Throwable;

class FeedCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'feed.cache.enabled' => true,
            'database.redis.default.database' => 15,
        ]);

        try {
            Redis::connection()->ping();
        } catch (Throwable) {
            $this->markTestSkipped('Redis недоступен для тестов кеша ленты.');
        }

        Redis::connection()->flushdb();
    }

    public function test_feed_is_rebuilt_once_and_then_served_from_cache(): void
    {
        $viewer = User::factory()->create();
        $friend = User::factory()->create();

        $this->makeFriends($viewer, $friend);

        Post::factory()->create(['author_user_id' => $viewer->id, 'text' => 'Мой пост']);
        Post::factory()->create(['author_user_id' => $friend->id, 'text' => 'Пост друга']);

        Sanctum::actingAs($viewer);

        $first = $this->getJson('/post/feed');
        $first->assertOk()->assertHeader('X-Cache', 'MISS');

        $second = $this->getJson('/post/feed');
        $second->assertOk()->assertHeader('X-Cache', 'HIT');

        $this->assertSame($first->json(), $second->json());
        $this->assertCount(2, $second->json());
    }

    public function test_new_post_appears_in_friend_feed_without_waiting_for_ttl(): void
    {
        $author = User::factory()->create();
        $friend = User::factory()->create();

        $this->makeFriends($author, $friend);

        Post::factory()->create([
            'author_user_id' => $author->id,
            'text' => 'Старый пост',
            'created_at' => now()->subMinute(),
        ]);

        Sanctum::actingAs($friend);

        // Прогреваем ленту друга: ключ в Redis появляется.
        $this->getJson('/post/feed')->assertOk()->assertHeader('X-Cache', 'MISS');

        Sanctum::actingAs($author);
        $postId = $this->postJson('/post/create', ['text' => 'Новый пост'])
            ->assertOk()
            ->json('id');

        Sanctum::actingAs($friend);
        $this->getJson('/post/feed')
            ->assertOk()
            ->assertHeader('X-Cache', 'HIT')
            ->assertJsonPath('0.id', $postId)
            ->assertJsonPath('0.text', 'Новый пост');
    }

    public function test_deleted_post_disappears_from_author_and_friend_feeds(): void
    {
        $author = User::factory()->create();
        $friend = User::factory()->create();

        $this->makeFriends($author, $friend);

        Sanctum::actingAs($author);
        $postId = $this->postJson('/post/create', ['text' => 'Удаляемый пост'])->json('id');

        Sanctum::actingAs($friend);
        $this->getJson('/post/feed')->assertJsonPath('0.id', $postId);

        Sanctum::actingAs($author);
        $this->putJson("/post/delete/{$postId}")->assertOk();

        Sanctum::actingAs($friend);
        $this->getJson('/post/feed')->assertOk()->assertJsonMissing(['id' => $postId]);
    }

    public function test_friendship_change_rebuilds_feed(): void
    {
        $viewer = User::factory()->create();
        $stranger = User::factory()->create();

        Post::factory()->create([
            'author_user_id' => $viewer->id,
            'text' => 'Свой пост',
            'created_at' => now()->subMinute(),
        ]);

        $post = Post::factory()->create([
            'author_user_id' => $stranger->id,
            'text' => 'Пост будущего друга',
        ]);

        Sanctum::actingAs($viewer);

        $this->getJson('/post/feed')->assertOk()->assertHeader('X-Cache', 'MISS')->assertJsonMissing(['id' => $post->id]);
        $this->getJson('/post/feed')->assertOk()->assertHeader('X-Cache', 'HIT');

        $this->putJson("/friend/set/{$stranger->id}")->assertOk();

        $this->getJson('/post/feed')
            ->assertOk()
            ->assertHeader('X-Cache', 'MISS')
            ->assertJsonPath('0.id', $post->id);
    }

    public function test_feed_keeps_only_configured_number_of_entries(): void
    {
        config(['feed.cache.size' => 2]);

        $viewer = User::factory()->create();

        $oldest = Post::factory()->create([
            'author_user_id' => $viewer->id,
            'text' => 'Старый пост',
            'created_at' => now()->subMinutes(3),
        ]);
        $middle = Post::factory()->create([
            'author_user_id' => $viewer->id,
            'text' => 'Средний пост',
            'created_at' => now()->subMinutes(2),
        ]);
        $newest = Post::factory()->create([
            'author_user_id' => $viewer->id,
            'text' => 'Новый пост',
            'created_at' => now()->subMinute(),
        ]);

        Sanctum::actingAs($viewer);

        $feed = $this->getJson('/post/feed?limit=10')->assertOk()->json();

        $this->assertSame([$newest->id, $middle->id], array_column($feed, 'id'));
        $this->assertNotContains($oldest->id, array_column($feed, 'id'));
    }

    public function test_cache_disabled_returns_off_source(): void
    {
        config(['feed.cache.enabled' => false]);

        $user = User::factory()->create();
        Post::factory()->create(['author_user_id' => $user->id]);

        Sanctum::actingAs($user);

        $this->getJson('/post/feed')
            ->assertOk()
            ->assertHeader('X-Cache', 'OFF');
    }

    public function test_offset_beyond_feed_size_returns_empty_page(): void
    {
        config(['feed.cache.size' => 2]);

        $user = User::factory()->create();
        Post::factory()->count(3)->create(['author_user_id' => $user->id]);

        Sanctum::actingAs($user);

        $this->getJson('/post/feed?offset=500&limit=20')
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_home_page_is_served_from_page_cache(): void
    {
        $user = User::factory()->create();

        Post::factory()->count(6)->create(['author_user_id' => $user->id]);

        Sanctum::actingAs($user);

        $first = $this->getJson('/post/feed?limit=5');
        $first->assertOk()->assertHeader('X-Cache', 'MISS');
        $this->assertCount(5, $first->json());

        $second = $this->getJson('/post/feed?limit=5');
        $second->assertOk()->assertHeader('X-Cache', 'HIT');

        $this->assertSame($first->json(), $second->json());
    }

    public function test_new_post_invalidates_home_page_cache(): void
    {
        $user = User::factory()->create();

        Post::factory()->create([
            'author_user_id' => $user->id,
            'text' => 'Старый пост',
            'created_at' => now()->subMinute(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/post/feed?limit=5')->assertOk()->assertHeader('X-Cache', 'MISS');

        $postId = $this->postJson('/post/create', ['text' => 'Новый пост'])->assertOk()->json('id');

        $this->getJson('/post/feed?limit=5')
            ->assertOk()
            ->assertJsonPath('0.id', $postId)
            ->assertJsonPath('0.text', 'Новый пост');
    }

    public function test_other_pages_do_not_use_page_cache(): void
    {
        $user = User::factory()->create();
        Post::factory()->count(3)->create(['author_user_id' => $user->id]);

        Sanctum::actingAs($user);

        $this->getJson('/post/feed?offset=0&limit=20')->assertOk();

        $this->assertFalse(Cache::has("feed:page:{$user->id}:0:20"));
        $this->assertFalse(Cache::has("feed:page:{$user->id}:0:5"));
    }

    public function test_page_cache_is_disabled_with_flag(): void
    {
        config(['feed.cache.enabled' => false]);

        $user = User::factory()->create();
        Post::factory()->create(['author_user_id' => $user->id]);

        Sanctum::actingAs($user);

        $this->getJson('/post/feed?limit=5')->assertOk()->assertHeader('X-Cache', 'OFF');

        $this->assertFalse(Cache::has("feed:page:{$user->id}:0:5"));
    }

    public function test_post_update_invalidates_home_page_cache(): void
    {
        $author = User::factory()->create();
        $friend = User::factory()->create();

        $this->makeFriends($author, $friend);

        Sanctum::actingAs($author);
        $postId = $this->postJson('/post/create', ['text' => 'Старый текст'])->json('id');

        Sanctum::actingAs($friend);
        $this->getJson('/post/feed?limit=5')->assertOk()->assertHeader('X-Cache', 'MISS');
        $this->getJson('/post/feed?limit=5')->assertOk()->assertHeader('X-Cache', 'HIT');

        Sanctum::actingAs($author);
        $this->putJson('/post/update', ['id' => $postId, 'text' => 'Новый текст'])->assertOk();

        Sanctum::actingAs($friend);
        $this->getJson('/post/feed?limit=5')
            ->assertOk()
            ->assertJsonPath('0.text', 'Новый текст');
    }

    private function makeFriends(User $first, User $second): void
    {
        Sanctum::actingAs($first);
        $this->putJson("/friend/set/{$second->id}")->assertOk();
    }
}
