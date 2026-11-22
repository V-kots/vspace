<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use App\Repositories\PostRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PostCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['feed.post.enabled' => true]);
        Cache::flush();
    }

    public function test_post_body_is_cached_after_first_read(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->create([
            'author_user_id' => $user->id,
            'text' => 'Текст поста',
        ]);

        $repository = app(PostRepository::class);

        $first = $repository->findByIds([$post->id]);

        $this->assertSame('Текст поста', $first[0]['text']);
        $this->assertTrue(Cache::has('post:'.$post->id));

        $this->assertSame($first, $repository->findByIds([$post->id]));
    }

    public function test_update_invalidates_cached_body(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->create([
            'author_user_id' => $user->id,
            'text' => 'Старый текст',
        ]);

        $repository = app(PostRepository::class);
        $repository->findByIds([$post->id]);
        $this->assertTrue(Cache::has('post:'.$post->id));

        $repository->updateText($post->id, 'Новый текст');

        $this->assertFalse(Cache::has('post:'.$post->id));
        $this->assertSame('Новый текст', $repository->findById($post->id)['text']);
    }

    public function test_delete_invalidates_cached_body(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->create(['author_user_id' => $user->id]);

        $repository = app(PostRepository::class);
        $repository->findByIds([$post->id]);
        $this->assertTrue(Cache::has('post:'.$post->id));

        $repository->deleteById($post->id);

        $this->assertFalse(Cache::has('post:'.$post->id));
        $this->assertNull($repository->findById($post->id));
    }

    public function test_post_endpoint_uses_cached_body(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->create([
            'author_user_id' => $user->id,
            'text' => 'Пост для открытия',
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/post/get/{$post->id}")
            ->assertOk()
            ->assertJsonPath('text', 'Пост для открытия');

        $this->assertTrue(Cache::has('post:'.$post->id));
    }

    public function test_cache_is_not_used_when_disabled(): void
    {
        config(['feed.post.enabled' => false]);

        $user = User::factory()->create();
        $post = Post::factory()->create(['author_user_id' => $user->id]);

        $repository = app(PostRepository::class);
        $repository->findByIds([$post->id]);

        $this->assertFalse(Cache::has('post:'.$post->id));
    }
}
