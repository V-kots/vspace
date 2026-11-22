<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ApiError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FriendTest extends TestCase
{
    use RefreshDatabase;

    public function test_friend_set_requires_authentication(): void
    {
        $friend = User::factory()->create();

        $this->assertApiError(
            $this->putJson("/friend/set/{$friend->id}"),
            401,
            ApiError::CODE_UNAUTHORIZED,
        );
    }

    public function test_authenticated_user_can_add_and_remove_friend(): void
    {
        $user = User::factory()->create();
        $friend = User::factory()->create();

        Sanctum::actingAs($user);

        $this->putJson("/friend/set/{$friend->id}")->assertOk();

        // Дружба хранится одной строкой в каноническом порядке.
        $this->assertDatabaseCount('friendships', 1);

        $this->getJson('/friend/list')
            ->assertOk()
            ->assertJsonPath('0.id', $friend->id);

        // Повторное добавление не создаёт дубль.
        $this->putJson("/friend/set/{$friend->id}")->assertOk();
        $this->assertDatabaseCount('friendships', 1);

        $this->putJson("/friend/delete/{$friend->id}")->assertOk();
        $this->assertDatabaseCount('friendships', 0);

        // Повторное удаление идемпотентно.
        $this->putJson("/friend/delete/{$friend->id}")->assertOk();
    }

    public function test_friend_add_alias_works_like_set(): void
    {
        $user = User::factory()->create();
        $friend = User::factory()->create();

        Sanctum::actingAs($user);

        $this->putJson("/friend/add/{$friend->id}")->assertOk();

        $this->assertDatabaseCount('friendships', 1);

        $this->getJson('/friend/list')
            ->assertOk()
            ->assertJsonPath('0.id', $friend->id);

        $this->putJson("/friend/delete/{$friend->id}")->assertOk();
    }

    public function test_friend_list_requires_authentication(): void
    {
        $this->assertApiError(
            $this->getJson('/friend/list'),
            401,
            ApiError::CODE_UNAUTHORIZED,
        );
    }

    public function test_friend_list_is_symmetric(): void
    {
        $user = User::factory()->create();
        $friend = User::factory()->create();
        $reverseFriend = User::factory()->create();

        Sanctum::actingAs($user);
        $this->putJson("/friend/set/{$friend->id}")->assertOk();

        // Встречное добавление тоже означает дружбу: направление не важно.
        Sanctum::actingAs($reverseFriend);
        $this->putJson("/friend/set/{$user->id}")->assertOk();

        Sanctum::actingAs($user);

        $response = $this->getJson('/friend/list');

        $response
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonMissingPath('0.email')
            ->assertJsonMissingPath('0.password')
            ->assertJsonMissingPath('0.gender');

        $ids = array_column($response->json(), 'id');

        $this->assertContains($friend->id, $ids);
        $this->assertContains($reverseFriend->id, $ids);
        $this->assertNotContains($user->id, $ids);
    }

    public function test_delete_from_any_side_removes_friendship_for_both(): void
    {
        $user = User::factory()->create();
        $friend = User::factory()->create();

        Sanctum::actingAs($user);
        $this->putJson("/friend/set/{$friend->id}")->assertOk();

        // Удаляет «другая сторона» — связь исчезает у обоих.
        Sanctum::actingAs($friend);
        $this->putJson("/friend/delete/{$user->id}")->assertOk();

        $this->assertDatabaseCount('friendships', 0);

        $this->getJson('/friend/list')->assertOk()->assertJsonCount(0);

        Sanctum::actingAs($user);
        $this->getJson('/friend/list')->assertOk()->assertJsonCount(0);
    }

    public function test_friend_list_is_empty_when_no_friends(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/friend/list')
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_user_cannot_add_self_as_friend(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $this->assertApiError(
            $this->putJson("/friend/set/{$user->id}"),
            400,
            ApiError::CODE_VALIDATION,
            'Нельзя добавить себя в друзья',
        );

        $this->assertDatabaseCount('friendships', 0);
    }

    public function test_friend_set_returns_404_for_unknown_user(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->assertApiError(
            $this->putJson('/friend/set/00000000-0000-0000-0000-000000000000'),
            404,
            ApiError::CODE_NOT_FOUND,
            'Пользователь не найден',
        );
    }

    public function test_friend_set_validates_uuid(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->assertApiError(
            $this->putJson('/friend/set/not-a-uuid'),
            400,
            ApiError::CODE_VALIDATION,
        );
    }

    public function test_friend_delete_validates_uuid(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->assertApiError(
            $this->putJson('/friend/delete/not-a-uuid'),
            400,
            ApiError::CODE_VALIDATION,
        );
    }
}
