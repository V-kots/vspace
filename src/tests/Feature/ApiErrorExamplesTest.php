<?php

namespace Tests\Feature;

use App\Support\ApiError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Сценарии из Postman-коллекции «Примеры ошибок» (api/postman/VSpace.postman_collection.json).
 */
class ApiErrorExamplesTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_not_found_when_user_does_not_exist(): void
    {
        $response = $this->postJson('/login', [
            'id' => '00000000-0000-0000-0000-000000000000',
            'password' => 'Secret123!',
        ]);

        $this->assertApiError(
            $response,
            404,
            ApiError::CODE_NOT_FOUND,
            'Пользователь не найден',
        );
    }

    public function test_register_returns_validation_error_for_invalid_payload(): void
    {
        $response = $this->postJson('/user/register', [
            'first_name' => '',
            'password' => '123',
        ]);

        $this->assertApiError($response, 400, ApiError::CODE_VALIDATION);
        $response->assertJsonPath('message', fn (string $message) => $message !== '');
    }

    public function test_get_user_returns_not_found_when_profile_missing(): void
    {
        $response = $this->getJson('/user/get/00000000-0000-0000-0000-000000000000');

        $this->assertApiError(
            $response,
            404,
            ApiError::CODE_NOT_FOUND,
            'Анкета не найдена',
        );
    }

    public function test_get_user_returns_validation_error_for_invalid_id(): void
    {
        $response = $this->getJson('/user/get/not-a-uuid');

        $this->assertApiError(
            $response,
            400,
            ApiError::CODE_VALIDATION,
            'Невалидные данные',
        );
    }
}
