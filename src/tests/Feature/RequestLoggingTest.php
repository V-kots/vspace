<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RequestLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_mutation_is_logged_as_info_without_payload(): void
    {
        $user = User::factory()->create();
        $friend = User::factory()->create();

        Sanctum::actingAs($user);

        Log::spy();

        $this->putJson("/friend/set/{$friend->id}")->assertOk();

        Log::shouldHaveReceived('log')
            ->withArgs(function (string $level, string $message, array $context) use ($user): bool {
                return $level === 'info'
                    && $message === 'http_request'
                    && $context['status'] === 200
                    && $context['user_id'] === (string) $user->id
                    && ! array_key_exists('payload', $context);
            })
            ->atLeast()
            ->once();
    }

    public function test_failed_request_is_logged_with_redacted_payload(): void
    {
        Log::spy();

        $this->postJson('/login', [
            'id' => '00000000-0000-0000-0000-000000000000',
            'password' => 'super-secret',
        ])->assertNotFound();

        Log::shouldHaveReceived('log')
            ->withArgs(function (string $level, string $message, array $context): bool {
                return $level === 'warning'
                    && $message === 'http_request'
                    && $context['status'] === 404
                    && $context['payload']['password'] === '[redacted]'
                    && $context['payload']['id'] === '00000000-0000-0000-0000-000000000000';
            })
            ->atLeast()
            ->once();
    }

    public function test_json_list_response_is_logged_with_sample_and_total_count(): void
    {
        User::factory()->count(7)->create([
            'first_name' => 'Иван',
            'second_name' => 'Петров',
        ]);

        Log::spy();

        $this->getJson('/user/search?'.http_build_query([
            'first_name' => 'Иван',
            'last_name' => 'Петров',
            'limit' => 25,
        ]))->assertOk();

        Log::shouldHaveReceived('log')
            ->withArgs(function (string $level, string $message, array $context): bool {
                return $level === 'debug'
                    && $message === 'http_request'
                    && $context['query']['first_name'] === 'Иван'
                    && $context['query']['limit'] === '25'
                    && $context['response_count'] === 7
                    && count($context['response']) === 5
                    && $context['response_truncated'] === true
                    && $context['response'][0]['first_name'] === 'Иван'
                    && ! array_key_exists('payload', $context);
            })
            ->atLeast()
            ->once();
    }

    public function test_json_object_response_is_logged_without_sensitive_fields(): void
    {
        $user = User::factory()->create(['first_name' => 'Иван']);

        Log::spy();

        $this->getJson("/user/get/{$user->id}")->assertOk();

        Log::shouldHaveReceived('log')
            ->withArgs(function (string $level, string $message, array $context) use ($user): bool {
                return $message === 'http_request'
                    && $context['response']['id'] === (string) $user->id
                    && $context['response']['first_name'] === 'Иван'
                    && ! array_key_exists('response_count', $context)
                    && ! array_key_exists('email', $context['response'])
                    && ! array_key_exists('password', $context['response']);
            })
            ->atLeast()
            ->once();
    }

    public function test_login_token_in_response_is_redacted(): void
    {
        $user = User::factory()->create(['password' => 'secret-pass']);

        Log::spy();

        $this->postJson('/login', [
            'id' => $user->id,
            'password' => 'secret-pass',
        ])->assertOk();

        Log::shouldHaveReceived('log')
            ->withArgs(function (string $level, string $message, array $context) use ($user): bool {
                return $message === 'http_request'
                    && $context['response']['token'] === '[redacted]'
                    && $context['response']['user_id'] === (string) $user->id;
            })
            ->atLeast()
            ->once();
    }

    public function test_non_json_response_body_is_not_logged(): void
    {
        Route::get('/__plain', fn () => response('plain text'));

        Log::spy();

        $this->get('/__plain')->assertOk();

        Log::shouldHaveReceived('log')
            ->withArgs(function (string $level, string $message, array $context): bool {
                return $message === 'http_request'
                    && ! array_key_exists('response', $context)
                    && ! array_key_exists('response_count', $context);
            })
            ->atLeast()
            ->once();
    }
}
