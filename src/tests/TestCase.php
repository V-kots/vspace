<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    protected function assertApiError(
        TestResponse $response,
        int $status,
        int $code,
        ?string $message = null,
    ): void {
        $response
            ->assertStatus($status)
            ->assertJsonStructure(['message', 'request_id', 'code'])
            ->assertJsonPath('code', $code);

        if ($message !== null) {
            $response->assertJsonPath('message', $message);
        }
    }
}
