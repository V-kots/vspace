<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ApiError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchUsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_returns_profiles_matching_both_name_prefixes_sorted_by_id(): void
    {
        $second = User::factory()->create([
            'first_name' => 'Иван',
            'second_name' => 'Петров',
            'city' => 'Москва',
        ]);

        $first = User::factory()->create([
            'first_name' => 'Иван',
            'second_name' => 'Петровский',
            'city' => 'Казань',
        ]);

        User::factory()->create([
            'first_name' => 'Иван',
            'second_name' => 'Сидоров',
        ]);

        User::factory()->create([
            'first_name' => 'Пётр',
            'second_name' => 'Петров',
        ]);

        $response = $this->getJson('/user/search?first_name=Ива&last_name=Петр');

        $response->assertOk();

        $expectedIds = collect([$first->id, $second->id])->sort()->values()->all();

        $response
            ->assertJsonCount(2)
            ->assertJsonPath('0.id', $expectedIds[0])
            ->assertJsonPath('1.id', $expectedIds[1])
            ->assertJsonMissing(['password', 'email', 'gender']);

        $response->assertJsonFragment([
            'id' => $second->id,
            'first_name' => 'Иван',
            'second_name' => 'Петров',
            'city' => 'Москва',
        ]);
    }

    public function test_search_is_case_insensitive(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Ivan',
            'second_name' => 'Petrov',
        ]);

        $this->getJson('/user/search?first_name=iva&last_name=petr')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $user->id);
    }

    public function test_search_returns_empty_array_when_nothing_matches(): void
    {
        User::factory()->create([
            'first_name' => 'Иван',
            'second_name' => 'Петров',
        ]);

        $this->getJson('/user/search?first_name=Иван&last_name=Сидоров')
            ->assertOk()
            ->assertJson([]);
    }

    public function test_search_requires_both_query_parameters(): void
    {
        $this->assertApiError(
            $this->getJson('/user/search'),
            400,
            ApiError::CODE_VALIDATION,
        );

        $this->assertApiError(
            $this->getJson('/user/search?first_name=Иван'),
            400,
            ApiError::CODE_VALIDATION,
        );
    }

    public function test_search_treats_like_wildcards_as_literal_characters(): void
    {
        User::factory()->create([
            'first_name' => 'Иван',
            'second_name' => 'Петров',
        ]);

        $this->getJson('/user/search?first_name=%&last_name=%')
            ->assertOk()
            ->assertJson([]);
    }

    public function test_search_response_matches_openapi_user_schema(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Иван',
            'second_name' => 'Петров',
        ]);

        $response = $this->getJson('/user/search?first_name=Иван&last_name=Петров');

        $response->assertOk()->assertJsonCount(1);

        $this->assertSame(
            ['id', 'first_name', 'second_name', 'birthdate', 'biography', 'city'],
            array_keys($response->json('0')),
        );

        $response
            ->assertJsonPath('0.id', $user->id)
            ->assertJsonPath('0.first_name', 'Иван')
            ->assertJsonPath('0.second_name', 'Петров');
    }

    public function test_search_is_protected_from_sql_injection_in_first_name(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Иван',
            'second_name' => 'Петров',
        ]);

        $payloads = [
            "' OR '1'='1",
            "%' OR 1=1 --",
            "'; DROP TABLE users; --",
            "Иван' UNION SELECT id, first_name, second_name, birthdate, biography, city FROM users --",
        ];

        foreach ($payloads as $payload) {
            $this->getJson('/user/search?'.http_build_query([
                'first_name' => $payload,
                'last_name' => 'Петров',
            ]))
                ->assertOk()
                ->assertJson([]);
        }

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_search_is_protected_from_sql_injection_in_last_name(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Иван',
            'second_name' => 'Петров',
        ]);

        $payloads = [
            "' OR '1'='1",
            "%' OR 1=1 --",
            "'; DROP TABLE users; --",
        ];

        foreach ($payloads as $payload) {
            $this->getJson('/user/search?'.http_build_query([
                'first_name' => 'Иван',
                'last_name' => $payload,
            ]))
                ->assertOk()
                ->assertJson([]);
        }

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_search_rejects_array_query_parameters(): void
    {
        $this->assertApiError(
            $this->getJson('/user/search?first_name[]=Иван&last_name=Петров'),
            400,
            ApiError::CODE_VALIDATION,
        );
    }
}
