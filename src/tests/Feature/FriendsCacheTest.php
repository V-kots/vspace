<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FriendsCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['friends.cache.enabled' => true]);
        Cache::flush();
    }

    public function test_friend_list_is_cached(): void
    {
        $viewer = User::factory()->create();
        $friend = User::factory()->create();

        $this->makeFriends($viewer, $friend);

        Sanctum::actingAs($viewer);

        $first = $this->getJson('/friend/list')->assertOk();
        $this->assertTrue(Cache::has("friends:profiles:{$viewer->id}"));

        $second = $this->getJson('/friend/list')->assertOk();

        $this->assertSame($first->json(), $second->json());
        $this->assertSame([$friend->id], array_column($second->json(), 'id'));
    }

    public function test_adding_friend_invalidates_cache(): void
    {
        $viewer = User::factory()->create();
        $stranger = User::factory()->create();

        Sanctum::actingAs($viewer);

        $this->getJson('/friend/list')->assertOk()->assertExactJson([]);

        $this->putJson("/friend/set/{$stranger->id}")->assertOk();

        $this->getJson('/friend/list')
            ->assertOk()
            ->assertJsonPath('0.id', $stranger->id);
    }

    public function test_removing_friend_invalidates_cache(): void
    {
        $viewer = User::factory()->create();
        $friend = User::factory()->create();

        $this->makeFriends($viewer, $friend);

        Sanctum::actingAs($viewer);

        $this->getJson('/friend/list')->assertOk()->assertJsonPath('0.id', $friend->id);

        $this->putJson("/friend/delete/{$friend->id}")->assertOk();

        $this->getJson('/friend/list')->assertOk()->assertExactJson([]);
    }

    public function test_friends_count_uses_cache(): void
    {
        $viewer = User::factory()->create();
        $firstFriend = User::factory()->create();
        $secondFriend = User::factory()->create();

        $this->makeFriends($viewer, $firstFriend);
        $this->makeFriends($viewer, $secondFriend);

        Sanctum::actingAs($viewer);

        $this->getJson("/user/get/{$viewer->id}")
            ->assertOk()
            ->assertJsonPath('friends_count', 2);

        $this->assertTrue(Cache::has("friends:ids:{$viewer->id}"));
    }

    public function test_cache_is_not_used_when_disabled(): void
    {
        config(['friends.cache.enabled' => false]);

        $viewer = User::factory()->create();

        Sanctum::actingAs($viewer);

        $this->getJson('/friend/list')->assertOk();

        $this->assertFalse(Cache::has("friends:ids:{$viewer->id}"));
        $this->assertFalse(Cache::has("friends:profiles:{$viewer->id}"));
    }

    private function makeFriends(User $first, User $second): void
    {
        Sanctum::actingAs($first);
        $this->putJson("/friend/set/{$second->id}")->assertOk();
    }
}
