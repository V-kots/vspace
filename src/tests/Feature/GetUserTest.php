<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GetUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_user_returns_friends_count(): void
    {
        $user = User::factory()->create();
        $friend = User::factory()->create();
        $anotherFriend = User::factory()->create();

        Sanctum::actingAs($user);

        $this->putJson("/friend/set/{$friend->id}")->assertOk();
        $this->putJson("/friend/set/{$anotherFriend->id}")->assertOk();

        $this->getJson("/user/get/{$user->id}")
            ->assertOk()
            ->assertJsonPath('friends_count', 2);

        // Дружба ненаправленная: у друга в счётчике тоже 1 пользователь.
        $this->getJson("/user/get/{$friend->id}")
            ->assertOk()
            ->assertJsonPath('friends_count', 1);
    }

    public function test_get_user_returns_public_profile(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Иван',
            'second_name' => 'Петров',
            'birthdate' => '1995-05-12',
            'biography' => 'Хобби и интересы',
            'city' => 'Москва',
        ]);

        $response = $this->getJson("/user/get/{$user->id}");

        $response
            ->assertOk()
            ->assertJson([
                'id' => $user->id,
                'first_name' => 'Иван',
                'second_name' => 'Петров',
                'birthdate' => '1995-05-12',
                'biography' => 'Хобби и интересы',
                'city' => 'Москва',
            ])
            ->assertJsonMissing(['password', 'email', 'gender']);
    }

    public function test_get_user_returns_empty_biography_when_null(): void
    {
        $user = User::factory()->create([
            'biography' => null,
        ]);

        $this->getJson("/user/get/{$user->id}")
            ->assertOk()
            ->assertJsonPath('biography', '');
    }
}
