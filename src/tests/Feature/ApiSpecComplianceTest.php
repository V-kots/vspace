<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Проверки соответствия реализованных методов api/swagger/openapi.json.
 */
class ApiSpecComplianceTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_accepts_only_fields_declared_in_openapi_spec(): void
    {
        $response = $this->postJson('/user/register', [
            'first_name' => 'Иван',
            'second_name' => 'Петров',
            'birthdate' => '1995-05-12',
            'biography' => 'Хобби, интересы и т.п.',
            'city' => 'Москва',
            'password' => 'secret123',
        ]);

        $response->assertOk()->assertJsonStructure(['user_id']);

        $userId = $response->json('user_id');

        $this->getJson("/user/get/{$userId}")
            ->assertOk()
            ->assertJsonStructure(['id', 'first_name', 'second_name', 'birthdate', 'biography', 'city']);

        $this->postJson('/login', [
            'id' => $userId,
            'password' => 'secret123',
        ])
            ->assertOk()
            ->assertJsonStructure(['token']);
    }

    public function test_search_accepts_query_parameters_from_openapi_spec(): void
    {
        $this->postJson('/user/register', [
            'first_name' => 'Константин',
            'second_name' => 'Осипов',
            'birthdate' => '1995-05-12',
            'city' => 'Москва',
            'password' => 'secret123',
        ])->assertOk();

        $response = $this->getJson('/user/search?'.http_build_query([
            'first_name' => 'Конст',
            'last_name' => 'Оси',
        ]));

        $response
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonStructure([
                '*' => ['id', 'first_name', 'second_name', 'birthdate', 'biography', 'city'],
            ]);

        $this->assertSame('Константин', $response->json('0.first_name'));
        $this->assertSame('Осипов', $response->json('0.second_name'));
    }
}
